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

namespace Uhifadhi\Bundle\AtlasBundle\Model;

/**
 * THE FIGURE WRITTEN AT THE END OF EACH BAR — "46", "128 h".
 *
 * A BAR WITH ITS NUMBER ON IT IS READ WITHOUT A HOVER, which is what a
 * ranking is for. Chart.js core draws no value labels and the platform
 * ships no plugin for them; the plate draws these itself, past the end of
 * every bar, in the plate's own ink.
 *
 * WHAT A CALLER STATES IS THE UNIT AND THE PRECISION. The unit follows the
 * number with a space ("128 h"); the precision is the decimals a figure
 * keeps, nought for a count.
 *
 * ON A STACK, A FIGURE MAY BE THE STACK'S TOTAL (`totals`): one figure at the
 * end of each bar, its parts summed, never a nought. AND A SURFACE MAY STATE
 * THE FIGURE'S SIZE in pixels where its design draws it other than the
 * house's 10.5 — a wall read across a room.
 *
 * ON A WHOLE, every slice is named and figured outside it, at the middle of
 * its angle, the names laid down each side so two neighbours never print on
 * top of each other.
 */
final readonly class ChartFigures
{
    public function __construct(
        public string $unit = '',
        public int $precision = 0,
        public bool $totals = false,
        public ?float $size = null,
    ) {
        if ($precision < 0) {
            throw new \InvalidArgumentException(\sprintf('A figure keeps a whole number of decimals; %d is not one.', $precision));
        }
        if (null !== $size && $size <= 0.0) {
            throw new \InvalidArgumentException(\sprintf('A figure is written at a positive size; %s is not one.', $size));
        }
    }
}
