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

namespace Uhifadhi\Contracts\Access;

/** The four kinds of power the Permissions page groups its rows by. */
enum PowerGroup: string
{
    case GainingPower = 'gaining-power';
    case GivingPower = 'giving-power';
    case OthersRecords = 'somebody-elses-records';
    case TakingOver = 'taking-over-an-account';

    public function label(): string
    {
        return match ($this) {
            self::GainingPower => 'Gaining power',
            self::GivingPower => 'Giving power',
            self::OthersRecords => 'Somebody else’s records',
            self::TakingOver => 'Taking over an account',
        };
    }

    /** The question the group answers, under its heading. */
    public function question(): string
    {
        return match ($this) {
            self::GainingPower => 'can anybody raise themselves?',
            self::GivingPower => 'to somebody else',
            self::OthersRecords => 'whose records may they change?',
            self::TakingOver => 'can anybody get into another account?',
        };
    }
}
