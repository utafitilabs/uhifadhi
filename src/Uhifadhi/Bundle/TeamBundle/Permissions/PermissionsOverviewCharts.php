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

namespace Uhifadhi\Bundle\TeamBundle\Permissions;

use Uhifadhi\Bundle\AtlasBundle\Model\AtlasChart;
use Uhifadhi\Bundle\AtlasBundle\Model\AxisScale;
use Uhifadhi\Bundle\AtlasBundle\Model\Bar;
use Uhifadhi\Bundle\AtlasBundle\Model\ChartKind;
use Uhifadhi\Bundle\AtlasBundle\Model\ChartLegend;
use Uhifadhi\Bundle\AtlasBundle\Model\ChartNoughts;
use Uhifadhi\Bundle\AtlasBundle\Model\ChartSeries;
use Uhifadhi\Bundle\AtlasBundle\Model\DotKey;
use Uhifadhi\Bundle\AtlasBundle\Model\KeyEntry;
use Uhifadhi\Bundle\AtlasBundle\Model\KeyMark;
use Uhifadhi\Bundle\AtlasBundle\Model\RankedBars;

/**
 * THE HUB'S FOUR PLOTS, as the atlas draws the Team overview's: two lists of
 * ranked bars and two column charts, built from what
 * {@see PermissionOverviewReader::overview()} returns and nothing else.
 */
final class PermissionsOverviewCharts
{
    /** The concerns the first card shows; the rest are on Positions. */
    public const int CONCERNS = 12;

    /** The positions the second card shows. */
    public const int POSITIONS = 10;

    /** A place's name longer than this is drawn by its first word. */
    private const int SHORT_NAME = 14;

    private const float COLUMN_WIDTH = 40.0;

    private const float COLUMN_RADIUS = 1.5;

    /**
     * Concerns by the positions holding any pair of them: the figure and its
     * share of the positions.
     *
     * @param list<array{key: string, label: string, positions: int, share: int}> $rows
     */
    public static function concerns(array $rows): RankedBars
    {
        $bars = [];
        foreach (\array_slice($rows, 0, self::CONCERNS) as $row) {
            $bars[] = new Bar(
                label: $row['label'],
                value: (float) $row['positions'],
                figure: (string) $row['positions'],
                note: \sprintf(' · %d %%', $row['share']),
            );
        }

        return new RankedBars($bars, empty: 'No resource is declared.');
    }

    /**
     * Positions by the pairs they hold, the part not honoured or not in force
     * drawn beside the rest.
     *
     * @param list<array{position: string, held: int, problem: int, kinds: list<string>}> $rows
     */
    public static function positions(array $rows): RankedBars
    {
        $bars = [];
        foreach (\array_slice($rows, 0, self::POSITIONS) as $row) {
            $ok = $row['held'] - $row['problem'];
            $bars[] = new Bar(
                label: $row['position'],
                value: (float) $ok,
                rest: (float) $row['problem'],
                figure: (string) $ok,
                note: \sprintf('/%d%s', $row['held'], $row['problem'] > 0 ? \sprintf(' · %d %s', $row['problem'], $row['kinds'][0] ?? '') : ''),
            );
        }

        return new RankedBars(
            $bars,
            key: new DotKey([new KeyEntry('held'), new KeyEntry('not honoured or not in force', KeyMark::Rest)]),
            empty: 'No position yet.',
        );
    }

    /**
     * Who can act, by place: the whole organization, then each area.
     *
     * @param list<array{key: string, name: string, people: int}> $rows
     */
    public static function places(array $rows): AtlasChart
    {
        return self::columns(
            array_map(static fn (array $r): string => 'organization' === $r['key'] ? 'organization' : self::short($r['name']), $rows),
            array_map(static fn (array $r): int => $r['people'], $rows),
            'People',
        );
    }

    /**
     * The permission changes of the last six months, by month.
     *
     * @param list<array{key: string, month: string, changes: int}> $rows
     */
    public static function months(array $rows): AtlasChart
    {
        return self::columns(
            array_map(static fn (array $r): string => $r['month'], $rows),
            array_map(static fn (array $r): int => $r['changes'], $rows),
            'Changes',
        );
    }

    /**
     * The top of the axis is a round number above the tallest column, in
     * steps of ten above forty and of two below, with its half between.
     */
    public static function axis(int $max): AxisScale
    {
        $max = max(1, $max);
        $step = $max > 40 ? 10 : 2;
        $top = (float) ((int) ceil($max / $step) * $step);

        return new AxisScale($top, $top / 2);
    }

    /**
     * @param list<string> $labels
     * @param list<int>    $values
     */
    private static function columns(array $labels, array $values, string $series): AtlasChart
    {
        return new AtlasChart(
            ChartKind::Bar,
            $labels,
            [new ChartSeries($series, array_map(static fn (int $v): float => (float) $v, $values), accent: true)],
            axis: self::axis([] === $values ? 0 : max($values)),
            legend: ChartLegend::None,
            noughts: ChartNoughts::Hairline,
            barWidth: self::COLUMN_WIDTH,
            barRadius: self::COLUMN_RADIUS,
        );
    }

    private static function short(string $name): string
    {
        return mb_strlen($name) > self::SHORT_NAME ? explode(' ', $name)[0] : $name;
    }
}
