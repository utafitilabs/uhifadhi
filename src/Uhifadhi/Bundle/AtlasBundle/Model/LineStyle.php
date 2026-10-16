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
 * HOW A SERIES THAT IS A LINE OVER BARS IS DRAWN.
 *
 * A bar chart measures one thing; a line laid over it is the thing it is
 * measured against — the normal for those dates, the plan — and the two
 * styles say which: a solid line is a second measurement, a dashed one is
 * a reference.
 */
enum LineStyle: string
{
    case Solid = 'solid';
    case Dashed = 'dashed';
}
