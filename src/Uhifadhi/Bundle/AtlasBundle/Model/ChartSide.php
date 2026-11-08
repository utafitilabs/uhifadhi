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
 * WHICH VALUE AXIS A SERIES IS READ AGAINST on a chart of two measures: the
 * left one, or the second measure's, on the right.
 */
enum ChartSide: string
{
    case Left = 'left';
    case Right = 'right';
}
