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

namespace Uhifadhi\Bundle\AtlasBundle\Model\Heatmap;

/** A FIGURE, A RUN OF STATES, OR A BLANK — drawn differently and meaning different things. */
enum HeatCellKind
{
    case Figure;
    case Marks;
    case Blank;
}
