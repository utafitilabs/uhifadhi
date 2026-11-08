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

namespace Uhifadhi\Bundle\TeamBundle\Performance;

use Uhifadhi\Bundle\AtlasBundle\Model\AtlasChart;
use Uhifadhi\Bundle\AtlasBundle\Model\ChartSeries as AtlasSeries;
use Uhifadhi\Contracts\Performance\ChartSeries;
use Uhifadhi\Contracts\Performance\TopicChart;

/**
 * A CHART A TOPIC STATED, HANDED TO THE ATLAS THAT DRAWS IT.
 *
 * TWO SHAPES FOR ONE THING, AND THAT IS ON PURPOSE. A topic states its
 * chart in the CONTRACTS package, which depends on nothing — a module
 * publishing a topic must not have to install a drawing library to
 * describe a series. The atlas's own shape is the drawing one. The
 * translation is a dozen lines and it belongs to the host, which is the
 * only party that has both.
 *
 * THE KIND CROSSES AS IT IS: the atlas draws the contract's own
 * `ChartKind`, so a kind a topic can state is a kind the atlas can draw.
 */
final readonly class ChartBridge
{
    public static function atlas(TopicChart $chart): AtlasChart
    {
        return new AtlasChart(
            kind: $chart->kind,
            labels: $chart->labels,
            series: array_map(
                /*
                 * AND THE CATEGORY CROSSES. It did not: the atlas's series
                 * took a colour STRING, and Chart.js — like Leaflet before
                 * the plate learnt to resolve one — paints nothing at all
                 * when handed `var(--cat-3)`, so a topic's stated category
                 * was dropped and the series took its position instead.
                 * The chart plate resolves a token at mount now, the same
                 * way the map plate does, so the category is the thing
                 * that travels and the colour is decided where it is drawn.
                 *
                 * NULL STILL MEANS "IN ORDER", which is what most series
                 * want: a topic states a category only where the series is
                 * the same thing as something else on the page.
                 */
                static fn (ChartSeries $series): AtlasSeries => new AtlasSeries(
                    label: $series->label,
                    points: $series->points,
                    cat: $series->cat,
                ),
                $chart->series,
            ),
            target: $chart->target,
            unit: $chart->unit,
        );
    }
}
