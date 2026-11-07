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
 * THE THINGS THAT HAPPEN TO A STATION, and the whole of them the core knows.
 *
 * A LOG OF WHAT WAS DONE, not of what a row looks like. Every kind here is
 * something somebody did or something the ground did to the station — never a
 * field changing on its own.
 *
 * FOUR KINDS THE DESIGN DRAWS ARE NOT HERE: a radio call sign, a facilities
 * note, opening hours and a quarterly inspection round. Each is a model the
 * core does not have, and inventing an event for a field that does not exist
 * would be a log line about nothing. They are the named deferral in the
 * bundle's design decisions.
 */
enum StationEventKind: string
{
    case Recorded = 'recorded';
    case Renamed = 'renamed';
    case PointMoved = 'point_moved';
    case ZoneDerived = 'zone_derived';
    case Posted = 'posted';
    case PostingEnded = 'posting_ended';
    case LeaderAppointed = 'leader_appointed';
    case Deactivated = 'deactivated';
    case Reactivated = 'reactivated';

    /** What the log's kind chip calls a line of this kind. */
    public function label(): string
    {
        return match ($this) {
            self::Recorded => 'Opened',
            self::Renamed => 'Renamed',
            self::PointMoved => 'Point moved',
            self::ZoneDerived => 'Zone re-derived',
            self::Posted => 'Posted',
            self::PostingEnded => 'Posting ended',
            self::LeaderAppointed => 'Leader appointed',
            self::Deactivated => 'Closed',
            self::Reactivated => 'Reopened',
        };
    }

    /** The log's one loud line: a post that is no longer open. */
    public function isClosure(): bool
    {
        return self::Deactivated === $this;
    }
}
