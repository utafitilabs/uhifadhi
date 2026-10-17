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
 * WHICH PANEL OF A PAIR ON ONE AXIS THIS CHART IS.
 *
 * Two charts stacked in one card on the same labels read as one figure
 * only if their plots line up: the upper writes no names under its axis
 * (the lower writes them once for both), and both give their value axis
 * the same width, so a column banded in one is banded in the other at the
 * same place.
 */
enum ChartPanel: string
{
    case Upper = 'upper';
    case Lower = 'lower';
}
