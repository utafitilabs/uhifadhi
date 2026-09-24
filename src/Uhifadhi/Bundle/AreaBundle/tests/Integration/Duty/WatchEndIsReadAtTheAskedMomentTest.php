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

namespace Uhifadhi\Bundle\AreaBundle\Tests\Integration\Duty;

use PHPUnit\Framework\Attributes\CoversClass;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\Entity\CheckIn;
use Uhifadhi\Bundle\AreaBundle\Entity\CheckInStatus;
use Uhifadhi\Bundle\AreaBundle\Entity\PersonPosition;
use Uhifadhi\Bundle\AreaBundle\Enum\PositionSourceEnum;
use Uhifadhi\Bundle\AreaBundle\Service\CheckInStatusService;
use Uhifadhi\Bundle\AreaBundle\Service\PresenceService;
use Uhifadhi\Bundle\AreaBundle\Service\StationService;
use Uhifadhi\Bundle\AreaBundle\Tests\Integration\Fixtures\FixtureRoster;
use Uhifadhi\Bundle\AreaBundle\Tests\Integration\Fixtures\HostPerson;
use Uhifadhi\Bundle\AreaBundle\Tests\Integration\IntegrationTestCase;
use Uhifadhi\Bundle\AreaBundle\Tests\Integration\TestKernel;
use Uhifadhi\Contracts\Roster\Watch;

/**
 * A WATCH IS OVER WHEN THE MOMENT ASKED FOR SAYS SO — NEVER WHEN THE SERVER
 * HAPPENS TO BE RUNNING.
 *
 * THE DEFECT THIS PINS. `PresenceService` drops a watch the roster has
 * already ended, and it decided "already" by reading the wall clock. So a
 * live plate asked for half past ten answered differently depending on what
 * time of day the question was put: correct all morning, and empty after the
 * evening's rostered end had passed. A suite pinning its own clock at 10:30
 * therefore passed until about six and failed every evening after — the
 * failure everybody learns to re-run rather than read.
 *
 * WHY THE KERNEL'S CLOCK IS PINNED LATE. {@see TestKernel::CLOCK} is the last
 * minute of the fixture day, so a read that still consults the clock is wrong
 * in EVERY run rather than only in the evening. That is the whole difference
 * between a bug that hides for months and one somebody fixes on the morning
 * they introduce it.
 */
#[CoversClass(PresenceService::class)]
final class WatchEndIsReadAtTheAskedMomentTest extends IntegrationTestCase
{
    private const string DAY = '2026-09-19';

    /** The watch the roster says runs 06:00 → 14:00. */
    private const string ENDS = '2026-09-19T14:00:00+03:00';

    /** Before that end, and the moment the answer is asked for. */
    private const string MID_MORNING = '2026-09-19T10:30:00+03:00';

    /** After it. */
    private const string EVENING = '2026-09-19T18:00:00+03:00';

    /**
     * THE REGRESSION. The kernel's clock says 23:59 — long past the rostered
     * end — and the caller asks for 10:30. The marker must be there: what the
     * caller asked for is the only moment that counts.
     */
    public function testAMarkerSurvivesWhenTheClockSaysTheWatchIsOverAndTheAskedMomentDoesNot(): void
    {
        $area = $this->aRangerOnAWatchEndingAtTwo();

        $live = $this->presence()->liveIn((string) $area->getUuidString(), new \DateTimeImmutable(self::MID_MORNING));

        self::assertCount(1, $live->positions, 'At 10:30 the watch is running, whatever the server clock says.');
    }

    /** And asked after the end, the same data answers the other way. */
    public function testTheSameWatchIsGoneWhenTheAskedMomentIsPastItsEnd(): void
    {
        $area = $this->aRangerOnAWatchEndingAtTwo();

        $live = $this->presence()->liveIn((string) $area->getUuidString(), new \DateTimeImmutable(self::EVENING));

        self::assertSame([], $live->positions, 'Nobody checked out and the rostered end has passed.');
    }

    /**
     * ONE READING, TWO QUESTIONS, ONE RUN — which is the proof that the
     * answer is a function of the moment asked and of nothing else. Two
     * tests that each passed on their own could both be reading a clock.
     */
    public function testTheAnswerTurnsOnTheMomentAskedAndNothingElse(): void
    {
        $area = $this->aRangerOnAWatchEndingAtTwo();
        $presence = $this->presence();
        $uuid = (string) $area->getUuidString();

        self::assertCount(1, $presence->liveIn($uuid, new \DateTimeImmutable(self::MID_MORNING))->positions);
        self::assertSame([], $presence->liveIn($uuid, new \DateTimeImmutable(self::EVENING))->positions);
        self::assertCount(1, $presence->liveIn($uuid, new \DateTimeImmutable(self::MID_MORNING))->positions, 'And back again.');
    }

    /** A ranger on an open watch the roster ends at 14:00, with one fix. */
    private function aRangerOnAWatchEndingAtTwo(): AreaOfInterest
    {
        $area = $this->anArea();

        $stations = static::getContainer()->get('test_public.area.stations');
        \assert($stations instanceof StationService);
        $station = $stations->add($area, 'Eastgate Post', -29.75, -3.2, 'ST-01');
        $station->setCatchmentM(500);
        $this->em->flush();

        $statuses = static::getContainer()->get('test_public.area.checkin_statuses');
        \assert($statuses instanceof CheckInStatusService);
        $status = null;
        foreach ($statuses->offeredBy($area) as $one) {
            if ('at_post' === $one->getKey()) {
                $status = $one;
            }
        }
        self::assertInstanceOf(CheckInStatus::class, $status);

        $person = new HostPerson()->named('Asha', 'Mollel');
        $this->em->persist($person);
        $this->em->flush();

        $checkIn = new CheckIn()
            ->setArea($area)
            ->setPerson($person)
            ->setClientRef('3b0c1f2e-5a44-4a1e-9f0e-2c7b1d9e4a10')
            ->setLocalDate(new \DateTimeImmutable(self::DAY))
            ->setStatus($status)
            ->setStation($station)
            ->setOccurredAt(new \DateTimeImmutable('2026-09-19T06:08:12+03:00'))
            ->setDeviceId('0f9ca41e')
            ->setAppVersion('0.1.0');
        $this->em->persist($checkIn);

        $ping = new PersonPosition()
            ->setArea($area)
            ->setPerson($person)
            ->setCheckIn($checkIn)
            ->setClientRef('a71c-'.uniqid())
            ->setRecordedAt(new \DateTimeImmutable('2026-09-19T10:26:00+03:00'))
            ->setPosition('{"type":"Point","coordinates":[-29.7500,-3.2000]}')
            ->setAccuracyM(8.0)
            ->setSource(PositionSourceEnum::Gps);
        $this->em->persist($ping);
        $this->em->flush();

        // THE ROSTER SAYS WHEN IT ENDS, and without one this branch is
        // unreachable: an installation with no roster has no rostered end.
        $roster = static::getContainer()->get(FixtureRoster::class);
        \assert($roster instanceof FixtureRoster);
        $roster->watches = [new Watch(
            self::DAY,
            new \DateTimeImmutable('2026-09-19T06:00:00+03:00'),
            new \DateTimeImmutable(self::ENDS),
            (string) $station->getUuidString(),
        )];

        return $area;
    }

    private function presence(): PresenceService
    {
        $presence = static::getContainer()->get('test_public.area.presence');
        \assert($presence instanceof PresenceService);

        return $presence;
    }
}
