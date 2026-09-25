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
 * THE TICKS, THE GRID AND THE AXIS LINE ARE THEMED WHERE THE CHART IS DRAWN.
 *
 * The builder states them as tokens — `var(--fog)`, `var(--font-mono)`, and
 * `color-mix(in srgb, var(--fog) 22%, transparent)` the way the design writes
 * a faded line — and a canvas parses none of them. The plate resolves every
 * one against the element it is mounted on, at mount and again when the
 * theme flips.
 *
 * AND A FLIP RE-READS THE TOKENS, NOT THE COLOURS. Chart.js keeps the very
 * object it was handed (`initConfig` mutates it in place), so once the plate
 * has resolved the tokens there are none left in it; the plate keeps a copy
 * of the configuration as stated and re-derives the drawn one from that.
 *
 * A text check over the shipped asset, like the swatch test beside it.
 */
final class ChartTickThemeTest extends TestCase
{
    public function testTheScalesTicksGridAndAxisLineAreResolvedLikeASeries(): void
    {
        $js = self::controllerJs();

        foreach (["[scale.ticks, 'color']", "[scale.ticks?.font, 'family']", "[scale.grid, 'color']", "[scale.border, 'color']"] as $door) {
            self::assertStringContainsString($door, $js);
        }
        self::assertStringContainsString('Object.values(config?.options?.scales ?? {})', $js);
    }

    /** A faded line is the token at an alpha, turned into a colour the canvas reads. */
    public function testAColorMixOverATokenBecomesTheResolvedColourAtThatAlpha(): void
    {
        $js = self::controllerJs();

        self::assertStringContainsString('const MIX = /^color-mix\\(\\s*in srgb\\s*,\\s*var\\(\\s*(--[a-zA-Z0-9-]+)\\s*\\)\\s+([\\d.]+)%\\s*,\\s*transparent\\s*\\)$/;', $js);
        self::assertStringContainsString('fade(this.token(mix[1], value), Number(mix[2]) / 100)', $js);
    }

    /** The stated configuration is copied before anything is resolved, and a flip starts from the copy. */
    public function testAFlipReDerivesTheDrawnConfigurationFromTheStatedOne(): void
    {
        $js = self::controllerJs();

        $copied = strpos($js, 'this.stated = structuredClone(event.detail.config);');
        self::assertNotFalse($copied);
        self::assertLessThan(strpos($js, 'this.paint(event.detail.config);'), $copied);

        self::assertStringContainsString('const fresh = structuredClone(this.stated);', $js);
        self::assertStringContainsString('this.chart.options = fresh.options;', $js);
        self::assertStringNotContainsString('this.chart.config._config', $js);
    }

    /** Each method's docblock sits above that method. */
    public function testTheFiguresDocblockSitsAboveTheFiguresPlugin(): void
    {
        $js = self::controllerJs();

        $doc = strpos($js, 'THE FIGURES PLUGIN');
        self::assertNotFalse($doc);
        self::assertGreaterThan(strpos($js, 'hairline(config) {'), $doc);
        self::assertLessThan(strpos($js, 'figure(config) {'), $doc);
    }

    private static function controllerJs(): string
    {
        return (string) file_get_contents(\dirname(__DIR__, 3).'/assets/controllers/chart_plate_controller.js');
    }
}
