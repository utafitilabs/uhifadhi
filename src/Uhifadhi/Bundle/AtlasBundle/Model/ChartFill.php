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
 * A FILL THAT CHANGES ACROSS THE PLOT, laid at draw time.
 *
 * Gradient, on an area: its colour at the top of the plot fading to nothing
 * at the floor. Radial, on a polar area: faint at the centre and full at the
 * rim, so the strength says the radius again.
 */
enum ChartFill: string
{
    case Gradient = 'gradient';
    case Radial = 'radial';
}
