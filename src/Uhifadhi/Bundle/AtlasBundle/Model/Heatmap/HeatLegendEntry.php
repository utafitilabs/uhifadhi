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

/** ONE SWATCH OF THE LEGEND and what it means. */
final readonly class HeatLegendEntry
{
    public function __construct(
        public HeatTint $tint,
        public string $label,
    ) {
    }
}
