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

use Bridge\Support\MessageText;
use YouTube\Parts\LiveChatMessage;
use YouTube\Parts\LiveChatMessageSnippet as Snippet;

/**
 * What YouTube chat says, as Discord should show it, and what the bot may say
 * back.
 *
 * @author Valithor Obsidion <valithor@valgorithms.com>
 */
final class YouTubeText
{
    /** YouTube takes 200 characters in one chat message. */
    public const LIMIT = 200;

    /** What `link` accepts for "the channel the bot is signed in as". */
    public const ME = 'me';

    /**
     * A channel however somebody referred to it: its id (`UC…`, kept exactly,
     * because ids are case-sensitive), its handle (`@name`, lowercased,
     * because handles are not), `me`, or null when it is none of these.
     *
     * Accepts the id or handle alone, or in a `youtube.com/channel/…` or
     * `youtube.com/@…` link.
     */
    public static function normaliseChannel(string $input): ?string
    {
        $input = trim($input, " \t\n\r\0\x0B<>");

        if (in_array(strtolower($input), [self::ME, 'mine', 'my channel'], true)) {
            return self::ME;
        }

        if (preg_match('~^(?:https?://)?(?:www\.|m\.)?youtube\.com/(channel/[^/?#]+|@[^/?#]+)~i', $input, $match) === 1) {
            $input = str_starts_with($match[1], 'channel/') ? substr($match[1], strlen('channel/')) : rawurldecode($match[1]);
        }

        if (preg_match('/^UC[A-Za-z0-9_-]{22}$/', $input) === 1) {
            return $input;
        }

        // Handles are 3 to 30 letters, digits, underscores, hyphens and dots.
        if (preg_match('/^@?([\p{L}\p{N}_.-]{3,30})$/u', $input, $match) === 1) {
            return '@' . mb_strtolower($match[1], 'UTF-8');
        }

        return null;
    }

    /**
     * One chat item as a line for Discord, or null for items that are not
     * relayed: polls, tombstones, system notices.
     *
     * Paid and membership events are relayed as what they are, under the
     * name of whoever sent them, so a Super Chat is not mistaken for an
     * ordinary line and a new member is not mistaken for silence.
     */
    public static function render(LiveChatMessage $message): ?string
    {
        $snippet = $message->snippet;

        if ($snippet === null) {
            return null;
        }

        return match ($snippet->type) {
            Snippet::TYPE_TEXT_MESSAGE_EVENT => self::said($message),
            Snippet::TYPE_SUPER_CHAT_EVENT => self::withComment(
                '💰 ' . self::amount($snippet->superChatDetails?->amountDisplayString) . 'Super Chat',
                $snippet->superChatDetails?->userComment,
            ),
            Snippet::TYPE_SUPER_STICKER_EVENT => self::withComment(
                '💰 ' . self::amount($snippet->superStickerDetails?->amountDisplayString) . 'Super Sticker',
                $snippet->superStickerDetails?->superStickerMetadata?->altText,
            ),
            Snippet::TYPE_NEW_SPONSOR_EVENT => $snippet->newSponsorDetails?->isUpgrade
                ? '⭐ Upgraded their membership' . self::level($snippet->newSponsorDetails?->memberLevelName, ' to ')
                : '⭐ Became a member' . self::level($snippet->newSponsorDetails?->memberLevelName),
            Snippet::TYPE_MEMBER_MILESTONE_CHAT_EVENT => self::withComment(
                self::months((int) ($snippet->memberMilestoneChatDetails?->memberMonth ?? 0)),
                $snippet->memberMilestoneChatDetails?->userComment,
            ),
            Snippet::TYPE_MEMBERSHIP_GIFTING_EVENT => self::gifted(
                (int) ($snippet->membershipGiftingDetails?->giftMembershipsCount ?? 1),
                $snippet->membershipGiftingDetails?->giftMembershipsLevelName,
            ),
            Snippet::TYPE_GIFT_MEMBERSHIP_RECEIVED_EVENT => '🎁 Received a gifted membership' . self::level($snippet->giftMembershipReceivedDetails?->memberLevelName),
            Snippet::TYPE_GIFT_EVENT => self::gift($snippet->giftDetails?->giftName, (int) ($snippet->giftDetails?->comboCount ?? 1)),
            default => null,
        };
    }

    /** The words of a text message: what a command is read from. */
    public static function said(LiveChatMessage $message): ?string
    {
        $text = $message->snippet?->textMessageDetails?->messageText ?? $message->snippet?->displayMessage;

        return is_string($text) && trim($text) !== '' ? $text : null;
    }

    /** A line the bot posts into YouTube chat: one line, nothing unprintable, at most 200 characters. */
    public static function forChat(string $text): string
    {
        return MessageText::truncate(MessageText::collapseWhitespace(MessageText::sanitize($text)), self::LIMIT);
    }

    private static function amount(?string $display): string
    {
        return $display === null || trim($display) === '' ? '' : trim($display) . ' ';
    }

    private static function withComment(string $what, ?string $comment): string
    {
        $comment = trim((string) $comment);

        return $comment === '' ? $what : $what . ': ' . $comment;
    }

    private static function level(?string $name, string $joiner = ' '): string
    {
        $name = trim((string) $name);

        if ($name === '') {
            return '';
        }

        return $joiner === ' ' ? ' (' . $name . ')' : $joiner . $name;
    }

    private static function months(int $months): string
    {
        return $months > 0
            ? sprintf('⭐ Member for %d %s', $months, $months === 1 ? 'month' : 'months')
            : '⭐ Member milestone';
    }

    private static function gifted(int $count, ?string $level): string
    {
        $count = max(1, $count);

        return sprintf('🎁 Gifted %d %s', $count, $count === 1 ? 'membership' : 'memberships') . self::level($level);
    }

    private static function gift(?string $name, int $combo): string
    {
        $name = trim((string) $name);

        return '🎁 Sent ' . ($name === '' ? 'a gift' : $name) . ($combo > 1 ? ' ×' . $combo : '');
    }
}
