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
use Uhifadhi\Bundle\AreaBundle\Entity\CheckInCorrection;
use Uhifadhi\Bundle\AreaBundle\Entity\CheckInStatus;
use Uhifadhi\Bundle\AreaBundle\Entity\PersonPosition;
use Uhifadhi\Bundle\AreaBundle\Entity\Station;
use Uhifadhi\Bundle\AreaBundle\Enum\PositionSourceEnum;
use Uhifadhi\Bundle\AreaBundle\Service\CheckInStatusService;
use Uhifadhi\Bundle\AreaBundle\Service\PresenceService;
use Uhifadhi\Bundle\AreaBundle\Service\StationService;
use Uhifadhi\Bundle\AreaBundle\Tests\Integration\Fixtures\HostPerson;
use Uhifadhi\Bundle\AreaBundle\Tests\Integration\IntegrationTestCase;
use Uhifadhi\Contracts\Area\DayState;
use Uhifadhi\Contracts\Area\PersonDay;
use Uhifadhi\Contracts\Area\PersonWatch;
use Uhifadhi\Contracts\Area\UnverifiedReason;

/**
 * HOW A DAY READS — derived on every read, and stored nowhere.
 *
 * THE CLAIM AND THE PROOF ARE TWO RECORDS. A ranger says "at station"; the
 * pings say where the phone was; the post says what inside means. This
 * is where the three meet, and the answer is computed at the moment of
 * asking — so a catchment corrected next month re-derives every day
 * that used it, which is exactly what a stored verdict could not do.
 *
 * UNVERIFIED IS NEVER AN ACCUSATION, and it has three different causes
 * that a page must be able to tell apart.
 */
#[CoversClass(PresenceService::class)]
final class PresenceTest extends IntegrationTestCase
{
    private const string DAY = '2026-09-19';

    /** A position inside the post's ring bears the claim out. */
    public function testAPositionInsideTheRingVerifiesTheDay(): void
    {
        [$area, $station] = $this->anAreaWithAPost(catchment: 300);
        $checkIn = $this->aClaim($area, $station, 'at_post');
        $this->aPing($checkIn, -29.7501, -3.2001);

        $day = $this->today($area);

        self::assertSame(DayState::AtPostVerified, $day->state);
        self::assertNull($this->watchOf($day)->unverifiedReason);
        self::assertNotNull($this->watchOf($day)->distanceM);
        self::assertSame(1, $day->pings);
    }

    /** And one outside it does not — which is a fact about a fix. */
    public function testAPositionOutsideTheRingLeavesTheDayUnverified(): void
    {
        [$area, $station] = $this->anAreaWithAPost(catchment: 50);
        $checkIn = $this->aClaim($area, $station, 'at_post');
        // ~1.5 km away: well outside a fifty-metre ring.
        $this->aPing($checkIn, -29.735, -3.2);

        $day = $this->today($area);

        self::assertSame(DayState::AtPostUnverified, $day->state);
        self::assertSame(UnverifiedReason::OutsideRing, $this->watchOf($day)->unverifiedReason);
    }

    /**
     * NO FIX IS ITS OWN REASON, and never "absent": the claim stands and
     * nothing bears it out yet. This is the never-block rule's own state.
     */
    public function testAClaimWithNoPositionAtAllIsUnverifiedAndNotAbsent(): void
    {
        [$area, $station] = $this->anAreaWithAPost(catchment: 300);
        $this->aClaim($area, $station, 'at_post');

        $day = $this->today($area);

        self::assertSame(DayState::AtPostUnverified, $day->state);
        self::assertSame(UnverifiedReason::NoFix, $this->watchOf($day)->unverifiedReason);
        self::assertSame(0, $day->pings);
    }

    /** A post with no ring has no inside, and that is not the ranger's doing. */
    public function testAPostWithNoCatchmentCannotVerifyAnybody(): void
    {
        [$area, $station] = $this->anAreaWithAPost(catchment: null);
        $checkIn = $this->aClaim($area, $station, 'at_post');
        $this->aPing($checkIn, -29.7501, -3.2001);

        $day = $this->today($area);

        self::assertSame(DayState::AtPostUnverified, $day->state);
        self::assertSame(UnverifiedReason::NoRing, $this->watchOf($day)->unverifiedReason);
    }

    /**
     * A CATCHMENT CORRECTED RE-DERIVES EVERY DAY THAT USED IT — the whole
     * reason the verdict is not stored.
     */
    public function testWideningTheRingReDerivesADayAlreadyRecorded(): void
    {
        [$area, $station] = $this->anAreaWithAPost(catchment: 50);
        $checkIn = $this->aClaim($area, $station, 'at_post');
        $this->aPing($checkIn, -29.7495, -3.2);

        self::assertSame(DayState::AtPostUnverified, $this->today($area)->state);

        $station->setCatchmentM(2000);
        $this->em->flush();
        $this->em->clear();

        self::assertSame(
            DayState::AtPostVerified,
            $this->today($area)->state,
            'the same day, read again against a corrected station',
        );
    }

    /** A status that is not at-post reads as its own kind, and needs no proof. */
    public function testAStatusThatIsNotAtPostIsReadAsItsKind(): void
    {
        [$area] = $this->anAreaWithAPost(catchment: 300);
        $this->aClaim($area, null, 'outside');

        $day = $this->today($area);

        self::assertSame(DayState::WorkingElsewhere, $day->state);
        self::assertNull($this->watchOf($day)->unverifiedReason);
        self::assertTrue($day->state->countsAsPresent());
    }

    /** Unfit is not working, and not working is not the same as no check-in. */
    public function testUnfitIsNotWorkingAndIsNotAnAbsence(): void
    {
        [$area] = $this->anAreaWithAPost(catchment: 300);
        $this->aClaim($area, null, 'unfit');

        $day = $this->today($area);

        self::assertSame(DayState::NotWorking, $day->state);
        self::assertFalse($day->state->countsAsPresent());
    }

    /**
     * THE LAST CORRECTION IS WHAT THE DAY WAS, and the morning is still on
     * the record for anybody who asks for the morning.
     */
    public function testACorrectionIsWhatTheDayEndedAsAndTheMorningSurvives(): void
    {
        [$area, $station] = $this->anAreaWithAPost(catchment: 300);
        $checkIn = $this->aClaim($area, $station, 'at_post');
        $this->aCorrection($checkIn, 'special', '2026-09-19T07:40:00+03:00');

        self::assertSame(DayState::Special, $this->today($area)->state, 'the day ended on the escort');

        // AND THE 06:08 CLAIM IS STILL EXACTLY WHAT IT WAS.
        $morning = $checkIn->stateAt(new \DateTimeImmutable('2026-09-19T06:30:00+03:00'));
        self::assertSame('at_post', $morning['status']?->getKey());
        self::assertNotNull($morning['station']);
    }

    /** Nobody who reported nothing is in the answer — that is the roster's question. */
    public function testADayNobodyReportedIsNotARow(): void
    {
        [$area] = $this->anAreaWithAPost(catchment: 300);

        self::assertSame([], $this->presence()->dayIn((string) $area->getUuidString(), self::DAY));
    }

    private string $personUuid = '';

    /** The one person's day as it reads now — asserted to exist, once. */
    private function today(AreaOfInterest $area): PersonDay
    {
        $day = $this->presence()->dayFor((string) $area->getUuidString(), $this->personUuid, self::DAY);
        self::assertInstanceOf(PersonDay::class, $day);

        return $day;
    }

    /**
     * A DAY HOLDS ANY NUMBER OF WATCHES — ruled 2026-09-21. Somebody
     * checks in at dawn, checks out at noon and checks in again at four,
     * and all three facts are ONE day: a timesheet, not a from-to.
     */
    public function testADayHoldsEveryWatchSomebodyWorked(): void
    {
        [$area, $station] = $this->anAreaWithAPost(300);

        $morning = $this->aClaim($area, $station, 'at_post');
        $morning->setEndedAt(new \DateTimeImmutable('2026-09-19T12:00:00+03:00'));

        $afternoon = $this->anotherClaim($area, $station, 'at_post', 'c0ffee00-0000-4000-8000-000000000002', '2026-09-19T16:00:00+03:00');
        $afternoon->setEndedAt(new \DateTimeImmutable('2026-09-19T18:30:00+03:00'));
        $this->em->flush();

        $day = $this->presence()->dayFor((string) $area->getUuidString(), $this->personUuid, self::DAY);
        self::assertInstanceOf(PersonDay::class, $day);

        self::assertCount(2, $day->watches, 'a second check-in after a check-out is a second watch, not a second day');
        self::assertSame('2026-09-19T06:08:12+03:00', $day->watches[0]->occurredAt?->format(\DateTimeInterface::ATOM));
        self::assertSame('2026-09-19T16:00:00+03:00', $day->watches[1]->occurredAt?->format(\DateTimeInterface::ATOM));
    }

    /** And the person appears ONCE, not once per claim. */
    public function testAPersonWhoWorkedTwiceIsOnePersonInTheDay(): void
    {
        [$area, $station] = $this->anAreaWithAPost(300);

        $this->aClaim($area, $station, 'at_post')->setEndedAt(new \DateTimeImmutable('2026-09-19T12:00:00+03:00'));
        $this->anotherClaim($area, $station, 'at_post', 'c0ffee00-0000-4000-8000-000000000002', '2026-09-19T16:00:00+03:00');
        $this->em->flush();

        $read = $this->presence()->dayIn((string) $area->getUuidString(), self::DAY);

        self::assertCount(1, $read, 'two claims by one person on one date are one day');
        self::assertSame($this->personUuid, $read[0]->personUuid);
    }

    /**
     * THE DAY TOTALS THE WATCHES THAT CLOSED. An open watch adds nothing
     * YET and is not nought — it has no length until somebody checks
     * out, and guessing one would make a total that moves while nobody
     * is doing anything.
     */
    public function testTheDayTotalsTheClosedWatchesAndSaysOneIsStillOpen(): void
    {
        [$area, $station] = $this->anAreaWithAPost(300);

        // 06:08 → 12:00 is 351 minutes; the afternoon is still open.
        $this->aClaim($area, $station, 'at_post')->setEndedAt(new \DateTimeImmutable('2026-09-19T12:00:00+03:00'));
        $this->anotherClaim($area, $station, 'at_post', 'c0ffee00-0000-4000-8000-000000000002', '2026-09-19T16:00:00+03:00');
        $this->em->flush();

        $day = $this->presence()->dayFor((string) $area->getUuidString(), $this->personUuid, self::DAY);
        self::assertInstanceOf(PersonDay::class, $day);

        self::assertSame(351, $day->minutesOnDuty());
        self::assertTrue($day->hasOpenWatch());
        self::assertNull($day->watches[1]->minutes(), 'an open watch has no length yet');
    }

    /**
     * THE DAY READS AS ITS LAST WATCH. What somebody is doing now — or
     * finished the day doing — is what a board is asking when it colours
     * a name; the earlier watches are there for anybody who needs more.
     */
    public function testTheDayReadsAsTheLastWatch(): void
    {
        [$area, $station] = $this->anAreaWithAPost(300);

        $this->aClaim($area, $station, 'at_post')->setEndedAt(new \DateTimeImmutable('2026-09-19T12:00:00+03:00'));
        $this->anotherClaim($area, $station, 'outside', 'c0ffee00-0000-4000-8000-000000000002', '2026-09-19T16:00:00+03:00');
        $this->em->flush();

        $day = $this->presence()->dayFor((string) $area->getUuidString(), $this->personUuid, self::DAY);
        self::assertInstanceOf(PersonDay::class, $day);

        self::assertSame(DayState::WorkingElsewhere, $day->state);
        self::assertSame('at_post', $day->watches[0]->statusKey, 'and the morning keeps what it was');
    }

    /** The day's first claim is the first, whatever came after it. */
    public function testTheDaysFirstClaimIsTheFirstOne(): void
    {
        [$area, $station] = $this->anAreaWithAPost(300);

        $this->aClaim($area, $station, 'at_post')->setEndedAt(new \DateTimeImmutable('2026-09-19T12:00:00+03:00'));
        $this->anotherClaim($area, $station, 'at_post', 'c0ffee00-0000-4000-8000-000000000002', '2026-09-19T16:00:00+03:00');
        $this->em->flush();

        $day = $this->presence()->dayFor((string) $area->getUuidString(), $this->personUuid, self::DAY);
        self::assertInstanceOf(PersonDay::class, $day);

        self::assertSame('2026-09-19T06:08:12+03:00', $day->occurredAt?->format(\DateTimeInterface::ATOM));
    }

    /** @return array{0: AreaOfInterest, 1: Station} */
    private function anAreaWithAPost(?int $catchment): array
    {
        $area = $this->anArea();

        /** @var StationService $stations */
        $stations = static::getContainer()->get('test_public.area.stations');
        $station = $stations->add($area, 'Eastgate Post', -29.75, -3.2, 'ST-01');
        $station->setCatchmentM($catchment);
        $this->em->flush();

        return [$area, $station];
    }

    private function aClaim(AreaOfInterest $area, ?Station $station, string $statusKey): CheckIn
    {
        /** @var CheckInStatusService $statuses */
        $statuses = static::getContainer()->get('test_public.area.checkin_statuses');
        $offered = $statuses->offeredBy($area);

        $status = null;
        foreach ($offered as $one) {
            if ($statusKey === $one->getKey()) {
                $status = $one;
            }
        }
        self::assertInstanceOf(CheckInStatus::class, $status);

        $person = new HostPerson()->named('Asha', 'Mollel');
        $this->em->persist($person);
        $this->em->flush();
        $this->personUuid = (string) $person->getUuidString();

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
        $this->em->flush();

        return $checkIn;
    }

    /**
     * A SECOND WATCH ON THE SAME DAY, for the same person — checked in
     * again after checking out. Nothing about the model has to allow this
     * specially: it is another row, with its own client reference.
     */
    private function anotherClaim(AreaOfInterest $area, ?Station $station, string $statusKey, string $ref, string $at): CheckIn
    {
        /** @var CheckInStatusService $statuses */
        $statuses = static::getContainer()->get('test_public.area.checkin_statuses');

        $status = null;
        foreach ($statuses->offeredBy($area) as $one) {
            if ($statusKey === $one->getKey()) {
                $status = $one;
            }
        }
        self::assertInstanceOf(CheckInStatus::class, $status);

        $person = $this->em->getRepository(HostPerson::class)->findOneBy(['uuid' => $this->personUuid]);
        self::assertInstanceOf(HostPerson::class, $person);

        $checkIn = new CheckIn()
            ->setArea($area)
            ->setPerson($person)
            ->setClientRef($ref)
            ->setLocalDate(new \DateTimeImmutable(self::DAY))
            ->setStatus($status)
            ->setStation($station)
            ->setOccurredAt(new \DateTimeImmutable($at))
            ->setDeviceId('0f9ca41e')
            ->setAppVersion('0.1.0');

        $this->em->persist($checkIn);
        $this->em->flush();

        return $checkIn;
    }

    /** The watch a day reads as — the last one somebody worked. */
    private function watchOf(PersonDay $day): PersonWatch
    {
        $watch = $day->lastWatch();
        self::assertInstanceOf(PersonWatch::class, $watch, 'a day that was read has a watch in it');

        return $watch;
    }

    private function aCorrection(CheckIn $checkIn, string $statusKey, string $from): void
    {
        $area = $checkIn->getArea();
        self::assertInstanceOf(AreaOfInterest::class, $area);

        /** @var CheckInStatusService $statuses */
        $statuses = static::getContainer()->get('test_public.area.checkin_statuses');
        $status = null;
        foreach ($statuses->offeredBy($area) as $one) {
            if ($statusKey === $one->getKey()) {
                $status = $one;
            }
        }
        self::assertInstanceOf(CheckInStatus::class, $status);

        $correction = new CheckInCorrection()
            ->setCheckIn($checkIn)
            ->setClientRef('9f2a-correction')
            ->setEffectiveFrom(new \DateTimeImmutable($from))
            ->setStatus($status);

        $checkIn->addCorrection($correction);
        $this->em->persist($correction);
        $this->em->flush();
        $this->remeasure($checkIn);
    }

    private function aPing(CheckIn $checkIn, float $lon, float $lat): void
    {
        $area = $checkIn->getArea();
        $person = $checkIn->getPerson();
        self::assertInstanceOf(AreaOfInterest::class, $area);
        self::assertNotNull($person);

        $ping = new PersonPosition()
            ->setArea($area)
            ->setPerson($person)
            ->setCheckIn($checkIn)
            ->setClientRef('a71c-'.uniqid())
            ->setRecordedAt(new \DateTimeImmutable('2026-09-19T06:38:00+03:00'))
            ->setPosition(\sprintf('{"type":"Point","coordinates":[%F,%F]}', $lon, $lat))
            ->setAccuracyM(8.0)
            ->setSource(PositionSourceEnum::Gps);

        $this->em->persist($ping);
        $this->em->flush();
        $this->foldPings($ping);
    }

    private function presence(): PresenceService
    {
        /** @var PresenceService $presence */
        $presence = static::getContainer()->get('test_public.area.presence');

        return $presence;
    }
}
