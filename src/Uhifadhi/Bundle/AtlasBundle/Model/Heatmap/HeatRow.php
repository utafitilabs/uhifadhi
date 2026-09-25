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

/** ONE ROW: the thing, its mark, a cell per column in the columns' order, and the way into it. */
final readonly class HeatRow
{
    /** @param list<HeatCell> $cells */
    public function __construct(
        public string $name,
        public string $mark,
        public array $cells,
        public ?string $url = null,
        /** What the row says under its name. */
        public string $note = '',
    ) {
    }
}
