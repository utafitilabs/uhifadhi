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

namespace Uhifadhi\Bundle\AreaBundle\Tests\Integration\Zone;

use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\HttpFoundation\File\File;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\Entity\ZoneEvent;
use Uhifadhi\Bundle\AreaBundle\Enum\ZoneEventKind;
use Uhifadhi\Bundle\AreaBundle\Repository\ZoneEventRepository;
use Uhifadhi\Bundle\AreaBundle\Service\ZoneImportService;
use Uhifadhi\Bundle\AreaBundle\Service\ZoneService;
use Uhifadhi\Bundle\AreaBundle\Tests\Integration\IntegrationTestCase;

/**
 * THE LOG IS THE SERVICE'S, NOT THE SCREEN'S.
 *
 * A LOG WRITTEN BY A CONTROLLER IS A LOG WITH HOLES IN IT. Every write that
 * changes a zone set can arrive from somewhere that is not an HTTP request —
 * a console importer, a fixture loader, a seeder, whatever an
 * installation writes next — and a line written in the controller means all
 * of those leave the history empty. The screen would look right and the
 * record would be wrong, which is the worst of the two.
 *
 * SO THE VERB WRITES THE LINE. A controller authorises, calls a verb and
 * responds; the verb is what knows the thing happened, so the verb is what
 * says so. This suite reaches the services directly — no kernel browser, no
 * request — and asserts the history is there anyway.
 *
 * THE ACTOR IS STILL THE CALLER'S TO SUPPLY, because only the caller knows
 * whether there is one. A console importer passes null and the line reads
 * without a name, which is the honest shape and not a gap.
 */
#[CoversClass(ZoneImportService::class)]
#[CoversClass(ZoneService::class)]
final class ZoneLogIsTheServicesTest extends IntegrationTestCase
{
    private const string AN_EAST_HALF = '{"type":"MultiPolygon","coordinates":[[[[-29.5,-3.6],[-29.0,-3.6],[-29.0,-2.8],[-29.5,-2.8],[-29.5,-3.6]]]]}';

    public function testAnImportWithNoRequestAnywhereNearItStillLeavesItsLine(): void
    {
        $area = $this->anArea();

        $this->import($area, 'West', self::A_WEST_HALF_RING, 'a.meena');

        $line = $this->lineOfKind($area, ZoneEventKind::Imported);
        self::assertNotNull($line, 'a console importer left no history');
        self::assertSame('1 zone added by a.meena', $line->getHeadline());
        self::assertStringContainsString('into an empty area', (string) $line->getDetail());
    }

    /** No actor is a line without a name, not a missing line. */
    public function testAnImportByNobodyIsStillLogged(): void
    {
        $area = $this->anArea();

        $this->import($area, 'West', self::A_WEST_HALF_RING);

        self::assertSame('1 zone added', $this->lineOfKind($area, ZoneEventKind::Imported)?->getHeadline());
    }

    public function testARenameIsLoggedByTheServiceThatRenames(): void
    {
        $area = $this->anArea();
        $zone = $this->aZone($area, 'Oldean', self::A_WEST_HALF);

        $this->zones()->rename($zone, 'Oldiani', 'n.kileo');

        self::assertSame('“Oldean” renamed to “Oldiani”', $this->lineOfKind($area, ZoneEventKind::Renamed)?->getHeadline());
    }

    public function testAReplacedRingIsLoggedByTheServiceThatReplacesIt(): void
    {
        $area = $this->anArea();
        $zone = $this->aZone($area, 'West', self::A_WEST_HALF);

        $this->zones()->replaceGeometry($zone, self::AN_EAST_HALF, 'n.kileo');

        self::assertSame('“West” redrawn', $this->lineOfKind($area, ZoneEventKind::RingReplaced)?->getHeadline());
    }

    public function testARemovedZoneIsLoggedByTheServiceThatRemovesIt(): void
    {
        $area = $this->anArea();
        $zone = $this->aZone($area, 'West', self::A_WEST_HALF);

        $this->zones()->remove($zone, 'n.kileo');

        self::assertSame('“West” removed', $this->lineOfKind($area, ZoneEventKind::Removed)?->getHeadline());
    }

    public function testClearingTheSetIsLoggedByTheServiceThatClearsIt(): void
    {
        $area = $this->anArea();
        $this->aZone($area, 'West', self::A_WEST_HALF);
        $this->aZone($area, 'East', self::AN_EAST_HALF);

        $this->zones()->removeAll($area, 'n.kileo');

        self::assertSame('all 2 zones removed by n.kileo', $this->lineOfKind($area, ZoneEventKind::Cleared)?->getHeadline());
    }

    /** Clearing an area that has none is not an event. */
    public function testClearingAnEmptySetLogsNothing(): void
    {
        $area = $this->anArea();

        $this->zones()->removeAll($area);

        self::assertNull($this->lineOfKind($area, ZoneEventKind::Cleared));
    }

    /** A file nobody could read is a line too: somebody tried, and nothing changed. */
    public function testARefusedFileIsLoggedByTheServiceThatRefusedIt(): void
    {
        $area = $this->anArea();

        try {
            $this->importRaw($area, 'name,lat,lon', 'wards.csv', 'n.kileo');
        } catch (\Uhifadhi\Bundle\AreaBundle\Exception\ZoneImportException) {
            // The refusal is the point; the line is what is asserted.
        }

        self::assertSame('File refused', $this->lineOfKind($area, ZoneEventKind::Refused)?->getHeadline());
    }

    // ---------------------------------------------------------------- fixtures

    private const array A_WEST_HALF_RING = [[[-30.0, -3.6], [-29.5, -3.6], [-29.5, -2.8], [-30.0, -2.8], [-30.0, -3.6]]];

    private function zones(): ZoneService
    {
        /** @var ZoneService $service */
        $service = static::getContainer()->get('test_public.area.zones');

        return $service;
    }

    private function lineOfKind(AreaOfInterest $area, ZoneEventKind $kind): ?ZoneEvent
    {
        /** @var ZoneEventRepository $events */
        $events = static::getContainer()->get('test_public.area.zone_event_repository');

        foreach ($events->findByArea($area) as $line) {
            if ($kind === $line->getKind()) {
                return $line;
            }
        }

        return null;
    }

    /** @param list<list<list<float|int>>> $ring */
    private function import(AreaOfInterest $area, string $name, array $ring, ?string $actor = null): void
    {
        $this->importRaw($area, (string) json_encode([
            'type' => 'FeatureCollection',
            'features' => [['type' => 'Feature', 'properties' => ['Name' => $name], 'geometry' => ['type' => 'Polygon', 'coordinates' => $ring]]],
        ], \JSON_THROW_ON_ERROR), 'zones.geojson', $actor);
    }

    private function importRaw(AreaOfInterest $area, string $document, string $fileName, ?string $actor = null): void
    {
        /** @var ZoneImportService $imports */
        $imports = static::getContainer()->get('test_public.area.zone_import');

        $directory = sys_get_temp_dir().'/zone-log-'.bin2hex(random_bytes(6));
        mkdir($directory, 0o777, true);
        $path = $directory.'/'.$fileName;
        file_put_contents($path, $document);

        $plan = $imports->plan($area, new File($path), $fileName, actor: $actor);
        $imports->apply($area, $plan, $plan->arrivingNames(), $actor);
    }
}
