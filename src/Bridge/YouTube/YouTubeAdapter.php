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
use Bridge\Command\Access;
use Bridge\Command\ChatDispatcher;
use Bridge\Command\ChatInvocation;
use Bridge\Command\Context;
use YouTube\Parts\LiveChatMessage;

/**
 * Runs the catalogue from YouTube chat, every connector's commands included,
 * through the core's {@see ChatDispatcher}.
 *
 * What only this class knows is where YouTube keeps rank: `isChatOwner` for the
 * channel itself, `isChatModerator` for its moderators, and the configured
 * owner's channel id for the bot's operator.
 *
 * Every reply is a post, and every post costs 50 units of quota. So by default
 * only moderators and the channel get answers (`YOUTUBE_COMMANDS=moderators`):
 * anybody else could spend the day's quota typing `!bridge help` in a loop.
 * `everyone` answers everyone, within the actions' cooldowns; `off` answers
 * nobody. A command nobody may run here is still not relayed, because the
 * relay drops command lines whoever typed them.
 *
 * @author Valithor Obsidion <valithor@valgorithms.com>
 */
final class YouTubeAdapter
{
    private readonly ChatDispatcher $dispatcher;

    public function __construct(
        private readonly YouTubeConnector $connector,
        Bot $bot,
    ) {
        $this->dispatcher = new ChatDispatcher($bot, $connector);
    }

    /** Runs a line of chat if it is a command this person may have answered, reporting whether it ran. */
    public function handle(LiveChatMessage $message): bool
    {
        $text = YouTubeText::said($message);

        if ($text === null) {
            return false;
        }

        $who = $this->invocation($message);

        if (! $this->answers($who->access)) {
            return false;
        }

        return $this->dispatcher->dispatch(
            $text,
            $who,
            fn (string $reply) => $this->connector->send($who->room, $reply)->then(
                null,
                // A reply the quota cannot pay for is dropped, not retried.
                static fn (): mixed => null,
            ),
        );
    }

    /** Who typed a message, and their rung on the one ladder. */
    public function invocation(LiveChatMessage $message): ChatInvocation
    {
        $author = $message->authorDetails;
        $channelId = (string) ($author?->channelId ?? '');
        $owner = $this->connector->getConfig()->ownerChannelId;

        return new ChatInvocation(
            room: (string) $this->connector->getChannel()?->id,
            roomId: null,
            invokerName: (string) ($author?->displayName ?: 'someone'),
            invokerId: $channelId,
            access: Context::ladder(
                $owner !== null && $channelId === $owner,
                (bool) ($author?->isChatOwner ?? false),
                (bool) ($author?->isChatModerator ?? false),
            ),
            message: $message,
        );
    }

    private function answers(Access $access): bool
    {
        return match ($this->connector->getConfig()->commands) {
            YouTubeConfig::COMMANDS_EVERYONE => true,
            YouTubeConfig::COMMANDS_OFF => false,
            default => $access !== Access::Everyone,
        };
    }
}
