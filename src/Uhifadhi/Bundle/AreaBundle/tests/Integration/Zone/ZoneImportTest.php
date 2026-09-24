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
use Uhifadhi\Bundle\AreaBundle\Entity\Zone;
use Uhifadhi\Bundle\AreaBundle\Entity\ZoneImport;
use Uhifadhi\Bundle\AreaBundle\Exception\ZoneImportException;
use Uhifadhi\Bundle\AreaBundle\Model\ZoneImportResult;
use Uhifadhi\Bundle\AreaBundle\Service\ZoneImportService;
use Uhifadhi\Bundle\AreaBundle\Tests\Integration\IntegrationTestCase;

/**
 * A WHOLE ZONING SCHEME, FROM ONE FILE — the shape a zoning scheme actually
 * arrives in: a GeoJSON FeatureCollection exported from a desktop GIS, one
 * feature per zone, names in whichever property that tool wrote them to, and
 * every other property the export happens to carry.
 *
 * THE FILE IS NEVER CLEANED BY HAND. An export carries KML residue —
 * description, timestamp, begin, end, altitudeMode, tessellate, extrude,
 * visibility, drawOrder, icon — and merge fields a layer merge added. None of
 * it is a reason to refuse a file and none of it is stored; the summary simply
 * states what was ignored, so nobody is sent back to a text editor.
 *
 * A WHOLE FILE IS REFUSED FOR THREE REASONS AND NO OTHERS — it cannot be read
 * as GeoJSON, it carries no property that names every feature, or its
 * coordinates are projected rather than degrees. Those are the refusals here.
 * What one FEATURE of a readable file can be turned away for is a verdict in
 * the preview, and lives in {@see ZoneAdditiveImportTest}.
 */
#[CoversClass(ZoneImportService::class)]
#[CoversClass(ZoneImport::class)]
final class ZoneImportTest extends IntegrationTestCase
{
    private function importer(): ZoneImportService
    {
        /** @var ZoneImportService $service */
        $service = static::getContainer()->get('test_public.area.zone_import');

        return $service;
    }

    /**
     * THE OWNER'S OWN SHAPE: eleven features, names in `Name`, the CRS84 urn
     * spelled out, and three-element coordinates because the source was KML and
     * every position carries an altitude.
     */
    public function testAFeatureCollectionOfElevenBecomesElevenZones(): void
    {
        $area = $this->anArea();

        $result = $this->import($area, $this->ownersShape());

        self::assertSame(11, $result->count());
        self::assertSame('Name', $result->nameProperty);
        self::assertSame(
            ['Sector 01', 'Sector 02', 'Sector 03', 'Sector 04', 'Sector 05', 'Sector 06',
                'Sector 07', 'Sector 08', 'Sector 09', 'Sector 10', 'Sector 11'],
            $result->added,
        );

        $stored = $this->em->getRepository(Zone::class)->findBy(['area' => $area], ['name' => 'ASC']);
        self::assertCount(11, $stored);
        // The geometry is the database's, not a string echoed back: it was read
        // to a MultiPolygon and the Z ordinate the KML carried is gone.
        self::assertStringContainsString('MultiPolygon', (string) $stored[0]->getGeom());
        self::assertStringNotContainsString('1200', (string) $stored[0]->getGeom());
    }

    /** Whatever else the export carried is named in the summary and stored nowhere. */
    public function testEveryOtherPropertyIsIgnoredAndTheSummarySaysWhich(): void
    {
        $area = $this->anArea();

        $result = $this->import($area, $this->ownersShape());

        self::assertSame(
            ['altitudeMode', 'begin', 'description', 'drawOrder', 'end', 'extrude',
                'icon', 'layer', 'path', 'tessellate', 'timestamp', 'visibility'],
            $result->ignoredProperties,
        );
    }

    /** The name property is whichever of the accepted spellings the file used first. */
    public function testTheNamePropertyIsTheFirstUsableOneInTheFile(): void
    {
        $area = $this->anArea();

        $result = $this->import($area, $this->collection([
            $this->feature(['zone' => 'Western Sector', 'description' => 'x'], self::A_WEST_HALF_RING),
            $this->feature(['zone' => 'Eastern Sector', 'description' => 'y'], self::A_EAST_HALF_RING),
        ]));

        self::assertSame('zone', $result->nameProperty);
        self::assertSame(['Western Sector', 'Eastern Sector'], $result->added);
    }

    /**
     * PROVENANCE, NOT THE FILE. Where the scheme came from is worth keeping —
     * the filename somebody uploaded, when, who, how many zones it made and
     * which property supplied the names. The file itself is parsed and let go.
     */
    public function testTheImportStoresItsProvenanceAndKeepsNoFile(): void
    {
        $area = $this->anArea();
        $directory = $this->aTemporaryDirectory();
        $path = $directory.'/wards_2026.geojson';
        file_put_contents($path, $this->ownersShape());

        $this->importer()->importInto($area, new File($path), 'wards_2026.geojson', 'ranger@example.test');

        $this->em->clear();
        $imports = $this->em->getRepository(ZoneImport::class)->findAll();
        self::assertCount(1, $imports);
        self::assertSame('wards_2026.geojson', $imports[0]->getFileName());
        self::assertSame('ranger@example.test', $imports[0]->getImportedBy());
        self::assertSame(11, $imports[0]->getZoneCount());
        self::assertSame('Name', $imports[0]->getNameProperty());
        self::assertNotNull($imports[0]->getImportedAt());

        // Every zone points back at the import that made it.
        $zones = $this->em->getRepository(Zone::class)->findBy(['area' => $area]);
        self::assertSame(11, \count($zones));
        self::assertSame($imports[0]->getId(), $zones[0]->getImport()?->getId());

        // NOTHING WAS WRITTEN. The directory holds the one file that was
        // uploaded and no copy of it, and no column of the provenance row
        // carries the document.
        self::assertSame(['wards_2026.geojson'], array_values(array_diff(scandir($directory) ?: [], ['.', '..'])));
        $row = $this->em->getConnection()->fetchOne('SELECT row_to_json(zone_import) FROM zone_import');
        self::assertIsString($row);
        self::assertStringNotContainsString('Feature', $row);
    }

    /** A user is not always known — a fixture loader has no session. */
    public function testAnImportWithNoKnownUserStoresTheRestOfTheProvenance(): void
    {
        $area = $this->anArea();

        $this->import($area, $this->ownersShape());

        $this->em->clear();
        $imports = $this->em->getRepository(ZoneImport::class)->findAll();
        self::assertNull($imports[0]->getImportedBy());
    }

    public function testADeclaredProjectedCrsIsRefusedAndNamed(): void
    {
        $area = $this->anArea();

        $this->expectException(ZoneImportException::class);
        $this->expectExceptionMessageMatches('/EPSG::?32736/');

        $this->import($area, (string) json_encode([
            'type' => 'FeatureCollection',
            'crs' => ['type' => 'name', 'properties' => ['name' => 'urn:ogc:def:crs:EPSG::32736']],
            'features' => [$this->feature(['Name' => 'Sector 01'], self::A_WEST_HALF_RING)],
        ], \JSON_THROW_ON_ERROR));

        self::assertSame(0, $this->countZones($area));
    }

    /** The CRS84 urn is WGS84 written out, and a file that omits `crs` is WGS84 by definition. */
    public function testTheCrs84UrnAndAMissingCrsAreBothAccepted(): void
    {
        $result = $this->import($this->anArea('With a CRS'), (string) json_encode([
            'type' => 'FeatureCollection',
            'crs' => ['type' => 'name', 'properties' => ['name' => 'urn:ogc:def:crs:OGC:1.3:CRS84']],
            'features' => [$this->feature(['Name' => 'Sector 01'], self::A_WEST_HALF_RING)],
        ], \JSON_THROW_ON_ERROR));
        self::assertSame(1, $result->count());

        $bare = $this->import($this->anArea('Without a CRS'), $this->collection([
            $this->feature(['Name' => 'Sector 01'], self::A_WEST_HALF_RING),
        ]));
        self::assertSame(1, $bare->count());
    }

    public function testAFeatureWithNoUsableNamePropertyIsRefused(): void
    {
        $area = $this->anArea();

        $this->expectException(ZoneImportException::class);
        $this->expectExceptionMessageMatches('/name/i');

        $this->import($area, $this->collection([
            $this->feature(['description' => 'the western part'], self::A_WEST_HALF_RING),
        ]));
    }

    public function testADocumentThatIsNotGeoJsonIsRefused(): void
    {
        $this->expectException(ZoneImportException::class);
        $this->expectExceptionMessageMatches('/GeoJSON/');

        $this->import($this->anArea(), "name,lat,lon\nWest,1,2\n", 'zones.csv');
    }

    public function testADocumentThatIsNotAFeatureCollectionIsRefused(): void
    {
        $this->expectException(ZoneImportException::class);
        $this->expectExceptionMessageMatches('/FeatureCollection/');

        $this->import($this->anArea(), (string) json_encode([
            'type' => 'LineString', 'coordinates' => [[-29.8, -3.2], [-29.6, -3.1]],
        ], \JSON_THROW_ON_ERROR));
    }

    /** One polygon and one name is a scheme of one — a Feature on its own is accepted. */
    public function testABareFeatureIsAcceptedAsASchemeOfOne(): void
    {
        $result = $this->import($this->anArea(), (string) json_encode(
            $this->feature(['Name' => 'Western Sector'], self::A_WEST_HALF_RING),
            \JSON_THROW_ON_ERROR,
        ));

        self::assertSame(1, $result->count());
        self::assertSame(['Western Sector'], $result->added);
    }

    // ---------------------------------------------------------------- fixtures

    /**
     * The rings the base class's halves are written from, as the RING a feature
     * carries rather than the MultiPolygon a column takes — with the altitude
     * every KML export writes into a third ordinate.
     */
    private const array A_WEST_HALF_RING = [[[-30.0, -3.6, 1200.0], [-29.5, -3.6, 1200.0], [-29.5, -2.8, 1200.0], [-30.0, -2.8, 1200.0], [-30.0, -3.6, 1200.0]]];
    private const array A_EAST_HALF_RING = [[[-29.5, -3.6, 1200.0], [-29.0, -3.6, 1200.0], [-29.0, -2.8, 1200.0], [-29.5, -2.8, 1200.0], [-29.5, -3.6, 1200.0]]];

    /**
     * A FILE THAT SHOUTS ITS NAMES DOES NOT MAKE THE PRODUCT SHOUT THEM.
     *
     * A shapefile's attribute table is very often upper case throughout,
     * because that is how the tool that wrote it writes — not because anybody
     * decided the zone is called CRATER. Imported straight, the name shouted
     * in the register, on the plate's key, in the sidebar's menu and in the
     * middle of every sentence that said it.
     *
     * A name with one lower-case letter in it was written by a person and is
     * left exactly as it arrived, because a corrected name that is now wrong
     * is worse than a shouting one.
     */
    public function testAnEntirelyUpperCaseNameIsTitleCasedAndAMixedOneIsNot(): void
    {
        $area = $this->anArea();

        $result = $this->import($area, $this->collection([
            $this->feature(['name' => 'CRATER'], $this->squareAt(-29.9)),
            $this->feature(['name' => 'OL DOINYO LENGAI'], $this->squareAt(-29.7)),
            $this->feature(['name' => 'Forest Edge'], $this->squareAt(-29.5)),
            $this->feature(['name' => 'NCA Highlands'], $this->squareAt(-29.3)),
        ]));

        self::assertSame(['Crater', 'Ol Doinyo Lengai', 'Forest Edge', 'NCA Highlands'], $result->added);
    }

    /** @return list<list<list<float>>> */
    private function squareAt(float $west): array
    {
        $east = $west + 0.1;

        return [[
            [$west, -3.6], [$east, -3.6], [$east, -3.5], [$west, -3.5], [$west, -3.6],
        ]];
    }

    private function import(AreaOfInterest $area, string $document, string $originalName = 'zones.geojson'): ZoneImportResult
    {
        $path = $this->aTemporaryDirectory().'/'.$originalName;
        file_put_contents($path, $document);

        return $this->importer()->importInto($area, new File($path), $originalName);
    }

    private function aTemporaryDirectory(): string
    {
        $directory = sys_get_temp_dir().'/zone-import-'.bin2hex(random_bytes(6));
        mkdir($directory, 0o777, true);

        return $directory;
    }

    private function countZones(AreaOfInterest $area): int
    {
        return \count($this->em->getRepository(Zone::class)->findBy(['area' => $area]));
    }

    /**
     * ELEVEN STRIPS OF THE SAME RECTANGLE, each carrying the residue a KML
     * conversion leaves behind and a layer merge adds.
     */
    private function ownersShape(): string
    {
        $features = [];
        for ($i = 0; $i < 11; ++$i) {
            $west = -30.0 + ($i * (1.0 / 11));
            $east = -30.0 + (($i + 1) * (1.0 / 11));
            $features[] = $this->feature([
                'Name' => \sprintf('Sector %02d', $i + 1),
                'description' => 'exported from the wards layer',
                'timestamp' => null,
                'begin' => null,
                'end' => null,
                'altitudeMode' => 'clampToGround',
                'tessellate' => -1,
                'extrude' => 0,
                'visibility' => -1,
                'drawOrder' => null,
                'icon' => null,
                'layer' => 'wards',
                'path' => '/home/gis/wards.kmz',
            ], [[
                [$west, -3.6, 1200.0], [$east, -3.6, 1200.0], [$east, -2.8, 1200.0],
                [$west, -2.8, 1200.0], [$west, -3.6, 1200.0],
            ]]);
        }

        return $this->collection($features);
    }

    /**
     * @param array<string, mixed>        $properties
     * @param list<list<list<float|int>>> $ring
     *
     * @return array<string, mixed>
     */
    private function feature(array $properties, array $ring): array
    {
        return [
            'type' => 'Feature',
            'properties' => $properties,
            'geometry' => ['type' => 'Polygon', 'coordinates' => $ring],
        ];
    }

    /** @param list<array<string, mixed>> $features */
    private function collection(array $features): string
    {
        return (string) json_encode(['type' => 'FeatureCollection', 'features' => $features], \JSON_THROW_ON_ERROR);
    }
}
