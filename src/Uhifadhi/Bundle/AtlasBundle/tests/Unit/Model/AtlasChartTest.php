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

namespace Uhifadhi\Bundle\AtlasBundle\Tests\Unit\Model;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\UX\Chartjs\Model\Chart as UxChart;
use Uhifadhi\Bundle\AtlasBundle\Chart\ChartBuilder;
use Uhifadhi\Bundle\AtlasBundle\Model\AtlasChart;
use Uhifadhi\Bundle\AtlasBundle\Model\AxisScale;
use Uhifadhi\Bundle\AtlasBundle\Model\ChartFigures;
use Uhifadhi\Bundle\AtlasBundle\Model\ChartKind;
use Uhifadhi\Bundle\AtlasBundle\Model\ChartLegend;
use Uhifadhi\Bundle\AtlasBundle\Model\ChartSeries;

/**
 * THE PLATFORM'S ONE CHART, AND WHAT A MODULE MAY SAY ABOUT IT.
 *
 * THE SAME BARGAIN THE MAP PLATE MAKES. A module states WHAT its series
 * is — a run over time, a comparison, parts of a whole, a movement
 * either side of nought — and the atlas decides what that looks like:
 * the colours, the grid, the axes, the legend, the height. A module
 * handing over chart options would be a module deciding what the
 * platform's charts look like, and the second module would decide
 * differently.
 *
 * A HOLE IS A HOLE. A period nobody reported is null all the way down to
 * Chart.js, which draws a gap; turning it into a nought on the way would
 * be the library inventing a quiet month.
 */
#[CoversClass(AtlasChart::class)]
#[CoversClass(AxisScale::class)]
#[CoversClass(ChartFigures::class)]
#[CoversClass(ChartBuilder::class)]
final class AtlasChartTest extends TestCase
{
    /**
     * A RANKING READS SIDEWAYS: the name on the left, the bar running right,
     * the longest on top. Chart.js draws it as a bar chart whose index axis is
     * `y` — the one option the docs give for a horizontal bar chart.
     *
     * @see https://www.chartjs.org/docs/latest/charts/bar.html#horizontal-bar-chart — "set the `indexAxis` property in the options object to `'y'`. The default for this property is `'x'`"
     */
    public function testARankingIsBarsWhoseIndexAxisIsY(): void
    {
        $chart = self::builder()->chart(new AtlasChart(
            ChartKind::Ranked,
            ['Endulen', 'Lerai'],
            [new ChartSeries('Patrols', [46.0, 27.0])],
        ));

        self::assertSame(UxChart::TYPE_BAR, $chart->getType());
        self::assertSame('y', self::at($chart->getOptions(), 'indexAxis'));
        // The value axis is the horizontal one now, and it is the one that
        // starts at nought and carries the grid; the names carry none.
        self::assertTrue(self::at(self::scale($chart, 'x'), 'beginAtZero'));
        self::assertFalse(self::at(self::under(self::scale($chart, 'y'), 'grid'), 'display'));
        self::assertArrayNotHasKey('indexAxis', self::builder()->chart(new AtlasChart(ChartKind::Bar, ['a'], [new ChartSeries('S', [1.0])]))->getOptions());
    }

    /**
     * THE CALLER MAY STATE THE AXIS. A ranking's gridlines land on whole
     * numbers only if somebody rounds the top of the scale, and the rule is
     * the caller's — "the smallest covering multiple of three" is patrol's,
     * not the platform's. What the atlas does is put the two numbers where
     * Chart.js reads them.
     *
     * @see https://www.chartjs.org/docs/latest/axes/cartesian/linear.html — `max`: "User defined maximum number for the scale, overrides maximum value from data" (options.scales[scaleId]); `ticks.stepSize`: "User-defined fixed step size for the scale" (options.scales[scaleId].ticks)
     */
    public function testAStatedAxisBecomesTheValueScalesMaximumAndStep(): void
    {
        $vertical = self::builder()->chart(new AtlasChart(
            ChartKind::Bar,
            ['W1'],
            [new ChartSeries('Patrols', [41.0])],
            axis: new AxisScale(max: 45.0, step: 15.0),
        ));
        $ranked = self::builder()->chart(new AtlasChart(
            ChartKind::Ranked,
            ['Endulen'],
            [new ChartSeries('Patrols', [46.0])],
            axis: new AxisScale(max: 48.0, step: 16.0),
        ));

        // On a vertical chart the value axis is `y`; on a ranking it is `x`.
        self::assertSame(45.0, self::at(self::scale($vertical, 'y'), 'max'));
        self::assertSame(15.0, self::at(self::under(self::scale($vertical, 'y'), 'ticks'), 'stepSize'));
        self::assertArrayNotHasKey('max', self::scale($vertical, 'x'));
        self::assertSame(48.0, self::at(self::scale($ranked, 'x'), 'max'));
        self::assertSame(16.0, self::at(self::under(self::scale($ranked, 'x'), 'ticks'), 'stepSize'));
        self::assertArrayNotHasKey('max', self::scale($ranked, 'y'));
    }

    /** An axis nobody stated leaves the scale to the library's own ticks. */
    public function testAnUnstatedAxisLeavesTheScaleToTheLibrary(): void
    {
        $chart = self::builder()->chart(new AtlasChart(ChartKind::Bar, ['a'], [new ChartSeries('S', [1.0])]));

        self::assertArrayNotHasKey('max', self::scale($chart, 'y'));
        self::assertArrayNotHasKey('ticks', self::scale($chart, 'y'));
    }

    /**
     * THE SMALLEST COVERING MULTIPLE, stated once for every caller whose
     * gridlines must land on whole numbers: the top of the scale is the
     * smallest multiple of the step count that still covers the largest
     * value, never below the count itself — so an empty month still has a
     * width to measure against.
     */
    public function testACoveringAxisRoundsUpToAMultipleOfItsTickCountAndNeverBelowIt(): void
    {
        self::assertEquals(new AxisScale(3.0, 1.0), AxisScale::covering(0.0, 3));
        self::assertEquals(new AxisScale(3.0, 1.0), AxisScale::covering(0.0167, 3));
        self::assertEquals(new AxisScale(3.0, 1.0), AxisScale::covering(3.0, 3));
        self::assertEquals(new AxisScale(6.0, 2.0), AxisScale::covering(3.2, 3));
        self::assertEquals(new AxisScale(9.0, 3.0), AxisScale::covering(7.0, 3));
        self::assertEquals(new AxisScale(48.0, 16.0), AxisScale::covering(46.0, 3));
    }

    /** A scale that cannot be drawn is refused where it is stated. */
    public function testAnAxisWithNoRoomOrNoStepIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new AxisScale(max: 0.0, step: 0.0);
    }

    /**
     * THE FIGURE ON THE BAR. The design writes "46" and "128 h" at the end
     * of each bar; Chart.js core draws no value labels and the importmap
     * ships no datalabels plugin, so the atlas draws them with an INLINE
     * plugin of its own on the plate, configured under its id here — which
     * is where the docs put a plugin's options.
     *
     * @see https://www.chartjs.org/docs/latest/developers/plugins.html — "Plugin options are located under the `options.plugins` config and are scoped by the plugin ID: `options.plugins.{plugin-id}`"
     * @see https://www.chartjs.org/docs/latest/configuration/layout.html — `layout.padding`: "The padding to add inside the chart", default 0 — the room the figure past the last bar needs
     */
    public function testStatedFiguresAreTheInlinePluginsOptionsAndRoomIsMadeForThem(): void
    {
        $ranked = self::builder()->chart(new AtlasChart(
            ChartKind::Ranked,
            ['S. Laizer'],
            [new ChartSeries('Patrol-hours', [128.0])],
            figures: new ChartFigures(unit: 'h'),
        ));
        $vertical = self::builder()->chart(new AtlasChart(
            ChartKind::Bar,
            ['W1'],
            [new ChartSeries('Patrols', [41.0])],
            figures: new ChartFigures(),
        ));
        $bare = self::builder()->chart(new AtlasChart(ChartKind::Bar, ['W1'], [new ChartSeries('Patrols', [41.0])]));

        $plugins = self::at($ranked->getOptions(), 'plugins');
        self::assertIsArray($plugins);
        self::assertSame(['unit' => 'h', 'precision' => 0], self::at($plugins, ChartBuilder::FIGURES_PLUGIN));
        // A ranking's figures sit past the bar's right end; a column's above its top.
        self::assertSame(['right' => 36], self::at(self::under($ranked->getOptions(), 'layout'), 'padding'));
        self::assertSame(['top' => 16], self::at(self::under($vertical->getOptions(), 'layout'), 'padding'));

        $none = self::at($bare->getOptions(), 'plugins');
        self::assertIsArray($none);
        self::assertArrayNotHasKey(ChartBuilder::FIGURES_PLUGIN, $none);
        self::assertArrayNotHasKey('layout', $bare->getOptions());
    }

    /**
     * THE CHIP LEGEND TURNS THE CANVAS LEGEND OFF. A legend drawn twice —
     * once in pills under the plate, once by the library inside it — names
     * every series twice; when the plate draws the chips, the library's own
     * is switched off by the one option the docs give for it.
     *
     * @see https://www.chartjs.org/docs/latest/configuration/legend.html — `display`, boolean, default true: "Is the legend shown?" (options.plugins.legend)
     */
    public function testTheChipLegendSwitchesTheCanvasLegendOff(): void
    {
        $series = [new ChartSeries('foot', [1.0]), new ChartSeries('vehicle', [2.0])];
        $canvas = self::builder()->chart(new AtlasChart(ChartKind::Bar, ['W1'], $series));
        $chips = self::builder()->chart(new AtlasChart(ChartKind::Bar, ['W1'], $series, legend: ChartLegend::Chips));
        $none = self::builder()->chart(new AtlasChart(ChartKind::Bar, ['W1'], $series, legend: ChartLegend::None));

        self::assertTrue(self::at(self::legend($canvas), 'display'));
        self::assertFalse(self::at(self::legend($chips), 'display'));
        self::assertFalse(self::at(self::legend($none), 'display'));
    }

    /**
     * THE LEGEND'S ROWS COME FROM THE SERIES, in order, each wearing the
     * category the builder gave its bars — so the pill under the plate and
     * the bar above it cannot disagree — and the target line last, as the
     * idle chip, because a dashed grey line is not a category.
     */
    public function testTheLegendRowsAreTheSeriesInTheirCategoriesAndTheTargetLast(): void
    {
        $chart = new AtlasChart(
            ChartKind::Bar,
            ['W1'],
            [new ChartSeries('foot', [1.0], cat: 7), new ChartSeries('vehicle', [2.0]), new ChartSeries('drone', [3.0], '#E05B41')],
            target: 2.0,
            legend: ChartLegend::Chips,
        );

        self::assertSame([
            ['label' => 'foot', 'cat' => 7, 'swatch' => null],
            ['label' => 'vehicle', 'cat' => 2, 'swatch' => null],
            ['label' => 'drone', 'cat' => null, 'swatch' => '#E05B41'],
            ['label' => 'Target', 'cat' => null, 'swatch' => null],
        ], $chart->legendRows());
    }

    /** @return array<array-key, mixed> */
    private static function legend(UxChart $chart): array
    {
        $plugins = self::at($chart->getOptions(), 'plugins');
        self::assertIsArray($plugins);
        $legend = self::at($plugins, 'legend');
        self::assertIsArray($legend);

        return $legend;
    }

    public function testARunOverTimeIsALineWithItsLabelsAndItsHoles(): void
    {
        $chart = self::builder()->chart(new AtlasChart(
            ChartKind::Line,
            ['apr', 'may', 'jun'],
            [new ChartSeries('Open', [9.0, null, 11.0])],
        ));

        self::assertSame(UxChart::TYPE_LINE, $chart->getType());
        self::assertSame(['apr', 'may', 'jun'], self::at($chart->getData(), 'labels'));
        self::assertSame([9.0, null, 11.0], self::at(self::dataset($chart, 0), 'data'));
        self::assertSame('Open', self::at(self::dataset($chart, 0), 'label'));
    }

    /** Parts of a whole are bars that stack; a comparison is bars that do not. */
    public function testAStackIsBarsThatStackAndAComparisonIsBarsThatDoNot(): void
    {
        $stacked = self::builder()->chart(new AtlasChart(
            ChartKind::Stacked,
            ['apr'],
            [new ChartSeries('Filled', [9.0]), new ChartSeries('Vacant', [2.0])],
        ));
        $bars = self::builder()->chart(new AtlasChart(ChartKind::Bar, ['apr'], [new ChartSeries('Filled', [9.0])]));

        self::assertSame(UxChart::TYPE_BAR, $stacked->getType());
        self::assertTrue(self::at(self::scale($stacked, 'x'), 'stacked'));
        self::assertTrue(self::at(self::scale($stacked, 'y'), 'stacked'));
        self::assertSame(UxChart::TYPE_BAR, $bars->getType());
        self::assertArrayNotHasKey('stacked', self::scale($bars, 'x'));
    }

    /**
     * A TARGET LINE IS A FACT, not a decoration: it is what somebody
     * committed to, and a chart of attainment without it is a chart of a
     * number.
     */
    public function testATargetIsDrawnAsItsOwnLine(): void
    {
        $chart = self::builder()->chart(new AtlasChart(
            ChartKind::Line,
            ['apr', 'may'],
            [new ChartSeries('Coverage', [58.0, 61.0])],
            target: 60.0,
        ));

        $datasets = self::at($chart->getData(), 'datasets');
        self::assertIsArray($datasets);
        self::assertCount(2, $datasets, 'the series, and the line it is measured against');
        self::assertSame([60.0, 60.0], self::at(self::dataset($chart, 1), 'data'));
        self::assertSame('Target', self::at(self::dataset($chart, 1), 'label'));
    }

    /** A module states no colour and gets the platform's, in order. */
    public function testTheSeriesTakeThePlatformsOwnColoursInOrder(): void
    {
        $chart = self::builder()->chart(new AtlasChart(
            ChartKind::Bar,
            ['apr'],
            [new ChartSeries('One', [1.0]), new ChartSeries('Two', [2.0])],
        ));

        self::assertNotSame(
            self::at(self::dataset($chart, 0), 'backgroundColor'),
            self::at(self::dataset($chart, 1), 'backgroundColor'),
        );
        // A TOKEN, NEVER A VALUE: the first series takes the first category
        // and the chart's own controller resolves it where it is drawn, so
        // the same series follows the theme and matches the third zone on a
        // plate beside it.
        self::assertSame('var(--cat-1)', self::at(self::dataset($chart, 0), 'backgroundColor'));
        self::assertSame('var(--cat-2)', self::at(self::dataset($chart, 1), 'backgroundColor'));
    }

    /** A SERIES MAY STATE ITS CATEGORY, and then it wears that one wherever it sits. */
    public function testASeriesStatesItsCategoryAndKeepsIt(): void
    {
        $chart = self::builder()->chart(new AtlasChart(
            ChartKind::Bar,
            ['apr'],
            [new ChartSeries('One', [1.0], cat: 7), new ChartSeries('Two', [2.0])],
        ));

        self::assertSame('var(--cat-7)', self::at(self::dataset($chart, 0), 'backgroundColor'));
        // And the one that states nothing still takes its place in order.
        self::assertSame('var(--cat-2)', self::at(self::dataset($chart, 1), 'backgroundColor'));
    }

    /** A category outside the palette is refused where it is stated, not drawn as nothing. */
    public function testACategoryOutsideThePaletteIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ChartSeries('One', [1.0], cat: 19);
    }

    /** And a module that owns a colour keeps it — a module's hue is its own. */
    public function testASeriesWithItsOwnSwatchKeepsIt(): void
    {
        $chart = self::builder()->chart(new AtlasChart(
            ChartKind::Bar,
            ['apr'],
            [new ChartSeries('Incidents', [1.0], '#E05B41')],
        ));

        self::assertSame('#E05B41', self::at(self::dataset($chart, 0), 'backgroundColor'));
    }

    /** A chart nobody published a point in is not drawn at all. */
    public function testAChartOfNothingIsEmpty(): void
    {
        self::assertTrue(new AtlasChart(ChartKind::Line, ['apr'], [new ChartSeries('X', [null])])->isEmpty());
        self::assertFalse(new AtlasChart(ChartKind::Line, ['apr'], [new ChartSeries('X', [0.0])])->isEmpty());
    }

    /**
     * One dataset of a built chart, asserted down to it rather than cast:
     * the library types its payload as a plain array, and a test that
     * casts its way in is a test that passes when the shape changes.
     *
     * @return array<array-key, mixed>
     */
    private static function dataset(UxChart $chart, int $position): array
    {
        $datasets = self::at($chart->getData(), 'datasets');
        self::assertIsArray($datasets);
        self::assertArrayHasKey($position, $datasets);
        self::assertIsArray($datasets[$position]);

        return $datasets[$position];
    }

    /** @return array<array-key, mixed> */
    private static function scale(UxChart $chart, string $axis): array
    {
        $scales = self::at($chart->getOptions(), 'scales');
        self::assertIsArray($scales);
        $scale = self::at($scales, $axis);
        self::assertIsArray($scale);

        return $scale;
    }

    /**
     * A nested block of a payload, asserted to be one rather than cast.
     *
     * @param array<array-key, mixed> $payload
     *
     * @return array<array-key, mixed>
     */
    private static function under(array $payload, string $key): array
    {
        $block = self::at($payload, $key);
        self::assertIsArray($block);

        return $block;
    }

    /** @param array<array-key, mixed> $payload */
    private static function at(array $payload, string|int $key): mixed
    {
        self::assertArrayHasKey($key, $payload);

        return $payload[$key];
    }

    private static function builder(): ChartBuilder
    {
        return new ChartBuilder(new \Symfony\UX\Chartjs\Builder\ChartBuilder());
    }
}
