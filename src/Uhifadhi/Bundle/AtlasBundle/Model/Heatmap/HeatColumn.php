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

/** ONE COLUMN'S HEADER, written out; its total arrives printed, published and never summed. */
final readonly class HeatColumn
{
    public function __construct(
        public string $key,
        public string $label,
        public string $unit = '',
        public string $caption = '',
        public string $total = '',
        public string $totalDelta = '',
        /** 'good', 'bad', 'flat' or '' — never a colour. */
        public string $totalTone = '',
    ) {
    }
}
