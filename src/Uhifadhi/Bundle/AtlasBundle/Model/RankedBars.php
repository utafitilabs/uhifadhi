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
 * RANKED BARS — one row per thing, the value read off the end of the bar.
 *
 * THE WIDTHS ARE THE ATLAS'S. A row is read against the largest row's whole,
 * so the longest bar runs the track and the comparison the card exists to make
 * is the one a reader gets; a row that states a whole of its own (`Bar::$of`)
 * is read against that instead. The caller orders the rows: longest first is
 * the usual reading, and the atlas does not reorder what it is handed.
 */
final readonly class RankedBars
{
    /**
     * @param list<Bar> $bars  in the order they are drawn
     * @param string    $empty what the card says where there is no row
     */
    public function __construct(
        public array $bars,
        public BarFill $fill = BarFill::Solid,
        public ?DotKey $key = null,
        public string $empty = '',
    ) {
    }

    public function isEmpty(): bool
    {
        return [] === $this->bars;
    }

    /** @return list<DrawnBar> */
    public function rows(): array
    {
        $largest = 0.0;
        foreach ($this->bars as $bar) {
            $largest = max($largest, $bar->whole());
        }

        $rows = [];
        foreach ($this->bars as $bar) {
            $scale = $bar->of ?? $largest;
            $rows[] = new DrawnBar(
                $bar,
                $scale > 0 ? round($bar->value / $scale * 100, 1) : 0.0,
                $scale > 0 ? round($bar->rest / $scale * 100, 1) : 0.0,
            );
        }

        return $rows;
    }
}
