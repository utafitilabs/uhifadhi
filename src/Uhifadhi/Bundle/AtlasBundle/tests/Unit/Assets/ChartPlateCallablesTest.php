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

namespace Uhifadhi\Bundle\AtlasBundle\Tests\Unit\Assets;

use PHPUnit\Framework\TestCase;
use Uhifadhi\Bundle\AtlasBundle\Chart\ChartBuilder;

/**
 * WHAT THE BUILDER STATES AS A FACT, THE PLATE TURNS INTO A FUNCTION.
 *
 * JSON carries no function, so the builder writes a gradient, a dashed
 * silence, a log axis, nought's stronger line, the legend's filter and a
 * nested doughnut's rows as facts — under an `atlas…` key, or a plugin's
 * id — and the plate puts the function on. A text check over the shipped
 * asset, like the figures test beside it: it catches a seam unpicked, a key
 * renamed on one side only, a plugin id drifting from the builder's.
 *
 * @see https://www.chartjs.org/docs/latest/general/options.html#scriptable-options
 * @see https://www.chartjs.org/docs/latest/charts/line.html#segment
 * @see https://www.chartjs.org/docs/latest/configuration/legend.html#legend-label-configuration — `filter`, `generateLabels`
 * @see https://www.chartjs.org/docs/latest/configuration/device-pixel-ratio.html
 */
final class ChartPlateCallablesTest extends TestCase
{
    public function testEveryInlinePluginIsPutOnUnderTheIdTheBuilderWritesItsOptionsBy(): void
    {
        $js = self::controllerJs();

        foreach ([
            'CENTRE' => ChartBuilder::CENTRE_PLUGIN,
            'QUADRANTS' => ChartBuilder::QUADRANTS_PLUGIN,
            'EMPTY' => ChartBuilder::EMPTY_PLUGIN,
            'FRAME' => ChartBuilder::FRAME_PLUGIN,
            'RANGES' => ChartBuilder::RANGES_PLUGIN,
            'PANELS' => ChartBuilder::PANELS_PLUGIN,
            'AXIS_ENDS' => ChartBuilder::AXIS_ENDS_PLUGIN,
        ] as $constant => $id) {
            self::assertStringContainsString(\sprintf("const %s = '%s';", $constant, $id), $js);
        }
        self::assertStringContainsString('if (stated[CENTRE])', $js);
        self::assertStringContainsString('if (stated[QUADRANTS])', $js);
        self::assertStringContainsString('id: EMPTY, afterDraw:', $js);
        self::assertStringContainsString('id: FRAME, beforeDraw:', $js);
        self::assertStringContainsString('id: AXIS_ENDS, afterDraw:', $js);
        self::assertStringContainsString("ctx.fontVariantCaps = 'all-small-caps';", $js);
    }

    /**
     * WHAT AN EXTENSION KIND STATES AS A FACT: a cell's share of the plot, a
     * treemap's colours and words per rectangle, a flow's two colours, a
     * ramp's legend rows, a calendar's month names — each put on as the
     * function the package calls.
     */
    public function testTheExtensionKindsFactsBecomeTheFunctionsTheirPackagesCall(): void
    {
        $js = self::controllerJs();
        $builder = (string) file_get_contents(\dirname(__DIR__, 3).'/Chart/ChartBuilder.php');

        foreach (['atlasCell', 'atlasTree', 'atlasFlows', 'atlasRamp', 'atlasNames'] as $fact) {
            self::assertStringContainsString("'".$fact."'", $builder, $fact.' is stated by the builder');
            self::assertStringContainsString('delete '.(str_starts_with($fact, 'atlasR') || str_starts_with($fact, 'atlasN') ? ('atlasRamp' === $fact ? 'labels' : 'ticks') : 'dataset').'.'.$fact.';', $js, $fact.' is read and taken off by the plate');
        }
        self::assertStringContainsString('dataset.width = (ctx) =>', $js);
        self::assertStringContainsString('dataset.height = (ctx) =>', $js);
        self::assertStringContainsString('dataset.colorFrom = (ctx) => flows.from[ctx.dataIndex];', $js);
        self::assertStringContainsString('labels.generateLabels = () => bands.words.map(', $js);
        self::assertStringContainsString('ticks.callback = (value, i) => names[i] || \'\';', $js);
    }

    /**
     * A PACKAGE IS LOADED ONLY FOR THE CHART THAT NEEDS IT: imported lazily,
     * registered with Chart.js, and the held canvas put on the page after.
     */
    public function testAHeldChartIsReleasedOnceItsPackagesAreRegistered(): void
    {
        $js = self::controllerJs();

        self::assertStringContainsString("import { Chart } from 'chart.js';", $js);
        self::assertStringContainsString('static values = { needs: Array };', $js);
        self::assertStringContainsString("static targets = ['held'];", $js);
        self::assertStringContainsString('Chart.register(...parts)', $js);
        self::assertStringContainsString('Promise.all(this.needsValue.map(load)).then(', $js);
        self::assertStringContainsString('held.replaceWith(held.content.cloneNode(true))', $js);
    }

    /** At least twice the pixels, on every paint, so a flip keeps it. */
    public function testAChartIsDrawnAtTwiceThePixelsAtLeast(): void
    {
        $js = self::controllerJs();

        self::assertStringContainsString('const MIN_PIXEL_RATIO = 2;', $js);
        self::assertStringContainsString('config.options.devicePixelRatio = Math.max(MIN_PIXEL_RATIO, window.devicePixelRatio || 1);', $js);
    }

    public function testTheFactsTheBuilderStatesBecomeTheirFunctions(): void
    {
        $js = self::controllerJs();
        $builder = (string) file_get_contents(\dirname(__DIR__, 3).'/Chart/ChartBuilder.php');

        foreach (['atlasLog', 'atlasClock', 'atlasFilter', 'atlasRing', 'zeroColor', 'gapStyle', 'gradient'] as $fact) {
            self::assertStringContainsString("'".$fact."'", $builder, $fact.' is stated by the builder');
            self::assertStringContainsString($fact, $js, $fact.' is read by the plate');
        }
        self::assertStringContainsString('dataset.segment = {', $js);
        self::assertStringContainsString('createLinearGradient(0, area.top, 0, area.bottom)', $js);
        self::assertStringContainsString('createRadialGradient(r.xCenter, r.yCenter, 0, r.xCenter, r.yCenter, r.drawingArea)', $js);
        self::assertStringContainsString('labels.filter = (row, data) => !data.datasets[row.datasetIndex][flag];', $js);
        self::assertStringContainsString('ticks.callback = (value) => (power(value)', $js);
        self::assertStringContainsString('grid.color = (ctx) => (ctx.tick && 0 === ctx.tick.value ? zero : rest);', $js);
        self::assertStringContainsString('label: (item) => this.tooltipLabel(item, tip, type)', $js);
    }

    /** The hatch is a pattern of the part's own colour, laid where the builder stated a texture. */
    public function testATexturedPartIsHatchedInItsOwnColour(): void
    {
        $js = self::controllerJs();

        self::assertStringContainsString('dataset.texture[i] ? this.hatch(color) : color', $js);
        self::assertStringContainsString("ctx.createPattern(tile, 'repeat') || color", $js);
    }

    private static function controllerJs(): string
    {
        return (string) file_get_contents(\dirname(__DIR__, 3).'/assets/controllers/chart_plate_controller.js');
    }
}
