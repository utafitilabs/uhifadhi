<?php

declare(strict_types=1);

/*
 * This file is part of the Uhifadhi core.
 *
 * (c) Ezekiel Mjema <https://github.com/eemjema>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Uhifadhi\Bundle\TeamBundle\Performance;

use Uhifadhi\Bundle\AtlasBundle\Model\Sparkline;
use Uhifadhi\Contracts\Performance\TopicMovement;

/**
 * ONE TOPIC ON THE OVERVIEW: its name, its headline figure, where that
 * figure moved, and the way into the topic.
 *
 * ONE FIGURE A TOPIC, and it is the topic's own first — the overview
 * does not pick among somebody else's five. Everything here arrives
 * printed, so the template writes it down and works nothing out.
 */
final readonly class TopicCard
{
    public function __construct(
        public string $key,
        public string $title,
        public string $label,
        /** The figure, printed; '' where the topic published none. */
        public string $figure,
        public string $unit = '',
        public string $delta = '',
        /** 'good', 'bad', 'flat', or '' where the figure makes no claim. */
        public string $deltaTone = '',
        /** The history as the atlas draws it under the figure; null where there is no line to draw. */
        public ?Sparkline $spark = null,
        /** What a card with no figure says instead of one. */
        public string $word = '',
        public string $caption = '',
        /** Whether a module publishes this topic, or the host does. */
        public bool $byModule = false,
        public ?string $url = null,
        /**
         * WHAT MOVED, IN THE TOPIC'S OWN WORDS — null where the topic
         * cannot write a sentence, or had nothing worth saying. The
         * figure says how much; this says what it means, which is the
         * one thing a seam cannot compute.
         */
        public ?TopicMovement $movement = null,
    ) {
    }

    public function isKnown(): bool
    {
        return '' !== $this->figure;
    }
}
