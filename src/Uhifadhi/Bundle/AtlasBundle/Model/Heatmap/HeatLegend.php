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

namespace Uhifadhi\Bundle\AtlasBundle\Model\Heatmap;

/**
 * THE LEGEND A HEAT TABLE IS READ WITH: what a tint is, and — in the sentence
 * that matters more — what it is not. Drawn by `atlas_heat_legend()`.
 */
final readonly class HeatLegend
{
    /** @param list<HeatLegendEntry> $entries */
    public function __construct(
        public array $entries,
        public string $note = '',
    ) {
    }
}
