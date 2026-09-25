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

/**
 * A CHART'S COLOURS ARE TOKENS UNTIL THE MOMENT IT IS DRAWN.
 *
 * Chart.js paints onto a canvas, and a canvas is not the document: a
 * `var(--cat-3)` handed to the 2D context is a string it cannot parse, and
 * the series draws as nothing. That is why the builder once shipped six hex
 * colours — a seventh palette beside the one the product has, right on the
 * night canvas and wrong on paper.
 *
 * SO THE TOKEN TRAVELS AND THE CONTROLLER RESOLVES IT, at mount and again
 * when the theme flips, exactly as the map plate resolves a layer's swatch.
 * A text check over the shipped asset: it catches the seam being unpicked —
 * the resolution dropped, the theme watch removed, the cache left uncleared
 * so a flip redraws yesterday's palette.
 */
final class ChartSwatchTokenTest extends TestCase
{
    public function testTheControllerResolvesATokenAgainstTheElementItIsMountedOn(): void
    {
        $js = self::controllerJs();

        self::assertStringContainsString("addEventListener('chartjs:pre-connect'", $js);
        self::assertStringContainsString('getComputedStyle(this.element).getPropertyValue(name)', $js);
        self::assertStringContainsString('const TOKEN = /^var\\(\\s*(--[a-zA-Z0-9-]+)\\s*\\)$/;', $js);
    }

    /**
     * EVERY PROPERTY A COLOUR CAN REACH A DATASET THROUGH. Resolving only
     * `backgroundColor` left a line chart's stroke as an unparseable string,
     * which is the same defect the map plate had when it resolved the layer's
     * swatch and not the per-feature rules.
     */
    public function testEveryPaintedPropertyIsResolvedAndNotOnlyTheFill(): void
    {
        $js = self::controllerJs();

        foreach (['backgroundColor', 'borderColor', 'pointBackgroundColor', 'pointBorderColor'] as $property) {
            self::assertStringContainsString("'".$property."'", $js);
        }
    }

    /**
     * AND AGAIN WHEN THE THEME FLIPS. `getComputedStyle` answers with what
     * the token means right now; a chart that resolved once would be drawn in
     * yesterday's palette until the page was reloaded.
     */
    public function testTheChartIsRepaintedWhenTheThemeFlips(): void
    {
        $js = self::controllerJs();

        self::assertStringContainsString("attributeFilter: ['class']", $js);
        self::assertStringContainsString('this.swatches.clear();', $js);
        self::assertStringContainsString("this.chart.update('none');", $js);
    }

    /** The builder ships no colour of its own — a series is a category. */
    public function testTheBuilderNamesNoColour(): void
    {
        $builder = (string) file_get_contents(\dirname(__DIR__, 3).'/Chart/ChartBuilder.php');
        $declarations = (string) preg_replace('~/\*.*?\*/~s', '', $builder);

        self::assertDoesNotMatchRegularExpression(
            '/#[0-9a-fA-F]{6}\b/',
            $declarations,
            'The builder picked six colours. A series is a category, resolved where it is drawn.',
        );
        self::assertStringContainsString("\\sprintf('var(--cat-%d)'", $declarations);
    }

    private static function controllerJs(): string
    {
        return (string) file_get_contents(\dirname(__DIR__, 3).'/assets/controllers/chart_plate_controller.js');
    }
}
