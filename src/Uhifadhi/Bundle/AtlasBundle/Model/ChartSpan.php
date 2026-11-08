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
 * A RANGE ON THE VALUE AXIS FOR ONE ITEM, start to end — a shift from 06:00
 * to 14:00, a closure, a season. A floating bar is drawn between the two.
 */
final readonly class ChartSpan
{
    public function __construct(
        public float $from,
        public float $to,
    ) {
        if ($from > $to) {
            throw new \InvalidArgumentException(\sprintf('A span runs forward; this one runs from %s to %s.', $from, $to));
        }
    }
}
