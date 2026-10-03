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

namespace Uhifadhi\Testing\Authority;

/**
 * THE KINDS OF PERSON EVERY PROBE IS SENT AS. They are kinds, not personas:
 * each differs from its neighbour in one thing the rules read, so a cell that
 * changes points at the rule that changed it.
 */
enum Person: string
{
    case SignedOut = 'signed out';
    /** An Admin, so a refusal can only be the deactivation. */
    case DeactivatedWhileSignedIn = 'deactivated';
    case StaffWithoutPosition = 'no position';
    /** Placed at Kilimani, in Operations: where every probe's record is. */
    case StaffHoldingThePair = 'the pair';
    case StaffHoldingAllButThePair = 'all but the pair';
    /** The same position as "the pair", placed at Tambarare, in ICT. */
    case StaffHoldingThePairElsewhere = 'the pair, elsewhere';
    case Admin = 'Admin';
    case SuperAdmin = 'Super Admin';
}
