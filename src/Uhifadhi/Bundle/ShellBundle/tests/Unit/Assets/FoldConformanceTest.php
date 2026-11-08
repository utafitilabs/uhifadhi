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

namespace Uhifadhi\Bundle\ShellBundle\Tests\Unit\Assets;

use Uhifadhi\Test\FoldConformanceTestCase;

/**
 * NO CORE SHEET EASES A HOUSE FOLD OF ITS OWN: every sheet the core ships,
 * the shell's with its fold block taken out, since that block is the one place
 * a fold eases.
 */
final class FoldConformanceTest extends FoldConformanceTestCase
{
    private const string OPENS = '/* ==================== HOUSE — THE FOLD';
    private const string CLOSES = '/* ==================== /HOUSE — THE FOLD ==================== */';

    public function testTheShellSheetHasOneFoldBlock(): void
    {
        $sheet = (string) file_get_contents(self::shellSheet());

        self::assertSame(1, substr_count($sheet, self::OPENS));
        self::assertSame(1, substr_count($sheet, self::CLOSES));
        self::assertNotSame([], self::foldTransitions(self::foldBlock($sheet)), 'the block eases the folds');
    }

    /** The shell sheet's fold block, from its opening banner to its closing one. */
    public static function foldBlock(string $sheet): string
    {
        $start = strpos($sheet, self::OPENS);
        $end = strpos($sheet, self::CLOSES);
        self::assertIsInt($start, 'the shell sheet opens its fold block');
        self::assertIsInt($end, 'the shell sheet closes its fold block');

        return substr($sheet, $start, $end - $start);
    }

    protected static function sheets(): iterable
    {
        $bundles = \dirname(__DIR__, 4);
        foreach (glob($bundles.'/*/public/*.css') ?: [] as $path) {
            $css = (string) file_get_contents($path);
            if ($path === self::shellSheet()) {
                $css = str_replace(self::foldBlock($css), '', $css);
            }

            yield substr($path, \strlen($bundles) + 1) => $css;
        }
    }

    private static function shellSheet(): string
    {
        return \dirname(__DIR__, 3).'/public/shell.css';
    }
}
