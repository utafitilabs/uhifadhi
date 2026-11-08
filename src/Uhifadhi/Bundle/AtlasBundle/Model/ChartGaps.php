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
 * HOW A LINE CROSSES A READING THAT NEVER CAME.
 *
 * A null is a gap, and the library leaves the line broken there. Dashed
 * crosses the silence in the muted ink, so the eye sees that the device was
 * silent, not a value.
 */
enum ChartGaps: string
{
    case Blank = 'blank';
    case Dashed = 'dashed';
}
