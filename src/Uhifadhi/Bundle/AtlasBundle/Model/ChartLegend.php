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
 * WHERE A CHART'S LEGEND IS DRAWN, if anywhere.
 *
 * ONE LEGEND, NEVER TWO. The library draws its own inside the canvas; the
 * house draws a row of chips under a plate. A chart takes one of them, and
 * the moment it takes the chips the library's is switched off, so no
 * series is named twice.
 */
enum ChartLegend: string
{
    /** The library's own, inside the canvas — drawn where more than one thing is plotted. */
    case Canvas = 'canvas';

    /** A row of house chips under the box, one per series, wearing the series' category. */
    case Chips = 'chips';

    /** None at all — a single series named in the title needs no key. */
    case None = 'none';
}
