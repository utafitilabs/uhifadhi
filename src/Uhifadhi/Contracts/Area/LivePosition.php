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

namespace Uhifadhi\Contracts\Area;

/**
 * WHERE ONE PERSON ON AN OPEN WATCH LAST WAS.
 *
 * THE LATEST FIX AND NOT A TRACK. "Where is everybody" is answered by
 * one point per person; the line they walked to get there is a
 * different question, asked of a different read, and a seam that
 * returned every ping of every open watch would hand a map five
 * thousand points to draw four people with.
 *
 * IT IS A POSITION AND A READING, TOGETHER. The point alone cannot be
 * drawn honestly: a marker at a post means one thing when a fix inside
 * the ring bears the claim out and another when nothing does, and a
 * consumer left to work that out from coordinates would work it out
 * differently from the day board. So the state is derived once, by the
 * same derivation the day is read with, and travels with the point.
 *
 * WHETHER IT IS STALE IS NOT HERE. That depends on when you ask, and
 * {@see LivePresence::isStale()} answers it.
 *
 * THE INTERVAL IS HERE, THOUGH, AND IT HAS TO BE. How often a handset
 * was told to ping is the AREA's, and a reading one scope wider holds
 * positions from several areas at once: an organization-level plate
 * given one interval for all of them would paint the rangers of a
 * thirty-minute area amber beside the rangers of a five-minute one, on
 * the same silence. So a position carries the interval it was expected
 * at, and the set's own interval is the fallback for a position that
 * does not state one.
 */
final readonly class LivePosition
{
    public function __construct(
        public string $personUuid,
        public string $personName,
        /** The claim this position belongs to, so a caller can open the watch. */
        public string $clientRef,
        /** How the watch reads — the same derivation the day board draws. */
        public DayState $state,
        public float $latitude,
        public float $longitude,
        /** When the phone took the fix, not when the server received it. */
        public \DateTimeImmutable $recordedAt,
        /** The fix's own accuracy in metres, as the handset reported it. */
        public float $accuracyM = 0.0,
        /** The post this watch claims, where it claims one. */
        public ?string $stationUuid = null,
        public ?string $stationName = null,
        /**
         * WHETHER THIS FIX FELL INSIDE THE POST'S CATCHMENT.
         *
         * NULL IS A THIRD ANSWER AND THE COMMON ONE. A watch with no
         * post has no inside; a post with no ring has no inside either.
         * Neither is "outside", and a consumer that read null as false
         * would draw a ranger as away from a post nobody drew a ring
         * around.
         */
        public ?bool $insideCatchment = null,
        /** How far the fix was from the post, where both exist. */
        public ?float $distanceM = null,
        /** What the handset had left, where it said. */
        public ?int $batteryPct = null,
        /**
         * HOW OFTEN THIS POSITION'S OWN AREA TELLS ITS HANDSETS TO PING.
         *
         * Null where the caller is reading one area and the set already
         * states it — which is every per-area reading, and why this is
         * last and optional. A reading ACROSS areas fills it, because
         * that is the only way one plate can judge two areas' silences
         * by their own clocks.
         */
        public ?int $pingIntervalMinutes = null,
        /**
         * When the area stops believing a fix, in minutes, where it said so;
         * null is the rule every area starts with, two intervals.
         */
        public ?int $staleAfterMinutes = null,
    ) {
    }

    /** How long ago this fix was taken, in seconds, at the moment asked. */
    public function ageSeconds(\DateTimeImmutable $asOf): int
    {
        return max(0, $asOf->getTimestamp() - $this->recordedAt->getTimestamp());
    }
}
