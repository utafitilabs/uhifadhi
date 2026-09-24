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
 * THE FIGURE ON THE BAR IS DRAWN BY THE PLATE, WITH NO LIBRARY BEHIND IT.
 *
 * Chart.js core draws no value labels, and the host's importmap ships
 * `chart.js` and nothing beside it — no datalabels plugin, and this bundle
 * adds no dependency to a host. What the docs do give is an INLINE PLUGIN:
 * a `plugins: [...]` array on the chart config, each entry carrying an `id`
 * and the draw hooks it wants. The UX bridge hands the whole config to the
 * plate in `chartjs:pre-connect` and then to `new Chart(ctx, config)`, so
 * the plate can put the plugin on before the chart is built.
 *
 * A text check over the shipped asset, like the swatch test beside it: it
 * catches the seam being unpicked — the hook renamed, the id drifting from
 * the one the builder writes its options under, the figure painted in a
 * colour the theme does not own.
 *
 * @see https://www.chartjs.org/docs/latest/developers/plugins.html — "const chart = new Chart(ctx, { plugins: [{ beforeInit: function(chart, args, options) {} }] })"; "Plugins must define a unique id in order to be configurable"; "Plugin options are located under the options.plugins config and are scoped by the plugin ID"
 * @see https://www.chartjs.org/docs/latest/api/interfaces/Plugin.html — `afterDatasetsDraw(chart, args, options)`: "Called after the chart datasets have been drawn"
 * @see https://www.chartjs.org/docs/latest/developers/api.html — `.getDatasetMeta(index)`: "The data property of the metadata will contain information about each point, bar, etc."; `.isDatasetVisible(datasetIndex)`
 * @see vendor/symfony/ux-chartjs/assets/dist/controller.js — `this.dispatchEvent("pre-connect", { options: payload.options, config: payload })` then `new Chart(canvasContext, payload)`
 */
final class ChartFiguresPluginTest extends TestCase
{
    public function testThePlateAddsAnInlinePluginUnderTheIdTheBuilderConfiguresItBy(): void
    {
        $js = self::controllerJs();

        self::assertStringContainsString("const FIGURES = '".ChartBuilder::FIGURES_PLUGIN."';", $js);
        self::assertStringContainsString('config.options?.plugins?.[FIGURES]', $js);
        self::assertStringContainsString('config.plugins = [...(config.plugins ?? []), ', $js);
        self::assertStringContainsString('id: FIGURES', $js);
        self::assertStringContainsString('afterDatasetsDraw(chart, args, options)', $js);
    }

    /** Every bar of every visible dataset, read from the library's own metadata. */
    public function testEveryVisibleBarGetsItsFigureFromTheChartsOwnMetadata(): void
    {
        $js = self::controllerJs();

        self::assertStringContainsString('chart.isDatasetVisible(index)', $js);
        self::assertStringContainsString('chart.getDatasetMeta(index).data', $js);
        // A hole stays a hole: a null point has no figure.
        self::assertStringContainsString('null === value || undefined === value', $js);
    }

    /**
     * SIDEWAYS ON A RANKING, UPRIGHT ON A COLUMN. The library says which by
     * the same option that made the chart horizontal.
     */
    public function testTheFigureSitsPastTheBarEndWhicheverWayTheBarsRun(): void
    {
        $js = self::controllerJs();

        self::assertStringContainsString("'y' === chart.options.indexAxis", $js);
        self::assertStringContainsString("ctx.textAlign = 'left'", $js);
        self::assertStringContainsString("ctx.textAlign = 'center'", $js);
    }

    /**
     * THE INK IS THE PLATE'S, NOT A HEX. The figure is the same ink as the
     * caption beside it, read from the element the plate is mounted on, so
     * it turns over with the theme like every other word on the page.
     */
    public function testTheFigureIsWrittenInThePlatesOwnInkAndMonoFace(): void
    {
        $js = self::controllerJs();

        self::assertStringContainsString('getComputedStyle(this.element)', $js);
        self::assertStringContainsString("getPropertyValue('--font-mono')", $js);
        self::assertStringContainsString('ctx.fillStyle = ink.color', $js);
        self::assertDoesNotMatchRegularExpression('/#[0-9a-fA-F]{6}\b/', $js);
    }

    private static function controllerJs(): string
    {
        return (string) file_get_contents(\dirname(__DIR__, 3).'/assets/controllers/chart_plate_controller.js');
    }
}
