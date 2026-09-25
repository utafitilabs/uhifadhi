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

namespace Uhifadhi\Bundle\AtlasBundle\Tests\Unit\Template;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Uhifadhi\Bundle\AtlasBundle\AtlasBundle;
use Uhifadhi\Bundle\AtlasBundle\Shell\AtlasStylesheets;

/**
 * A MONTH DRAWN ON A PAGE THAT LINKS NO MAP SHEET IS STILL A MONTH.
 *
 * THE BUG, NAMED: the roster's Calendar tab drew `atlas_calendar()` and
 * got a LIST — seven columns of nothing — because `.cal` lived in the
 * map's stylesheet and only map pages link that. The markup was right,
 * the page returned 200, and no test in the fleet could see it.
 *
 * SO THE COMPONENT'S RULES LIVE WITH THE COMPONENT, in a sheet the
 * shell links in every head. This asserts the two halves of that: the
 * grid is in the month's own sheet and no longer in the map's, and the
 * bundle publishes that sheet to the shell.
 */
#[CoversClass(AtlasStylesheets::class)]
final class ComponentSheetsTest extends TestCase
{
    /** The design's month: seven columns, and a cell of one height. */
    public function testTheMonthsOwnSheetCarriesTheSevenColumnGridAndTheCellHeight(): void
    {
        $calendar = self::sheet('calendar.css');

        self::assertMatchesRegularExpression('/\.cal\s*\{[^}]*grid-template-columns:\s*repeat\(7, 1fr\)/', $calendar);
        self::assertMatchesRegularExpression('/\.cal \.dc\s*\{[^}]*height:\s*var\(--cal-cell-height, 96px\)/', $calendar);
    }

    /**
     * AND THE MAP'S SHEET NO LONGER CARRIES THEM — the whole point. A
     * copy left behind is the copy a page links by accident, and the two
     * drift the first time one is edited.
     */
    public function testTheMapsSheetKeepsNoCopyOfTheMonthOrTheChart(): void
    {
        $map = self::sheet('map.css');

        self::assertStringNotContainsString('.cal ', $map);
        self::assertStringNotContainsString('.cal-plate', $map);
        self::assertStringNotContainsString('.chart-plate', $map);
    }

    /** The chart is in the same arrangement, and for the same reason. */
    public function testTheChartsOwnSheetCarriesItsFixedHeight(): void
    {
        self::assertMatchesRegularExpression(
            '/\.chart-plate > \.chart-box\s*\{[^}]*height:\s*var\(--chart-height, 196px\)/',
            self::sheet('chart.css'),
        );
    }

    /**
     * THE SPARKLINE'S TWO BOXES AND ITS THREE TONES are in the chart's sheet,
     * which reaches every head: a line drawn on a page whose own sheet does
     * not know it is a line still has a size and a stroke.
     */
    public function testTheChartsSheetCarriesTheSparklinesBoxesAndTones(): void
    {
        $chart = self::sheet('chart.css');

        self::assertMatchesRegularExpression('/\.sk\s*\{[^}]*height:\s*26px/', $chart);
        self::assertMatchesRegularExpression('/\.spark\s*\{[^}]*height:\s*18px;\s*width:\s*70px/', $chart);
        foreach (['up' => '--c-ok', 'dn' => '--c-fail', 'fl' => '--c-fog'] as $tone => $token) {
            self::assertMatchesRegularExpression('/\.spark polyline\.'.$tone.'\s*\{[^}]*'.$token.'/', $chart);
        }
    }

    /**
     * THE RANKED BARS AND THEIR DOT KEY are in the chart's sheet, at the
     * design's measures: a 162px label column, a 14px track, 7px between
     * rows; the key in 9.5px mono with 16px between entries — and the shell
     * keeps no copy, because a second copy is the one that drifts.
     */
    public function testTheChartsSheetCarriesTheBarsAndTheKeyAndTheShellNoCopy(): void
    {
        $chart = self::sheet('chart.css');

        self::assertMatchesRegularExpression('/\.sxbars\s*\{[^}]*gap:\s*7px;\s*margin:\s*12px 0 2px/', $chart);
        self::assertMatchesRegularExpression('/\.sxbar\s*\{[^}]*grid-template-columns:\s*minmax\(0, 162px\) minmax\(0, 1fr\) auto/', $chart);
        self::assertMatchesRegularExpression('/\.sxbar \.t\s*\{[^}]*height:\s*14px/', $chart);
        self::assertMatchesRegularExpression('/\.sxmxkey\s*\{[^}]*gap:\s*16px[^}]*font-size:\s*9\.5px/', $chart);
        foreach (['.sxdot.v', '.sxdot.b', '.sxdot.inh', '.sxdot.no'] as $mark) {
            self::assertStringContainsString($mark.' {', $chart);
        }

        $shell = (string) file_get_contents(\dirname(new \ReflectionClass(\Uhifadhi\Bundle\ShellBundle\ShellBundle::class)->getFileName() ?: '').'/public/shell.css');
        self::assertDoesNotMatchRegularExpression('/\.(sxbars?|sxmxkey|sxdot)\b/', $shell);
    }

    /**
     * THE HEAT TABLE'S TINTS AND ITS LEGEND are in its own sheet, the five
     * places and the dashed absence — and the performance sheet that drew
     * them keeps no copy.
     */
    public function testTheHeatSheetCarriesTheTintsAndTheLegendAndTeamNoCopy(): void
    {
        $heat = self::sheet('heat.css');

        self::assertMatchesRegularExpression('/\.hcell\.h5\s*\{[^}]*--c-acc\)\) 22%/', $heat);
        self::assertMatchesRegularExpression('/\.hcell\.h1\s*\{[^}]*--c-fail\)\) 11%/', $heat);
        self::assertMatchesRegularExpression('/\.hcell\.h0\s*\{[^}]*border-style:\s*dashed/', $heat);
        self::assertMatchesRegularExpression('/\.legend \.sw\.h0\s*\{[^}]*dashed/', $heat);

        $performance = (string) file_get_contents(\dirname(new \ReflectionClass(\Uhifadhi\Bundle\TeamBundle\TeamBundle::class)->getFileName() ?: '').'/public/performance.css');
        self::assertDoesNotMatchRegularExpression('/^\.(hcell|legend|cmark|heat|dept|sg)\b/m', $performance);
    }

    /** AN AREA'S FACE is the map sheet's, and the area's sheet keeps no copy. */
    public function testTheMapSheetCarriesTheThumbnailAndTheAreaNoCopy(): void
    {
        $map = self::sheet('map.css');

        self::assertMatchesRegularExpression('/\.ax-sat\s*\{[^}]*object-fit:\s*cover/', $map);
        self::assertMatchesRegularExpression('/\.ax-outline path\s*\{[^}]*stroke:\s*rgb\(var\(--c-acc\)\)/', $map);

        $area = (string) file_get_contents(\dirname(new \ReflectionClass(\Uhifadhi\Bundle\AreaBundle\AreaBundle::class)->getFileName() ?: '').'/public/area.css');
        self::assertDoesNotMatchRegularExpression('/^\.ax-(sat|outline)\b/m', $area);
    }

    /**
     * ALL THREE ARE PUBLISHED TO THE SHELL, THE MAP INCLUDED.
     *
     * THE DEFECT THIS CLOSES, and it had recurred for a year: "a page that
     * draws a map links map.css" held only while a page could know. A plate
     * is now drawn by a WIDGET — any cell of a composed surface may draw one
     * — so the organization Overview composed a map cell onto a page that
     * linked no map sheet, `.map-plate`, `.map-legend` and `.lay .sw` had no
     * rules, and the plate came apart with no error anywhere. The head
     * cannot be decided by what a page happens to compose, so the sheet
     * joins the two that already reach every head.
     */
    public function testAllThreeSheetsArePublishedIncludingTheMaps(): void
    {
        $published = new AtlasStylesheets()->stylesheets();

        self::assertSame(
            [AtlasBundle::STYLESHEET, AtlasBundle::CHART_STYLESHEET, AtlasBundle::CALENDAR_STYLESHEET, AtlasBundle::HEAT_STYLESHEET],
            $published,
        );
    }

    /** And each published path is a file this bundle actually ships. */
    public function testEveryPublishedPathIsAFileTheBundleShips(): void
    {
        foreach (new AtlasStylesheets()->stylesheets() as $path) {
            self::assertFileExists(\dirname(__DIR__, 3).'/public/'.basename($path));
        }
    }

    private static function sheet(string $name): string
    {
        $css = file_get_contents(\dirname(__DIR__, 3).'/public/'.$name);
        self::assertIsString($css, $name.' must ship.');

        return $css;
    }
}
