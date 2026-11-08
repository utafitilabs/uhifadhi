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
 * ONE LINE, ONE SET OF BARS, ONE BAND OF A STACK, ONE RING OF A WHOLE.
 *
 * THE POINTS KEEP THEIR HOLES. A period nobody reported is null all the
 * way down to the library, which draws a gap; turning it into a nought
 * on the way would be a chart inventing a quiet month.
 *
 * A POINT IS WHAT THE KIND READS: a number per label on most kinds; a
 * {@see ChartPoint} (x, y, and a bubble's size) on a scatter or a bubble, or
 * wherever a long run is decimated; a {@see ChartSpan} (start to end) on a
 * floating bar; a {@see ChartReadings} (every reading at the label) on a
 * boxplot or a violin. The chart refuses a point of the wrong shape for its
 * kind.
 *
 * A SERIES IS A CATEGORY, NOT A COLOUR. `cat` is its position in the
 * palette, 1 to 18, and the chart resolves it — so a series drawn beside a
 * zone of the same category matches it, and every chart in the product
 * turns over together with the theme. A module never knows what green is
 * here. NULL IS THE ORDINARY CASE: a series that states no category takes
 * the next one in order.
 *
 * `swatch` IS A TOKEN, for a series that is a status rather than a
 * category — `var(--ok)`, `var(--warn)`, `var(--dim)`. A hex still draws,
 * right in one palette and wrong in the other two.
 *
 * A SERIES MAY BE A LINE OVER THE BARS (`line`): on a bar chart it is drawn
 * as a line and not a second set of bars — the normal the bars are read
 * against, dashed, or a second measurement, solid. And it MAY MARK ONE
 * POINT (`marked`): the reading the page is about wears a dot, the rest of
 * the line wears none.
 *
 * AND WHAT A LINE OR A MARK SAYS ABOUT ITSELF: its own unit where a chart
 * has two measures, the right-hand axis, a marker, a curve, a dash (a third
 * encoding where two hues and their markers still sit too near), where a
 * stepped line changes, and the hatch over a part that is "empty". On a
 * stacked-panels chart, the pane it is drawn in and whether as bars.
 */
final readonly class ChartSeries
{
    /**
     * @param list<float|ChartPoint|ChartSpan|ChartReadings|null> $points  one per label, in the same order; a
     *                                                                     scatter's or a bubble's items need no label
     * @param string|null                                         $swatch  a token, where the series is a status
     * @param int|null                                            $cat     the category this series wears, 1 to 18 — its
     *                                                                     position in the palette, resolved where it is drawn
     * @param bool                                                $accent  the series wears the house accent: the one quantity a
     *                                                                     card measures where it is no category at all
     * @param LineStyle|null                                      $line    on a bar chart, this series is a line over the bars
     * @param int|null                                            $marked  the index of the one point drawn with a marker
     * @param string                                              $unit    this series' own unit, on a chart of two measures
     * @param ChartSide                                           $axis    the value axis it is read against
     * @param PointMarker|null                                    $marker  the marker its points wear; unstated, the next in order where several series share a plot
     * @param ChartCurve|null                                     $curve   how its line runs between readings; unstated, the chart's
     * @param bool                                                $dash    its line is dashed
     * @param ChartStep|null                                      $stepped where a stepped line changes its level; unstated, after the reading
     * @param ChartTexture|null                                   $texture the hatch over its fill
     * @param int                                                 $pane    on a stacked-panels chart, the pane it is drawn in: 0 the first (on top), 1 the second
     * @param bool                                                $bar     on a stacked-panels chart, it is drawn as bars, not a line
     */
    public function __construct(
        public string $label,
        public array $points,
        public ?string $swatch = null,
        public ?int $cat = null,
        public bool $accent = false,
        public ?LineStyle $line = null,
        public ?int $marked = null,
        public string $unit = '',
        public ChartSide $axis = ChartSide::Left,
        public ?PointMarker $marker = null,
        public ?ChartCurve $curve = null,
        public bool $dash = false,
        public ?ChartStep $stepped = null,
        public ?ChartTexture $texture = null,
        public int $pane = 0,
        public bool $bar = false,
    ) {
        if (0 !== $pane && 1 !== $pane) {
            throw new \InvalidArgumentException(\sprintf('A series is drawn in the first pane (0) or the second (1); "%s" names pane %d.', $label, $pane));
        }
        if (null !== $marked && ($marked < 0 || $marked >= \count($points))) {
            throw new \InvalidArgumentException(\sprintf('A marked point is one of the %d points of "%s"; %d is none of them.', \count($points), $label, $marked));
        }
        if ($accent && (null !== $cat || null !== $swatch)) {
            throw new \InvalidArgumentException(\sprintf('A series wears a category or the accent, never both; "%s" states both.', $label));
        }
        if (null !== $cat && ($cat < 1 || $cat > PlatePalette::CATEGORIES)) {
            throw new \InvalidArgumentException(\sprintf('A category is its position in a declared order, 1 to %d; "%d" is not one.', PlatePalette::CATEGORIES, $cat));
        }
    }
}
