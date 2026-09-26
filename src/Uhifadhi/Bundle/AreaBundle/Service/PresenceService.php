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

use Psr\Clock\ClockInterface;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\Entity\CheckIn;
use Uhifadhi\Bundle\AreaBundle\Enum\CheckInStatusKind;
use Uhifadhi\Bundle\AreaBundle\Model\WatchFacts;
use Uhifadhi\Bundle\AreaBundle\Repository\AreaOfInterestRepository;
use Uhifadhi\Bundle\AreaBundle\Repository\CheckInRepository;
use Uhifadhi\Contracts\Area\DayState;
use Uhifadhi\Contracts\Area\LivePosition;
use Uhifadhi\Contracts\Area\LivePositionsInterface;
use Uhifadhi\Contracts\Area\LivePresence;
use Uhifadhi\Contracts\Area\PersonDay;
use Uhifadhi\Contracts\Area\PersonWatch;
use Uhifadhi\Contracts\Area\PresenceProviderInterface;
use Uhifadhi\Contracts\Area\UnverifiedReason;
use Uhifadhi\Contracts\Roster\WatchProviderInterface;
use Uhifadhi\Contracts\Shell\Scope;

/**
 * HOW A DAY READS — judged here, on every read, from facts stored on the
 * check-in rows; no verdict is stored anywhere.
 *
 * THE CLAIM AND THE PROOF ARE TWO DIFFERENT RECORDS and this is the
 * only place they meet. A check-in says "at post"; the pings say where
 * the phone was; the post says what inside means. `verified` is the
 * answer to a question asked at the moment of asking — so a catchment
 * corrected next month re-judges every day that used it, which is
 * precisely what a stored verdict could not do.
 *
 * FACTS ON THE ROW, JUDGEMENTS ON READ. What the pings said is folded
 * into each watch's own row as they arrive — how many, the first and the
 * last, the newest fix and its distance from the post, the nearest any
 * fix came to the post ({@see PresenceFactsService}). Those are facts: a
 * ring moved or a ping interval changed alters none of them. What is
 * judged from them — verified or not and why, late, silent, still on
 * watch — is judged here, against the ring and the interval as they
 * stand at the moment of reading. `area:presence:rebuild` recomputes the
 * facts from the kept pings whenever a post's point or the zones move.
 *
 * A READ COSTS THE ROWS, NOT THE PINGS. A board reads the day's rows, a
 * live plate the open ones, a person's day that person's — a fixed number
 * of statements, whatever the headcount and however many pings a watch
 * sent. Only the roster, asked per open watch whether its rostered end
 * has passed, adds one question per person on watch.
 *
 * UNVERIFIED IS NEVER AN ACCUSATION. It has three causes and the
 * reading says which: no position arrived, the post has no ring, or the
 * position fell outside it. Only the third is about where somebody
 * stood, and even that is a fact about a fix rather than about a
 * person.
 *
 * THE WATCH CLOSES ITSELF ON READ. A ranger who never checked out is
 * not an open watch forever: the rostered end closes it and the day is
 * marked `not_checked_out`. Derived, like everything else here — a
 * state that needed a nightly job to be true would be wrong every night
 * the job did not run, and right again the morning somebody noticed.
 *
 * THE LAST CORRECTION OF THE DAY IS WHAT THE DAY WAS. A ranger who
 * checked in at post and left on an escort at 07:40 had both; the day's
 * own reading is where they ended, and the morning is still on the
 * record for anybody who asks for 06:30.
 */
final readonly class PresenceService implements PresenceProviderInterface, LivePositionsInterface, PersonLivePositionsInterface
{
    public function __construct(
        /**
         * WHAT TIME IT IS, ASKED OF SOMETHING A TEST CAN SET.
         *
         * This service used to read the wall clock in two places, and both
         * of them decided whether a watch was over. A suite that pins a
         * clock at half past ten therefore passed all morning and failed
         * after six — the roster's demo plate emptied itself, because every
         * open watch read as one the roster had already ended. A reading
         * that changes with the hour it is run at is not a reading.
         *
         * A CALLER THAT STATES A MOMENT STILL WINS. Every public read here
         * that takes an `$asOf` passes it down, so an answer a caller asked
         * for at a fixed instant is reproducible without a clock at all;
         * this is what answers "now" for the reads that have no instant of
         * their own, notably a whole day's board.
         */
        private ClockInterface $clock,
        private AreaOfInterestRepository $areas,
        private CheckInRepository $checkIns,
        // HOW OFTEN THIS AREA'S HANDSETS REPORT — the one reading of it.
        private PingInterval $pingInterval,
        /** @var iterable<WatchProviderInterface> */
        private iterable $rosters = [],
        // WHO MAY SEE WHOM: the rank rule, applied to every live read a
        // viewer is handed. Absent in a kernel without security.
        private ?LiveVisibility $visibility = null,
    ) {
    }

    public function dayIn(string $areaUuid, string $localDate): array
    {
        $area = $this->areas->findOneBy(['uuid' => $areaUuid]);
        if (null === $area) {
            return [];
        }

        $day = new \DateTimeImmutable($localDate);

        /*
         * ONE PERSON, ONE DAY, HOWEVER MANY WATCHES. Ruled 2026-09-21: a
         * day holds any number of check-in/check-out pairs. Reading each
         * claim as its own "day" would hand a caller two entries for one
         * person on one date, and whichever it kept first would be the
         * only one it ever saw.
         *
         * Keyed while folding and re-indexed on the way out, so the list
         * is a list and the order is the order people first reported.
         */
        // ONE INSTANT FOR THE WHOLE BOARD. A day has no moment of its own,
        // so the clock supplies one — once, here, rather than per row: rows
        // that each asked the clock could close one watch and leave the next
        // open on the same second.
        $asOf = $this->clock->now();

        return $this->daysOf($this->checkIns->findForDay($area, $day), $areaUuid, $localDate, $asOf);
    }

    /**
     * THE CLAIMS OF ONE DAY, FOLDED INTO PEOPLE — each watch judged from its
     * own row's facts, read for the whole set in one statement.
     *
     * @param list<CheckIn> $checkIns
     *
     * @return list<PersonDay>
     */
    private function daysOf(array $checkIns, string $areaUuid, string $localDate, \DateTimeImmutable $asOf): array
    {
        $facts = $this->checkIns->findFactsFor($checkIns);

        $days = [];
        foreach ($checkIns as $checkIn) {
            $person = $checkIn->getPerson();
            $uuid = (string) $person?->getUuidString();

            $days[$uuid] ??= [
                'name' => $person?->getFullName() ?? '',
                'watches' => [],
            ];
            $days[$uuid]['watches'][] = $this->watch($checkIn, $facts[(int) $checkIn->getId()] ?? new WatchFacts(), $areaUuid, $localDate, $asOf);
        }

        $read = [];
        foreach ($days as $uuid => $held) {
            /** @var list<PersonWatch> $watches */
            $watches = $held['watches'];
            $read[] = self::fold($uuid, (string) $held['name'], $localDate, $watches);
        }

        return $read;
    }

    /**
     * WHERE EVERYBODY ON AN OPEN WATCH IS, at the moment asked.
     *
     * ONE DERIVATION, READ TWICE. The state on every marker comes from
     * {@see watch()} — the same code the day board reads — so a ranger
     * cannot be "at post, verified" on the map and unverified on the
     * board. What the map adds is the LATEST fix rather than the
     * nearest one: the board asks whether the claim was ever borne out,
     * and the map asks where the phone is now.
     *
     * A WATCH WITH NO FIX IS NOT A MARKER. They may well be at their
     * post; what is absent is a POSITION, and drawing one at the post's
     * own point would turn a claim into proof.
     */
    public function liveIn(string $areaUuid, \DateTimeImmutable $asOf): LivePresence
    {
        $area = $this->areas->findOneBy(['uuid' => $areaUuid]);
        if (null === $area) {
            return new LivePresence([], PingInterval::DEFAULT_MINUTES, $asOf);
        }

        return new LivePresence($this->visible($area, $this->positionsIn($area, $this->checkIns->findOpenIn($area), $asOf)), $this->intervalOf($area), $asOf);
    }

    /**
     * ONE PERSON'S LIVE READING — the frame a ping publishes. The same
     * derivation as {@see liveIn()}, over that person's open watches only,
     * so the mark on the wire and the mark on the plate cannot disagree and
     * building it costs one ranger's row.
     */
    public function liveOf(string $areaUuid, string $personUuid, \DateTimeImmutable $asOf): LivePresence
    {
        $area = $this->areas->findOneBy(['uuid' => $areaUuid]);
        if (null === $area) {
            return new LivePresence([], PingInterval::DEFAULT_MINUTES, $asOf);
        }

        return new LivePresence(
            $this->positionsIn($area, $this->checkIns->findOpenForPerson($area, $personUuid), $asOf),
            $this->intervalOf($area),
            $asOf,
        );
    }

    /**
     * THE SAME READING, ONE SCOPE WIDER — the organization, or one area of
     * it.
     *
     * NOT A SECOND AGGREGATE. This walks the very loop {@see liveIn()} walks
     * and concatenates it; there is no org-level query, no second derivation
     * and no other place a live position can come from. Two code paths would
     * drift, and the day they disagreed nobody could say which was right —
     * so the organization's answer is the areas' answers, and a test asserts
     * exactly that.
     *
     * EVERY POSITION KEEPS ITS OWN AREA'S CLOCK. Areas ping at different
     * intervals, and one number for all of them would call a thirty-minute
     * area's rangers stale beside a five-minute area's on the same silence.
     * So each position states the interval it was expected at and the set
     * states the default for anything that does not.
     *
     * THE FRESHEST FIRST, ACROSS AREAS, for the reason one area's are: a
     * rail beside a map is read from the top, and the top is where the news
     * is. Which area a fix came from is a column, not an ordering.
     */
    public function forScope(Scope $scope, \DateTimeImmutable $asOf): LivePresence
    {
        if (!$scope->isOrganization()) {
            return $this->liveIn((string) $scope->areaUuid, $asOf);
        }

        $positions = [];
        foreach ($this->areas->findAllOrdered() as $area) {
            foreach ($this->visible($area, $this->positionsIn($area, $this->checkIns->findOpenIn($area), $asOf, $this->intervalOf($area))) as $position) {
                $positions[] = $position;
            }
        }

        usort(
            $positions,
            static fn (LivePosition $a, LivePosition $b): int => $b->recordedAt <=> $a->recordedAt,
        );

        return new LivePresence($positions, PingInterval::DEFAULT_MINUTES, $asOf);
    }

    /**
     * The positions the signed-in viewer may see ({@see LiveVisibility}). The
     * one-person read the publisher uses is not a viewer's and is not narrowed.
     *
     * @param list<LivePosition> $positions
     *
     * @return list<LivePosition>
     */
    private function visible(AreaOfInterest $area, array $positions): array
    {
        return null === $this->visibility ? $positions : $this->visibility->visibleIn($area, $positions);
    }

    /** How often this area tells its handsets to report, defaulted and floored. */
    private function intervalOf(AreaOfInterest $area): int
    {
        return $this->pingInterval->for($area);
    }

    /**
     * ONE AREA'S LIVE POSITIONS, freshest first — the whole of the
     * derivation, and the only place it happens. The position is the row's
     * newest fix; its distance from the post is the row's; whether that is
     * inside is asked of the ring now.
     *
     * @param list<CheckIn> $open          the open watches to read — the area's, or one person's
     * @param int|null      $stampInterval the area's ping interval, stamped onto every
     *                                     position so a reading ACROSS areas can judge
     *                                     each one's silence by its own clock. Null for
     *                                     a single-area read, where the set states it
     *                                     once and every position shares it
     *
     * @return list<LivePosition>
     */
    private function positionsIn(AreaOfInterest $area, array $open, \DateTimeImmutable $asOf, ?int $stampInterval = null): array
    {
        $areaUuid = (string) $area->getUuidString();
        $facts = $this->checkIns->findFactsFor($open);

        $positions = [];
        foreach ($open as $checkIn) {
            $person = $checkIn->getPerson();
            $uuid = $person?->getUuidString();
            $fix = $facts[(int) $checkIn->getId()] ?? new WatchFacts();
            if (null === $uuid || null === $fix->latitude || null === $fix->longitude || null === $fix->lastFixAt) {
                continue;
            }

            // THE DAY THE WATCH BELONGS TO, in the ranger's own words —
            // what `watch()` needs to ask the roster whether the watch
            // should already have closed.
            $localDate = $checkIn->getLocalDate()?->format('Y-m-d') ?? $asOf->format('Y-m-d');
            $watch = $this->watch($checkIn, $fix, $areaUuid, $localDate, $asOf);

            // A WATCH THE ROSTER HAS ALREADY ENDED IS NOT LIVE. Nobody
            // checked out, but the rostered end passed — its last ping is
            // where somebody was when they stopped, and a live map would
            // stand a marker at a post nobody is at.
            if ($watch->notCheckedOut) {
                continue;
            }

            $station = $checkIn->stateAt($asOf)['station'];
            $catchment = $station?->getCatchmentM();
            $distance = null === $station ? null : $fix->lastFixM;

            $positions[] = new LivePosition(
                personUuid: $uuid,
                personName: $person->getFullName(),
                clientRef: $checkIn->getClientRef(),
                state: $watch->state,
                latitude: $fix->latitude,
                longitude: $fix->longitude,
                recordedAt: $fix->lastFixAt,
                accuracyM: $fix->lastFixAccuracyM ?? 0.0,
                stationUuid: $station?->getUuidString(),
                stationName: $station?->getName(),
                // NULL WHERE THERE IS NO INSIDE TO BE IN — no post, no
                // ring, or no distance to measure. Not false, which would
                // read as "outside".
                insideCatchment: null === $distance || null === $catchment ? null : $distance <= $catchment,
                distanceM: $distance,
                batteryPct: $fix->lastFixBatteryPct,
                pingIntervalMinutes: $stampInterval,
            );
        }

        // THE FRESHEST FIRST, because a rail beside a map is read from
        // the top and the top is where the news is.
        usort(
            $positions,
            static fn (LivePosition $a, LivePosition $b): int => $b->recordedAt <=> $a->recordedAt,
        );

        return $positions;
    }

    /**
     * THE DAY, OUT OF ITS WATCHES.
     *
     * The day READS as its last watch — what somebody is doing now, or
     * finished the day doing, is what a board is asking when it colours
     * a name. The totals are the whole day's, and the first claim is the
     * first claim.
     *
     * @param list<PersonWatch> $watches
     */
    private static function fold(string $personUuid, string $personName, string $localDate, array $watches): PersonDay
    {
        $pings = 0;
        $lastPingAt = null;
        foreach ($watches as $watch) {
            $pings += $watch->pings;
            if (null !== $watch->lastPingAt && (null === $lastPingAt || $watch->lastPingAt > $lastPingAt)) {
                $lastPingAt = $watch->lastPingAt;
            }
        }

        // A DAY WITH NO WATCH IN IT IS NOT BUILT — this is only ever called
        // with the claims somebody made — so the last one is the day's
        // reading and there is always one.
        $last = [] === $watches ? null : $watches[\count($watches) - 1];

        return new PersonDay(
            personUuid: $personUuid,
            personName: $personName,
            localDate: $localDate,
            state: null === $last ? DayState::NotWorking : $last->state,
            watches: $watches,
            occurredAt: ([] === $watches ? null : $watches[0]->occurredAt),
            lastPingAt: $lastPingAt,
            pings: $pings,
        );
    }

    /**
     * ONE PERSON'S DAY, read from that person's rows alone — the same fold
     * and the same judgement {@see dayIn()} makes for the whole board, so the
     * person's calendar and the board cannot disagree about a day.
     */
    public function dayFor(string $areaUuid, string $personUuid, string $localDate): ?PersonDay
    {
        $area = $this->areas->findOneBy(['uuid' => $areaUuid]);
        if (null === $area) {
            return null;
        }

        $checkIns = $this->checkIns->findForPersonDay($area, $personUuid, new \DateTimeImmutable($localDate));

        return $this->daysOf($checkIns, $areaUuid, $localDate, $this->clock->now())[0] ?? null;
    }

    /**
     * One claim, read against its own proof — one watch of somebody's day.
     *
     * `$asOf` IS THE MOMENT THE READING IS FOR, and it is an argument rather
     * than a clock read here so that every watch of one answer is judged at
     * the SAME instant: a board whose rows each asked the clock separately
     * could close one watch and leave the next open on the same second.
     */
    private function watch(CheckIn $checkIn, WatchFacts $facts, string $areaUuid, string $localDate, \DateTimeImmutable $asOf): PersonWatch
    {
        // WHAT THE DAY ENDED AS. A correction is a second claim from its
        // own moment, and the day's reading is the last of them.
        $end = $checkIn->getEndedAt() ?? $asOf;
        $state = $checkIn->stateAt($end);

        $status = $state['status'];
        $station = $state['station'];
        $kind = $status?->getKind() ?? CheckInStatusKind::AtPost;

        $distance = null;
        $reason = null;
        $day = match ($kind) {
            CheckInStatusKind::WorkingElsewhere => DayState::WorkingElsewhere,
            CheckInStatusKind::NotWorking => DayState::NotWorking,
            CheckInStatusKind::Special => DayState::Special,
            CheckInStatusKind::AtPost => DayState::AtPostUnverified,
        };

        if (CheckInStatusKind::AtPost === $kind) {
            // THE NEAREST ANY FIX CAME TO THE POST, from the row; the ring
            // it is held against is the post's as it stands now.
            $catchment = $station?->getCatchmentM();
            $nearest = null === $station ? null : $facts->closestM;

            if (null === $nearest) {
                // NO POSITION AT ALL — the never-block rule's own state:
                // the claim stands and nothing bears it out yet.
                $reason = UnverifiedReason::NoFix;
            } elseif (null === $catchment) {
                // A POST WITH NO RING HAS NO INSIDE. Not the ranger's doing
                // and never drawn as such.
                $distance = $nearest;
                $reason = UnverifiedReason::NoRing;
            } elseif ($nearest <= $catchment) {
                $distance = $nearest;
                $day = DayState::AtPostVerified;
            } else {
                $distance = $nearest;
                $reason = UnverifiedReason::OutsideRing;
            }
        }

        return new PersonWatch(
            clientRef: $checkIn->getClientRef(),
            state: $day,
            statusKey: $status?->getKey(),
            statusLabel: $status?->getLabel(),
            stationUuid: $station?->getUuidString(),
            stationName: $station?->getName(),
            unverifiedReason: $reason,
            distanceM: $distance,
            occurredAt: $checkIn->getOccurredAt(),
            endedAt: $checkIn->getEndedAt(),
            notCheckedOut: $this->notCheckedOut($checkIn, $areaUuid, $localDate, $asOf),
            lastPingAt: $facts->lastPingAt,
            pings: $facts->pings,
            handoverNote: $checkIn->getHandoverNote(),
            note: $state['note'],
        );
    }

    /**
     * A WATCH THAT ENDED AND NOBODY CLOSED.
     *
     * The rostered end is the roster's to say. An installation with no
     * roster module has no rostered end, so an open watch is simply
     * still open — the phone may yet check out, and marking it closed
     * on a guess would be the server inventing a time somebody stopped
     * work.
     */
    private function notCheckedOut(CheckIn $checkIn, string $areaUuid, string $localDate, \DateTimeImmutable $asOf): bool
    {
        if (null !== $checkIn->getEndedAt()) {
            return false;
        }

        $person = $checkIn->getPerson()?->getUuidString();
        if (null === $person) {
            return false;
        }

        foreach ($this->rosters as $roster) {
            foreach ($roster->watchesFor($areaUuid, $person, $localDate, $localDate) as $watch) {
                // AGAINST THE MOMENT THE READING IS FOR, never the wall
                // clock: a live plate asked for 10:30 must answer for 10:30
                // whatever time the server happens to be running at.
                if ($watch->endsAt < $asOf) {
                    return true;
                }
            }
        }

        return false;
    }
}
