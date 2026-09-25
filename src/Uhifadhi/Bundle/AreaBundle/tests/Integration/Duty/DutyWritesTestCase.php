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

use Doctrine\Bundle\DoctrineBundle\Middleware\BacktraceDebugDataHolder;
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
 * THE HANDSET'S WRITES, THROUGH THE REAL WRITE SERVICE, WITH THE HUB RECORDED.
 *
 * The bare kernel carries no field API, so {@see CheckInService} is built here
 * from the container's own collaborators and a hub that keeps what it was
 * handed. What the database was asked is read off the DBAL debug middleware
 * the profiler reads — `doctrine.debug_data_holder`, on in a debug kernel —
 * so a test can state how many statements a write or a read costs.
 *
 * @see https://symfony.com/doc/current/testing/profiling.html — the Doctrine collector's query count is the documented measure of a request's queries
 * @see vendor/symfony/doctrine-bridge/Middleware/Debug/Connection.php — every statement, and START TRANSACTION / COMMIT, is recorded in the DebugDataHolder
 * @see vendor/doctrine/doctrine-bundle/config/middlewares.php — `doctrine.debug_data_holder`
 */
abstract class DutyWritesTestCase extends IntegrationTestCase
{
    protected const string NOW = '2026-09-19T11:42:00+03:00';
    protected const string DAY = '2026-09-19';

    /** @var list<Update> */
    protected array $updates = [];

    /** @return array{0: AreaOfInterest, 1: Station, 2: Station} the area, a post with a ring, a post without one */
    protected function ground(): array
    {
        $area = $this->anArea();
        $this->aZone($area, 'West', self::A_WEST_HALF);
        $this->aZone($area, 'East', self::A_EAST_HALF);

        $eastgate = $this->stations()->add($area, 'Eastgate Post', -29.75, -3.2, 'ST-01');
        $eastgate->setCatchmentM(300);
        $westgate = $this->stations()->add($area, 'Westgate Post', -29.9, -3.3, 'ST-02');
        $this->em->flush();

        return [$area, $eastgate, $westgate];
    }

    protected function aPerson(string $first = 'Asha', string $last = 'Mollel'): HostPerson
    {
        $person = new HostPerson()->named($first, $last);
        $this->em->persist($person);
        $this->em->flush();

        return $person;
    }

    protected function service(): CheckInService
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

        return new CheckInService(
            $this->em,
            $checkIns,
            $corrections,
            $positions,
            $stations,
            $statuses,
            $this->facts(),
            $this->publisher(),
        );
    }

    protected function publisher(): PresencePublisher
    {
        $hub = new MockHub(
            'https://hub.example.test/.well-known/mercure',
            new StaticTokenProvider('test.publisher.token'),
            function (Update $update): string {
                $this->updates[] = $update;

                return 'id';
            },
        );

        return new PresencePublisher($hub, $this->presence(), new MockClock(self::NOW), new NullLogger());
    }

    protected function presence(): PresenceService
    {
        /** @var PresenceService $presence */
        $presence = static::getContainer()->get('test_public.area.presence');

        return $presence;
    }

    protected function facts(): PresenceFactsService
    {
        /** @var PresenceFactsService $facts */
        $facts = static::getContainer()->get('test_public.area.presence_facts');

        return $facts;
    }

    /**
     * A CLAIM BODY, as §13A has the handset send it.
     *
     * @return array<string, mixed>
     */
    protected function claimBody(string $ref, ?Station $station, string $at = '2026-09-19T06:08:12+03:00', ?float $lat = null, ?float $lon = null, string $status = 'at_post'): array
    {
        $body = [
            'clientRef' => $ref,
            'occurredAt' => $at,
            'localDate' => self::DAY,
            'status' => $status,
            'deviceId' => '0f9ca41e',
            'appVersion' => '0.1.0',
        ];
        if (null !== $station) {
            $body['stationUuid'] = $station->getUuidString();
        }
        if (null !== $lat && null !== $lon) {
            $body += ['lat' => $lat, 'lon' => $lon, 'accuracyM' => 8.0];
        }

        return $body;
    }

    /** @return array<string, mixed> one ping of a §13C batch */
    protected function pingBody(string $ref, string $checkinRef, string $at, float $lat, float $lon, ?int $battery = null): array
    {
        return [
            'clientRef' => $ref,
            'checkinRef' => $checkinRef,
            'recordedAt' => $at,
            'lat' => $lat,
            'lon' => $lon,
            'accuracyM' => 8.0,
            'batteryPct' => $battery,
        ];
    }

    /**
     * THE ROW'S FACTS AS STORED, read straight off the table, instants in UTC.
     *
     * @return array<string, mixed>
     */
    protected function rowFacts(string $clientRef): array
    {
        $row = $this->em->getConnection()->fetchAssociative(
            <<<'SQL'
                SELECT c.ping_count,
                       to_char(c.first_ping_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS') AS first_ping_at,
                       to_char(c.last_ping_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS') AS last_ping_at,
                       ST_X(c.last_fix) AS lon, ST_Y(c.last_fix) AS lat,
                       to_char(c.last_fix_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS') AS last_fix_at,
                       c.last_fix_accuracy_m, c.last_fix_battery_pct, c.last_fix_m, c.closest_m,
                       z.name AS zone, s.name AS nearest_station
                FROM duty_checkin c
                LEFT JOIN zone z ON z.id = c.last_fix_zone_id
                LEFT JOIN station s ON s.id = c.nearest_station_id
                WHERE c.client_ref = :ref
                SQL,
            ['ref' => $clientRef],
        );
        self::assertNotFalse($row, 'the claim is stored');

        return $row;
    }

    /** Metres between two points on the spheroid, asked of PostGIS. */
    protected function metres(float $lon, float $lat, float $toLon, float $toLat): float
    {
        $metres = $this->em->getConnection()->fetchOne(
            'SELECT ST_Distance(ST_SetSRID(ST_MakePoint(:a, :b), 4326)::geography, ST_SetSRID(ST_MakePoint(:c, :d), 4326)::geography)',
            ['a' => $lon, 'b' => $lat, 'c' => $toLon, 'd' => $toLat],
        );
        self::assertIsNumeric($metres);

        return (float) $metres;
    }

    /** Forget every statement recorded so far. */
    protected function startCounting(): void
    {
        $this->queryLog()->reset();
    }

    /**
     * The statements the default connection ran since {@see startCounting()},
     * transaction markers included.
     *
     * @return list<string>
     */
    protected function statements(): array
    {
        $data = $this->queryLog()->getData();
        $statements = [];
        foreach ($data['default'] ?? [] as $query) {
            self::assertIsString($query['sql']);
            $statements[] = $query['sql'];
        }

        return $statements;
    }

    /** The statements that read or wrote rows — transaction and savepoint markers left out. */
    protected function queryCount(): int
    {
        return \count(array_filter(
            $this->statements(),
            static fn (string $sql): bool => !str_starts_with($sql, '"') && !str_starts_with($sql, 'SAVEPOINT') && !str_starts_with($sql, 'RELEASE SAVEPOINT'),
        ));
    }

    private function queryLog(): BacktraceDebugDataHolder
    {
        $holder = static::getContainer()->get('doctrine.debug_data_holder');
        self::assertInstanceOf(BacktraceDebugDataHolder::class, $holder);

        return $holder;
    }

    private function stations(): StationService
    {
        /** @var StationService $stations */
        $stations = static::getContainer()->get('test_public.area.stations');

        return $stations;
    }
}
