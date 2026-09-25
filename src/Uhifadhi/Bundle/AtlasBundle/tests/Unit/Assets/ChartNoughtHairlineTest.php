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
 * A NOUGHT'S HAIRLINE IS FADED BY THE PLATE.
 *
 * The builder asks for a two-pixel stub (`minBarLength`) and states the fade
 * under `options.plugins.noughts`; the plate, which is where a series' token
 * becomes a color, turns that color into a per-bar list in which every
 * nought wears the same color at the stated opacity. Chart.js reads a list
 * as one value per bar: "indexable options" — "an array in which each item
 * corresponds to the element at the same index"
 * (https://www.chartjs.org/docs/latest/general/options.html#indexable-options).
 *
 * A text check over the shipped asset, like the figures test beside it.
 */
final class ChartNoughtHairlineTest extends TestCase
{
    public function testThePlateReadsTheFadeUnderTheIdTheBuilderWritesItBy(): void
    {
        $js = self::controllerJs();

        self::assertStringContainsString("const NOUGHTS = '".ChartBuilder::NOUGHTS."';", $js);
        self::assertStringContainsString('config.options?.plugins?.[NOUGHTS]', $js);
    }

    /** Only a nought is faded, fill and stroke alike, and only after the token became a color. */
    public function testEveryNoughtAndOnlyANoughtWearsTheFadedColor(): void
    {
        $js = self::controllerJs();

        self::assertStringContainsString('dataset.data.map((value) => (0 === value ? fade(color, opacity) : color))', $js);
        self::assertStringContainsString("for (const property of ['backgroundColor', 'borderColor'])", $js);
        self::assertLessThan(strpos($js, 'this.hairline(event.detail.config)'), strpos($js, 'this.paint(event.detail.config)'));
    }

    /** The two shapes a resolved token takes — a hex and an rgb() — both carry an alpha. */
    public function testTheFadeKeepsTheHueAndAddsTheAlpha(): void
    {
        $js = self::controllerJs();

        self::assertStringContainsString('function fade(color, opacity)', $js);
        self::assertStringContainsString('Math.round(opacity * 255)', $js);
        self::assertStringContainsString('/ ${opacity})', $js);
    }

    private static function controllerJs(): string
    {
        return (string) file_get_contents(\dirname(__DIR__, 3).'/assets/controllers/chart_plate_controller.js');
    }
}
