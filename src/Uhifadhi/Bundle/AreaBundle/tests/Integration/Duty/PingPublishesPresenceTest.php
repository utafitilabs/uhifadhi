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
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Mercure\Jwt\StaticTokenProvider;
use Symfony\Component\Mercure\MockHub;
use Symfony\Component\Mercure\Update;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\Entity\CheckIn;
use Uhifadhi\Bundle\AreaBundle\Entity\CheckInCorrection;
use Uhifadhi\Bundle\AreaBundle\Entity\PersonPosition;
use Uhifadhi\Bundle\AreaBundle\Entity\Station;
use Uhifadhi\Bundle\AreaBundle\Repository\CheckInCorrectionRepository;
use Uhifadhi\Bundle\AreaBundle\Repository\CheckInRepository;
use Uhifadhi\Bundle\AreaBundle\Repository\PersonPositionRepository;
use Uhifadhi\Bundle\AreaBundle\Repository\StationRepository;
use Uhifadhi\Bundle\AreaBundle\Service\CheckInService;
use Uhifadhi\Bundle\AreaBundle\Service\CheckInStatusService;
use Uhifadhi\Bundle\AreaBundle\Service\PresenceFactsService;
use Uhifadhi\Bundle\AreaBundle\Service\PresencePublisher;
use Uhifadhi\Bundle\AreaBundle\Service\PresenceService;
use Uhifadhi\Bundle\AreaBundle\Service\StationService;
use Uhifadhi\Bundle\AreaBundle\Tests\Integration\Fixtures\HostPerson;
use Uhifadhi\Bundle\AreaBundle\Tests\Integration\IntegrationTestCase;

/**
 * A HANDSET'S WRITE REACHES THE WIRE ONCE, after it is stored.
 *
 * The real write path — {@see CheckInService} against a real PostGIS — with
 * the hub replaced by a recorder. What is asserted is the count and the order:
 * the row is in the database before the frame goes out, a claim with a fix
 * publishes the mark, a batch of pings publishes ONE frame carrying the latest
 * of them, a check-out publishes the person as gone, and a batch the area
 * already held publishes nothing because it stored nothing.
 */
#[CoversClass(CheckInService::class)]
#[CoversClass(PresencePublisher::class)]
final class PingPublishesPresenceTest extends IntegrationTestCase
{
    private const string NOW = '2026-09-19T07:00:00+03:00';
    private const string CLAIM_REF = '3b0c1f2e-5a44-4a1e-9f0e-2c7b1d9e4a10';

    /** @var list<Update> */
    private array $updates = [];

    /**
     * How many pings the database held at the moment each frame went out.
     *
     * @var list<int>
     */
    private array $storedAtPublish = [];

    public function testAClaimWithAFixPublishesTheMarkOnceTheRowIsStored(): void
    {
        [$area, $station, $person] = $this->ground();

        $this->service()->claim($area, $person, $this->claim($station, lat: -3.2001, lon: -29.7501));

        $update = $this->theOneUpdate();
        self::assertSame([PresencePublisher::topicFor((string) $area->getUuidString())], $update->getTopics());
        self::assertTrue($update->isPrivate());
        $frame = self::frame($update);
        self::assertSame($person->getUuidString(), $frame['id']);
        self::assertSame([-29.7501, -3.2001], self::coordinates($frame));
    }

    public function testABatchOfPingsPublishesExactlyOneFrameCarryingTheLatest(): void
    {
        [$area, $station, $person] = $this->ground();
        $service = $this->service();
        $service->claim($area, $person, $this->claim($station));
        $this->updates = [];
        $this->storedAtPublish = [];

        $service->ping($area, $person, ['positions' => [
            $this->ping('a71c0000-0000-4000-8000-000000000001', '2026-09-19T06:10:00+03:00', lat: -3.2000, lon: -29.7500),
            $this->ping('a71c0000-0000-4000-8000-000000000002', '2026-09-19T06:50:00+03:00', lat: -3.2010, lon: -29.7400),
        ]]);

        self::assertCount(2, $this->em->getRepository(PersonPosition::class)->findAll(), 'both pings are stored');
        self::assertSame([2], $this->storedAtPublish, 'stored first, published second');
        self::assertSame([-29.74, -3.201], self::coordinates(self::frame($this->theOneUpdate())));
    }

    /** A batch the area already holds stores nothing, so it publishes nothing. */
    public function testABatchAlreadyHeldPublishesNothing(): void
    {
        [$area, $station, $person] = $this->ground();
        $service = $this->service();
        $service->claim($area, $person, $this->claim($station));
        $batch = ['positions' => [$this->ping('a71c0000-0000-4000-8000-000000000001', '2026-09-19T06:50:00+03:00', lat: -3.2, lon: -29.75)]];
        $service->ping($area, $person, $batch);
        $this->updates = [];
        $this->storedAtPublish = [];

        $service->ping($area, $person, $batch);

        self::assertSame([], $this->updates);
    }

    public function testACheckOutPublishesThePersonAsGone(): void
    {
        [$area, $station, $person] = $this->ground();
        $service = $this->service();
        [$checkIn] = $service->claim($area, $person, $this->claim($station, lat: -3.2001, lon: -29.7501));
        $this->updates = [];
        $this->storedAtPublish = [];

        $service->amend($checkIn, ['endedAt' => '2026-09-19T06:58:00+03:00']);

        $frame = self::frame($this->theOneUpdate());
        self::assertSame($person->getUuidString(), $frame['id']);
        self::assertNull($frame['geometry']);
        self::assertSame(['gone' => true], $frame['properties']);
    }

    /**
     * THE REAL WRITE SERVICE, with the hub swapped for a recorder. Built by
     * hand because the bare kernel carries no field API; every collaborator
     * but the hub is the container's own.
     */
    private function service(): CheckInService
    {
        $checkIns = $this->em->getRepository(CheckIn::class);
        $corrections = $this->em->getRepository(CheckInCorrection::class);
        $positions = $this->em->getRepository(PersonPosition::class);
        $stations = $this->em->getRepository(Station::class);
        self::assertInstanceOf(CheckInRepository::class, $checkIns);
        self::assertInstanceOf(CheckInCorrectionRepository::class, $corrections);
        self::assertInstanceOf(PersonPositionRepository::class, $positions);
        self::assertInstanceOf(StationRepository::class, $stations);

        /** @var CheckInStatusService $statuses */
        $statuses = static::getContainer()->get('test_public.area.checkin_statuses');
        /** @var PresenceService $presence */
        $presence = static::getContainer()->get('test_public.area.presence');
        /** @var PresenceFactsService $facts */
        $facts = static::getContainer()->get('test_public.area.presence_facts');

        $hub = new MockHub(
            'https://hub.example.test/.well-known/mercure',
            new StaticTokenProvider('test.publisher.token'),
            function (Update $update) use ($positions): string {
                // THE ROW IS THERE BEFORE THE FRAME GOES OUT: a subscriber that
                // reloads on a frame reads what the frame said.
                $this->storedAtPublish[] = $positions->count([]);
                $this->updates[] = $update;

                return 'id';
            },
        );

        return new CheckInService(
            $this->em,
            $checkIns,
            $corrections,
            $positions,
            $stations,
            $statuses,
            $facts,
            new PresencePublisher($hub, $presence, new MockClock(self::NOW), new NullLogger()),
        );
    }

    /** @return array{AreaOfInterest, Station, HostPerson} */
    private function ground(): array
    {
        $area = $this->anArea();
        /** @var StationService $stations */
        $stations = static::getContainer()->get('test_public.area.stations');
        $station = $stations->add($area, 'Eastgate Post', -29.75, -3.2, 'ST-01');
        $station->setCatchmentM(300);

        $person = new HostPerson()->named('Asha', 'Mollel');
        $this->em->persist($person);
        $this->em->flush();

        return [$area, $station, $person];
    }

    /** @return array<string, mixed> */
    private function claim(Station $station, ?float $lat = null, ?float $lon = null): array
    {
        $body = [
            'clientRef' => self::CLAIM_REF,
            'occurredAt' => '2026-09-19T06:08:12+03:00',
            'localDate' => '2026-09-19',
            'status' => 'at_post',
            'stationUuid' => $station->getUuidString(),
            'deviceId' => '0f9ca41e',
            'appVersion' => '0.1.0',
        ];
        if (null !== $lat && null !== $lon) {
            $body += ['lat' => $lat, 'lon' => $lon, 'accuracyM' => 8.0];
        }

        return $body;
    }

    /** @return array<string, mixed> */
    private function ping(string $ref, string $at, float $lat, float $lon): array
    {
        return [
            'clientRef' => $ref,
            'checkinRef' => self::CLAIM_REF,
            'recordedAt' => $at,
            'lat' => $lat,
            'lon' => $lon,
            'accuracyM' => 8.0,
        ];
    }

    /** Exactly one frame went out since the recorder was last emptied. */
    private function theOneUpdate(): Update
    {
        $updates = $this->updates;
        self::assertCount(1, $updates, 'one write, one frame');

        return $updates[0];
    }

    /** @return array<string, mixed> */
    private static function frame(Update $update): array
    {
        $frame = json_decode($update->getData(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($frame);

        /** @var array<string, mixed> $frame */
        return $frame;
    }

    /**
     * @param array<string, mixed> $frame
     *
     * @return list<float>
     */
    private static function coordinates(array $frame): array
    {
        self::assertIsArray($frame['geometry']);
        self::assertIsArray($frame['geometry']['coordinates']);

        $rounded = [];
        foreach ($frame['geometry']['coordinates'] as $coordinate) {
            self::assertIsFloat($coordinate);
            $rounded[] = round($coordinate, 4);
        }

        return $rounded;
    }
}
