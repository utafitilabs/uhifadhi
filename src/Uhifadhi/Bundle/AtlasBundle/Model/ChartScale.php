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
 * HOW THE VALUE AXIS IS RULED: in equal amounts, or in powers of ten — a
 * gridline and a figure at each power only, for values that run across
 * several of them.
 *
 * @see https://www.chartjs.org/docs/latest/axes/cartesian/logarithmic.html
 */
enum ChartScale: string
{
    case Linear = 'linear';
    case Log = 'log';
}
