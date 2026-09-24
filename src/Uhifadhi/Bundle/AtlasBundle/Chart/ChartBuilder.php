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

namespace Uhifadhi\Bundle\AtlasBundle\Chart;

use Symfony\UX\Chartjs\Builder\ChartBuilderInterface;
use Symfony\UX\Chartjs\Model\Chart;
use Uhifadhi\Bundle\AtlasBundle\Model\AtlasChart;
use Uhifadhi\Bundle\AtlasBundle\Model\ChartKind;
use Uhifadhi\Bundle\AtlasBundle\Model\ChartLegend;
use Uhifadhi\Bundle\AtlasBundle\Model\ChartSeries;
use Uhifadhi\Contracts\Atlas\PlatePalette;

/**
 * WHAT A STATED CHART BECOMES — the one place the platform decides what a
 * chart looks like.
 *
 * THE MAP BUILDER'S SIBLING. Everything a module would otherwise have to
 * know about Chart.js is here and nowhere else: the type behind each
 * shape, the scales, the legend, the grid, the tension of a line, the
 * colours. A module states a kind and a series; two modules cannot
 * disagree about what a bar chart is.
 *
 * NO ANIMATION, AND NO ASPECT RATIO OF ITS OWN. A chart in a card is
 * given a box by the card, and one that animated on every render would
 * be a page that moves while it is being read.
 *
 * NULLS SURVIVE. Chart.js draws a gap where a point is null, which is
 * the truthful reading of a period nobody reported.
 *
 * @see https://www.chartjs.org/docs/latest/charts/bar.html#horizontal-bar-chart — a ranking is `indexAxis: 'y'`; "any options specified on the x-axis in a bar chart, are applied to the y-axis in a horizontal bar chart"
 * @see https://www.chartjs.org/docs/latest/axes/cartesian/linear.html — `max` and `ticks.stepSize` on the value scale
 * @see https://www.chartjs.org/docs/latest/configuration/legend.html — `plugins.legend.display`
 * @see https://www.chartjs.org/docs/latest/developers/plugins.html — "Plugin options are located under the options.plugins config and are scoped by the plugin ID"
 * @see https://www.chartjs.org/docs/latest/configuration/layout.html — `layout.padding`, "The padding to add inside the chart"
 */
final readonly class ChartBuilder
{
    /**
     * HOW MANY SERIES TAKE A CATEGORY OF THEIR OWN before the order
     * begins again — the palette's own count, so a chart and a zone key
     * beside it never disagree about how many distinct marks there are.
     *
     * THIS WAS SIX HEX COLOURS. They were picked once, they were right on
     * the night canvas and wrong on paper, and they were a seventh palette
     * beside the one the product has. A series is a CATEGORY now: the
     * caller states it or takes the next one in order, and the value is
     * resolved where the chart is drawn.
     */
    private const int CATEGORIES = PlatePalette::CATEGORIES;

    /**
     * THE ID OF THE PLATE'S OWN FIGURES PLUGIN — the inline plugin
     * chart_plate_controller.js puts on a chart whose options carry a block
     * under this key. Chart.js scopes a plugin's options by its id, so this
     * string is written here and read there, and nowhere else.
     */
    public const string FIGURES_PLUGIN = 'figures';

    /** THE ROOM A FIGURE NEEDS past the longest bar, in canvas pixels: "128 h" in the mono face at 10px. */
    private const int FIGURE_ROOM_BESIDE = 36;
    private const int FIGURE_ROOM_ABOVE = 16;

    public function __construct(private ChartBuilderInterface $charts)
    {
    }

    public function chart(AtlasChart $chart): Chart
    {
        $built = $this->charts->createChart(self::typeOf($chart->kind));

        $datasets = [];
        foreach ($chart->series as $position => $series) {
            $datasets[] = self::dataset($series, $position, $chart->kind);
        }

        if (null !== $chart->target) {
            // THE TARGET IS A FLAT LINE ACROSS THE WHOLE AXIS, drawn as a
            // series of its own so it carries a legend row and a hover
            // like everything else on the chart.
            $datasets[] = [
                'label' => $chart->targetLabel,
                'data' => array_fill(0, \count($chart->labels), $chart->target),
                'type' => Chart::TYPE_LINE,
                'borderColor' => 'rgba(120,130,125,.85)',
                'borderDash' => [5, 4],
                'borderWidth' => 1.5,
                'pointRadius' => 0,
                'fill' => false,
            ];
        }

        $built->setData(['labels' => $chart->labels, 'datasets' => $datasets]);
        $built->setOptions(self::options($chart));

        return $built;
    }

    /** @return array<string, mixed> */
    private static function dataset(ChartSeries $series, int $position, ChartKind $kind): array
    {
        /*
         * THE TOKEN, NOT THE VALUE. Chart.js is handed `var(--cat-3)` and
         * the chart's own controller resolves it against the element it is
         * mounted on, at mount and again when the theme flips — the same
         * door the map plate's layers go through, for the same reason: a
         * colour decided in PHP is a colour that cannot follow a palette
         * it has never seen.
         *
         * A `swatch` still wins where a module states one, which is the
         * deprecated door and is honoured for one release.
         */
        $colour = $series->swatch ?? \sprintf('var(--cat-%d)', $series->cat ?? ($position % self::CATEGORIES) + 1);

        $dataset = [
            'label' => $series->label,
            'data' => $series->points,
            'backgroundColor' => $colour,
            'borderColor' => $colour,
            'borderWidth' => 1.5,
        ];

        if (ChartKind::Line === $kind) {
            // A GENTLE CURVE, NOT A SPLINE: enough to read the shape,
            // never enough to invent a value between two months.
            $dataset['tension'] = 0.25;
            $dataset['fill'] = false;
            $dataset['pointRadius'] = 2;
            $dataset['backgroundColor'] = 'transparent';
        }

        if (ChartKind::Stacked === $kind) {
            $dataset['stack'] = 'one';
        }

        return $dataset;
    }

    /** A ranking reads sideways; everything else stands up. */
    private static function ranked(AtlasChart $chart): bool
    {
        return ChartKind::Ranked === $chart->kind;
    }

    /** @return array<string, mixed> */
    private static function options(AtlasChart $chart): array
    {
        /*
         * THE INDEX AXIS CARRIES THE NAMES AND NO GRID; THE VALUE AXIS
         * STARTS AT NOUGHT AND CARRIES THE GRID. Which letter is which is
         * the one thing a ranking changes: `indexAxis: 'y'` turns the
         * bars sideways and the value scale becomes `x`.
         */
        $index = ['grid' => ['display' => false], 'ticks' => ['maxRotation' => 0]];
        $value = ['beginAtZero' => ChartKind::Diverging !== $chart->kind, 'grid' => ['drawBorder' => false]];

        if (null !== $chart->axis) {
            $value['max'] = $chart->axis->max;
            $value['ticks'] = ['stepSize' => $chart->axis->step];
        }

        if (ChartKind::Stacked === $chart->kind) {
            $index['stacked'] = true;
            $value['stacked'] = true;
        }

        $scales = self::ranked($chart) ? ['x' => $value, 'y' => $index] : ['x' => $index, 'y' => $value];

        $plugins = [
            // THE LIBRARY'S LEGEND IS DRAWN WHERE MORE THAN ONE THING IS
            // PLOTTED — and never beside a chip legend, which would name
            // every series twice.
            'legend' => [
                'display' => ChartLegend::Canvas === $chart->legend && (\count($chart->series) > 1 || null !== $chart->target),
                'position' => 'bottom',
            ],
        ];

        $options = [
            'responsive' => true,
            'maintainAspectRatio' => false,
            // A PAGE THAT MOVES WHILE IT IS BEING READ is a page nobody
            // can compare two figures on.
            'animation' => false,
        ];

        if (self::ranked($chart)) {
            $options['indexAxis'] = 'y';
        }

        if (null !== $chart->figures) {
            // THE FIGURE IS THE PLATE'S PLUGIN, configured where Chart.js
            // reads a plugin's options — under its id — and given room past
            // the longest bar, or the canvas clips the last one.
            $plugins[self::FIGURES_PLUGIN] = ['unit' => $chart->figures->unit, 'precision' => $chart->figures->precision];
            $options['layout'] = ['padding' => self::ranked($chart) ? ['right' => self::FIGURE_ROOM_BESIDE] : ['top' => self::FIGURE_ROOM_ABOVE]];
        }

        $options['plugins'] = $plugins;
        $options['scales'] = $scales;

        return $options;
    }

    private static function typeOf(ChartKind $kind): string
    {
        return match ($kind) {
            ChartKind::Line => Chart::TYPE_LINE,
            // A DIVERGING CHART IS BARS EITHER SIDE OF NOUGHT — the shape
            // is the data's, not a chart type of its own.
            // AND A RANKING IS BARS TURNED SIDEWAYS: the type stays `bar`
            // and `indexAxis` does the turning, in options().
            ChartKind::Bar, ChartKind::Stacked, ChartKind::Diverging, ChartKind::Ranked => Chart::TYPE_BAR,
        };
    }
}
