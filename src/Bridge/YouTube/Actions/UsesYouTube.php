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

use Bridge\Command\ActionError;
use Bridge\Command\Arguments;
use Bridge\Command\Context;
use Bridge\YouTube\YouTubeConnector;
use React\Promise\PromiseInterface;
use YouTube\Http\Exceptions\HttpException;
use YouTube\Http\Exceptions\MissingScopeException;
use YouTube\Http\Exceptions\QuotaExceededException;

/**
 * Reaches the YouTube connector from inside an action, and turns YouTube's
 * refusals into sentences.
 *
 * The connector can be absent: it is installed only when the environment
 * carries a YouTube client, and it can fail to start while the rest of the bot
 * runs on.
 *
 * @author Valithor Obsidion <valithor@valgorithms.com>
 */
trait UsesYouTube
{
    private function youtube(Context $context): YouTubeConnector
    {
        $connector = $context->bot->connector(YouTubeConnector::NAME);

        if (! $connector instanceof YouTubeConnector || $connector->getChannel() === null) {
            throw new ActionError('the YouTube side is not connected, so that cannot be done right now.');
        }

        return $connector;
    }

    /** The live chat to act on, or why there is none. */
    private function liveChat(Context $context): string
    {
        return $this->youtube($context)->activeChatId()
            ?? throw new ActionError('the channel is not live, so there is no YouTube chat to act on.');
    }

    /**
     * Wraps a handler so YouTube's refusal reaches whoever typed the command as
     * a sentence.
     *
     * @param  callable(Context, Arguments): mixed $handler
     * @return \Closure(Context, Arguments): mixed
     */
    private function explained(callable $handler): \Closure
    {
        return static function (Context $context, Arguments $arguments) use ($handler): mixed {
            try {
                $result = $handler($context, $arguments);
            } catch (\Throwable $e) {
                throw self::explainYouTube($e);
            }

            return $result instanceof PromiseInterface
                ? $result->then(null, static fn (\Throwable $e) => throw self::explainYouTube($e))
                : $result;
        };
    }

    /**
     * What YouTube's refusal means to the person who asked.
     *
     * Google's messages for a 4xx are written for people and carry nothing
     * secret, so they are passed on. Anything else is left for the adapter to
     * log and summarise.
     */
    private static function explainYouTube(\Throwable $e): \Throwable
    {
        if ($e instanceof ActionError) {
            return $e;
        }

        if ($e instanceof QuotaExceededException) {
            $resets = $e->getResetsAt();

            return new ActionError(
                "today's YouTube quota is spent" . ($resets === null ? '' : sprintf(', until <t:%d:t>', $resets->getTimestamp())) . '.',
                0,
                $e,
            );
        }

        if ($e instanceof MissingScopeException) {
            return new ActionError('the bot signed in to YouTube without permission for that. Sign it in again.', 0, $e);
        }

        if ($e instanceof HttpException && $e->getStatus() >= 400 && $e->getStatus() < 500) {
            $said = trim((string) preg_replace('/^[\w.]+: /', '', $e->getMessage()));
            $said = trim((string) preg_replace('/\s*\(\d+( \w+)?\)$/', '', $said));

            return new ActionError('YouTube refused that' . ($said === '' ? '.' : ': ' . rtrim($said, '.') . '.'), 0, $e);
        }

        return $e;
    }
}
