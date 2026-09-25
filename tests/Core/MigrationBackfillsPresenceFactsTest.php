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

namespace Uhifadhi\Core\Tests\Core;

use Doctrine\ORM\EntityManagerInterface;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\Entity\Station;
use Uhifadhi\Bundle\AreaBundle\Entity\Zone;
use Uhifadhi\Bundle\AreaBundle\Service\CheckInService;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Bundle\TeamBundle\Enum\TeamRoleEnum;

/**
 * AN INSTALLATION THAT HELD PINGS BEFORE THE ROW CARRIED THEIR FACTS READS
 * THE SAME ROWS AFTER THE UPGRADE AS ONE THAT WROTE THEM LIVE.
 *
 * The ground is written through the handset's own write service at the
 * newest version, so every row's facts are the ones the pings folded in. The
 * migration that added them is then unwound — the facts go, the check-ins and
 * the pings stay, which is an installation on the release before — and run
 * again: its backfill must put back exactly what the live writes had.
 */
final class MigrationBackfillsPresenceFactsTest extends MigrationsTestCase
{
    private const string FACTS_VERSION = 'Uhifadhi\\Bundle\\AreaBundle\\Migrations\\Version20260925200000';

    public function testTheBackfillWritesWhatThePingsWroteLive(): void
    {
        $this->console('doctrine:migrations:migrate', ['--no-interaction' => true, 'version' => 'latest']);
        $this->seedAGroundThroughTheWriteService();

        $live = $this->facts();
        self::assertCount(3, $live);
        self::assertSame([2, 1, 0], array_column($live, 'ping_count'));

        $this->asAFreshProcess();
        $this->console('doctrine:migrations:execute', ['versions' => [self::FACTS_VERSION], '--down' => true, '--no-interaction' => true]);
        self::assertSame(3, $this->connection->fetchOne('SELECT COUNT(*) FROM duty_checkin'), 'the check-ins outlive the facts');
        self::assertSame(3, $this->connection->fetchOne('SELECT COUNT(*) FROM duty_position'), 'and so do the pings');

        $this->asAFreshProcess();
        $this->console('doctrine:migrations:migrate', ['--no-interaction' => true, 'version' => 'latest']);

        self::assertSame($live, $this->facts());
    }

    private function seedAGroundThroughTheWriteService(): void
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine.orm.entity_manager');

        $area = new AreaOfInterest()->setName('Sample Reserve')->setSource('upload')
            ->setGeom('{"type":"MultiPolygon","coordinates":[[[[-30.0,-3.6],[-29.0,-3.6],[-29.0,-2.8],[-30.0,-2.8],[-30.0,-3.6]]]]}');
        $em->persist($area);
        $em->persist(new Zone()->setArea($area)->setName('West')
            ->setGeom('{"type":"MultiPolygon","coordinates":[[[[-30.0,-3.6],[-29.5,-3.6],[-29.5,-2.8],[-30.0,-2.8],[-30.0,-3.6]]]]}'));
        $eastgate = new Station()->setArea($area)->setName('Eastgate Post')->setPoint('{"type":"Point","coordinates":[-29.75,-3.2]}')->setCatchmentM(300);
        $westgate = new Station()->setArea($area)->setName('Westgate Post')->setPoint('{"type":"Point","coordinates":[-29.9,-3.3]}');
        $em->persist($eastgate);
        $em->persist($westgate);
        $people = [];
        foreach (['Asha', 'Baraka', 'Chausiku'] as $first) {
            $people[] = $person = new User()->setEmail(strtolower($first).'@example.test')->setFirstName($first)->setLastName('Mollel')
                ->setPassword('x')->setTeamRole(TeamRoleEnum::Staff)->setVerified(true);
            $em->persist($person);
        }
        $em->flush();

        $service = static::getContainer()->get('test_public.area.checkins');
        self::assertInstanceOf(CheckInService::class, $service);

        $ping = static fn (string $ref, string $watch, string $at, float $lat, float $lon, ?int $battery): array => [
            'clientRef' => $ref, 'checkinRef' => $watch, 'recordedAt' => $at, 'lat' => $lat, 'lon' => $lon, 'accuracyM' => 8.0, 'batteryPct' => $battery,
        ];

        $service->claim($area, $people[0], self::claim('claim-a', $eastgate, ['lat' => -3.2003, 'lon' => -29.7503, 'accuracyM' => 11.0]));
        $service->ping($area, $people[0], ['positions' => [
            $ping('p-2', 'claim-a', '2026-09-19T07:00:00+03:00', -3.201, -29.74, 60),
            $ping('p-1', 'claim-a', '2026-09-19T06:30:00+03:00', -3.2, -29.75, 70),
        ]]);
        $service->claim($area, $people[1], self::claim('claim-b', $westgate));
        $service->ping($area, $people[1], ['positions' => [$ping('p-3', 'claim-b', '2026-09-19T06:40:00+03:00', -3.31, -29.91, null)]]);
        $service->claim($area, $people[2], self::claim('claim-c', $eastgate, ['lat' => -3.1, 'lon' => -29.2, 'accuracyM' => 20.0]));
    }

    /**
     * @param array<string, mixed> $fix
     *
     * @return array<string, mixed>
     */
    private static function claim(string $ref, Station $station, array $fix = []): array
    {
        return [
            'clientRef' => $ref, 'occurredAt' => '2026-09-19T06:00:00+03:00', 'localDate' => '2026-09-19',
            'status' => 'at_post', 'stationUuid' => $station->getUuidString(), 'deviceId' => 'd', 'appVersion' => '0.1.0',
        ] + $fix;
    }

    /** @return list<array<string, mixed>> every row's facts, by claim reference */
    private function facts(): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->connection->fetchAllAssociative(
            'SELECT client_ref, ping_count, first_ping_at, last_ping_at, ST_AsText(last_fix) AS last_fix, last_fix_at,'
            .' last_fix_accuracy_m, last_fix_battery_pct, last_fix_m, closest_m, last_fix_zone_id, nearest_station_id'
            .' FROM duty_checkin ORDER BY client_ref',
        );

        return $rows;
    }
}
