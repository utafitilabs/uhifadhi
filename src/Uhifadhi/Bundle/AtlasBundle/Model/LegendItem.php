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
 * One row of a plate's legend.
 *
 * Two kinds of row, one class, because the plate template iterates a single
 * list and the difference is data:
 *
 *   - a row with a `layerId` is a SWITCH — the plate has that layer drawn and
 *     clicking the row shows or hides it;
 *   - a row without one is a KEY — it says what a colour means and switches
 *     nothing, because there is no layer behind it.
 *
 * `group` is the heading the row sits under. Rows sharing a group are drawn
 * together, so each contributor's layers read as that contributor's.
 *
 * `note` is the quiet word after the label where a row has no count — the
 * design's "Zones · context", "The pin · being placed".
 */
final readonly class LegendItem
{
    public function __construct(
        public string $label,
        public string $swatch,
        public LayerShape $shape = LayerShape::Fill,
        public ?string $group = null,
        public ?int $count = null,
        public ?string $layerId = null,
        public bool $visible = true,
        public ?string $note = null,
    ) {
    }
}
