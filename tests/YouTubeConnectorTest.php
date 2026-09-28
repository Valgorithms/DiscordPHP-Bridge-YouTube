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

namespace Bridge\YouTube\Tests;

use Bridge\Bot;
use Bridge\Command\Access;
use Bridge\Command\Action;
use Bridge\Command\ActionError;
use Bridge\Command\Arguments;
use Bridge\Command\Context;
use Bridge\Command\Surface;
use Bridge\Config;
use Bridge\Environment;
use Bridge\Message\Incoming;
use Bridge\Message\Outgoing;
use Bridge\Room;
use Bridge\Store;
use Bridge\Support\ConnectionAlerts;
use Bridge\Support\Filesystem;
use Bridge\YouTube\YouTubeConfig;
use Bridge\YouTube\YouTubeConnector;
use Discord\Builders\MessageBuilder;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use React\EventLoop\StreamSelectLoop;
use React\Promise\PromiseInterface;
use YouTube\Http\Exceptions\QuotaExceededException;
use YouTube\LiveChat\ChatReader;
use YouTube\Parts\LiveChatMessage;
use YouTube\YouTube;

use function React\Promise\resolve;

/**
 * The connector against a real {@see Bot} that never connects, and a real
 * YouTubePHP client whose HTTP is scripted: YouTube chat in, deletions and
 * bans, what may be posted back, the quota, and the owner's DMs.
 */
final class YouTubeConnectorTest extends TestCase
{
    private const OWN = 'UCownchannel0000000000aa';

    private const GUILD = '111111111111';

    private const CHANNEL = '222222222222';

    private string $dir;

    private Bot $bot;

    private ScriptedDriver $driver;

    private FastLoop $loop;

    private YouTube $youtube;

    private YouTubeConnector $connector;

    private float $now = 1_790_000_000.0;

    /** @var list<Incoming> */
    private array $incoming = [];

    /** @var list<array{level: string, message: string}> */
    private array $log = [];

    /** @var list<array{sent: string, edits: list<string>}> */
    private array $dms = [];

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/bridge-youtube-' . bin2hex(random_bytes(6));
        @mkdir($this->dir, 0o777, true);

        $logger = new class ($this->log) extends AbstractLogger {
            public function __construct(private array &$log)
            {
            }

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->log[] = ['level' => (string) $level, 'message' => (string) $message];
            }
        };

        $path = $this->dir . '/bridges.json';
        $this->bot = new Bot(
            Config::fromEnvironment(Environment::fromArray(['DISCORD_TOKEN' => 'test.token.here']), $path),
            new Store($path, Filesystem::blocking()),
            // Nothing here resolves a name; see the core's BuildsBot.
            ['logger' => $logger, 'loop' => new StreamSelectLoop(), 'dnsConfig' => '8.8.8.8', 'socket_options' => ['dns' => '8.8.8.8']],
        );

        $this->driver = new ScriptedDriver();
        $this->loop = new FastLoop(new StreamSelectLoop(), fire: false);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/{,.}*', GLOB_BRACE) ?: [] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        @rmdir($this->dir);
    }

    /**
     * A started connector, signed in as the channel.
     *
     * @param array<string, string> $settings
     * @param array<string, mixed>  $quota
     */
    private function started(array $settings = [], array $quota = []): YouTubeConnector
    {
        $this->youtube = new YouTube([
            'token' => 'token',
            'driver' => $this->driver,
            'loop' => $this->loop,
            'clock' => fn (): float => $this->now,
            'quota' => $quota,
            'logger' => $this->bot->getLogger(),
        ]);

        $this->connector = new YouTubeConnector(
            YouTubeConfig::fromEnvironment(Environment::fromArray($settings + ['YOUTUBE_CLIENT_ID' => 'id', 'YOUTUBE_CLIENT_SECRET' => 'secret'])),
            $this->youtube,
            fn (): float => $this->now,
        );
        $this->bot->addConnector($this->connector);
        $this->connector->onIncoming(function (Incoming $incoming): void {
            $this->incoming[] = $incoming;
        });

        $this->driver->respond(200, ['items' => [[
            'kind' => 'youtube#channel',
            'id' => self::OWN,
            'snippet' => [
                'title' => 'The Channel',
                'customUrl' => '@TheChannel',
                'thumbnails' => ['default' => ['url' => 'https://yt3.example/own.jpg']],
            ],
        ]]]);

        $started = null;
        $this->connector->start()->then(static function (bool $ok) use (&$started): void {
            $started = $ok;
        });
        $this->assertTrue($started, 'started');

        return $this->connector;
    }

    /**
     * Bridges the channel, which starts watching it; the first check finds
     * these broadcasts live.
     *
     * @param list<array<string, mixed>> $live
     */
    private function bridge(array $live = []): void
    {
        $this->bot->getStore()->link(YouTubeConnector::NAME, self::GUILD, self::CHANNEL, self::OWN);
        $this->driver->respond(200, ['items' => $live]);

        $this->connector->sync($this->bot->getStore()->links(YouTubeConnector::NAME));
    }

    /** @return array<string, mixed> */
    private static function broadcast(string $id = 'b1', string $privacy = 'public'): array
    {
        return [
            'kind' => 'youtube#liveBroadcast',
            'id' => $id,
            'snippet' => ['title' => 'Building a bridge', 'liveChatId' => 'chat-' . $id, 'actualStartTime' => '2026-09-21T19:00:00Z'],
            'status' => ['lifeCycleStatus' => 'live', 'privacyStatus' => $privacy],
        ];
    }

    private function reader(string $chat = 'chat-b1'): ChatReader
    {
        $reader = $this->connector->chatReader($chat);
        $this->assertInstanceOf(ChatReader::class, $reader, 'reading ' . $chat);

        return $reader;
    }

    /**
     * One chat item, as the reader hands it over.
     *
     * @param array<string, mixed> $snippet
     * @param array<string, mixed> $author
     */
    private static function item(string $id, string $text = 'hello', array $snippet = [], array $author = []): LiveChatMessage
    {
        return new LiveChatMessage(null, [
            'id' => $id,
            'snippet' => $snippet + ['type' => 'textMessageEvent', 'displayMessage' => $text, 'textMessageDetails' => ['messageText' => $text]],
            'authorDetails' => $author + ['channelId' => 'UCviewer', 'displayName' => '@viewer', 'profileImageUrl' => 'https://yt3.example/viewer.jpg'],
        ]);
    }

    public function testOnlyTheSignedInChannelCanBeBridged(): void
    {
        $this->started();

        foreach (['me', self::OWN, '@thechannel'] as $target) {
            $room = null;
            $this->connector->resolve($target)->then(static function (?Room $found) use (&$room): void {
                $room = $found;
            });
            $this->assertSame(self::OWN, $room?->id, $target);
            $this->assertSame('https://www.youtube.com/@TheChannel', $room->url);
        }

        $refusal = null;
        $this->connector->resolve('@someoneelse')->then(null, static function (\Throwable $e) use (&$refusal): void {
            $refusal = $e;
        });
        $this->assertInstanceOf(ActionError::class, $refusal, 'shown to whoever asked, not logged as a failure');
        $this->assertStringContainsString('The Channel', $refusal->getMessage());
    }

    public function testTheChannelIsWatchedOnlyWhileBridged(): void
    {
        $this->started();
        $this->assertSame([], $this->connector->joined());

        $this->bridge();
        $this->assertSame([self::OWN], $this->connector->joined());
        $this->assertSame('youtube/v3/liveBroadcasts', ScriptedDriver::path($this->driver->last()));

        $this->bot->getStore()->unlink(YouTubeConnector::NAME, self::GUILD, self::CHANNEL);
        $this->assertSame(['join' => [], 'part' => [self::OWN]], $this->connector->sync($this->bot->getStore()->links(YouTubeConnector::NAME)));
        $this->assertSame([], $this->loop->pending(), 'no more checks, and no more quota spent');
    }

    public function testChatIsRelayedUnderTheSendersNameAndAvatar(): void
    {
        $this->started();
        $this->bridge([self::broadcast()]);

        $this->reader()->emit('chat.message', [self::item('m1', 'hello from YouTube')]);

        $this->assertCount(1, $this->incoming);
        $incoming = $this->incoming[0];
        $this->assertSame(self::OWN, $incoming->target);
        $this->assertSame('@viewer', $incoming->author);
        $this->assertSame('UCviewer', $incoming->authorId);
        $this->assertSame('hello from YouTube', $incoming->text);
        $this->assertSame('m1', $incoming->id);

        $avatar = null;
        $this->connector->avatarFor($incoming)->then(static function (?string $url) use (&$avatar): void {
            $avatar = $url;
        });
        $this->assertSame('https://yt3.example/viewer.jpg', $avatar, 'from the message itself, at no cost');
    }

    public function testASuperChatIsRelayedAsOne(): void
    {
        $this->started();
        $this->bridge([self::broadcast()]);

        $this->reader()->emit('chat.superchat', [self::item('m1', '', [
            'type' => 'superChatEvent',
            'superChatDetails' => ['amountDisplayString' => '$5.00', 'userComment' => 'great stream'],
        ])]);

        $this->assertSame('💰 $5.00 Super Chat: great stream', $this->incoming[0]->text);
    }

    public function testABanOnYouTubeTakesTheirMessagesOutOfDiscord(): void
    {
        $this->started();
        $this->bridge([self::broadcast()]);

        $this->reader()->emit('chat.banned', [self::item('ban1', '', [
            'type' => 'userBannedEvent',
            'userBannedDetails' => ['banType' => 'permanent', 'bannedUserDetails' => ['channelId' => 'UCspammer', 'displayName' => '@spammer']],
        ], ['channelId' => 'UCmoderator'])]);

        $this->assertCount(1, $this->incoming);
        $this->assertTrue($this->incoming[0]->deleted);
        $this->assertNull($this->incoming[0]->id, 'everything recent of theirs, not one message');
        $this->assertSame('UCspammer', $this->incoming[0]->authorId);
    }

    public function testATombstoneTakesThatMessageOutOfDiscord(): void
    {
        $this->started();
        $this->bridge([self::broadcast()]);

        $this->reader()->emit('chat.deleted', ['m1', self::item('m1')]);

        $this->assertTrue($this->incoming[0]->deleted);
        $this->assertSame('m1', $this->incoming[0]->id);
    }

    public function testNothingFromDiscordIsPostedToYouTube(): void
    {
        $this->started();
        $this->bridge([self::broadcast()]);
        $requests = count($this->driver->requests);

        $sent = 'unset';
        $this->connector->relay(self::OWN, new Outgoing('someone (discord)', 'hello YouTube'))->then(static function (?string $id) use (&$sent): void {
            $sent = $id;
        });

        $this->assertNull($sent);
        $this->assertCount($requests, $this->driver->requests, 'every post costs 50 units');
    }

    public function testAReplyIsPostedAndDoesNotComeBackAsChat(): void
    {
        $this->started();
        $this->bridge([self::broadcast()]);
        $this->driver->respond(200, ['id' => 'bot1', 'snippet' => ['type' => 'textMessageEvent']]);

        $this->connector->send(self::OWN, "pong\nand more");

        $request = $this->driver->last();
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('youtube/v3/liveChat/messages', ScriptedDriver::path($request));
        $this->assertSame(
            ['snippet' => ['liveChatId' => 'chat-b1', 'type' => 'textMessageEvent', 'textMessageDetails' => ['messageText' => 'pong and more']]],
            json_decode($request->getContent(), true),
        );

        $own = ['channelId' => self::OWN, 'displayName' => '@TheChannel', 'isChatOwner' => true];
        $this->reader()->emit('chat.message', [self::item('bot1', 'pong and more', author: $own)]);
        $this->reader()->emit('chat.message', [self::item('m2', 'the streamer typing', author: $own)]);

        $this->assertSame(['the streamer typing'], array_map(static fn (Incoming $i): ?string => $i->text, $this->incoming));
    }

    public function testNothingIsPostedWhenNotLive(): void
    {
        $this->started();
        $this->bridge();

        $error = null;
        $this->connector->send(self::OWN, 'hello')->then(null, static function (\Throwable $e) use (&$error): void {
            $error = $e;
        });

        $this->assertInstanceOf(ActionError::class, $error);
        $this->assertStringContainsString('not live', $error->getMessage());
    }

    public function testAReplyLeavesTheReserveForWhatTheOwnerDoes(): void
    {
        $this->started(quota: ['daily' => 200, 'reserve' => 160]);
        $this->bridge([self::broadcast()]);

        $error = null;
        $this->connector->send(self::OWN, 'an answer')->then(null, static function (\Throwable $e) use (&$error): void {
            $error = $e;
        });
        $this->assertInstanceOf(QuotaExceededException::class, $error, 'a reply may not spend the reserve');

        $this->driver->respond(200, ['id' => 'bot1']);
        $this->connector->send(self::OWN, 'said on purpose', ['deliberate' => true]);
        $this->assertSame('POST', $this->driver->last()->getMethod(), 'the owner may');
    }

    public function testTheChatEndingIsTheBroadcastEnding(): void
    {
        $this->started();
        $this->bridge([self::broadcast()]);

        $this->reader()->emit('chat.ended', ['the chat ended']);

        $this->assertNull($this->connector->chatReader('chat-b1'));
        $this->assertSame([], $this->connector->getWatcher()?->live(), 'without waiting for two more checks');
    }

    public function testChatThatStaysDownIsDmedWithAButton(): void
    {
        $this->started();
        $alerts = new ConnectionAlerts($this->bot, function (MessageBuilder $message): PromiseInterface {
            $this->dms[] = ['sent' => json_encode($message), 'edits' => []];
            $index = count($this->dms) - 1;

            return resolve(new class ($this, $index) {
                public function __construct(private readonly YouTubeConnectorTest $test, private readonly int $index)
                {
                }

                public function edit(MessageBuilder $message): PromiseInterface
                {
                    $this->test->edited($this->index, $message);

                    return resolve($this);
                }
            });
        });
        (new \ReflectionProperty(YouTubeConnector::class, 'alerts'))->setValue($this->connector, $alerts);
        $this->bridge([self::broadcast()]);
        $reader = $this->reader();

        $reader->emit('chat.disconnected', [0, 'Network is unreachable']);
        $reader->emit('chat.reconnect_failed', [10]);
        $reader->emit('chat.reconnect_failed', [10]);

        $this->assertCount(1, $this->dms, 'one DM an outage');
        $this->assertStringContainsString('YouTube is disconnected', $this->dms[0]['sent']);
        $this->assertStringContainsString('after 10 attempts', $this->dms[0]['sent']);
        $this->assertStringContainsString('every 5 minutes', $this->dms[0]['sent']);
        $this->assertStringContainsString('bridge:reconnect:youtube', $this->dms[0]['sent']);

        $reader->emit('chat.connected');

        $this->assertStringContainsString('YouTube is back', $this->dms[0]['edits'][0] ?? '');
    }

    /** @internal */
    public function edited(int $index, MessageBuilder $message): void
    {
        $this->dms[$index]['edits'][] = (string) json_encode($message);
    }

    public function testReconnectingWithNothingLiveAsksYouTubeAgain(): void
    {
        $this->started();
        $this->bridge();
        $this->driver->respond(200, ['items' => [self::broadcast()]]);

        $this->connector->reconnect();

        $this->assertInstanceOf(ChatReader::class, $this->connector->chatReader('chat-b1'), 'and finds the stream');
    }

    public function testUnlistedStreamsAreNotAnnounced(): void
    {
        $this->started();
        $this->bridge([self::broadcast('b1', 'unlisted')]);

        $this->assertSame([], $this->connector->liveStreams([self::OWN]), 'its link in Discord would publish it');
        $this->assertInstanceOf(ChatReader::class, $this->connector->chatReader('chat-b1'), 'its chat is still relayed');
    }

    public function testAPublicStreamIsAnnouncedWithItsTitle(): void
    {
        $this->started();
        $this->bridge([self::broadcast('b1')]);

        $this->assertSame([[
            'id' => 'b1',
            'login' => self::OWN,
            'name' => 'The Channel',
            'title' => 'Building a bridge',
            'game' => '',
            'started_at' => (new \DateTimeImmutable('2026-09-21T19:00:00Z'))->getTimestamp(),
        ]], $this->connector->liveStreams([self::OWN]));
    }

    public function testAViewersCommandIsNotAnsweredByDefault(): void
    {
        $this->started();
        $this->bridge([self::broadcast()]);
        $requests = count($this->driver->requests);

        $this->reader()->emit('chat.message', [self::item('m1', '!bridge about')]);

        $this->assertCount($requests, $this->driver->requests, 'an answer is a post, and a post costs 50 units');
    }

    public function testAModeratorsCommandIsAnswered(): void
    {
        $this->started();
        $this->bridge([self::broadcast()]);
        $this->driver->respond(200, ['id' => 'bot1']);

        $this->reader()->emit('chat.message', [self::item('m1', '!bridge about', author: ['isChatModerator' => true])]);

        $this->assertSame('POST', $this->driver->last()->getMethod());
        $posted = json_decode($this->driver->last()->getContent(), true)['snippet']['textMessageDetails']['messageText'];
        $this->assertLessThanOrEqual(200, mb_strlen($posted));
    }

    public function testTheQuotaRunningOutIsToldOnceADay(): void
    {
        $this->started();
        $this->bridge([self::broadcast()]);
        $resets = $this->youtube->getMeter()->resetsAt();

        $this->youtube->emit('quota.exhausted', ['default', $resets, $this->youtube]);
        $this->reader()->emit('chat.paused', [$resets]);
        $this->youtube->emit('quota.exhausted', ['search', $resets, $this->youtube]);

        $told = array_filter($this->log, static fn (array $line): bool => str_contains($line['message'], 'the quota is spent'));
        $this->assertCount(1, $told);
    }

    /**
     * Runs one of the connector's commands as if typed in Discord.
     *
     * @param list<string> $arguments
     */
    private function command(string $name, array $arguments = [], Access $access = Access::Operator): mixed
    {
        foreach ($this->connector->actions() as $action) {
            if ($action instanceof Action && $action->name === $name) {
                $context = new Context($this->bot, Surface::discord(), $access, 'Owner', '1000', connector: YouTubeConnector::NAME, target: self::OWN);
                $result = null;
                $failure = null;

                $outcome = $action->run($context, Arguments::fromParts($arguments));

                if ($outcome instanceof PromiseInterface) {
                    $outcome->then(
                        static function (mixed $value) use (&$result): void {
                            $result = $value;
                        },
                        static function (\Throwable $e) use (&$failure): void {
                            $failure = $e;
                        },
                    );

                    if ($failure !== null) {
                        throw $failure;
                    }

                    return $result;
                }

                return $outcome;
            }
        }

        $this->fail("no action called {$name}");
    }

    public function testTheOwnerCanSpeakAsTheChannel(): void
    {
        $this->started(quota: ['daily' => 200, 'reserve' => 160]);
        $this->bridge([self::broadcast()]);
        $this->driver->respond(200, ['id' => 'bot1']);

        $this->assertSame('posted.', $this->command('say', ['going live in five']));
        $this->assertSame('going live in five', json_decode($this->driver->last()->getContent(), true)['snippet']['textMessageDetails']['messageText']);
    }

    public function testTheQuotaIsReported(): void
    {
        $this->started(quota: ['daily' => 10000, 'reserve' => 500]);
        $this->youtube->getMeter()->spend('liveChatMessages.list', 1234);

        $said = $this->command('quota');

        // And one more for loading the channel at sign-in.
        $this->assertStringContainsString('1,235 of 10,000 units used today, 500 kept back for commands', $said);
        $this->assertStringContainsString('<t:', $said);
    }

    public function testABanNamesThePersonAsChatShowsThem(): void
    {
        $this->started();
        $this->bridge([self::broadcast()]);
        $this->reader()->emit('chat.message', [self::item('m1', 'spam')]);
        $this->incoming = [];
        $this->driver->respond(200, ['id' => 'ban1', 'snippet' => ['type' => 'permanent']]);

        $this->assertSame('banned @viewer.', $this->command('ban', ['viewer'], Access::Moderator));

        $this->assertSame(
            ['snippet' => ['liveChatId' => 'chat-b1', 'type' => 'permanent', 'bannedUserDetails' => ['channelId' => 'UCviewer']]],
            json_decode($this->driver->last()->getContent(), true),
        );
        $this->assertTrue($this->incoming[0]->deleted, 'and what they said leaves Discord');
        $this->assertSame('UCviewer', $this->incoming[0]->authorId);
    }

    public function testATimeoutSaysHowLong(): void
    {
        $this->started();
        $this->bridge([self::broadcast()]);
        $this->reader()->emit('chat.message', [self::item('m1')]);
        $this->driver->respond(200, ['id' => 'ban1']);

        $this->assertSame('timed @viewer out for 60s.', $this->command('timeout', ['@viewer', '60'], Access::Moderator));
        $this->assertSame('60', json_decode($this->driver->last()->getContent(), true)['snippet']['banDurationSeconds']);
    }

    public function testOnlyABanThisBotMadeCanBeLifted(): void
    {
        $this->started();
        $this->bridge([self::broadcast()]);
        $this->reader()->emit('chat.message', [self::item('m1')]);

        try {
            $this->command('unban', ['viewer'], Access::Moderator);
            $this->fail('expected a refusal');
        } catch (ActionError $e) {
            $this->assertStringContainsString('YouTube Studio', $e->getMessage());
        }

        $this->driver->respond(200, ['id' => 'ban1']);
        $this->command('ban', ['viewer'], Access::Moderator);
        $this->driver->respond(204);

        $this->assertSame('unbanned @viewer.', $this->command('unban', ['viewer'], Access::Moderator));
        $this->assertSame('DELETE', $this->driver->last()->getMethod());
        $this->assertContains(['id', 'ban1'], ScriptedDriver::queryPairs($this->driver->last()));
    }

    public function testDeleteTakesTheirLatestMessages(): void
    {
        $this->started();
        $this->bridge([self::broadcast()]);
        foreach (['m1', 'm2', 'm3'] as $id) {
            $this->reader()->emit('chat.message', [self::item($id)]);
        }
        $this->incoming = [];
        $this->driver->respond(204)->respond(204);

        $this->assertSame('deleted 2 messages from @viewer.', $this->command('delete', ['viewer', '2'], Access::Moderator));

        $this->assertSame(['m2', 'm3'], array_map(static fn (Incoming $i): ?string => $i->id, $this->incoming));
    }

    public function testSomebodyNeverSeenCannotBeNamed(): void
    {
        $this->started();
        $this->bridge([self::broadcast()]);

        $this->expectException(ActionError::class);
        $this->expectExceptionMessage('I have not seen `nobody`');

        $this->command('ban', ['nobody'], Access::Moderator);
    }
}
