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

namespace Uhifadhi\Bundle\TeamBundle\Tests\Unit\Permissions;

use PHPUnit\Framework\TestCase;
use Uhifadhi\Bundle\AtlasBundle\Model\ChartLegend;
use Uhifadhi\Bundle\AtlasBundle\Model\ChartNoughts;
use Uhifadhi\Bundle\AtlasBundle\Model\KeyEntry;
use Uhifadhi\Bundle\TeamBundle\Permissions\PermissionsOverviewCharts;
use Uhifadhi\Contracts\Performance\ChartKind;

/**
 * The hub's four plots, read off the overview reader's rows as the design's
 * script drew them.
 */
final class PermissionsOverviewChartsTest extends TestCase
{
    public function testConcernsShowTheFirstTwelveWithTheirShare(): void
    {
        $rows = [];
        for ($i = 14; $i > 0; --$i) {
            $rows[] = ['key' => 'c'.$i, 'label' => 'Concern '.$i, 'positions' => $i, 'share' => $i * 5];
        }

        $bars = PermissionsOverviewCharts::concerns($rows);

        self::assertCount(12, $bars->bars);
        self::assertSame('Concern 14', $bars->bars[0]->label);
        self::assertSame(14.0, $bars->bars[0]->value);
        self::assertSame('14', $bars->bars[0]->figure);
        self::assertSame(' · 70 %', $bars->bars[0]->note);
    }

    public function testPositionsDrawTheProblemBesideWhatIsHeld(): void
    {
        $bars = PermissionsOverviewCharts::positions([
            ['position' => 'Station head', 'held' => 5, 'problem' => 0, 'kinds' => []],
            ['position' => 'People officer', 'held' => 2, 'problem' => 1, 'kinds' => ['not honoured']],
        ]);

        self::assertSame([5.0, 0.0], [$bars->bars[0]->value, $bars->bars[0]->rest]);
        self::assertSame([1.0, 1.0], [$bars->bars[1]->value, $bars->bars[1]->rest]);
        self::assertSame('/5', $bars->bars[0]->note);
        self::assertSame('1', $bars->bars[1]->figure);
        self::assertSame('/2 · 1 not honoured', $bars->bars[1]->note);
        self::assertNotNull($bars->key);
        self::assertSame(['held', 'not honoured or not in force'], array_map(static fn (KeyEntry $e): string => $e->label, $bars->key->entries));
    }

    public function testPlacesAreColumnsWithTheOrganizationFirstAndLongNamesShortened(): void
    {
        $chart = PermissionsOverviewCharts::places([
            ['key' => 'organization', 'name' => 'The whole organization', 'people' => 22],
            ['key' => 'area-1', 'name' => 'Kilimani Game Reserve', 'people' => 145],
            ['key' => 'area-2', 'name' => 'Ziwa Kavu', 'people' => 115],
        ]);

        self::assertSame(ChartKind::Bar, $chart->kind);
        self::assertSame(['organization', 'Kilimani', 'Ziwa Kavu'], $chart->labels);
        self::assertSame([22.0, 145.0, 115.0], $chart->series[0]->points);
        self::assertTrue($chart->series[0]->accent);
        self::assertSame(ChartLegend::None, $chart->legend);
        self::assertSame(ChartNoughts::Hairline, $chart->noughts);
        self::assertSame([150.0, 75.0], [$chart->axis?->max, $chart->axis?->step]);
    }

    public function testTheAxisStepsByTwoUpToFortyAndByTenAbove(): void
    {
        self::assertSame([8.0, 4.0], [PermissionsOverviewCharts::axis(7)->max, PermissionsOverviewCharts::axis(7)->step]);
        self::assertSame(40.0, PermissionsOverviewCharts::axis(40)->max);
        self::assertSame(50.0, PermissionsOverviewCharts::axis(41)->max);
        self::assertSame(2.0, PermissionsOverviewCharts::axis(0)->max, 'an empty chart still has a width to measure against');
    }

    public function testMonthsAreTheirOwnLabels(): void
    {
        $chart = PermissionsOverviewCharts::months([
            ['key' => '2026-10', 'month' => 'Oct', 'changes' => 3],
            ['key' => '2026-11', 'month' => 'Nov', 'changes' => 0],
        ]);

        self::assertSame(['Oct', 'Nov'], $chart->labels);
        self::assertSame([3.0, 0.0], $chart->series[0]->points);
    }
}
