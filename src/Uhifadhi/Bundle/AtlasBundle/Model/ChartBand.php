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
 * THE RANGE AROUND A LINE — the fastest and the slowest either side of the
 * median, one pair of edges per label, filled between in the line's colour.
 *
 * The band carries one legend row, under its own name; each edge answers
 * the hover with its own word.
 */
final readonly class ChartBand
{
    /**
     * @param list<float|null> $from the lower edge, one per label
     * @param list<float|null> $to   the upper edge, one per label
     */
    public function __construct(
        public array $from,
        public array $to,
        public string $label = 'Range',
        public string $fromLabel = 'Low',
        public string $toLabel = 'High',
    ) {
    }
}
