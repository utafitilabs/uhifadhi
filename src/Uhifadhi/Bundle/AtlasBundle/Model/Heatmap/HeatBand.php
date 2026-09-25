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
 * THE ROWS A PLACING IS MADE AMONG. A band and not a heading: the rows under
 * it are placed against each other and nobody else, and the rule is drawn so
 * a reader can see what a tint was measured against.
 */
final readonly class HeatBand
{
    /** @param list<HeatRow> $rows */
    public function __construct(
        public string $name,
        public array $rows,
        public string $note = '',
    ) {
    }

    public function count(): int
    {
        return \count($this->rows);
    }
}
