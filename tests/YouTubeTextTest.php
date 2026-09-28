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

use Bridge\YouTube\YouTubeText;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use YouTube\Parts\LiveChatMessage;

/**
 * How a channel is referred to, how each kind of chat item reads in Discord,
 * and what the bot may post back.
 */
final class YouTubeTextTest extends TestCase
{
    private const ID = 'UCabcdefghijklmnopqrstuV';

    /** @return iterable<string, array{0: string, 1: ?string}> */
    public static function channels(): iterable
    {
        yield 'an id, case kept' => [self::ID, self::ID];
        yield 'a channel link' => ['https://www.youtube.com/channel/' . self::ID . '/live', self::ID];
        yield 'a handle' => ['@Valithor', '@valithor'];
        yield 'a handle without its @' => ['Valithor', '@valithor'];
        yield 'a handle link' => ['https://youtube.com/@Valithor/streams', '@valithor'];
        yield 'a link Discord wrapped' => ['<https://m.youtube.com/@some_one.x>', '@some_one.x'];
        yield 'me' => ['me', 'me'];
        yield 'mine' => ['Mine', 'me'];
        yield 'too short for a handle' => ['ab', null];
        yield 'not a channel at all' => ['this is not a channel!', null];
        yield 'another site' => ['https://www.twitch.tv/valithor', null];
    }

    #[DataProvider('channels')]
    public function testAChannelIsRecognisedHoweverItIsWritten(string $input, ?string $expected): void
    {
        $this->assertSame($expected, YouTubeText::normaliseChannel($input));
    }

    /** @param array<string, mixed> $snippet */
    private static function item(string $type, array $snippet = []): LiveChatMessage
    {
        return new LiveChatMessage(null, ['id' => 'm1', 'snippet' => ['type' => $type] + $snippet]);
    }

    /** @return iterable<string, array{0: LiveChatMessage, 1: ?string}> */
    public static function items(): iterable
    {
        yield 'text' => [self::item('textMessageEvent', ['displayMessage' => 'shown', 'textMessageDetails' => ['messageText' => 'hello there']]), 'hello there'];
        yield 'super chat' => [self::item('superChatEvent', ['superChatDetails' => ['amountDisplayString' => '$5.00', 'userComment' => 'great stream']]), '💰 $5.00 Super Chat: great stream'];
        yield 'super chat, no comment' => [self::item('superChatEvent', ['superChatDetails' => ['amountDisplayString' => '£2.00']]), '💰 £2.00 Super Chat'];
        yield 'super sticker' => [self::item('superStickerEvent', ['superStickerDetails' => ['amountDisplayString' => '$1.99', 'superStickerMetadata' => ['altText' => 'A waving cat']]]), '💰 $1.99 Super Sticker: A waving cat'];
        yield 'new member' => [self::item('newSponsorEvent', ['newSponsorDetails' => ['memberLevelName' => 'Crew']]), '⭐ Became a member (Crew)'];
        yield 'upgrade' => [self::item('newSponsorEvent', ['newSponsorDetails' => ['memberLevelName' => 'Captain', 'isUpgrade' => true]]), '⭐ Upgraded their membership to Captain'];
        yield 'milestone' => [self::item('memberMilestoneChatEvent', ['memberMilestoneChatDetails' => ['memberMonth' => 12, 'userComment' => 'a year!']]), '⭐ Member for 12 months: a year!'];
        yield 'gifting' => [self::item('membershipGiftingEvent', ['membershipGiftingDetails' => ['giftMembershipsCount' => 5, 'giftMembershipsLevelName' => 'Crew']]), '🎁 Gifted 5 memberships (Crew)'];
        yield 'gift received' => [self::item('giftMembershipReceivedEvent', ['giftMembershipReceivedDetails' => ['memberLevelName' => 'Crew']]), '🎁 Received a gifted membership (Crew)'];
        yield 'gift' => [self::item('giftEvent', ['giftDetails' => ['giftName' => 'Rose', 'comboCount' => 3]]), '🎁 Sent Rose ×3'];
        yield 'a poll is not chat' => [self::item('pollEvent'), null];
        yield 'a tombstone is not chat' => [self::item('tombstone'), null];
    }

    #[DataProvider('items')]
    public function testEachKindOfItemReadsAsWhatItIs(LiveChatMessage $message, ?string $expected): void
    {
        $this->assertSame($expected, YouTubeText::render($message));
    }

    public function testAPostIsOneShortLine(): void
    {
        $this->assertSame('one two three', YouTubeText::forChat("one\ntwo\r\n\tthree\x07"));

        $long = YouTubeText::forChat(str_repeat('a', 250));
        $this->assertSame(YouTubeText::LIMIT, mb_strlen($long));
        $this->assertStringEndsWith('…', $long);
    }
}
