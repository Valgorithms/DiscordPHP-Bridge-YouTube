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
use Bridge\YouTube\YouTubeText;
use React\Promise\PromiseInterface;

/**
 * The quota, and the one deliberate way to post into YouTube chat.
 *
 * @author Valithor Obsidion <valithor@valgorithms.com>
 */
final class YouTubeActions implements ProvidesActions
{
    use UsesYouTube;

    public function actions(): array
    {
        return [
            new Action('youtube', 'quota', $this->quota(...), "How much of today's YouTube quota is left", access: Access::Moderator, slash: new Slash(ephemeral: true)),
            new Action('youtube', 'say', $this->explained($this->say(...)), 'Post in the YouTube live chat as the channel (50 quota units)', '<message>', access: Access::Operator, slash: new Slash([new SlashOption('message', 'What to post. At most 200 characters.', SlashOption::STRING, true)])),
        ];
    }

    /**
     * Today's count: the shared units, the reserve, and when it resets.
     * Answered from the meter; asking costs nothing.
     */
    private function quota(Context $context, Arguments $arguments): string
    {
        $meter = $this->youtube($context)->getYouTube()->getMeter();
        $resets = $meter->resetsAt();

        $when = $context->surface->isDiscord()
            ? sprintf('<t:%d:R>', $resets->getTimestamp())
            : 'in ' . $resets->diffForHumans(syntax: \Carbon\CarbonInterface::DIFF_ABSOLUTE, parts: 2);

        return sprintf(
            'YouTube quota: %s of %s units used today%s. Search %d of %d. It resets %s.',
            number_format($meter->used()),
            number_format($meter->limit()),
            $meter->reserve() > 0 ? sprintf(', %s kept back for commands', number_format($meter->reserve())) : '',
            $meter->used('search'),
            $meter->limit('search'),
            $when,
        );
    }

    /** Posts as the channel. The owner's alone: it speaks for the channel, and costs 50 units. */
    private function say(Context $context, Arguments $arguments): PromiseInterface
    {
        $text = trim($arguments->named('message') ?? $arguments->rest());

        if ($text === '') {
            throw new ActionError('say what? `say <message>`');
        }

        $connector = $this->youtube($context);
        $this->liveChat($context);

        $posted = YouTubeText::forChat($text);

        return $connector->send((string) $connector->getChannel()?->id, $posted, ['deliberate' => true])->then(
            static fn (): string => mb_strlen($text) > YouTubeText::LIMIT
                ? sprintf('posted, cut to %d characters.', YouTubeText::LIMIT)
                : 'posted.',
        );
    }
}
