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

/**
 * WHO OR WHAT A POWER IS ASKED ABOUT, relative to the person acting. The page
 * finds a real one on the installation — a colleague placed in the actor's
 * area, an Admin, a position somebody elsewhere holds — and asks about it.
 */
enum PowerTarget: string
{
    case Themselves = 'themselves';
    case TheirOwnPosition = 'their-own-position';
    case AStrongerPosition = 'a-stronger-position';
    case APositionHeldElsewhere = 'a-position-held-elsewhere';
    case ANewAccount = 'a-new-account';
    case TheRankScale = 'the-rank-scale';
    case AColleague = 'a-colleague';
    case SomebodyBeyondTheirArea = 'somebody-beyond-their-area';
    case AnAdmin = 'an-admin';
    case ASuperAdmin = 'a-super-admin';

    public function label(): string
    {
        return match ($this) {
            self::Themselves => 'themselves',
            self::TheirOwnPosition => 'their own position',
            self::AStrongerPosition => 'a stronger position',
            self::APositionHeldElsewhere => 'a position held outside their area',
            self::ANewAccount => 'a new account',
            self::TheRankScale => 'the rank scale',
            self::AColleague => 'a colleague in their area',
            self::SomebodyBeyondTheirArea => 'somebody beyond their area',
            self::AnAdmin => 'an Admin',
            self::ASuperAdmin => 'a Super Admin',
        };
    }

    /** Whether the target is a person, rather than a position, a list or nothing yet. */
    public function isPerson(): bool
    {
        return \in_array($this, [self::Themselves, self::AColleague, self::SomebodyBeyondTheirArea, self::AnAdmin, self::ASuperAdmin], true);
    }
}
