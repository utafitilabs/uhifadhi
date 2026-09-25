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
 * A HEAT TABLE, ready to draw: a sortable head, bands of rows, a heat cell per
 * column. Drawn by `atlas_heatmap()`; the legend that reads it is
 * {@see HeatLegend}.
 */
final readonly class HeatTable
{
    /**
     * @param list<HeatColumn> $columns
     * @param list<HeatBand>   $bands
     */
    public function __construct(
        public array $columns,
        public array $bands,
    ) {
    }

    public function isEmpty(): bool
    {
        return [] === $this->bands;
    }

    public function rowCount(): int
    {
        $rows = 0;
        foreach ($this->bands as $band) {
            $rows += $band->count();
        }

        return $rows;
    }
}
