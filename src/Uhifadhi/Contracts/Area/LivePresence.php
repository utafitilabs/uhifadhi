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
 * WHERE EVERYBODY ON AN OPEN WATCH IS, AT ONE MOMENT — and what makes
 * a position old enough to stop believing.
 *
 * THE INTERVAL TRAVELS WITH THE ANSWER. Whether a fix twenty minutes
 * old is fresh depends entirely on how often the area's handsets are
 * told to ping: at five minutes it is four missed pings and a phone in
 * trouble; at thirty it is a phone doing exactly what it was told. A
 * consumer that hardcoded a threshold would draw one area's rangers as
 * missing and another's as present on the same silence, so the area
 * states its interval here and nobody guesses.
 *
 * ASKED AT AN INSTANT, NOT FOR A DAY. {@see PresenceProviderInterface}
 * answers "how did the 19th read", keyed by the ranger's own date, and
 * every field of it is settled once the day is over. This answers
 * "where is everybody now", which is never settled — the same watch
 * gives a different answer a minute later — so it is a separate read
 * with a separate clock.
 */
final readonly class LivePresence
{
    /**
     * A FIX IS STALE AFTER THIS MANY INTERVALS, and the number is two
     * rather than one for a plain reason: a handset that pings every
     * thirty minutes is SILENT for thirty minutes by design, so calling
     * one missed deadline stale would paint every ranger amber for half
     * of every hour. Two means the phone has actually missed a ping.
     */
    public const int STALE_AFTER_INTERVALS = 2;

    /**
     * @param list<LivePosition> $positions           one per person, latest fix first
     * @param int                $pingIntervalMinutes how often this area's handsets are told to report, and
     *                                                the fallback for a position that states none of its own
     */
    public function __construct(
        public array $positions,
        public int $pingIntervalMinutes,
        /** The moment this answer was true. */
        public \DateTimeImmutable $asOf,
    ) {
    }

    /**
     * WHETHER TO STOP BELIEVING THIS POSITION — the phone has missed
     * more than one ping, so where it says somebody is may no longer be
     * where they are.
     *
     * A STALE POSITION IS NOT AN ABSENT ONE. The ranger is on an open
     * watch and the last thing anybody knows is this point; a surface
     * draws it dimmed with its age, never drops it, because a marker
     * that vanished when a phone lost signal would read as a ranger who
     * went home.
     *
     * BY ITS OWN AREA'S CLOCK WHERE IT HAS ONE. A reading across areas
     * holds positions expected at different intervals, and judging all
     * of them by one would call a thirty-minute area's rangers stale
     * beside a five-minute area's on the same silence. A position that
     * states no interval is read at the set's, which is every per-area
     * reading.
     *
     * AN AREA MAY NAME THE SILENCE ITSELF — "stale after" on its settings —
     * and a position stamped with it is judged by that instead of by two
     * intervals.
     */
    public function isStale(LivePosition $position): bool
    {
        return $position->ageSeconds($this->asOf) > $this->staleAfterSeconds($position);
    }

    /**
     * THE SILENCE THAT MAKES THIS POSITION STALE, in seconds: its area's own
     * "stale after" where it named one, two of its intervals otherwise. ONE
     * ANSWER for the server's reading and for the plate that keeps ageing a
     * mark on its own clock, so the two never disagree about the same fix.
     */
    public function staleAfterSeconds(LivePosition $position): int
    {
        if (null !== $position->staleAfterMinutes) {
            return $position->staleAfterMinutes * 60;
        }

        return ($position->pingIntervalMinutes ?? $this->pingIntervalMinutes) * 60 * self::STALE_AFTER_INTERVALS;
    }

    /** How many of the positions nobody should be believing any more. */
    public function staleCount(): int
    {
        $stale = 0;
        foreach ($this->positions as $position) {
            if ($this->isStale($position)) {
                ++$stale;
            }
        }

        return $stale;
    }

    public function isEmpty(): bool
    {
        return [] === $this->positions;
    }
}
