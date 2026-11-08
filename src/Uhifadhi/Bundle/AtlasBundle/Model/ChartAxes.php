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
 * WHERE A SCATTER'S TWO AXES ARE DRAWN: at the plot's edges, or crossing at
 * nought, a step stronger than the grid, for a change either way on both.
 *
 * @see https://www.chartjs.org/docs/latest/axes/cartesian/#axis-position — `position: 'center'`
 */
enum ChartAxes: string
{
    case Edge = 'edge';
    case Centre = 'centre';
}
