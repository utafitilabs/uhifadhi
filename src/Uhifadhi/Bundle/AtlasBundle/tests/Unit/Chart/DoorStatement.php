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

namespace Uhifadhi\Bundle\AtlasBundle\Tests\Unit\Chart;

use Uhifadhi\Bundle\AtlasBundle\Model\AtlasChart;
use Uhifadhi\Bundle\AtlasBundle\Model\AxisScale;
use Uhifadhi\Bundle\AtlasBundle\Model\ChartAxes;
use Uhifadhi\Bundle\AtlasBundle\Model\ChartAxis;
use Uhifadhi\Bundle\AtlasBundle\Model\ChartBand;
use Uhifadhi\Bundle\AtlasBundle\Model\ChartCentre;
use Uhifadhi\Bundle\AtlasBundle\Model\ChartChild;
use Uhifadhi\Bundle\AtlasBundle\Model\ChartCurve;
use Uhifadhi\Bundle\AtlasBundle\Model\ChartDecimation;
use Uhifadhi\Bundle\AtlasBundle\Model\ChartFigures;
use Uhifadhi\Bundle\AtlasBundle\Model\ChartFill;
use Uhifadhi\Bundle\AtlasBundle\Model\ChartFlowTarget;
use Uhifadhi\Bundle\AtlasBundle\Model\ChartGaps;
use Uhifadhi\Bundle\AtlasBundle\Model\ChartLegend;
use Uhifadhi\Bundle\AtlasBundle\Model\ChartNoughts;
use Uhifadhi\Bundle\AtlasBundle\Model\ChartPane;
use Uhifadhi\Bundle\AtlasBundle\Model\ChartPanel;
use Uhifadhi\Bundle\AtlasBundle\Model\ChartPart;
use Uhifadhi\Bundle\AtlasBundle\Model\ChartPoint;
use Uhifadhi\Bundle\AtlasBundle\Model\ChartQuadrant;
use Uhifadhi\Bundle\AtlasBundle\Model\ChartQuadrants;
use Uhifadhi\Bundle\AtlasBundle\Model\ChartRamp;
use Uhifadhi\Bundle\AtlasBundle\Model\ChartRange;
use Uhifadhi\Bundle\AtlasBundle\Model\ChartReadings;
use Uhifadhi\Bundle\AtlasBundle\Model\ChartScale;
use Uhifadhi\Bundle\AtlasBundle\Model\ChartSeries;
use Uhifadhi\Bundle\AtlasBundle\Model\ChartSide;
use Uhifadhi\Bundle\AtlasBundle\Model\ChartSpan;
use Uhifadhi\Bundle\AtlasBundle\Model\ChartStep;
use Uhifadhi\Bundle\AtlasBundle\Model\ChartTexture;
use Uhifadhi\Bundle\AtlasBundle\Model\ChartThreshold;
use Uhifadhi\Bundle\AtlasBundle\Model\ChartTime;
use Uhifadhi\Bundle\AtlasBundle\Model\DecimationAlgorithm;
use Uhifadhi\Bundle\AtlasBundle\Model\LineStyle;
use Uhifadhi\Bundle\AtlasBundle\Model\PointMarker;
use Uhifadhi\Bundle\AtlasBundle\Model\TimeUnit;
use Uhifadhi\Contracts\Performance\ChartKind;

/**
 * A DESIGN STATEMENT READ AS AN `AtlasChart`, field for field.
 *
 * The design workspace states a chart as a plain object whose fields are
 * named as the atlas's are (`kind`, `labels`, `series`, `parts`, `band` …);
 * this reads one, decoded from JSON, into the models a module would build. A
 * field the models cannot state fails here — which is the point: every
 * field the design states must be statable in PHP.
 */
final class DoorStatement
{
    /** @param array<mixed> $s */
    public static function chart(array $s): AtlasChart
    {
        $kind = ChartKind::from(self::string($s, 'kind'));
        $decimated = isset($s['decimation']);

        return new AtlasChart(
            kind: $kind,
            labels: array_map(static fn (mixed $label): string => \is_scalar($label) ? (string) $label : throw new \UnexpectedValueException('A label is a string.'), self::list($s, 'labels')),
            series: array_map(static fn (mixed $one): ChartSeries => self::series(self::map($one), $kind, $decimated), self::list($s, 'series')),
            target: self::number($s, 'target'),
            unit: self::string($s, 'unit', ''),
            targetLabel: self::string($s, 'targetLabel', 'Target'),
            axis: isset($s['axis']) ? self::axisScale(self::map($s['axis'])) : null,
            figures: isset($s['figures']) ? self::figures(self::map($s['figures'])) : null,
            legend: ChartLegend::from(self::string($s, 'legend', 'canvas')),
            noughts: ChartNoughts::from(self::string($s, 'noughts', 'blank')),
            barWidth: self::number($s, 'barWidth'),
            barRadius: self::number($s, 'barRadius'),
            ranges: array_map(static function (mixed $one): ChartRange {
                $range = self::map($one);

                return new ChartRange(self::number($range, 'from'), self::number($range, 'to'), self::string($range, 'label', ''), self::stringOrNull($range, 'swatch'), self::intOrNull($range, 'cat'), true === ($range['accent'] ?? false));
            }, self::list($s, 'ranges')),
            highlight: self::intOrNull($s, 'highlight'),
            panel: isset($s['panel']) ? ChartPanel::from(self::string($s, 'panel')) : null,
            parts: array_map(static function (mixed $one): ChartPart {
                $part = self::map($one);

                return new ChartPart(self::intOrNull($part, 'cat'), self::stringOrNull($part, 'swatch'), isset($part['texture']) ? ChartTexture::from(self::string($part, 'texture')) : null);
            }, self::list($s, 'parts')),
            centre: isset($s['centre']) ? new ChartCentre(self::scalar(self::map($s['centre']), 'figure'), self::string(self::map($s['centre']), 'caption', '')) : null,
            empty: self::stringOrNull($s, 'empty'),
            curve: isset($s['curve']) ? ChartCurve::from(self::string($s, 'curve')) : null,
            gaps: ChartGaps::from(self::string($s, 'gaps', 'blank')),
            fill: isset($s['fill']) ? ChartFill::from(self::string($s, 'fill')) : null,
            quadrants: isset($s['quadrants']) ? self::quadrants(self::map($s['quadrants'])) : null,
            frame: true === ($s['frame'] ?? false),
            threshold: isset($s['threshold']) ? self::threshold(self::map($s['threshold'])) : null,
            scale: ChartScale::from(self::string($s, 'scale', 'linear')),
            axes: ChartAxes::from(self::string($s, 'axes', 'edge')),
            decimation: $decimated ? self::decimation(self::map($s['decimation'])) : null,
            xAxis: isset($s['xAxis']) ? self::axis(self::map($s['xAxis'])) : null,
            yAxis: isset($s['yAxis']) ? self::axis(self::map($s['yAxis'])) : null,
            y2Axis: isset($s['y2Axis']) ? self::axis(self::map($s['y2Axis'])) : null,
            band: isset($s['band']) ? self::band(self::map($s['band'])) : null,
            children: array_map(static function (mixed $one): ChartChild {
                $child = self::map($one);

                return new ChartChild(self::string($child, 'label'), self::number($child, 'value') ?? 0.0, self::intOrNull($child, 'of') ?? 0);
            }, self::list($s, 'children')),
            childLabel: self::string($s, 'childLabel', 'Parts'),
            clock: true === ($s['clock'] ?? false),
            sizeLabel: self::string($s, 'sizeLabel', ''),
            time: isset($s['time']) ? self::time(self::map($s['time'])) : null,
            panes: array_map(static function (mixed $one): ChartPane {
                $pane = self::map($one);

                return new ChartPane(self::string($pane, 'title', ''), self::number($pane, 'weight') ?? 1.0, self::number($pane, 'max'), self::number($pane, 'step'));
            }, self::list($s, 'panes')),
            rows: array_map(static fn (mixed $row): string => \is_string($row) ? $row : throw new \UnexpectedValueException('A row is a string.'), self::list($s, 'rows')),
            values: array_map(static fn (mixed $v): float|array|null => \is_array($v) ? array_map(static fn (mixed $cell): ?float => self::number([$cell], 0), array_values($v)) : self::number([$v], 0), self::list($s, 'values')),
            ramp: isset($s['ramp']) ? self::ramp(self::map($s['ramp'])) : null,
            start: isset($s['start']) ? new \DateTimeImmutable(self::string($s, 'start'), new \DateTimeZone('UTC')) : null,
            names: array_map(static fn (mixed $name): string => \is_string($name) ? $name : throw new \UnexpectedValueException('A name is a string.'), self::stringMap($s, 'names')),
            targets: array_map(static function (mixed $one): ChartFlowTarget {
                $target = self::map($one);

                return new ChartFlowTarget(self::string($target, 'label'), self::stringOrNull($target, 'swatch'), self::intOrNull($target, 'cat'));
            }, self::list($s, 'targets')),
            flows: array_map(static fn (mixed $row): array => array_map(static fn (mixed $flow): float => self::number([$flow], 0) ?? 0.0, array_values(self::map($row))), self::list($s, 'flows')),
        );
    }

    /** @param array<mixed> $t */
    private static function time(array $t): ChartTime
    {
        return new ChartTime(TimeUnit::from(self::string($t, 'unit')), self::stringOrNull($t, 'format'), self::stringOrNull($t, 'tooltip'));
    }

    /** @param array<mixed> $r */
    private static function ramp(array $r): ChartRamp
    {
        return new ChartRamp(array_map(static fn (mixed $v): float => self::number([$v], 0) ?? 0.0, self::list($r, 'breaks')), self::stringOrNull($r, 'token'));
    }

    /**
     * @param array<mixed> $a
     *
     * @return array<string, mixed>
     */
    private static function stringMap(array $a, string $key): array
    {
        $map = [];
        foreach (self::map($a[$key] ?? []) as $name => $value) {
            $map[(string) $name] = $value;
        }

        return $map;
    }

    /** @param array<mixed> $s */
    private static function series(array $s, ChartKind $kind, bool $decimated): ChartSeries
    {
        $line = $s['line'] ?? null;

        return new ChartSeries(
            label: self::string($s, 'label'),
            points: array_map(static fn (mixed $point): float|ChartPoint|ChartSpan|ChartReadings|null => self::point($point, $kind), self::list($s, 'points')),
            swatch: self::stringOrNull($s, 'swatch'),
            cat: self::intOrNull($s, 'cat'),
            accent: true === ($s['accent'] ?? false),
            line: match (true) {
                null === $line, false === $line => null,
                true === $line => LineStyle::Solid,
                default => LineStyle::from(self::string($s, 'line')),
            },
            marked: self::intOrNull($s, 'marked'),
            unit: self::string($s, 'unit', ''),
            axis: ChartSide::from(self::string($s, 'axis', 'left')),
            marker: isset($s['marker']) ? PointMarker::from(self::string($s, 'marker')) : null,
            curve: isset($s['curve']) ? ChartCurve::from(self::string($s, 'curve')) : null,
            dash: true === ($s['dash'] ?? false),
            stepped: isset($s['stepped']) ? ChartStep::from(self::string($s, 'stepped')) : null,
            texture: isset($s['texture']) ? ChartTexture::from(self::string($s, 'texture')) : null,
            pane: self::intOrNull($s, 'pane') ?? 0,
            bar: true === ($s['bar'] ?? false),
        );
    }

    private static function point(mixed $point, ChartKind $kind): float|ChartPoint|ChartSpan|ChartReadings|null
    {
        if (null === $point || \is_int($point) || \is_float($point)) {
            return null === $point ? null : (float) $point;
        }
        $point = self::map($point);
        if (\in_array($kind, [ChartKind::Boxplot, ChartKind::Violin], true)) {
            return new ChartReadings(array_map(static fn (mixed $v): float => self::number([$v], 0) ?? 0.0, array_values($point)));
        }
        if (array_is_list($point)) {
            return new ChartSpan(self::number($point, 0) ?? 0.0, self::number($point, 1) ?? 0.0);
        }

        return new ChartPoint(self::number($point, 'x') ?? 0.0, self::number($point, 'y') ?? 0.0, self::number($point, 'size'));
    }

    /** @param array<mixed> $a */
    private static function axisScale(array $a): AxisScale
    {
        return new AxisScale(self::number($a, 'max') ?? 0.0, self::number($a, 'step'), self::number($a, 'min'));
    }

    /** @param array<mixed> $a */
    private static function axis(array $a): ChartAxis
    {
        return new ChartAxis(self::string($a, 'title', ''), self::string($a, 'unit', ''), self::number($a, 'min'), self::number($a, 'max'), self::number($a, 'step'));
    }

    /** @param array<mixed> $f */
    private static function figures(array $f): ChartFigures
    {
        return new ChartFigures(self::string($f, 'unit', ''), self::intOrNull($f, 'precision') ?? 0, true === ($f['totals'] ?? false), self::number($f, 'size'));
    }

    /** @param array<mixed> $q */
    private static function quadrants(array $q): ChartQuadrants
    {
        return new ChartQuadrants(self::number($q, 'x') ?? 0.0, self::number($q, 'y') ?? 0.0, array_map(static function (mixed $one): ChartQuadrant {
            $part = self::map($one);

            return new ChartQuadrant(self::string($part, 'label', ''), self::stringOrNull($part, 'swatch'), self::intOrNull($part, 'cat'));
        }, self::list($q, 'parts')));
    }

    /** @param array<mixed> $t */
    private static function threshold(array $t): ChartThreshold
    {
        return new ChartThreshold(self::number($t, 'value') ?? 0.0, self::string($t, 'above'), self::stringOrNull($t, 'below'));
    }

    /** @param array<mixed> $d */
    private static function decimation(array $d): ChartDecimation
    {
        return new ChartDecimation(false !== ($d['enabled'] ?? true), DecimationAlgorithm::from(self::string($d, 'algorithm', 'lttb')), self::intOrNull($d, 'samples'));
    }

    /** @param array<mixed> $b */
    private static function band(array $b): ChartBand
    {
        $edge = static fn (string $key): array => array_map(static fn (mixed $v): ?float => null === $v ? null : self::number([$v], 0), self::list($b, $key));

        return new ChartBand($edge('from'), $edge('to'), self::string($b, 'label', 'Range'), self::string($b, 'fromLabel', 'Low'), self::string($b, 'toLabel', 'High'));
    }

    /** @return array<mixed> */
    private static function map(mixed $value): array
    {
        return \is_array($value) ? $value : throw new \UnexpectedValueException('A block of a statement is an object or a list.');
    }

    /**
     * @param array<mixed> $a
     *
     * @return list<mixed>
     */
    private static function list(array $a, string $key): array
    {
        $value = $a[$key] ?? [];

        return \is_array($value) ? array_values($value) : throw new \UnexpectedValueException(\sprintf('"%s" is a list.', $key));
    }

    /** @param array<mixed> $a */
    private static function string(array $a, string $key, ?string $default = null): string
    {
        $value = $a[$key] ?? $default;

        return \is_string($value) ? $value : throw new \UnexpectedValueException(\sprintf('"%s" is a string.', $key));
    }

    /** @param array<mixed> $a */
    private static function scalar(array $a, string $key): string
    {
        $value = $a[$key] ?? null;

        return \is_scalar($value) ? (string) $value : throw new \UnexpectedValueException(\sprintf('"%s" is a figure.', $key));
    }

    /** @param array<mixed> $a */
    private static function stringOrNull(array $a, string $key): ?string
    {
        return isset($a[$key]) ? self::string($a, $key) : null;
    }

    /** @param array<mixed> $a */
    private static function intOrNull(array $a, string $key): ?int
    {
        $value = $a[$key] ?? null;

        return null === $value || \is_int($value) ? $value : throw new \UnexpectedValueException(\sprintf('"%s" is a whole number.', $key));
    }

    /** @param array<mixed> $a */
    private static function number(array $a, int|string $key): ?float
    {
        $value = $a[$key] ?? null;

        return match (true) {
            null === $value => null,
            \is_int($value), \is_float($value) => (float) $value,
            default => throw new \UnexpectedValueException(\sprintf('"%s" is a number.', $key)),
        };
    }
}
