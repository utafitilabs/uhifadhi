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

namespace Uhifadhi\Bundle\AtlasBundle\Model;

/**
 * HOW A BAR CHART DRAWS A NOUGHT.
 *
 * A comparison across categories usually leaves a nought as no bar at all.
 * Where the nought IS the reading — an area with no station, a department
 * nobody is placed in — a gap in the row of columns reads as missing data,
 * so the chart says so and the nought keeps a column of its own.
 */
enum ChartNoughts: string
{
    /** No bar: the library's own answer. */
    case Blank = 'blank';

    /** A two-pixel stub on the axis, faded, so the category keeps a visible column. */
    case Hairline = 'hairline';
}
