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
 */
final readonly class ChartFigures
{
    public function __construct(
        public string $unit = '',
        public int $precision = 0,
    ) {
        if ($precision < 0) {
            throw new \InvalidArgumentException(\sprintf('A figure keeps a whole number of decimals; %d is not one.', $precision));
        }
    }
}
