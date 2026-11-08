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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\UX\Chartjs\Builder\ChartBuilder as UxChartBuilder;
use Symfony\UX\Chartjs\Model\Chart as UxChart;
use Uhifadhi\Bundle\AtlasBundle\Chart\ChartBuilder;
use Uhifadhi\Bundle\AtlasBundle\Model\AtlasChart;
use Uhifadhi\Bundle\AtlasBundle\Model\AxisScale;
use Uhifadhi\Bundle\AtlasBundle\Model\ChartAxis;
use Uhifadhi\Bundle\AtlasBundle\Model\ChartBand;
use Uhifadhi\Bundle\AtlasBundle\Model\ChartChild;
use Uhifadhi\Bundle\AtlasBundle\Model\ChartDecimation;
use Uhifadhi\Bundle\AtlasBundle\Model\ChartFigures;
use Uhifadhi\Bundle\AtlasBundle\Model\ChartFlowTarget;
use Uhifadhi\Bundle\AtlasBundle\Model\ChartPane;
use Uhifadhi\Bundle\AtlasBundle\Model\ChartPart;
use Uhifadhi\Bundle\AtlasBundle\Model\ChartPoint;
use Uhifadhi\Bundle\AtlasBundle\Model\ChartQuadrant;
use Uhifadhi\Bundle\AtlasBundle\Model\ChartQuadrants;
use Uhifadhi\Bundle\AtlasBundle\Model\ChartRamp;
use Uhifadhi\Bundle\AtlasBundle\Model\ChartReadings;
use Uhifadhi\Bundle\AtlasBundle\Model\ChartSeries;
use Uhifadhi\Bundle\AtlasBundle\Model\ChartSide;
use Uhifadhi\Bundle\AtlasBundle\Model\ChartSpan;
use Uhifadhi\Bundle\AtlasBundle\Model\ChartThreshold;
use Uhifadhi\Bundle\AtlasBundle\Model\ChartTime;
use Uhifadhi\Bundle\AtlasBundle\Model\DecimationAlgorithm;
use Uhifadhi\Bundle\AtlasBundle\Model\TimeUnit;
use Uhifadhi\Contracts\Performance\ChartKind;

/**
 * WHAT A KIND CANNOT BE IS REFUSED WHERE IT IS STATED, with a sentence that
 * says why — and what the builder writes for the facts the design's door
 * leaves to the surface (a stack's totals, a figure's size), the packages a
 * statement is drawn with, and what an extension kind counts as empty.
 */
#[CoversClass(AtlasChart::class)]
#[CoversClass(ChartBuilder::class)]
final class ChartStatementTest extends TestCase
{
    /** THE ONE LIST: every kind the atlas draws, by the name the design states it with. */
    public function testTheKindsAreTheCataloguesKinds(): void
    {
        self::assertSame([
            'line', 'area', 'stepped', 'bar', 'horizontal-bar', 'stacked', 'stacked-horizontal', 'diverging', 'ranked', 'combo',
            'doughnut', 'pie', 'gauge', 'polar-area', 'radar', 'scatter', 'bubble', 'floating-bar', 'grouped-bar', 'stacked-area',
            'band', 'multi-axis-line', 'nested-doughnut', 'stacked-bar-line', 'scatter-multi-axis',
            'stacked-panels', 'heatmap', 'calendar-heatmap', 'treemap', 'sankey', 'boxplot', 'violin', 'funnel',
        ], array_map(static fn (ChartKind $kind): string => $kind->value, ChartKind::cases()));
    }

    /** @return iterable<string, array{\Closure(): mixed, string}> */
    public static function refusals(): iterable
    {
        $one = [new ChartSeries('A', [1.0])];

        yield 'a two-axis line of three measures' => [
            static fn (): AtlasChart => new AtlasChart(ChartKind::MultiAxisLine, ['W1'], [new ChartSeries('A', [1.0]), new ChartSeries('B', [1.0], axis: ChartSide::Right), new ChartSeries('C', [1.0])]),
            'A multi-axis-line states exactly two measures, the second on the right axis.',
        ];
        yield 'a two-axis scatter whose second measure is on the left' => [
            static fn (): AtlasChart => new AtlasChart(ChartKind::ScatterMultiAxis, [], [new ChartSeries('A', [new ChartPoint(1.0, 2.0)]), new ChartSeries('B', [new ChartPoint(1.0, 2.0)])]),
            'A scatter-multi-axis states exactly two measures, the second on the right axis.',
        ];
        yield 'a grouped bar of five series' => [
            static fn (): AtlasChart => new AtlasChart(ChartKind::GroupedBar, ['a'], array_map(static fn (int $i): ChartSeries => new ChartSeries('S'.$i, [1.0]), range(1, 5))),
            'A grouped bar holds at most four series; this one states 5.',
        ];
        yield 'a band with no edges' => [
            static fn (): AtlasChart => new AtlasChart(ChartKind::Band, ['W1', 'W2'], [new ChartSeries('Median', [1.0, 2.0])]),
            'A band states a from and a to for every label.',
        ];
        yield 'a band an edge short' => [
            static fn (): AtlasChart => new AtlasChart(ChartKind::Band, ['W1', 'W2'], [new ChartSeries('Median', [1.0, 2.0])], band: new ChartBand([0.5], [3.0, 4.0])),
            'A band states a from and a to for every label.',
        ];
        yield 'a nested doughnut whose children do not sum' => [
            static fn (): AtlasChart => new AtlasChart(ChartKind::NestedDoughnut, ['Snares', 'Fire'], [new ChartSeries('Incidents', [5.0, 2.0])], children: [new ChartChild('Wire', 4.0, 0), new ChartChild('Burning', 2.0, 1)]),
            'The children of "Snares" sum to 4, not 5.',
        ];
        yield 'a nested doughnut whose children are out of order' => [
            static fn (): AtlasChart => new AtlasChart(ChartKind::NestedDoughnut, ['Snares', 'Fire'], [new ChartSeries('Incidents', [5.0, 2.0])], children: [new ChartChild('Burning', 2.0, 1), new ChartChild('Wire', 5.0, 0)]),
            'A nested doughnut states its children in their parents\' order; "Wire" is out of it.',
        ];
        yield 'a scatter of figures' => [
            static fn (): AtlasChart => new AtlasChart(ChartKind::Scatter, [], $one),
            'A scatter reads an item (a ChartPoint) at every point; point 0 of "A" is not one.',
        ];
        yield 'a bubble with no size' => [
            static fn (): AtlasChart => new AtlasChart(ChartKind::Bubble, [], [new ChartSeries('A', [new ChartPoint(1.0, 2.0)])]),
            'A bubble reads an item with a size (a ChartPoint stating one) at every point; point 0 of "A" is not one.',
        ];
        yield 'a floating bar of figures' => [
            static fn (): AtlasChart => new AtlasChart(ChartKind::FloatingBar, ['a'], $one),
            'A floating-bar reads a span or nothing (a ChartSpan or null) at every point; point 0 of "A" is not one.',
        ];
        yield 'a bar of spans' => [
            static fn (): AtlasChart => new AtlasChart(ChartKind::Bar, ['a'], [new ChartSeries('A', [new ChartSpan(1.0, 2.0)])]),
            'A bar reads a figure or nothing (a float or null) at every point; point 0 of "A" is not one.',
        ];
        yield 'a stacked-panels chart of one pane' => [
            static fn (): AtlasChart => new AtlasChart(ChartKind::StackedPanels, ['a'], [new ChartSeries('A', [1.0]), new ChartSeries('B', [1.0], pane: 1)], panes: [new ChartPane('A')]),
            'A stacked-panels chart states two panes, and at least one series in the second.',
        ];
        yield 'a stacked-panels chart with nothing in its second pane' => [
            static fn (): AtlasChart => new AtlasChart(ChartKind::StackedPanels, ['a'], [new ChartSeries('A', [1.0])], panes: [new ChartPane('A'), new ChartPane('B')]),
            'A stacked-panels chart states two panes, and at least one series in the second.',
        ];
        yield 'a series in a third pane' => [
            static fn (): ChartSeries => new ChartSeries('A', [1.0], pane: 2),
            'A series is drawn in the first pane (0) or the second (1); "A" names pane 2.',
        ];
        yield 'a heatmap a row short' => [
            static fn (): AtlasChart => new AtlasChart(ChartKind::Heatmap, ['00:00', '01:00'], [new ChartSeries('Incidents', [])], rows: ['Mon', 'Tue'], values: [[1.0, 2.0], [3.0]], ramp: new ChartRamp([2.0, 4.0, 6.0, 8.0])),
            'A heatmap states one value per label in every row: 2 rows of 2.',
        ];
        yield 'a heatmap with no ramp' => [
            static fn (): AtlasChart => new AtlasChart(ChartKind::Heatmap, ['00:00'], [new ChartSeries('Incidents', [])], rows: ['Mon'], values: [[1.0]]),
            'A heatmap states its ramp.',
        ];
        yield 'a ramp of three breaks' => [
            static fn (): ChartRamp => new ChartRamp([1.0, 2.0, 3.0]),
            'A ramp states 4 breaks, one between each of its 5 steps.',
        ];
        yield 'a calendar heatmap with no start' => [
            static fn (): AtlasChart => new AtlasChart(ChartKind::CalendarHeatmap, [], [new ChartSeries('Patrols', [])], values: [1.0, 2.0], ramp: new ChartRamp([1.0, 2.0, 3.0, 4.0])),
            'A calendar heatmap states its start.',
        ];
        yield 'a calendar heatmap of rows' => [
            static fn (): AtlasChart => new AtlasChart(ChartKind::CalendarHeatmap, [], [new ChartSeries('Patrols', [])], values: [[1.0]], ramp: new ChartRamp([1.0, 2.0, 3.0, 4.0]), start: new \DateTimeImmutable('2026-01-01')),
            'A calendar heatmap states one value per day from its start.',
        ];
        yield 'a treemap child of no group' => [
            static fn (): AtlasChart => new AtlasChart(ChartKind::Treemap, ['Snares'], [], children: [new ChartChild('Wire', 4.0, 1)]),
            'A treemap child names a group by position; "Wire" names 1.',
        ];
        yield 'a sankey a flow short' => [
            static fn (): AtlasChart => new AtlasChart(ChartKind::Sankey, ['Snares', 'Fire'], [], targets: [new ChartFlowTarget('Resolved'), new ChartFlowTarget('Open')], flows: [[1.0, 2.0], [3.0]]),
            'A sankey states one flow per target from every source: 2 rows of 2.',
        ];
        yield 'a flow target that is a category and a token' => [
            static fn (): ChartFlowTarget => new ChartFlowTarget('Open', swatch: 'var(--fog)', cat: 2),
            'A target wears a category or a token, never both.',
        ];
        yield 'a boxplot of figures' => [
            static fn (): AtlasChart => new AtlasChart(ChartKind::Boxplot, ['a'], $one),
            'A boxplot reads a list of readings (a ChartReadings) at every point; point 0 of "A" is not one.',
        ];
        yield 'a violin with a label that has no readings' => [
            static fn (): AtlasChart => new AtlasChart(ChartKind::Violin, ['North'], [new ChartSeries('A', [new ChartReadings([])])]),
            'A violin states a list of readings for every label; "North" has none.',
        ];
        yield 'a funnel that widens' => [
            static fn (): AtlasChart => new AtlasChart(ChartKind::Funnel, ['Reported', 'Verified'], [new ChartSeries('Incidents', [10.0, 12.0])]),
            'A funnel never widens: each stage holds at most what the stage before it held.',
        ];
        yield 'a decimated run with no time axis' => [
            static fn (): AtlasChart => new AtlasChart(ChartKind::Line, [], [new ChartSeries('Signal', [new ChartPoint(0.0, 1.0)])], decimation: new ChartDecimation()),
            'A decimated run is read over time: it states its time axis.',
        ];
        yield 'a time format of nothing' => [
            static fn (): ChartTime => new ChartTime(TimeUnit::Day, format: ''),
            'A time axis states a date-fns format or none; an empty one writes nothing.',
        ];
        yield 'quadrants of three parts' => [
            static fn (): ChartQuadrants => new ChartQuadrants(1.0, 1.0, [new ChartQuadrant('a'), new ChartQuadrant('b'), new ChartQuadrant('c')]),
            'Quadrants state four parts: top-left, top-right, bottom-right, bottom-left; 3 were stated.',
        ];
        yield 'a threshold with no token past it' => [
            static fn (): ChartThreshold => new ChartThreshold(5.0, ''),
            'A threshold states its value and the token past it.',
        ];
        yield 'a part that is a category and a token' => [
            static fn (): ChartPart => new ChartPart(cat: 1, swatch: 'var(--ok)'),
            'A part wears a category or a token, never both.',
        ];
        yield 'a span that runs backward' => [
            static fn (): ChartSpan => new ChartSpan(14.0, 6.0),
            'A span runs forward; this one runs from 14 to 6.',
        ];
        yield 'an axis whose foot is above its top' => [
            static fn (): AxisScale => new AxisScale(max: -60.0, step: 10.0),
            'An axis runs from its foot (nought, unless stated) up to its maximum in positive steps no wider than itself; 0 to -60 with step 10 is not one.',
        ];
        yield 'a scatter axis that runs downward' => [
            static fn (): ChartAxis => new ChartAxis('Change', min: 5.0, max: -5.0),
            'An axis runs upward; "Change" runs from 5 to -5.',
        ];
    }

    /** @param \Closure(): mixed $statement */
    #[DataProvider('refusals')]
    public function testAStatementItsKindCannotBeIsRefusedWithItsReason(\Closure $statement, string $reason): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($reason);

        $statement();
    }

    /** A foot below nought, and a log axis with no step of its own, are axes. */
    public function testAnAxisMayStandOffNoughtAndMayRuleItself(): void
    {
        self::assertSame(-90.0, new AxisScale(max: -60.0, step: 10.0, min: -90.0)->min);
        self::assertNull(new AxisScale(max: 100000.0, min: 10.0)->step);
    }

    /**
     * A STACK'S TOTALS AND A FIGURE'S SIZE reach the figures plugin, where the
     * plate writes one figure at the end of each stack in the stated size.
     */
    public function testTotalsAndASizeReachTheFiguresPlugin(): void
    {
        $built = self::build(new AtlasChart(
            ChartKind::Stacked,
            ['Mon', 'Tue'],
            [new ChartSeries('Filled', [4.0, 6.0]), new ChartSeries('Open', [2.0, null])],
            figures: new ChartFigures(totals: true, size: 9.0),
        ));

        self::assertSame(['unit' => '', 'precision' => 0, 'totals' => true, 'size' => 9.0], self::at($built->getOptions(), 'plugins', ChartBuilder::FIGURES_PLUGIN));
    }

    /**
     * DECIMATION: the run handed over unparsed as {x: milliseconds, y}, the
     * library's plugin configured, and the x axis time — the library thins
     * nothing on a category axis.
     *
     * @see https://www.chartjs.org/docs/latest/configuration/decimation.html — the requirements: a line, `indexAxis: 'x'`, a linear or time x axis, `parsing: false`
     */
    public function testADecimatedRunIsHandedOverUnparsedOnATimeAxis(): void
    {
        $built = self::build(new AtlasChart(
            ChartKind::Line,
            [],
            [new ChartSeries('Signal', [new ChartPoint(1793574000000.0, -74.0), new ChartPoint(1793574120000.0, -73.5)])],
            axis: new AxisScale(max: -60.0, step: 10.0, min: -90.0),
            decimation: new ChartDecimation(algorithm: DecimationAlgorithm::MinMax, samples: 500),
            time: new ChartTime(TimeUnit::Day, format: 'EEE d'),
        ));

        self::assertSame([['x' => 1793574000000.0, 'y' => -74.0], ['x' => 1793574120000.0, 'y' => -73.5]], self::at($built->getData(), 'datasets', 0, 'data'));
        self::assertFalse(self::at($built->getData(), 'datasets', 0, 'parsing'));
        self::assertSame('x', self::at($built->getData(), 'datasets', 0, 'indexAxis'));
        self::assertFalse(self::at($built->getOptions(), 'parsing'));
        self::assertSame(['enabled' => true, 'algorithm' => 'min-max', 'samples' => 500], self::at($built->getOptions(), 'plugins', 'decimation'));
        self::assertSame('time', self::at($built->getOptions(), 'scales', 'x', 'type'));
        self::assertSame('auto', self::at($built->getOptions(), 'scales', 'x', 'ticks', 'source'));
        self::assertSame(['unit' => 'day', 'displayFormats' => ['day' => 'EEE d'], 'tooltipFormat' => 'EEE d MMM yyyy', 'isoWeekday' => true], self::at($built->getOptions(), 'scales', 'x', 'time'));
        self::assertSame(-90.0, self::at($built->getOptions(), 'scales', 'y', 'min'));
    }

    /**
     * WHAT A STATEMENT NEEDS ON TOP OF CHART.JS: the date adapter where an
     * axis is time (and on stacked panels, read over time), and the package
     * that draws its kind — the names the importmap carries.
     */
    public function testAStatementNamesThePackagesItIsDrawnWith(): void
    {
        $builder = new ChartBuilder(new UxChartBuilder());

        self::assertSame([], $builder->packages(self::minimal(ChartKind::Bar)));
        self::assertSame(['chartjs-adapter-date-fns'], $builder->packages(new AtlasChart(ChartKind::Line, ['2026-11-01'], [new ChartSeries('A', [1.0])], time: new ChartTime(TimeUnit::Month))));
        self::assertSame(['chartjs-adapter-date-fns'], $builder->packages(self::minimal(ChartKind::StackedPanels)));
        self::assertSame(['chartjs-chart-matrix'], $builder->packages(self::minimal(ChartKind::Heatmap)));
        self::assertSame(['chartjs-chart-matrix'], $builder->packages(self::minimal(ChartKind::CalendarHeatmap)));
        self::assertSame(['chartjs-chart-treemap'], $builder->packages(self::minimal(ChartKind::Treemap)));
        self::assertSame(['chartjs-chart-sankey'], $builder->packages(self::minimal(ChartKind::Sankey)));
        self::assertSame(['@sgratzl/chartjs-chart-boxplot'], $builder->packages(self::minimal(ChartKind::Boxplot)));
        self::assertSame(['@sgratzl/chartjs-chart-boxplot'], $builder->packages(self::minimal(ChartKind::Violin)));
        self::assertSame(['chartjs-chart-funnel'], $builder->packages(self::minimal(ChartKind::Funnel)));
    }

    /**
     * AN EXTENSION KIND'S FIGURES ARE NOT ITS SERIES' POINTS: a grid of
     * cells, a year of days, a tree, the flows — empty where none holds a
     * figure, and drawn where one does.
     */
    public function testAnExtensionKindIsEmptyWhenItsOwnFiguresAre(): void
    {
        $ramp = new ChartRamp([1.0, 2.0, 3.0, 4.0]);

        self::assertFalse(self::minimal(ChartKind::Heatmap)->isEmpty());
        self::assertTrue(new AtlasChart(ChartKind::Heatmap, ['a'], [new ChartSeries('A', [])], rows: ['Mon'], values: [[null]], ramp: $ramp)->isEmpty());
        self::assertFalse(self::minimal(ChartKind::CalendarHeatmap)->isEmpty());
        self::assertTrue(new AtlasChart(ChartKind::CalendarHeatmap, [], [new ChartSeries('A', [])], values: [], ramp: $ramp, start: new \DateTimeImmutable('2026-01-01'))->isEmpty());
        self::assertFalse(self::minimal(ChartKind::Treemap)->isEmpty());
        self::assertTrue(new AtlasChart(ChartKind::Treemap, ['a'], [])->isEmpty());
        self::assertFalse(self::minimal(ChartKind::Sankey)->isEmpty());
        self::assertTrue(new AtlasChart(ChartKind::Sankey, ['a'], [], targets: [new ChartFlowTarget('b')], flows: [[0.0]])->isEmpty());
        self::assertFalse(self::minimal(ChartKind::Boxplot)->isEmpty());
    }

    /**
     * A NAME ON A HORIZONTAL BAR'S AXIS is never skipped, and one longer than
     * eighteen characters is drawn on two lines, broken at the space nearest
     * its middle; a bar read upright keeps its names as they are.
     */
    public function testALongNameBesideASidewaysBarIsDrawnOnTwoLines(): void
    {
        $names = ['Kilimani Crater Conservation Area', 'Mbuyu Game Reserve', 'Supercalifragilisticexpialidocious'];
        $series = [new ChartSeries('Patrols', [1.0, 2.0, 3.0])];

        $ranked = self::build(new AtlasChart(ChartKind::Ranked, $names, $series));
        self::assertSame([['Kilimani Crater', 'Conservation Area'], 'Mbuyu Game Reserve', 'Supercalifragilisticexpialidocious'], self::at($ranked->getData(), 'labels'));
        self::assertFalse(self::at($ranked->getOptions(), 'scales', 'y', 'ticks', 'autoSkip'));

        self::assertSame($names, self::at(self::build(new AtlasChart(ChartKind::Bar, $names, $series))->getData(), 'labels'));
    }

    /** Every kind builds, as the Chart.js type behind it. */
    public function testEveryKindBuildsAsItsChartJsType(): void
    {
        $types = [];
        foreach (ChartKind::cases() as $kind) {
            $types[$kind->value] = self::build(self::minimal($kind))->getType();
        }

        self::assertSame([
            'line' => 6, 'bar' => 11, 'doughnut' => 3, 'pie' => 1, 'polarArea' => 1, 'radar' => 1, 'scatter' => 2, 'bubble' => 1,
            'matrix' => 2, 'treemap' => 1, 'sankey' => 1, 'boxplot' => 1, 'violin' => 1, 'funnel' => 1,
        ], array_count_values($types));
    }

    private static function minimal(ChartKind $kind): AtlasChart
    {
        return match ($kind) {
            ChartKind::Scatter => new AtlasChart($kind, [], [new ChartSeries('A', [new ChartPoint(1.0, 2.0)])]),
            ChartKind::ScatterMultiAxis => new AtlasChart($kind, [], [new ChartSeries('A', [new ChartPoint(1.0, 2.0)]), new ChartSeries('B', [new ChartPoint(1.0, 3.0)], axis: ChartSide::Right)]),
            ChartKind::Bubble => new AtlasChart($kind, ['a'], [new ChartSeries('A', [new ChartPoint(1.0, 2.0, 3.0)])]),
            ChartKind::FloatingBar => new AtlasChart($kind, ['a'], [new ChartSeries('A', [new ChartSpan(6.0, 14.0)])]),
            ChartKind::MultiAxisLine => new AtlasChart($kind, ['a'], [new ChartSeries('A', [1.0]), new ChartSeries('B', [2.0], axis: ChartSide::Right)]),
            ChartKind::Band => new AtlasChart($kind, ['a'], [new ChartSeries('A', [1.0])], band: new ChartBand([0.5], [2.0])),
            ChartKind::NestedDoughnut => new AtlasChart($kind, ['a'], [new ChartSeries('A', [3.0])], children: [new ChartChild('b', 3.0, 0)]),
            ChartKind::StackedPanels => new AtlasChart($kind, ['a'], [new ChartSeries('A', [1.0]), new ChartSeries('B', [2.0], pane: 1, bar: true)], panes: [new ChartPane('A', 2.0), new ChartPane('B')]),
            ChartKind::Heatmap => new AtlasChart($kind, ['00:00'], [new ChartSeries('A', [])], rows: ['Mon'], values: [[3.0]], ramp: new ChartRamp([1.0, 2.0, 3.0, 4.0])),
            ChartKind::CalendarHeatmap => new AtlasChart($kind, [], [new ChartSeries('A', [])], values: [1.0, 0.0], ramp: new ChartRamp([1.0, 2.0, 3.0, 4.0]), start: new \DateTimeImmutable('2026-01-01')),
            ChartKind::Treemap => new AtlasChart($kind, ['a'], [], children: [new ChartChild('b', 3.0, 0)]),
            ChartKind::Sankey => new AtlasChart($kind, ['a'], [], targets: [new ChartFlowTarget('b')], flows: [[2.0]]),
            ChartKind::Boxplot, ChartKind::Violin => new AtlasChart($kind, ['a'], [new ChartSeries('A', [new ChartReadings([1.0, 2.0, 4.0])])]),
            default => new AtlasChart($kind, ['a'], [new ChartSeries('A', [1.0])]),
        };
    }

    private static function build(AtlasChart $chart): UxChart
    {
        return new ChartBuilder(new UxChartBuilder())->chart($chart);
    }

    /** @param array<array-key, mixed> $payload */
    private static function at(array $payload, int|string ...$path): mixed
    {
        $value = $payload;
        foreach ($path as $key) {
            self::assertIsArray($value);
            self::assertArrayHasKey($key, $value);
            $value = $value[$key];
        }

        return $value;
    }
}
