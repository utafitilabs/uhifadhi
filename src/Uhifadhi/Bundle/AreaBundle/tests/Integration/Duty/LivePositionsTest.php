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
use Uhifadhi\Bundle\AreaBundle\Entity\Station;
use Uhifadhi\Bundle\AreaBundle\Enum\PositionSourceEnum;
use Uhifadhi\Bundle\AreaBundle\Service\CheckInStatusService;
use Uhifadhi\Bundle\AreaBundle\Service\PresenceService;
use Uhifadhi\Bundle\AreaBundle\Service\StationService;
use Uhifadhi\Bundle\AreaBundle\Tests\Integration\Fixtures\HostPerson;
use Uhifadhi\Bundle\AreaBundle\Tests\Integration\IntegrationTestCase;
use Uhifadhi\Contracts\Area\DayState;
use Uhifadhi\Contracts\Area\LivePresence;

/**
 * WHERE EVERYBODY IS — the read a live map is made of, against a real
 * PostGIS.
 *
 * THE DISTANCE IS THE DATABASE'S. A ring two hundred metres across is
 * exactly the scale at which a distance computed from degrees in PHP
 * is wrong, so inside-the-catchment is asked of `ST_Distance` on
 * geography and this suite runs against the real thing.
 *
 * ONE DERIVATION, READ TWICE. The state on a marker is the same
 * reading the day board draws — asserted here, because the whole
 * reason the area publishes this seam is that a module computing it
 * for itself would produce the second answer somebody acts on.
 */
#[CoversClass(PresenceService::class)]
final class LivePositionsTest extends IntegrationTestCase
{
    private const string DAY = '2026-09-19';
    private const string NOW = '2026-09-19T07:00:00+03:00';

    private string $personUuid = '';

    /** A ranger on an open watch, with a fix inside the post's ring. */
    public function testSomebodyOnAnOpenWatchIsWhereTheirLastFixSays(): void
    {
        [$area, $station] = $this->anAreaWithAPost(catchment: 300);
        $checkIn = $this->aClaim($area, $station);
        $this->aPing($checkIn, -29.7501, -3.2001, '2026-09-19T06:50:00+03:00');

        $live = $this->live($area);

        self::assertCount(1, $live->positions);
        $position = $live->positions[0];
        self::assertSame($this->personUuid, $position->personUuid);
        self::assertSame('Asha Mollel', $position->personName);
        self::assertSame('Eastgate Post', $position->stationName);
        self::assertEqualsWithDelta(-3.2001, $position->latitude, 0.00001);
        self::assertEqualsWithDelta(-29.7501, $position->longitude, 0.00001);
        self::assertSame(DayState::AtPostVerified, $position->state);
        self::assertTrue($position->insideCatchment);
        self::assertNotNull($position->distanceM);
        self::assertLessThan(300.0, $position->distanceM);
    }

    /**
     * THE LATEST FIX, NOT THE NEAREST ONE. The day board asks whether
     * the claim was ever borne out and reads the closest ping; a map
     * asks where the phone is NOW, and a ranger who walked away from
     * the post is not still standing at it.
     */
    public function testTheMarkerIsTheLatestFixAndNotTheNearest(): void
    {
        [$area, $station] = $this->anAreaWithAPost(catchment: 100);
        $checkIn = $this->aClaim($area, $station);
        $this->aPing($checkIn, -29.7500, -3.2000, '2026-09-19T06:10:00+03:00');
        $this->aPing($checkIn, -29.7350, -3.2000, '2026-09-19T06:50:00+03:00');

        $position = $this->live($area)->positions[0];

        self::assertEqualsWithDelta(-29.7350, $position->longitude, 0.00001);
        self::assertFalse($position->insideCatchment, 'the latest fix is well outside the ring');
        // And the DAY still reads verified off the nearest one, which is
        // the other question and the other answer.
        self::assertSame(DayState::AtPostVerified, $position->state);
    }

    /**
     * A POST WITH NO RING HAS NO INSIDE, and that is a third answer.
     * False would read as "outside", which is an accusation nobody
     * measured.
     */
    public function testAPostWithNoRingAnswersNeitherInsideNorOutside(): void
    {
        [$area, $station] = $this->anAreaWithAPost(catchment: null);
        $checkIn = $this->aClaim($area, $station);
        $this->aPing($checkIn, -29.7501, -3.2001, '2026-09-19T06:50:00+03:00');

        $position = $this->live($area)->positions[0];

        self::assertNull($position->insideCatchment);
        self::assertNotNull($position->distanceM, 'how far it was is still measurable');
    }

    /**
     * A WATCH SOMEBODY CHECKED OUT OF IS NOT LIVE. Its last ping is
     * where they were when they stopped, and a marker there would say
     * somebody is standing at a post they went home from.
     */
    public function testAClosedWatchIsNotOnTheMap(): void
    {
        [$area, $station] = $this->anAreaWithAPost(catchment: 300);
        $checkIn = $this->aClaim($area, $station);
        $this->aPing($checkIn, -29.7501, -3.2001, '2026-09-19T06:50:00+03:00');

        $checkIn->setEndedAt(new \DateTimeImmutable('2026-09-19T06:55:00+03:00'));
        $this->em->flush();

        self::assertTrue($this->live($area)->isEmpty());
    }

    /**
     * AND A WATCH WITH NO FIX IS NOT A MARKER. They may well be at
     * their post; what is absent is a POSITION, and drawing one at the
     * post's own point would turn a claim into proof.
     */
    public function testAWatchThatHasReportedNoPositionDrawsNoMarker(): void
    {
        [$area, $station] = $this->anAreaWithAPost(catchment: 300);
        $this->aClaim($area, $station);

        self::assertTrue($this->live($area)->isEmpty());
    }

    /**
     * THE CHECK-IN'S OWN POSITION IS A FIX. Somebody who tapped at the
     * gate two minutes ago and whose phone then lost signal is on the
     * map, at the gate.
     */
    public function testTheCheckInsOwnPositionCountsAsAFix(): void
    {
        [$area, $station] = $this->anAreaWithAPost(catchment: 300);
        $checkIn = $this->aClaim($area, $station);
        $checkIn->setPosition('{"type":"Point","coordinates":[-29.7502,-3.2002]}')
            ->setPositionAt(new \DateTimeImmutable('2026-09-19T06:58:00+03:00'))
            ->setAccuracyM(12.0);
        $this->em->flush();

        $position = $this->live($area)->positions[0];

        self::assertEqualsWithDelta(-29.7502, $position->longitude, 0.00001);
        self::assertSame(12.0, $position->accuracyM);
    }

    /** The area's own ping interval travels with the answer, so nobody guesses a threshold. */
    public function testTheAreasPingIntervalTravelsWithTheAnswer(): void
    {
        [$area, $station] = $this->anAreaWithAPost(catchment: 300);
        $area->setPingIntervalMinutes(5);
        $checkIn = $this->aClaim($area, $station);
        $this->aPing($checkIn, -29.7501, -3.2001, '2026-09-19T06:40:00+03:00');
        $this->em->flush();

        $live = $this->live($area);

        self::assertSame(5, $live->pingIntervalMinutes);
        // Twenty minutes of silence is four missed pings at five minutes.
        self::assertTrue($live->isStale($live->positions[0]));
        self::assertSame(1, $live->staleCount());
    }

    /** An area nobody has heard of is an empty answer, not an error. */
    public function testAnAreaNobodyKnowsIsEmptyRatherThanAFailure(): void
    {
        $live = $this->presence()->liveIn('0f6b0a60-0000-7000-8000-000000000000', new \DateTimeImmutable(self::NOW));

        self::assertTrue($live->isEmpty());
        self::assertGreaterThan(0, $live->pingIntervalMinutes);
    }

    private function live(AreaOfInterest $area): LivePresence
    {
        return $this->presence()->liveIn((string) $area->getUuidString(), new \DateTimeImmutable(self::NOW));
    }

    /** @return array{AreaOfInterest, Station} */
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

    private function aClaim(AreaOfInterest $area, Station $station): CheckIn
    {
        /** @var CheckInStatusService $statuses */
        $statuses = static::getContainer()->get('test_public.area.checkin_statuses');

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

    private function aPing(CheckIn $checkIn, float $lon, float $lat, string $at): void
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
            ->setRecordedAt(new \DateTimeImmutable($at))
            ->setPosition(\sprintf('{"type":"Point","coordinates":[%F,%F]}', $lon, $lat))
            ->setAccuracyM(8.0)
            ->setSource(PositionSourceEnum::Gps);

        $this->em->persist($ping);
        $this->em->flush();
    }

    private function presence(): PresenceService
    {
        /** @var PresenceService $presence */
        $presence = static::getContainer()->get('test_public.area.presence');

        return $presence;
    }
}
