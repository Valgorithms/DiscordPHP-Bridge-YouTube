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

namespace Bridge\YouTube\Actions;

use Bridge\Capability\ProvidesActions;
use Bridge\Command\Access;
use Bridge\Command\Action;
use Bridge\Command\ActionError;
use Bridge\Command\Arguments;
use Bridge\Command\Context;
use Bridge\Command\Slash;
use Bridge\Command\SlashOption;
use Bridge\Message\Incoming;
use React\Promise\PromiseInterface;
use YouTube\Parts\LiveChatBan;
use YouTube\Parts\LiveChatBanSnippet;

use function React\Promise\all;

/**
 * Moderating the live chat from anywhere: Discord, or any chat the bot is in.
 *
 * The bot is signed in as the channel, so it moderates with the channel's
 * authority. Each of these costs 50 units of quota, and `delete` costs 50 for
 * every message it deletes.
 *
 * People are named as chat shows them, `@handle` or not, or by channel id. A
 * name only works for someone who has said something since the bot started:
 * the bot learns who is who from the chat itself, at no cost.
 *
 * @author Valithor Obsidion <valithor@valgorithms.com>
 */
final class ModerationActions implements ProvidesActions
{
    use UsesYouTube;

    /** The longest `delete` goes back, in messages: each one costs 50 units. */
    public const MAX_DELETE = 5;

    /** How long a timeout lasts when nobody says. */
    public const DEFAULT_TIMEOUT = 300;

    public function actions(): array
    {
        $user = new SlashOption('user', 'Their name as chat shows it, or their channel id.', SlashOption::STRING, true);

        return [
            new Action('youtube', 'ban', $this->explained($this->ban(...)), 'Ban someone from the live chat', '<user>', access: Access::Moderator, group: 'mod', slash: new Slash([$user])),
            new Action('youtube', 'timeout', $this->explained($this->timeout(...)), 'Time someone out of the live chat', '<user> [seconds]', access: Access::Moderator, aliases: ['to'], group: 'mod', slash: new Slash([$user, new SlashOption('seconds', 'How long, in seconds. Defaults to 300.', SlashOption::INTEGER)])),
            new Action('youtube', 'unban', $this->explained($this->unban(...)), 'Lift a ban this bot made', '<user>', access: Access::Moderator, group: 'mod', slash: new Slash([$user])),
            new Action('youtube', 'delete', $this->explained($this->delete(...)), "Delete someone's latest messages from the live chat", '<user> [count]', access: Access::Moderator, group: 'mod', slash: new Slash([$user, new SlashOption('count', 'How many of their latest messages, up to 5. Defaults to 1.', SlashOption::INTEGER)])),
        ];
    }

    /** @return PromiseInterface<string> */
    private function ban(Context $context, Arguments $arguments): PromiseInterface
    {
        return $this->banFor($context, $arguments, LiveChatBanSnippet::TYPE_PERMANENT, null);
    }

    /** @return PromiseInterface<string> */
    private function timeout(Context $context, Arguments $arguments): PromiseInterface
    {
        $seconds = $arguments->named('seconds') ?? $arguments->get(1) ?? (string) self::DEFAULT_TIMEOUT;

        if (preg_match('/^\d+$/', $seconds) !== 1 || (int) $seconds < 1) {
            throw new ActionError('a timeout is a number of seconds: `timeout <user> [seconds]`.');
        }

        return $this->banFor($context, $arguments, LiveChatBanSnippet::TYPE_TEMPORARY, (int) $seconds);
    }

    /** @return PromiseInterface<string> */
    private function banFor(Context $context, Arguments $arguments, string $type, ?int $seconds): PromiseInterface
    {
        $connector = $this->youtube($context);
        $chat = $this->liveChat($context);
        $who = $this->who($context, $arguments);

        $details = ['liveChatId' => $chat, 'type' => $type, 'bannedUserDetails' => ['channelId' => $who['channel_id']]];
        if ($seconds !== null) {
            $details['banDurationSeconds'] = (string) $seconds;
        }

        return $connector->getYouTube()->liveChatBans->insert('snippet', ['snippet' => $details])->then(
            function (LiveChatBan $ban) use ($connector, $who, $seconds): string {
                if (is_string($ban->id)) {
                    $connector->rememberBan($who['channel_id'], $ban->id);
                }

                // What they said goes from Discord too. YouTube also reports
                // the ban in chat, which would do the same; doing it here does
                // not wait on that.
                $connector->deleted(Incoming::purge((string) $connector->getChannel()?->id, $who['channel_id'], $who['name']));

                return $seconds === null
                    ? sprintf('banned %s.', $who['name'])
                    : sprintf('timed %s out for %ds.', $who['name'], $seconds);
            },
        );
    }

    /**
     * Lifts a ban. YouTube lifts a ban by the ban's own id, which it gives
     * only to whoever made it, so this works for bans this bot made since it
     * started. Others are lifted in YouTube Studio.
     *
     * @return PromiseInterface<string>
     */
    private function unban(Context $context, Arguments $arguments): PromiseInterface
    {
        $connector = $this->youtube($context);
        $who = $this->who($context, $arguments);
        $banId = $connector->banOf($who['channel_id']);

        if ($banId === null) {
            throw new ActionError(sprintf(
                'I can only lift bans I made since I started, and I did not ban %s. Lift it in YouTube Studio.',
                $who['name'],
            ));
        }

        return $connector->getYouTube()->liveChatBans->delete($banId)->then(
            function () use ($connector, $who): string {
                $connector->forgetBan($who['channel_id']);

                return sprintf('unbanned %s.', $who['name']);
            },
        );
    }

    /** @return PromiseInterface<string> */
    private function delete(Context $context, Arguments $arguments): PromiseInterface
    {
        $connector = $this->youtube($context);
        $this->liveChat($context);
        $who = $this->who($context, $arguments);

        $count = $arguments->named('count') ?? $arguments->get(1) ?? '1';

        if (preg_match('/^\d+$/', $count) !== 1 || (int) $count < 1 || (int) $count > self::MAX_DELETE) {
            throw new ActionError(sprintf('delete up to %d messages at a time: `delete <user> [count]`.', self::MAX_DELETE));
        }

        $ids = array_slice($who['messages'], -(int) $count);

        if ($ids === []) {
            throw new ActionError(sprintf('%s has not said anything since I started.', $who['name']));
        }

        $youtube = $connector->getYouTube();
        $own = (string) $connector->getChannel()?->id;

        return all(array_map(
            static fn (string $id): PromiseInterface => $youtube->liveChatMessages->delete($id)->then(
                static fn () => $connector->deleted(Incoming::deletion($own, $id)),
            ),
            $ids,
        ))->then(static fn (array $done): string => sprintf(
            'deleted %d %s from %s.',
            count($done),
            count($done) === 1 ? 'message' : 'messages',
            $who['name'],
        ));
    }

    /**
     * Who a command names: somebody recently in chat, or a channel id.
     *
     * @return array{channel_id: string, name: string, messages: list<string>}
     */
    private function who(Context $context, Arguments $arguments): array
    {
        $query = trim((string) ($arguments->named('user') ?? $arguments->get(0) ?? ''));

        if ($query === '') {
            throw new ActionError('name someone: their name as chat shows it, or their channel id.');
        }

        $chatter = $this->youtube($context)->findChatter($query);

        if ($chatter !== null) {
            return $chatter;
        }

        if (preg_match('/^UC[A-Za-z0-9_-]{22}$/', $query) === 1) {
            return ['channel_id' => $query, 'name' => $query, 'messages' => []];
        }

        throw new ActionError(sprintf(
            'I have not seen `%s` in chat since I started. Use the name chat shows for them, or their channel id.',
            mb_substr($query, 0, 60),
        ));
    }
}
