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

namespace Uhifadhi\Bundle\TeamBundle\Enum;

/** What started a permissions run. */
enum PermissionRunTriggerEnum: string
{
    /** A change to who may do what was written to the history. */
    case HistoryLine = 'history-line';
    case Nightly = 'nightly';
    case Deploy = 'deploy';
    /** A Super Admin pressed Recompute now. */
    case Manual = 'manual';
}
