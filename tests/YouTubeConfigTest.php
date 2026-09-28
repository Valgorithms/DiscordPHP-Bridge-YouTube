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

use Bridge\Environment;
use Bridge\YouTube\YouTubeConfig;
use PHPUnit\Framework\TestCase;

/**
 * The settings, from the environment: what is needed, what has a sensible
 * default, and what is refused rather than guessed at.
 */
final class YouTubeConfigTest extends TestCase
{
    private const CLIENT = ['YOUTUBE_CLIENT_ID' => 'id.apps.googleusercontent.com', 'YOUTUBE_CLIENT_SECRET' => 'secret'];

    public function testTheDefaults(): void
    {
        $config = YouTubeConfig::fromEnvironment(Environment::fromArray(self::CLIENT));

        $this->assertSame(10000, $config->dailyQuota);
        $this->assertSame(500, $config->quotaReserve);
        $this->assertSame('!', $config->prefix);
        $this->assertSame(YouTubeConfig::COMMANDS_MODERATORS, $config->commands, 'every reply costs 50 units');
        $this->assertSame('stream', $config->chatMode);
        $this->assertFalse($config->announceUnlisted, 'an unlisted stream stays unlisted');
        $this->assertNull($config->refreshToken);
        $this->assertNull($config->ownerChannelId);
    }

    public function testEverySettingIsRead(): void
    {
        $config = YouTubeConfig::fromEnvironment(Environment::fromArray(self::CLIENT + [
            'YOUTUBE_REFRESH_TOKEN' => '1//refresh',
            'YOUTUBE_DAILY_QUOTA' => '50000',
            'YOUTUBE_QUOTA_RESERVE' => '0',
            'YOUTUBE_PREFIX' => '?',
            'YOUTUBE_COMMANDS' => 'Everyone',
            'YOUTUBE_OWNER_CHANNEL_ID' => 'UCowner',
            'YOUTUBE_CHAT_MODE' => 'poll',
            'YOUTUBE_ANNOUNCE_UNLISTED' => 'true',
        ]));

        $this->assertSame('1//refresh', $config->refreshToken);
        $this->assertSame(50000, $config->dailyQuota);
        $this->assertSame(0, $config->quotaReserve);
        $this->assertSame('?', $config->prefix);
        $this->assertSame('everyone', $config->commands);
        $this->assertSame('UCowner', $config->ownerChannelId);
        $this->assertSame('poll', $config->chatMode);
        $this->assertTrue($config->announceUnlisted);
    }

    public function testTheSecretIsRequired(): void
    {
        $this->expectException(\RuntimeException::class);

        YouTubeConfig::fromEnvironment(Environment::fromArray(['YOUTUBE_CLIENT_ID' => 'id']));
    }

    public function testAQuotaThatIsNotANumberIsRefused(): void
    {
        $this->expectExceptionMessage('YOUTUBE_DAILY_QUOTA must be a whole number');

        YouTubeConfig::fromEnvironment(Environment::fromArray(self::CLIENT + ['YOUTUBE_DAILY_QUOTA' => '10k']));
    }

    public function testAnUnknownCommandsSettingIsRefused(): void
    {
        $this->expectExceptionMessage('YOUTUBE_COMMANDS must be everyone, moderators or off');

        YouTubeConfig::fromEnvironment(Environment::fromArray(self::CLIENT + ['YOUTUBE_COMMANDS' => 'mods']));
    }

    public function testAnUnknownChatModeIsRefused(): void
    {
        $this->expectExceptionMessage('YOUTUBE_CHAT_MODE must be stream or poll');

        YouTubeConfig::fromEnvironment(Environment::fromArray(self::CLIENT + ['YOUTUBE_CHAT_MODE' => 'push']));
    }

    public function testItIsInstalledWhenThereIsAClient(): void
    {
        $this->assertTrue(YouTubeConfig::isConfigured(Environment::fromArray(self::CLIENT)));
        $this->assertFalse(YouTubeConfig::isConfigured(Environment::fromArray(['TWITCH_CLIENT_ID' => 'x'])));
    }
}
