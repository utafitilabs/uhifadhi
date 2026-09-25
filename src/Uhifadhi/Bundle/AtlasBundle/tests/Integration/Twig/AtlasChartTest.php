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

namespace Uhifadhi\Bundle\AtlasBundle\Tests\Integration\Twig;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Uhifadhi\Bundle\AtlasBundle\Model\AtlasChart;
use Uhifadhi\Bundle\AtlasBundle\Model\AxisScale;
use Uhifadhi\Bundle\AtlasBundle\Model\ChartFigures;
use Uhifadhi\Bundle\AtlasBundle\Model\ChartKind;
use Uhifadhi\Bundle\AtlasBundle\Model\ChartLegend;
use Uhifadhi\Bundle\AtlasBundle\Model\ChartNoughts;
use Uhifadhi\Bundle\AtlasBundle\Model\ChartSeries;
use Uhifadhi\Bundle\AtlasBundle\Tests\Integration\TestKernel;
use Uhifadhi\Bundle\AtlasBundle\Twig\ChartRuntime;

/**
 * `atlas_chart()` THROUGH THE REAL LIBRARY, in a real container.
 *
 * A unit test can say what the builder produces; only this can say that a
 * module writing one line gets a chart — the runtime resolved, the
 * library's own canvas rendered, the card around it drawn.
 *
 * IT IS `atlas_chart` AND NOT `render_chart`, because the library ships a
 * function of that name and this platform's chart is not the library's: a
 * module states a kind and a series, and what that looks like is the
 * atlas's to decide. Both exist; the one to write is ours.
 */
#[CoversClass(ChartRuntime::class)]
final class AtlasChartTest extends TestCase
{
    public function testAStatedChartBecomesACardWithTheLibrarysCanvasInIt(): void
    {
        $html = self::render(new AtlasChart(
            ChartKind::Line,
            ['apr', 'may'],
            [new ChartSeries('Coverage', [58.0, 61.0])],
            target: 60.0,
            unit: '%',
        ), 'Coverage, twelve periods', 'What the organization covered.');

        self::assertStringContainsString('class="chart-plate"', $html);
        self::assertStringContainsString('Coverage, twelve periods', $html);
        self::assertStringContainsString('What the organization covered.', $html);
        // The library's own controller, mounted on its own element.
        self::assertStringContainsString('symfony--ux-chartjs--chart', $html);
        self::assertStringContainsString('&quot;type&quot;:&quot;line&quot;', $html);
    }

    /**
     * A CHART NOBODY PUBLISHED A POINT IN IS NOT DRAWN. A box with axes and
     * no line in it reads as a measurement of nought.
     */
    public function testAChartOfNothingSaysSoInsteadOfDrawingAnEmptyBox(): void
    {
        $html = self::render(new AtlasChart(ChartKind::Bar, ['apr'], [new ChartSeries('X', [null])]));

        self::assertStringContainsString('No figure for this period', $html);
        self::assertStringContainsString('which is not a nought', $html);
        self::assertStringNotContainsString('symfony--ux-chartjs--chart', $html);
    }

    /** The height comes through the same door a plate's does. */
    public function testACustomPropertySizesTheBoxAndNotTheCanvas(): void
    {
        $html = self::render(
            new AtlasChart(ChartKind::Bar, ['apr'], [new ChartSeries('X', [1.0])]),
            attributes: ['--chart-height' => '240px', 'aria-label' => 'Seats'],
        );

        self::assertStringContainsString('style="--chart-height:240px"', $html);
        self::assertStringContainsString('data-controller="uhifadhi--atlas-bundle--chart-plate"', $html);
        self::assertStringContainsString('aria-label="Seats"', $html);
        self::assertStringNotContainsString('--chart-height', substr($html, strpos($html, 'chart-box') ?: 0));
    }

    /**
     * A RANKING, FIGURED AND SCALED, ALL THE WAY TO THE MARKUP A BROWSER IS
     * SERVED: the index axis, the stated maximum and step, and the figures'
     * options are in the view the library's controller reads.
     */
    public function testARankedChartCarriesItsAxisAndFiguresIntoTheServedView(): void
    {
        $html = self::render(new AtlasChart(
            ChartKind::Ranked,
            ['Endulen', 'Lerai'],
            [new ChartSeries('Patrols', [46.0, 27.0])],
            unit: 'patrols',
            axis: AxisScale::covering(46.0, 3),
            figures: new ChartFigures(),
        ));

        $view = self::view($html);
        self::assertSame('bar', $view['type']);
        self::assertSame('y', self::at($view, 'options', 'indexAxis'));
        self::assertSame(48, self::at($view, 'options', 'scales', 'x', 'max'));
        self::assertSame(16, self::at($view, 'options', 'scales', 'x', 'ticks', 'stepSize'));
        self::assertSame(['unit' => '', 'precision' => 0], self::at($view, 'options', 'plugins', 'figures'));
        // No chip legend was asked for, so none is drawn — and one series draws no canvas legend either.
        self::assertStringNotContainsString('chart-legend', $html);
        self::assertFalse(self::at($view, 'options', 'plugins', 'legend', 'display'));
    }

    /**
     * THE CHIP LEGEND IS THE PLATE'S OWN MARKUP: one house pill per series
     * under the box, wearing the series' category through the shell's
     * `data-cat` door, the target as the idle pill — and the canvas legend
     * off, so nothing is named twice.
     */
    public function testTheChipLegendIsDrawnUnderThePlateFromTheSeries(): void
    {
        $html = self::render(new AtlasChart(
            ChartKind::Bar,
            ['W1', 'W2'],
            [new ChartSeries('foot', [34.0, 28.0], cat: 1), new ChartSeries('drone', [6.0, 4.0], cat: 3), new ChartSeries('legacy', [1.0, 1.0], '#E05B41')],
            target: 30.0,
            legend: ChartLegend::Chips,
        ));

        self::assertStringContainsString('<div class="chart-legend">', $html);
        self::assertStringContainsString('<span class="chip" data-cat="1">foot</span>', $html);
        self::assertStringContainsString('<span class="chip" data-cat="3">drone</span>', $html);
        self::assertStringContainsString('<span class="chip" style="--cat:#E05B41">legacy</span>', $html);
        self::assertStringContainsString('<span class="chip idle">Target</span>', $html);
        self::assertFalse(self::at(self::view($html), 'options', 'plugins', 'legend', 'display'));
        // The legend follows the box and precedes the caption.
        self::assertLessThan(strpos($html, 'chart-legend'), strpos($html, 'chart-box'));
    }

    /**
     * AN ACCENT SERIES WITH ITS NOUGHTS AS HAIRLINES, all the way to the
     * served view: the accent token, the two-pixel minimum, the fade the
     * plate reads, the column width — and its chip is the accent pill.
     */
    public function testAnAccentSeriesWithHairlineNoughtsCarriesAllOfItIntoTheServedView(): void
    {
        $html = self::render(new AtlasChart(
            ChartKind::Bar,
            ['north', 'south'],
            [new ChartSeries('Assignments', [31.0, 0.0], accent: true)],
            axis: new AxisScale(32.0, 16.0),
            legend: ChartLegend::Chips,
            noughts: ChartNoughts::Hairline,
            barWidth: 40.0,
        ));

        $view = self::view($html);
        $dataset = self::at($view, 'data', 'datasets', '0');
        self::assertIsArray($dataset);
        self::assertSame('var(--acc)', $dataset['backgroundColor'] ?? null);
        self::assertSame(2, $dataset['minBarLength'] ?? null);
        self::assertSame(40, $dataset['maxBarThickness'] ?? null);
        self::assertSame(['opacity' => 0.28], self::at($view, 'options', 'plugins', 'noughts'));
        self::assertStringContainsString('<span class="chip acc">Assignments</span>', $html);
    }

    /**
     * THE VIEW THE LIBRARY'S CONTROLLER READS, decoded out of the served
     * attribute — the one place a browser learns what the chart is.
     *
     * @return array<array-key, mixed>
     */
    private static function view(string $html): array
    {
        self::assertSame(1, preg_match('/data-symfony--ux-chartjs--chart-view-value="([^"]+)"/', $html, $match));
        $attribute = $match[1] ?? null;
        self::assertIsString($attribute);
        $view = json_decode(html_entity_decode($attribute, \ENT_QUOTES | \ENT_HTML5), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($view);

        return $view;
    }

    /**
     * A value down a path of keys, each block asserted to exist rather than
     * cast — the payload is the library's plain array.
     *
     * @param array<array-key, mixed> $payload
     */
    private static function at(array $payload, string ...$path): mixed
    {
        $value = $payload;
        foreach ($path as $key) {
            self::assertIsArray($value);
            self::assertArrayHasKey($key, $value);
            $value = $value[$key];
        }

        return $value;
    }

    /** @param array<string, bool|string> $attributes */
    private static function render(AtlasChart $chart, string $title = '', string $caption = '', array $attributes = []): string
    {
        $kernel = new TestKernel('test', true);
        $kernel->boot();

        /** @var Environment $twig */
        $twig = $kernel->getContainer()->get('test.twig');
        $html = $twig->createTemplate('{{ atlas_chart(chart, title, caption, attributes) }}')->render([
            'chart' => $chart,
            'title' => $title,
            'caption' => $caption,
            'attributes' => $attributes,
        ]);

        $kernel->shutdown();

        return $html;
    }
}
