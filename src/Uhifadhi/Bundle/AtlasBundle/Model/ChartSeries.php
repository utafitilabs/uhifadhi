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

use Uhifadhi\Contracts\Atlas\PlatePalette;

/**
 * ONE LINE, ONE SET OF BARS, ONE BAND OF A STACK.
 *
 * THE POINTS KEEP THEIR HOLES. A period nobody reported is null all the
 * way down to the library, which draws a gap; turning it into a nought
 * on the way would be a chart inventing a quiet month.
 *
 * A SERIES IS A CATEGORY, NOT A COLOUR. `cat` is its position in the
 * palette, 1 to 18, and the chart resolves it — so a series drawn beside a
 * zone of the same category matches it, and every chart in the product
 * turns over together with the theme. A module never knows what green is
 * here.
 *
 * NULL IS THE ORDINARY CASE: a series that states no category takes the
 * next one in order, so two charts built by two modules on one page do not
 * read as one chart with nine series.
 *
 * `swatch` IS THE OLD DOOR AND IS DEPRECATED. It took a hex, which was
 * right in one palette and wrong in the other two, and it is honoured for
 * one release so a module that states one still draws.
 */
final readonly class ChartSeries
{
    /**
     * @param list<float|null> $points one per label, in the same order
     * @param string|null      $swatch @deprecated a colour a module picked — state `cat` instead
     * @param int|null         $cat    the category this series wears, 1 to 18 — its
     *                                 position in the palette, resolved where it is drawn
     * @param bool             $accent the series wears the house accent: the one quantity a
     *                                 card measures where it is no category at all
     */
    public function __construct(
        public string $label,
        public array $points,
        public ?string $swatch = null,
        public ?int $cat = null,
        public bool $accent = false,
    ) {
        if ($accent && (null !== $cat || null !== $swatch)) {
            throw new \InvalidArgumentException(\sprintf('A series wears a category or the accent, never both; "%s" states both.', $label));
        }
        if (null !== $cat && ($cat < 1 || $cat > PlatePalette::CATEGORIES)) {
            throw new \InvalidArgumentException(\sprintf('A category is its position in a declared order, 1 to %d; "%d" is not one.', PlatePalette::CATEGORIES, $cat));
        }
    }
}
