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

/**
 * THE SIX STANDING LISTS a run files beside its answers, in the order the
 * hub and the Analysis page draw them.
 */
enum FindingListEnum: string
{
    /** People placed nowhere, in no department, or holding no position. */
    case ReachNothing = 'reach-nothing';
    /** A pair that lifts a rule, stored on a position with no written reason in force. */
    case NotInForce = 'not-in-force';
    /** A pair only the tiers hold, still stored on a position. */
    case NotHonoured = 'not-honoured';
    /** A gaining-power answer below Super Admin. */
    case Gaining = 'gaining';
    /** A position nobody active holds. */
    case Vacant = 'vacant';
    /** An exception with its written reason in force. */
    case Exceptions = 'exceptions';
    /** Informational: a person who changed their own tier, rank or position since the previous run. */
    case SelfChanges = 'self-changes';

    public function label(): string
    {
        return match ($this) {
            self::ReachNothing => 'People who reach nothing',
            self::NotInForce => 'Sensitive permissions held but not in force',
            self::NotHonoured => 'Tier-only permissions stored on a position and not honoured',
            self::Gaining => 'Gaining power — answers below Super Admin',
            self::Vacant => 'Positions nobody holds',
            self::Exceptions => 'Exceptions in force',
            self::SelfChanges => 'Self-changes',
        };
    }

    /** Whether an entry on this list is something an administrator must put right. */
    public function isAlarm(): bool
    {
        return \in_array($this, [self::ReachNothing, self::NotInForce, self::NotHonoured, self::Gaining], true);
    }
}
