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

use Bridge\Environment;
use YouTube\LiveChat\ChatReader;

/**
 * What the YouTube connector needs to start, read from the same environment the
 * bot itself was.
 *
 * Secrets come from the environment only, never from the JSON store. The
 * refresh token is written back to the same `.env` after a device sign-in, so
 * the sign-in happens once.
 *
 * @author Valithor Obsidion <valithor@valgorithms.com>
 */
final class YouTubeConfig
{
    /** What the bot answers to in YouTube chat when no prefix is configured. */
    public const DEFAULT_PREFIX = '!';

    /** Google's default daily allowance, in units. */
    public const DEFAULT_QUOTA = 10000;

    /** Units kept back from reading chat, for what a person asks for. */
    public const DEFAULT_RESERVE = 500;

    /** Anyone in chat may run commands. */
    public const COMMANDS_EVERYONE = 'everyone';

    /** Only moderators and the channel may run commands in chat. The default. */
    public const COMMANDS_MODERATORS = 'moderators';

    /** Commands typed in YouTube chat are not answered at all. */
    public const COMMANDS_OFF = 'off';

    private function __construct(
        public readonly string $clientId,
        public readonly string $clientSecret,
        public readonly ?string $accessToken,
        public readonly ?string $refreshToken,
        public readonly int $dailyQuota,
        public readonly int $quotaReserve,
        public readonly string $prefix,
        public readonly string $commands,
        public readonly ?string $ownerChannelId,
        public readonly string $chatMode,
        public readonly bool $announceUnlisted,
        public readonly string $envPath,
    ) {
    }

    /**
     * @throws \RuntimeException When a required value is missing, or a value is not one it can use.
     */
    public static function fromEnvironment(Environment $environment): self
    {
        $commands = strtolower($environment->or('YOUTUBE_COMMANDS', self::COMMANDS_MODERATORS));
        if (! in_array($commands, [self::COMMANDS_EVERYONE, self::COMMANDS_MODERATORS, self::COMMANDS_OFF], true)) {
            throw new \RuntimeException("YOUTUBE_COMMANDS must be everyone, moderators or off, not \"{$commands}\".");
        }

        $mode = strtolower($environment->or('YOUTUBE_CHAT_MODE', ChatReader::MODE_STREAM));
        if (! in_array($mode, [ChatReader::MODE_STREAM, ChatReader::MODE_POLL], true)) {
            throw new \RuntimeException("YOUTUBE_CHAT_MODE must be stream or poll, not \"{$mode}\".");
        }

        $owner = $environment->get('YOUTUBE_OWNER_CHANNEL_ID');

        return new self(
            clientId: $environment->require('YOUTUBE_CLIENT_ID'),
            // Google needs the secret even for a "TVs and Limited Input
            // devices" client, for the device sign-in and for every refresh.
            clientSecret: $environment->require('YOUTUBE_CLIENT_SECRET'),
            accessToken: $environment->get('YOUTUBE_ACCESS_TOKEN'),
            refreshToken: $environment->get('YOUTUBE_REFRESH_TOKEN'),
            dailyQuota: self::whole($environment, 'YOUTUBE_DAILY_QUOTA', self::DEFAULT_QUOTA),
            quotaReserve: self::whole($environment, 'YOUTUBE_QUOTA_RESERVE', self::DEFAULT_RESERVE),
            prefix: $environment->or('YOUTUBE_PREFIX', self::DEFAULT_PREFIX),
            commands: $commands,
            ownerChannelId: $owner === null || $owner === '' ? null : $owner,
            chatMode: $mode,
            announceUnlisted: in_array(strtolower($environment->or('YOUTUBE_ANNOUNCE_UNLISTED', 'false')), ['1', 'true', 'yes', 'on'], true),
            envPath: $environment->path,
        );
    }

    /** Whether the environment carries enough to install this connector at all. */
    public static function isConfigured(Environment $environment): bool
    {
        return $environment->has('YOUTUBE_CLIENT_ID');
    }

    private static function whole(Environment $environment, string $key, int $default): int
    {
        $value = $environment->get($key);

        if ($value === null || $value === '') {
            return $default;
        }

        if (preg_match('/^\d+$/', $value) !== 1) {
            throw new \RuntimeException("{$key} must be a whole number, not \"{$value}\".");
        }

        return (int) $value;
    }
}
