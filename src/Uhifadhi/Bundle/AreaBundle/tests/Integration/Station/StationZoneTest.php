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

namespace Uhifadhi\Bundle\AreaBundle\Tests\Integration\Station;

use PHPUnit\Framework\Attributes\CoversClass;
use Uhifadhi\Bundle\AreaBundle\Entity\Station;
use Uhifadhi\Bundle\AreaBundle\Service\StationService;
use Uhifadhi\Bundle\AreaBundle\Tests\Integration\IntegrationTestCase;

/**
 * A STATION'S ZONE IS DERIVED FROM ITS POINT, AND CACHED.
 *
 * NOBODY TYPES A STATION'S ZONE. The point is the fact somebody records; which
 * zone it falls in is a question about the ground, answered by PostGIS, and a
 * field an admin could set by hand would be a field that disagrees with the map
 * the first time either changes.
 *
 * IT IS CACHED BECAUSE EVERY SURFACE ASKS. The zones page groups stations by
 * zone, the configure section groups the cards by zone, a station's band names
 * its zone, and the zone record lists its stations — computing a
 * point-in-polygon per station per page would put a spatial query behind every
 * row in the product. So it is stored and RECOMPUTED, and the recompute is the
 * interesting part: four things move the answer without anybody touching the
 * station.
 *
 * NULL IS UNZONED, AND UNZONED IS LEGAL. Zones are presence-driven, so a
 * stretch of ground nobody works may belong to none and a station standing on
 * it belongs to none either. That is a first-class answer everywhere, never an
 * error and never a zero.
 *
 * THE TIE-BREAK IS THE ZONE RULE'S OWN. A point on an edge two zones share —
 * or inside an accepted sliver — is covered by both, and the answer is the one
 * whose name sorts first, then the lowest id, exactly as
 * {@see \Uhifadhi\Bundle\AreaBundle\Service\ZoneService::zoneOf()} answers it.
 * One rule, so a station's cached zone and a live point-in-zone question can
 * never disagree.
 */
#[CoversClass(Station::class)]
#[CoversClass(StationService::class)]
final class StationZoneTest extends IntegrationTestCase
{
    /** Inside the western half, comfortably. */
    private const float WEST_LON = -29.75;
    private const float EAST_LON = -29.25;
    private const float LAT = -3.2;

    private const string AN_EAST_HALF = '{"type":"MultiPolygon","coordinates":[[[[-29.5,-3.6],[-29.0,-3.6],[-29.0,-2.8],[-29.5,-2.8],[-29.5,-3.6]]]]}';

    public function testAStationTakesTheZoneItsPointFallsIn(): void
    {
        $area = $this->anArea();
        $west = $this->aZone($area, 'West', self::A_WEST_HALF);

        $station = $this->stations()->add($area, 'Eastgate Post', self::WEST_LON, self::LAT, 'ST-01');

        self::assertSame($west->getId(), $station->getZone()?->getId());
    }

    /** Ground in no zone is legal, and a station standing on it is unzoned. */
    public function testAStationOnUnzonedGroundHasNoZone(): void
    {
        $area = $this->anArea();
        $this->aZone($area, 'West', self::A_WEST_HALF);

        $station = $this->stations()->add($area, 'Eastern Station', self::EAST_LON, self::LAT);

        self::assertNull($station->getZone());
    }

    public function testAnAreaWithNoZonesGivesItsStationsNone(): void
    {
        $station = $this->stations()->add($this->anArea(), 'Eastgate Post', self::WEST_LON, self::LAT);

        self::assertNull($station->getZone());
    }

    // ------------------------------------------- the four triggers

    /** ONE: the station moves. */
    public function testMovingAStationRederivesItsZone(): void
    {
        $area = $this->anArea();
        $this->aZone($area, 'West', self::A_WEST_HALF);
        $east = $this->aZone($area, 'East', self::AN_EAST_HALF);

        $station = $this->stations()->add($area, 'Eastgate Post', self::WEST_LON, self::LAT);
        $this->stations()->moveTo($station, self::EAST_LON, self::LAT);

        self::assertSame($east->getId(), $station->getZone()?->getId());
    }

    /** TWO: an import brings the ground a standing station is on into a zone. */
    public function testAnImportRederivesEveryStationInTheArea(): void
    {
        $area = $this->anArea();
        $station = $this->stations()->add($area, 'Eastgate Post', self::WEST_LON, self::LAT);
        self::assertNull($station->getZone());

        $this->importOneZone($area, 'West', self::A_WEST_HALF_RING);

        $this->em->refresh($station);
        self::assertSame('West', $station->getZone()?->getName());
    }

    /** THREE: a zone's ring is replaced and the ground under a station changes hands. */
    public function testReplacingARingRederivesEveryStationInTheArea(): void
    {
        $area = $this->anArea();
        $zone = $this->aZone($area, 'West', self::A_WEST_HALF);
        $station = $this->stations()->add($area, 'Eastern Station', self::EAST_LON, self::LAT);
        self::assertNull($station->getZone());

        $this->zones()->replaceGeometry($zone, self::AN_EAST_HALF);
        $this->stations()->rederiveFor($area);

        $this->em->refresh($station);
        self::assertSame('West', $station->getZone()?->getName());
    }

    /** FOUR: the set is cleared, and every station standing in one is unzoned again. */
    public function testClearingTheSetUnzonesEveryStation(): void
    {
        $area = $this->anArea();
        $this->aZone($area, 'West', self::A_WEST_HALF);
        $station = $this->stations()->add($area, 'Eastgate Post', self::WEST_LON, self::LAT);
        self::assertNotNull($station->getZone());

        $this->zones()->removeAll($area);
        $this->stations()->rederiveFor($area);

        $this->em->refresh($station);
        self::assertNull($station->getZone());
    }

    /** Removing ONE zone unzones the stations that stood in it and leaves the rest. */
    public function testRemovingOneZoneUnzonesOnlyItsOwnStations(): void
    {
        $area = $this->anArea();
        $west = $this->aZone($area, 'West', self::A_WEST_HALF);
        $this->aZone($area, 'East', self::AN_EAST_HALF);
        $inWest = $this->stations()->add($area, 'Eastgate Post', self::WEST_LON, self::LAT);
        $inEast = $this->stations()->add($area, 'Eastern Station', self::EAST_LON, self::LAT);

        $this->zones()->remove($west);
        $this->stations()->rederiveFor($area);

        $this->em->refresh($inWest);
        $this->em->refresh($inEast);
        self::assertNull($inWest->getZone());
        self::assertSame('East', $inEast->getZone()?->getName());
    }

    /**
     * A POINT TWO ZONES BOTH COVER HAS ONE ANSWER, and it is the same answer
     * every time: the zone whose name sorts first.
     */
    public function testAPointTwoZonesCoverGoesToTheOneThatSortsFirst(): void
    {
        $area = $this->anArea();
        // West and East meet exactly on -29.5; a point on the shared edge is
        // covered by both.
        $this->aZone($area, 'West', self::A_WEST_HALF);
        $this->aZone($area, 'East', self::AN_EAST_HALF);

        $station = $this->stations()->add($area, 'Border Station', -29.5, self::LAT);

        self::assertSame('East', $station->getZone()?->getName());
        $this->stations()->rederiveFor($area);
        $this->em->refresh($station);
        self::assertSame('East', $station->getZone()?->getName());
    }

    /** A station is the area's: another area's zones are not candidates. */
    public function testAStationNeverTakesAnotherAreasZone(): void
    {
        $other = $this->anArea('Second Reserve');
        $this->aZone($other, 'West', self::A_WEST_HALF);

        $station = $this->stations()->add($this->anArea('First Reserve'), 'Eastgate Post', self::WEST_LON, self::LAT);

        self::assertNull($station->getZone());
    }

    // ---------------------------------------------------------------- fixtures

    private const array A_WEST_HALF_RING = [[[-30.0, -3.6], [-29.5, -3.6], [-29.5, -2.8], [-30.0, -2.8], [-30.0, -3.6]]];

    private function stations(): StationService
    {
        /** @var StationService $service */
        $service = static::getContainer()->get('test_public.area.stations');

        return $service;
    }

    private function zones(): \Uhifadhi\Bundle\AreaBundle\Service\ZoneService
    {
        /** @var \Uhifadhi\Bundle\AreaBundle\Service\ZoneService $service */
        $service = static::getContainer()->get('test_public.area.zones');

        return $service;
    }

    /** @param list<list<list<float|int>>> $ring */
    private function importOneZone(\Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest $area, string $name, array $ring): void
    {
        /** @var \Uhifadhi\Bundle\AreaBundle\Service\ZoneImportService $imports */
        $imports = static::getContainer()->get('test_public.area.zone_import');

        $directory = sys_get_temp_dir().'/station-zone-'.bin2hex(random_bytes(6));
        mkdir($directory, 0o777, true);
        $path = $directory.'/zones.geojson';
        file_put_contents($path, (string) json_encode([
            'type' => 'FeatureCollection',
            'features' => [['type' => 'Feature', 'properties' => ['Name' => $name], 'geometry' => ['type' => 'Polygon', 'coordinates' => $ring]]],
        ], \JSON_THROW_ON_ERROR));

        $plan = $imports->plan($area, new \Symfony\Component\HttpFoundation\File\File($path), 'zones.geojson');
        $imports->apply($area, $plan, $plan->arrivingNames());
    }
}
