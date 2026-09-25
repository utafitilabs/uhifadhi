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

namespace Uhifadhi\Bundle\AreaBundle\Service;

use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;

/**
 * HOW OFTEN AN AREA'S HANDSETS REPORT A POSITION — the area's number, or half
 * an hour where it set none.
 *
 * THE AREA'S FACT, READ IN ONE PLACE. The check-in, the pings and the presence
 * derived from them are the area's, so the interval is `AreaOfInterest`'s own
 * column, written by the area settings' one write ({@see AreaIdentity}) and
 * read here by everything that counts from it: the handset's roster read
 * ({@see DutyRosterService}), the live reading ({@see PresenceService}) and any
 * module whose own thresholds are measured in intervals.
 *
 * ZERO AND BELOW ARE NOT AN INTERVAL. A phone given one would either never
 * ping or ping continuously, and both are worse than the default.
 */
final readonly class PingInterval
{
    /**
     * HALF AN HOUR, UNTIL AN AREA SAYS OTHERWISE — often enough that a watch
     * has a track rather than two points, rare enough that a day's duty does
     * not flatten the phone.
     */
    public const int DEFAULT_MINUTES = 30;

    /** What this area set, or the default where it set nothing usable. */
    public function for(AreaOfInterest $area): int
    {
        $set = $area->getPingIntervalMinutes();

        return null === $set || $set < 1 ? self::DEFAULT_MINUTES : $set;
    }
}
