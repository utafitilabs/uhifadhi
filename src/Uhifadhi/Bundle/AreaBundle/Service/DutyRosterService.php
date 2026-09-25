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
use Uhifadhi\Bundle\AreaBundle\Entity\CheckInStatus;
use Uhifadhi\Contracts\Entity\UserInterface;
use Uhifadhi\Contracts\Roster\Watch;
use Uhifadhi\Contracts\Roster\WatchProviderInterface;

/**
 * WHAT A HANDSET READS AT SIGN-IN AND ON EVERY SYNC — API-CONTRACT.md §13D.
 *
 * THREE THINGS THAT TRAVEL TOGETHER because the phone needs all three
 * before it can draw a month: the watches somebody is rostered for, the
 * words this area lets them check in with, and how often to ping while
 * a watch is open.
 *
 * THE AREA ASKS AND DOES NOT KEEP THE ROSTER. Who is on which watch is
 * a module this platform has not written yet, so it arrives through
 * {@see WatchProviderInterface}; an installation with no roster answers
 * nothing, and that is not an error. **A DAY WITH NO WATCH IS A REST
 * DAY** — the app draws no row, no dot and no reminder for it, so an
 * absence here is the answer rather than a gap.
 *
 * TWO PROVIDERS ARE TWO ROSTERS, NOT A MERGE. In practice an
 * installation runs one, and folding two together would invent watches
 * neither of them published; they are concatenated in registration
 * order and the phone reads a longer list.
 *
 * THE INTERVAL IS THE AREA'S AND IS HARDCODED NOWHERE IN THE APP. A
 * host that omits it leaves the phone on the last value it was given —
 * behind, not wrong — which is why it is always sent.
 */
final readonly class DutyRosterService
{
    /** The product's interval where an area sets none — {@see PingInterval::DEFAULT_MINUTES}. */
    public const int DEFAULT_PING_INTERVAL_MINUTES = PingInterval::DEFAULT_MINUTES;

    /** @param iterable<WatchProviderInterface> $rosters */
    public function __construct(
        private CheckInStatusService $statuses,
        // THE AREA'S NUMBER, read where the presence derivation reads it.
        private PingInterval $pingInterval,
        private iterable $rosters = [],
    ) {
    }

    /**
     * ONE PERSON'S MONTH IN ONE AREA, as the contract's document.
     *
     * @param string $from `2026-09-01`
     * @param string $to   `2026-09-30`
     *
     * @return array{pingIntervalMinutes: int, watches: list<array<string, mixed>>, checkInStatuses: list<array<string, mixed>>, updatedAt: string|null}
     */
    public function readFor(AreaOfInterest $area, UserInterface $person, string $from, string $to): array
    {
        $watches = $this->watches($area, $person, $from, $to);

        return [
            'pingIntervalMinutes' => $this->pingIntervalFor($area),
            /*
             * WHETHER THIS PERSON IS ROSTERED AT ALL, which is the
             * question a rest day and an unrostered ranger answer
             * differently and an empty `watches` array does not.
             *
             * A HANDSET HAS TO TELL THEM APART. Somebody rostered with
             * no watch today is ON A REST DAY and the phone says so;
             * somebody the roster has never heard of is not on a rest
             * day at all, and the phone must still offer them a
             * check-in — a ranger called in for one shift cannot be
             * refused the screen because nobody planned them.
             *
             * FALSE WHERE THERE IS NO ROSTER MODULE, and that is the
             * same answer for the same reason: an installation with no
             * roster plans nobody, so everybody may check in.
             */
            'rostered' => [] !== $watches,
            'watches' => array_map(
                static fn (Watch $watch): array => [
                    'localDate' => $watch->localDate,
                    'startsAt' => $watch->startsAt->format(\DateTimeInterface::ATOM),
                    'endsAt' => $watch->endsAt->format(\DateTimeInterface::ATOM),
                    'stationUuid' => $watch->stationUuid,
                    'label' => $watch->label,
                ],
                $watches,
            ),
            'checkInStatuses' => array_map(
                static fn (CheckInStatus $status): array => [
                    'key' => $status->getKey(),
                    'label' => $status->getLabel(),
                    'kind' => $status->getKind()->value,
                    'takesStation' => $status->takesStation(),
                    'order' => $status->getPosition(),
                ],
                $this->statuses->offeredBy($area),
            ),
            /*
             * WHEN THE WORDS LAST CHANGED, so a phone can tell whether the
             * list it is holding is the list the area publishes. Null is a
             * real answer: an area whose statuses have never been touched
             * has nothing to compare against, and the phone takes what it
             * was sent.
             */
            'updatedAt' => $this->statuses->lastChangedFor($area)?->format(\DateTimeInterface::ATOM),
        ];
    }

    /** What this area set, or the product's default where it set nothing. */
    public function pingIntervalFor(AreaOfInterest $area): int
    {
        return $this->pingInterval->for($area);
    }

    /**
     * @return list<Watch>
     */
    private function watches(AreaOfInterest $area, UserInterface $person, string $from, string $to): array
    {
        $areaUuid = $area->getUuidString();
        $personUuid = $person->getUuidString();
        if (null === $areaUuid || null === $personUuid) {
            return [];
        }

        $watches = [];
        foreach ($this->rosters as $roster) {
            foreach ($roster->watchesFor($areaUuid, $personUuid, $from, $to) as $watch) {
                $watches[] = $watch;
            }
        }

        return $watches;
    }
}
