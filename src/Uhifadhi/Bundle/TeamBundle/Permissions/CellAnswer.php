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

namespace Uhifadhi\Bundle\TeamBundle\Permissions;

/** What the rules answered for one cell of the Permissions page. */
enum CellAnswer: string
{
    case Allowed = 'allowed';
    case Refused = 'refused';
    /** The installation has nobody, or nothing, of that kind to ask about. */
    case NoTarget = 'no-target';
}
