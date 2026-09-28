<?php

declare(strict_types=1);

/*
 * This file is a part of the DiscordPHP-Bridge-YouTube project.
 *
 * Copyright (c) 2026-present Valithor Obsidion <valithor@valgorithms.com>
 *
 * This file is subject to the MIT license that is bundled
 * with this source code in the LICENSE.md file.
 */

namespace Bridge\YouTube;

use Bridge\Bot;
use Bridge\Capability\Avatars;
use Bridge\Capability\ProvidesActions;
use Bridge\Capability\Reconnects;
use Bridge\Command\ActionError;
use Bridge\Command\Surface;
use Bridge\Connector;
use Bridge\Links;
use Bridge\Message\Incoming;
use Bridge\Message\Outgoing;
use Bridge\Room;
use Bridge\Support\ConnectionAlerts;
use Bridge\Support\JsonFile;
use Bridge\Support\LiveAnnouncer;
use Bridge\YouTube\Actions\ModerationActions;
use Bridge\YouTube\Actions\YouTubeActions;
use Carbon\CarbonImmutable;
use React\Promise\PromiseInterface;
use YouTube\Auth\EnvFileTokenStore;
use YouTube\Http\Exceptions\QuotaExceededException;
use YouTube\LiveChat\BroadcastWatcher;
use YouTube\LiveChat\ChatReader;
use YouTube\Parts\Channel;
use YouTube\Parts\LiveBroadcast;
use YouTube\Parts\LiveBroadcastStatus;
use YouTube\Parts\LiveChatMessage;
use YouTube\Quota\Meter;
use YouTube\YouTube;

use function React\Promise\all;
use function React\Promise\reject;
use function React\Promise\resolve;

/**
 * YouTube, as far as the bridge is concerned: the live chat of the channel the
 * bot is signed in as, carried into Discord.
 *
 * One direction on purpose. Reading chat is cheap in quota; every message
 * posted into it costs 50 of the day's 10,000 units, so a busy Discord channel
 * relayed into YouTube would spend the day's quota within an hour. The bot
 * posts in YouTube chat only to answer commands, and when the owner uses
 * `say`. {@see relay()} resolves null for everything else.
 *
 * - **Chat:** a {@see BroadcastWatcher} notices the channel going live, and a
 *   {@see ChatReader} reads each live chat, streaming it. Text, Super Chats and
 *   Stickers, memberships and gifts are relayed, each under its sender's name
 *   and avatar.
 * - **Moderation:** a ban on YouTube removes the banned person's recent
 *   messages from Discord too. YouTube no longer says when a single message is
 *   deleted, so those copies stay.
 * - **Announcements:** going live and ending are announced in the bridged
 *   channels, from what the watcher already knows, at no extra quota.
 * - **Quota:** reading chat stops at the reserve, and the owner gets one DM
 *   saying chat is paused until the reset at midnight Pacific.
 * - **Drops:** the reader reconnects by itself. When it runs out of quick
 *   retries the owner gets a DM with a Reconnect button, and the DM changes
 *   when chat is back.
 *
 * @author Valithor Obsidion <valithor@valgorithms.com>
 */
final class YouTubeConnector implements Connector, ProvidesActions, Avatars, Reconnects
{
    /** The name this connector is addressed by, in the store and in chat. */
    public const NAME = 'youtube';

    /** Seconds between the reader's attempts once its quick retries run out; the owner's DM quotes it. */
    public const KEEP_TRYING_EVERY = 300.0;

    /**
     * How long a posted line is remembered, to recognise it when it comes back
     * through the chat stream. It comes back within seconds; this only has to
     * outlast a slow network.
     */
    public const ECHO_WINDOW = 30.0;

    /** Chatters remembered, for avatars and for moderating by name. */
    private const CHATTERS = 500;

    /** Bans remembered, so this bot can lift the ones it made. */
    private const BANS = 200;

    private Bot $bot;

    private ?Channel $channel = null;

    private ?BroadcastWatcher $watcher = null;

    private ?YouTubeAdapter $adapter = null;

    private ?LiveAnnouncer $announcer = null;

    /** Where connection trouble is reported: the bot's own, unless a test says otherwise. */
    private ?ConnectionAlerts $alerts = null;

    /** Whether the channel is bridged anywhere, which is what the watcher runs for. */
    private bool $bridged = false;

    /** @var array<string, array{reader: ChatReader, broadcast: LiveBroadcast}> The chats being read, by live chat id. */
    private array $chats = [];

    /** @var list<callable(Incoming): void> */
    private array $handlers = [];

    /** @var list<array{text: string, at: float}> What the bot posted recently, oldest first. */
    private array $sent = [];

    /** @var array<string, true> Ids of messages the bot posted recently. */
    private array $sentIds = [];

    /** @var array<string, array{name: string, avatar: ?string, messages: list<string>}> Recent chatters by channel id, oldest first. */
    private array $chatters = [];

    /** @var array<string, string> Channel id => the id of the ban this bot made. */
    private array $bans = [];

    /** When chat went down, as a Unix time; null while it is up. */
    private ?int $downSince = null;

    /** The quota day the owner was last told about, so it is said once. */
    private ?string $quotaToldFor = null;

    private bool $grantWarned = false;

    /** @var \Closure(): float */
    private \Closure $clock;

    /**
     * @param ?YouTube                $youtube A client to use instead of building one, for tests.
     * @param (\Closure(): float)|null $clock  The time, for tests.
     */
    public function __construct(
        private readonly YouTubeConfig $config,
        private ?YouTube $youtube = null,
        ?\Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): float => microtime(true);
    }

    // ── Identity ───────────────────────────────────────────────────────

    public function name(): string
    {
        return self::NAME;
    }

    public function label(): string
    {
        return 'YouTube';
    }

    /** YouTube chat takes 200 characters of flat text on a single line. */
    public function surface(): Surface
    {
        return new Surface(self::NAME, 'YouTube', YouTubeText::LIMIT, markdown: false, lines: false, prefix: $this->config->prefix);
    }

    public function getYouTube(): YouTube
    {
        return $this->youtube ?? throw new \LogicException('The YouTube connector has not been booted.');
    }

    public function getConfig(): YouTubeConfig
    {
        return $this->config;
    }

    /** The channel the bot is signed in as, once started. */
    public function getChannel(): ?Channel
    {
        return $this->channel;
    }

    public function getWatcher(): ?BroadcastWatcher
    {
        return $this->watcher;
    }

    /** The reader of a live chat, while it is being read. */
    public function chatReader(string $liveChatId): ?ChatReader
    {
        return $this->chats[$liveChatId]['reader'] ?? null;
    }

    // ── Lifecycle ──────────────────────────────────────────────────────

    public function boot(Bot $bot): void
    {
        $this->bot = $bot;
        $store = $bot->getStore();

        $this->youtube ??= new YouTube([
            'client_id' => $this->config->clientId,
            'client_secret' => $this->config->clientSecret,
            'token' => $this->config->accessToken,
            'refresh_token' => $this->config->refreshToken,
            // The sign-in's refresh token goes back to .env, so it happens once.
            'token_store' => new EnvFileTokenStore($this->config->envPath),
            'device_prompt' => fn (array $device) => $this->promptForDeviceCode($device),
            'quota' => [
                'daily' => $this->config->dailyQuota,
                'reserve' => $this->config->quotaReserve,
                'path' => dirname($store->path()) . '/youtube-quota.json',
            ],
            'loop' => $bot->getLoop(),
            'logger' => $bot->getLogger(),
        ]);
    }

    /**
     * Signs in and loads the channel. Reading chat starts once {@see sync()}
     * finds the channel bridged.
     *
     * The first start with no refresh token waits for the owner to enter the
     * DMed sign-in code, which is up to half an hour. Nothing else waits on it.
     */
    public function start(): PromiseInterface
    {
        $youtube = $this->getYouTube();
        $youtube->on('quota.exhausted', function (string $bucket, CarbonImmutable $resets): void {
            // Search and uploads have buckets of their own, which chat never touches.
            if ($bucket === Meter::DEFAULT_BUCKET) {
                $this->quotaSpent($resets);
            }
        });
        $youtube->on('grant_expires', fn (int $seconds) => $this->grantExpires($seconds));

        return $youtube->bootstrap()->then(function (?Channel $channel): bool {
            if ($channel === null) {
                throw new \RuntimeException('YouTube signed in without a channel.');
            }

            $this->channel = $channel;
            $this->adapter = new YouTubeAdapter($this, $this->bot);

            $this->watcher = new BroadcastWatcher($this->getYouTube());
            $this->watcher->on('broadcast.live', fn (LiveBroadcast $broadcast) => $this->joinChat($broadcast));
            $this->watcher->on('broadcast.ended', fn (LiveBroadcast $broadcast) => $this->leaveChat($broadcast));

            $this->announcer = $this->liveAnnouncer();
            $this->announcer->start();

            $this->bot->getLogger()->info(sprintf(
                '[youtube] signed in as %s (%s); %d bridge(s) configured, commands start with %s',
                (string) $channel->snippet?->title,
                (string) $channel->id,
                $this->bot->getStore()->links(self::NAME)->count(),
                $this->config->prefix,
            ));

            return true;
        });
    }

    public function stop(): void
    {
        $this->announcer?->stop();
        $this->watcher?->stop();

        foreach ($this->chats as $chat) {
            $chat['reader']->stop();
        }

        $this->chats = [];
        $this->youtube?->close();
    }

    /**
     * Tries chat again now; see {@see Reconnects}. With nothing live, it asks
     * YouTube again whether anything is.
     */
    public function reconnect(): PromiseInterface
    {
        if ($this->watcher === null) {
            return reject(new \RuntimeException('YouTube is not set up; it never started.'));
        }

        if ($this->chats === []) {
            return $this->watcher->check()->then(static fn (): mixed => null);
        }

        return all(array_map(
            static fn (array $chat): PromiseInterface => $chat['reader']->reconnect(),
            array_values($this->chats),
        ));
    }

    // ── Rooms ──────────────────────────────────────────────────────────

    /**
     * Starts watching the channel when it is bridged somewhere, and stops when
     * it no longer is: every check costs quota, and nothing would read it.
     */
    public function sync(Links $links): array
    {
        $own = $this->channel?->id;

        if ($own === null) {
            return ['join' => [], 'part' => []];
        }

        $wanted = in_array($own, $links->targets(), true);

        if ($wanted && ! $this->bridged) {
            $this->bridged = true;
            $this->watcher?->start();

            return ['join' => [$own], 'part' => []];
        }

        if (! $wanted && $this->bridged) {
            $this->bridged = false;
            $this->watcher?->stop();

            foreach ($this->chats as $chat) {
                $chat['reader']->stop();
            }
            $this->chats = [];

            return ['join' => [], 'part' => [$own]];
        }

        return ['join' => [], 'part' => []];
    }

    /** The channel, while it is bridged and watched. */
    public function joined(): array
    {
        return $this->bridged && $this->channel !== null ? [(string) $this->channel->id] : [];
    }

    public function queued(): int
    {
        return 0;
    }

    public function normalise(string $input): ?string
    {
        return YouTubeText::normaliseChannel($input);
    }

    /**
     * The channel the bot is signed in as, by id, handle, link or `me`. Any
     * other channel is refused with the reason: the API reads the live chat of
     * the signed-in account's own broadcasts, so another channel would need
     * its owner to sign the bot in.
     *
     * @return PromiseInterface<?Room>
     */
    public function resolve(string $target): PromiseInterface
    {
        $channel = $this->channel;

        if ($channel === null) {
            return reject(new \RuntimeException('YouTube is not signed in yet.'));
        }

        $handle = strtolower((string) $channel->snippet?->customUrl);

        if ($target === YouTubeText::ME || $target === $channel->id || ($handle !== '' && strtolower($target) === $handle)) {
            return resolve($this->room($channel));
        }

        return reject(new ActionError(sprintf(
            'the bot is signed in to YouTube as **%s**, and can only bridge that channel. To bridge `%s`, sign the bot in with the account that owns it.',
            (string) $channel->snippet?->title,
            $target,
        )));
    }

    private function room(Channel $channel): Room
    {
        $handle = (string) $channel->snippet?->customUrl;

        return new Room(
            id: (string) $channel->id,
            label: (string) ($channel->snippet?->title ?: $channel->id),
            url: 'https://www.youtube.com/' . ($handle !== '' ? $handle : 'channel/' . $channel->id),
            kind: 'channel',
            description: ($channel->snippet?->description ?? '') !== '' ? (string) $channel->snippet->description : null,
            avatarUrl: $channel->snippet?->thumbnails?->default?->url,
        );
    }

    // ── Messages ───────────────────────────────────────────────────────

    /**
     * Posts into the live chat, for a command's reply or the owner's `say`.
     * Each post costs 50 units of quota.
     *
     * A reply keeps clear of the quota reserve, which exists for what a person
     * deliberately does; `deliberate` in the options may spend it. Resolves
     * with the posted message's id.
     *
     * @param array<string, mixed> $options `deliberate`: spend the reserve if need be.
     */
    public function send(string $target, string $text, array $options = []): PromiseInterface
    {
        if ($this->channel === null || $target !== $this->channel->id) {
            return resolve(null);
        }

        $chatId = $this->activeChatId();

        if ($chatId === null) {
            return reject(new ActionError('the channel is not live, so there is no YouTube chat to post in.'));
        }

        $text = YouTubeText::forChat($text);

        if ($text === '') {
            return resolve(null);
        }

        $youtube = $this->getYouTube();
        $meter = $youtube->getMeter();

        if (! $meter->canAfford('liveChatMessages.insert', keepReserve: ! ($options['deliberate'] ?? false))) {
            return reject(QuotaExceededException::local('liveChatMessages.insert', $meter));
        }

        $this->sent[] = ['text' => $text, 'at' => ($this->clock)()];

        return $youtube->liveChatMessages->insert('snippet', [
            'snippet' => [
                'liveChatId' => $chatId,
                'type' => 'textMessageEvent',
                'textMessageDetails' => ['messageText' => $text],
            ],
        ])->then(function (LiveChatMessage $posted): ?string {
            if (is_string($posted->id)) {
                $this->sentIds[$posted->id] = true;

                if (count($this->sentIds) > 100) {
                    unset($this->sentIds[array_key_first($this->sentIds)]);
                }
            }

            return $posted->id;
        });
    }

    /**
     * Discord → YouTube is off by design: see the class docblock. Nothing is
     * posted, and nothing fails.
     */
    public function relay(string $target, Outgoing $message): PromiseInterface
    {
        return resolve(null);
    }

    public function onIncoming(callable $handler): void
    {
        $this->handlers[] = $handler;
    }

    /** @return list<\Bridge\Command\Action> */
    public function actions(): array
    {
        return [
            ...(new YouTubeActions())->actions(),
            ...(new ModerationActions())->actions(),
        ];
    }

    /** A chatter's avatar, which arrived with their message: no lookup, no quota. */
    public function avatarFor(Incoming $message): PromiseInterface
    {
        return resolve($this->chatters[(string) $message->authorId]['avatar'] ?? null);
    }

    // ── Chat ───────────────────────────────────────────────────────────

    /** The chat currently being read, newest first: where posts go. */
    public function activeChatId(): ?string
    {
        $ids = array_keys($this->chats);

        return $ids === [] ? null : (string) end($ids);
    }

    /**
     * Somebody recently in chat, by channel id or by name as it shows there,
     * `@` or not. The most recent match wins.
     *
     * @return array{channel_id: string, name: string, messages: list<string>}|null
     */
    public function findChatter(string $query): ?array
    {
        $query = trim($query);

        if (isset($this->chatters[$query])) {
            return ['channel_id' => $query] + array_intersect_key($this->chatters[$query], ['name' => 1, 'messages' => 1]);
        }

        $wanted = mb_strtolower(ltrim($query, '@'), 'UTF-8');

        foreach (array_reverse($this->chatters, true) as $channelId => $chatter) {
            if (mb_strtolower(ltrim($chatter['name'], '@'), 'UTF-8') === $wanted) {
                return ['channel_id' => (string) $channelId, 'name' => $chatter['name'], 'messages' => $chatter['messages']];
            }
        }

        return null;
    }

    /** Remembers a ban this bot made, so it can lift it: the API needs the ban's own id. */
    public function rememberBan(string $channelId, string $banId): void
    {
        unset($this->bans[$channelId]);
        $this->bans[$channelId] = $banId;

        if (count($this->bans) > self::BANS) {
            unset($this->bans[array_key_first($this->bans)]);
        }
    }

    /** The id of a ban this bot made on someone, if it remembers one. */
    public function banOf(string $channelId): ?string
    {
        return $this->bans[$channelId] ?? null;
    }

    public function forgetBan(string $channelId): void
    {
        unset($this->bans[$channelId]);
    }

    /** Hands the core a deletion, as if the network had reported it. */
    public function deleted(Incoming $deletion): void
    {
        $this->emitIncoming($deletion);
    }

    private function joinChat(LiveBroadcast $broadcast): void
    {
        $chatId = (string) $broadcast->snippet?->liveChatId;

        if (! $this->bridged || $chatId === '' || isset($this->chats[$chatId])) {
            return;
        }

        $reader = new ChatReader($this->getYouTube(), $chatId, [
            'mode' => $this->config->chatMode,
            'keep_trying_every' => self::KEEP_TRYING_EVERY,
        ]);

        $this->chats[$chatId] = ['reader' => $reader, 'broadcast' => $broadcast];
        $this->listen($reader, $broadcast);

        $this->bot->getLogger()->info(sprintf('[youtube] live: %s - reading its chat', (string) $broadcast->snippet?->title));
        $reader->start();
    }

    private function leaveChat(LiveBroadcast $broadcast): void
    {
        $chatId = (string) $broadcast->snippet?->liveChatId;

        if (isset($this->chats[$chatId])) {
            $this->chats[$chatId]['reader']->stop();
            unset($this->chats[$chatId]);
        }
    }

    private function listen(ChatReader $reader, LiveBroadcast $broadcast): void
    {
        foreach (['chat.message', 'chat.superchat', 'chat.member', 'chat.gift'] as $event) {
            $reader->on($event, fn (LiveChatMessage $message) => $this->received($message));
        }

        $reader->on('chat.deleted', fn (string $id) => $this->emitIncoming(Incoming::deletion($this->ownId(), $id)));

        $reader->on('chat.banned', function (LiveChatMessage $message): void {
            $banned = $message->snippet?->userBannedDetails?->bannedUserDetails;

            if ($banned?->channelId !== null) {
                $this->emitIncoming(Incoming::purge($this->ownId(), (string) $banned->channelId, (string) $banned->displayName));
            }
        });

        $reader->on('chat.ended', function () use ($broadcast): void {
            unset($this->chats[(string) $broadcast->snippet?->liveChatId]);
            $this->watcher?->ended((string) $broadcast->id);
        });

        $alerts = $this->alerts ??= $this->bot->connectionAlerts();

        $reader->on('chat.disconnected', function (): void {
            $this->downSince ??= (int) ($this->clock)();
        });
        $reader->on('chat.reconnect_failed', function (int $attempts) use ($alerts): void {
            $alerts->failed($this, sprintf(
                'Chat did not come back after %d attempts. It keeps trying every %d minutes.',
                $attempts,
                (int) round(self::KEEP_TRYING_EVERY / 60),
            ), $this->downSince);
        });
        $reader->on('chat.connected', function () use ($alerts): void {
            $this->downSince = null;
            $alerts->restored($this);
        });

        $reader->on('chat.paused', fn (CarbonImmutable $resumes) => $this->quotaSpent($resumes));
    }

    /** One chat item: remembered, answered if it is a command, and relayed. */
    private function received(LiveChatMessage $message): void
    {
        $author = $message->authorDetails;
        $authorId = (string) ($author?->channelId ?? $message->snippet?->authorChannelId ?? '');

        if ($authorId !== '') {
            $this->rememberChatter($authorId, (string) ($author?->displayName ?? ''), $author?->profileImageUrl, $message->id);
        }

        if ($this->isEcho($message, $authorId)) {
            return;
        }

        // Commands are answered here and dropped by the relay, which asks the
        // same dispatcher whether a line is one.
        $this->adapter?->handle($message);

        $text = YouTubeText::render($message);

        if ($text === null) {
            return;
        }

        $this->emitIncoming(new Incoming(
            target: $this->ownId(),
            author: (string) ($author?->displayName ?: 'someone'),
            authorId: $authorId !== '' ? $authorId : null,
            text: $text,
            id: is_string($message->id) ? $message->id : null,
            handle: $authorId !== '' ? $authorId : null,
        ));
    }

    /**
     * Whether a message is one the bot itself just posted, coming back through
     * the stream. The bot posts as the channel, so the channel's own messages
     * are only echoes when they match one: the streamer typing is relayed.
     */
    private function isEcho(LiveChatMessage $message, string $authorId): bool
    {
        if ($authorId === '' || $authorId !== $this->channel?->id) {
            return false;
        }

        if (is_string($message->id) && isset($this->sentIds[$message->id])) {
            unset($this->sentIds[$message->id]);

            return true;
        }

        $cutoff = ($this->clock)() - self::ECHO_WINDOW;
        $this->sent = array_values(array_filter($this->sent, static fn (array $line): bool => $line['at'] >= $cutoff));
        $text = trim((string) YouTubeText::said($message));

        foreach ($this->sent as $i => $line) {
            if ($line['text'] === $text) {
                // Each posted line is matched once, so the streamer saying
                // the same thing a moment later is still relayed.
                unset($this->sent[$i]);
                $this->sent = array_values($this->sent);

                return true;
            }
        }

        return false;
    }

    private function rememberChatter(string $channelId, string $name, ?string $avatar, ?string $messageId): void
    {
        $known = $this->chatters[$channelId] ?? ['name' => $name, 'avatar' => $avatar, 'messages' => []];
        unset($this->chatters[$channelId]);

        if ($messageId !== null) {
            $known['messages'][] = $messageId;
            $known['messages'] = array_slice($known['messages'], -10);
        }

        $this->chatters[$channelId] = ['name' => $name !== '' ? $name : $known['name'], 'avatar' => $avatar ?? $known['avatar'], 'messages' => $known['messages']];

        if (count($this->chatters) > self::CHATTERS) {
            unset($this->chatters[array_key_first($this->chatters)]);
        }
    }

    private function emitIncoming(Incoming $incoming): void
    {
        foreach ($this->handlers as $handler) {
            $handler($incoming);
        }
    }

    private function ownId(): string
    {
        return (string) $this->channel?->id;
    }

    // ── Announcements ──────────────────────────────────────────────────

    /**
     * Go-live and stream-end announcements for every bridged channel; see
     * {@see LiveAnnouncer}. The streams come from the watcher, so announcing
     * costs nothing it was not already spending. What was announced is kept
     * beside the store, so a restart mid-stream does not announce it again.
     */
    private function liveAnnouncer(): LiveAnnouncer
    {
        $store = $this->bot->getStore();

        return new LiveAnnouncer(
            $this->bot->getLoop(),
            $this->bot->getLogger(),
            new JsonFile(dirname($store->path()) . '/youtube-live.json', $store->filesystem()),
            fn (): array => $this->joined(),
            fn (array $channels): PromiseInterface => resolve($this->liveStreams($channels)),
            fn (string $channelId, string $text, array $stream) => $this->announce($channelId, $text, $stream),
            static fn (array $stream): string => 'https://www.youtube.com/watch?v=' . rawurlencode((string) $stream['id']),
            self::NAME,
        );
    }

    /**
     * What the watcher knows to be live, as the announcer wants it. Unlisted
     * and private broadcasts are left out unless YOUTUBE_ANNOUNCE_UNLISTED
     * says otherwise: posting their link in a Discord channel would publish
     * them.
     *
     * @param list<string> $channels
     *
     * @return list<array{id: string, login: string, name: string, title: string, game: string, started_at: int}>
     */
    public function liveStreams(array $channels): array
    {
        $own = $this->channel;

        if ($own === null || ! in_array($own->id, $channels, true) || $this->watcher === null) {
            return [];
        }

        $streams = [];

        foreach ($this->watcher->live() as $broadcast) {
            $privacy = $broadcast->status?->privacyStatus;

            if (! $this->config->announceUnlisted && $privacy !== null && $privacy !== LiveBroadcastStatus::PRIVACY_STATUS_PUBLIC) {
                continue;
            }

            $started = $broadcast->snippet?->actualStartTime;

            $streams[] = [
                'id' => (string) $broadcast->id,
                'login' => (string) $own->id,
                'name' => (string) ($own->snippet?->title ?? ''),
                'title' => (string) ($broadcast->snippet?->title ?? ''),
                'game' => '',
                'started_at' => $started instanceof \DateTimeInterface ? $started->getTimestamp() : (int) ($this->clock)(),
            ];
        }

        return $streams;
    }

    /**
     * Posts to every Discord channel bridged to the channel, as the channel:
     * its name and avatar, like a relayed line. The relay never sends a
     * webhook's messages back out, so the announcement stays in Discord.
     *
     * @param array<string, mixed> $stream
     */
    private function announce(string $channelId, string $text, array $stream): void
    {
        $avatar = $this->channel?->snippet?->thumbnails?->default?->url;

        foreach ($this->bot->getStore()->links(self::NAME)->discordFor($channelId) as $discordChannelId) {
            $channel = $this->bot->getChannel($discordChannelId);

            if ($channel === null) {
                continue;
            }

            $this->bot->delivery()->deliver(
                $channel,
                (string) $stream['name'],
                $text,
                is_string($avatar) ? $avatar : null,
                ' (' . self::NAME . ')',
            )->then(null, fn (\Throwable $e) => $this->bot->getLogger()->warning(sprintf(
                '[youtube] could not announce the stream in %s: %s',
                $discordChannelId,
                $e->getMessage(),
            )));
        }
    }

    // ── The owner ──────────────────────────────────────────────────────

    /** Tells the owner once a day that the quota ran out, and until when. */
    private function quotaSpent(CarbonImmutable $resets): void
    {
        $day = $this->getYouTube()->getMeter()->day();

        if ($this->quotaToldFor === $day) {
            return;
        }

        $this->quotaToldFor = $day;
        $meter = $this->getYouTube()->getMeter();

        $this->bot->getLogger()->warning('[youtube] the quota is spent; chat is paused until ' . $resets->toIso8601String());
        $this->bot->notifyOwner(sprintf(
            "⏸️ **YouTube chat is paused** until <t:%d:t> (<t:%d:R>), when Google's daily quota resets.\n"
            . 'Today used %d of %d units%s.',
            $resets->getTimestamp(),
            $resets->getTimestamp(),
            $meter->used(),
            $meter->limit(),
            $meter->reserve() > 0 ? sprintf('; %d are kept back for your commands', $meter->reserve()) : '',
        ));
    }

    /** Says once that Google will end the sign-in: the consent screen is in Testing. */
    private function grantExpires(int $seconds): void
    {
        if ($this->grantWarned) {
            return;
        }

        $this->grantWarned = true;
        $this->bot->notifyOwner(sprintf(
            "⚠️ **Google will sign YouTube out in %d days.** The app's OAuth consent screen in Google Cloud is in *Testing*, and Google ends every Testing sign-in after seven days.\n"
            . 'Publish the app on its OAuth consent screen page to keep the bot signed in.',
            max(1, intdiv($seconds, 86400)),
        ));
    }

    /**
     * Shows the sign-in code: in the log, always, and by DM to the owner when
     * one is set. A bot that needs signing in and only says so in a log file
     * stays signed out until somebody reads it.
     *
     * @param array<string, mixed> $device
     */
    private function promptForDeviceCode(array $device): void
    {
        $uri = (string) ($device['verification_uri'] ?? 'https://www.google.com/device');
        $code = (string) ($device['user_code'] ?? '?');
        $expires = (int) ($device['expires_in'] ?? 1800);

        $this->bot->getLogger()->warning(sprintf(
            "[youtube] SIGN-IN NEEDED - YouTube chat is off until this is approved.\n"
            . "        Open %s and enter %s, signed in as the account that streams\n"
            . '        (expires in %ds)',
            $uri,
            $code,
            $expires,
        ));

        $this->bot->notifyOwner(sprintf(
            "⚠️ **YouTube needs you to sign in.**\nOpen <%s> and enter `%s`, with the Google account that owns the channel. The code expires in %d minutes.",
            $uri,
            $code,
            (int) round($expires / 60),
        ));
    }
}
