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

namespace Uhifadhi\Bundle\AreaBundle\Enum;

/**
 * WHERE A STATION'S POINT CAME FROM.
 *
 * A point somebody recorded — typed on the form, placed on the configure
 * page — says where the post stands. A point invented for a post nobody has
 * placed yet only says roughly where it might be, and everything measured
 * against it (a check-in ring, a derived zone) inherits that doubt.
 */
enum StationPositionSource: string
{
    /** Recorded by somebody who knows where the post stands. */
    case Surveyed = 'surveyed';

    /** Put there until somebody records where the post really stands. */
    case Estimated = 'estimated';
}
