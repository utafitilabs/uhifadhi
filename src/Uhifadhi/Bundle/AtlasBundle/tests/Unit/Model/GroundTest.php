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

namespace Uhifadhi\Bundle\AtlasBundle\Tests\Unit\Model;

use PHPUnit\Framework\TestCase;
use Symfony\UX\Map\Map as UxMap;
use Uhifadhi\Bundle\AtlasBundle\Model\AtlasMap;
use Uhifadhi\Bundle\AtlasBundle\Model\GeoJsonLayer;
use Uhifadhi\Bundle\AtlasBundle\Model\Ground;
use Uhifadhi\Bundle\AtlasBundle\Model\LayerShape;
use Uhifadhi\Bundle\AtlasBundle\Model\LegendItem;
use Uhifadhi\Contracts\Atlas\PlatePalette;

/**
 * THE GROUND IS DRAWN ONE WAY ON EVERY PLATE: the boundary with its row, and
 * the zones as one quiet line layer with a row that counts them, both under
 * "The area", both beneath whatever a module adds.
 */
final class GroundTest extends TestCase
{
    private const array BOUNDARY = [
        'type' => 'Polygon',
        'coordinates' => [[[-29.5, -3.2], [-29.4, -3.2], [-29.4, -3.1], [-29.5, -3.1], [-29.5, -3.2]]],
    ];

    private const array ZONE = [
        'type' => 'Polygon',
        'coordinates' => [[[-29.5, -3.2], [-29.45, -3.2], [-29.45, -3.1], [-29.5, -3.1], [-29.5, -3.2]]],
    ];

    public function testTheZonesAreOneQuietLineLayerUnderTheArea(): void
    {
        $layer = new Ground(self::BOUNDARY, [['name' => 'North', 'geometry' => self::ZONE]])->zonesLayer();

        self::assertSame(Ground::ZONES_LAYER_ID, $layer->id);
        self::assertSame('Zones', $layer->label);
        self::assertSame(PlatePalette::DIM, $layer->swatch);
        self::assertSame(LayerShape::Line, $layer->shape);
        self::assertSame(1, $layer->count);
        self::assertTrue($layer->visible);
        self::assertSame(Ground::GROUP, $layer->group);
        self::assertSame('The area', Ground::GROUP);
        self::assertSame([
            'type' => 'FeatureCollection',
            'features' => [['type' => 'Feature', 'properties' => ['label' => 'North'], 'geometry' => self::ZONE]],
        ], $layer->features);
    }

    /**
     * "Zones · 0" is an answer; a missing row is a question. The layer is
     * built empty and starts hidden.
     */
    public function testTheZonesLayerIsThereWhenThereAreNoZones(): void
    {
        $layer = new Ground(null)->zonesLayer();

        self::assertSame(0, $layer->count);
        self::assertFalse($layer->visible);
        self::assertSame(['type' => 'FeatureCollection', 'features' => []], $layer->features);
    }

    public function testTheBoundaryRowSwitchesTheBoundary(): void
    {
        $row = new Ground(self::BOUNDARY)->boundaryRow();

        self::assertNotNull($row);
        self::assertSame('Boundary', $row->label);
        self::assertSame(PlatePalette::ACCENT, $row->swatch);
        self::assertSame(LayerShape::Line, $row->shape);
        self::assertSame(Ground::GROUP, $row->group);
        self::assertSame(AtlasMap::BOUNDARY_LAYER_ID, $row->layerId);
        self::assertNull($row->count);
    }

    public function testAGroundWithNoBoundaryHasNoBoundaryRow(): void
    {
        self::assertNull(new Ground(null)->boundaryRow());
    }

    public function testGeoJsonTextIsDecodedAndWhatWillNotParseIsNotDrawn(): void
    {
        $ground = Ground::fromGeoJson(
            json_encode(self::BOUNDARY, \JSON_THROW_ON_ERROR),
            [
                ['name' => 'North', 'geom' => json_encode(self::ZONE, \JSON_THROW_ON_ERROR)],
                ['name' => null, 'geom' => json_encode(self::ZONE, \JSON_THROW_ON_ERROR)],
                ['name' => 'Broken', 'geom' => '{'],
                ['name' => 'Empty', 'geom' => null],
                ['name' => 'Not geometry', 'geom' => '[1,2]'],
            ],
        );

        self::assertSame(self::BOUNDARY, $ground->boundary);
        self::assertSame([
            ['name' => 'North', 'geometry' => self::ZONE],
            // A zone with no name is drawn without a caption, never left off.
            ['name' => '', 'geometry' => self::ZONE],
        ], $ground->zones);
        self::assertTrue($ground->scrim);
    }

    public function testABoundaryThatWillNotParseIsAGroundWithoutABoundary(): void
    {
        self::assertNull(Ground::fromGeoJson('not json', [])->boundary);
        self::assertNull(Ground::fromGeoJson('', [])->boundary);
        self::assertNull(Ground::fromGeoJson(null, [])->boundary);
    }

    public function testThePlateTakesTheBoundaryWithTheScrimTheCallerChose(): void
    {
        $open = self::atlas()->ground(new Ground(self::BOUNDARY, scrim: false))->toArray();

        self::assertSame(['geojson' => self::BOUNDARY, 'scrim' => false], $open['boundary']);

        $dimmed = self::atlas()->ground(new Ground(self::BOUNDARY))->toArray();

        self::assertSame(['geojson' => self::BOUNDARY, 'scrim' => true], $dimmed['boundary']);
    }

    /**
     * THE GROUND IS UNDER EVERY MODULE MARK AND ITS ROWS OPEN THE LEGEND,
     * whenever the caller hands it over: the plate draws layers in the order
     * they are listed, so the zones come first in the list.
     */
    public function testTheGroundComesFirstInTheLayersAndTheLegend(): void
    {
        $map = self::atlas()
            ->addLayer(new GeoJsonLayer(id: 'sightings', label: 'sightings', features: ['type' => 'FeatureCollection', 'features' => []], group: 'Sightings'))
            ->ground(new Ground(self::BOUNDARY, [['name' => 'North', 'geometry' => self::ZONE]]))
        ;

        self::assertSame([Ground::ZONES_LAYER_ID, 'sightings'], array_column($map->toArray()['layers'], 'id'));
        self::assertSame(
            [['Boundary', 'The area', null], ['Zones', 'The area', 1], ['sightings', 'Sightings', null]],
            array_map(static fn (LegendItem $row): array => [$row->label, $row->group, $row->count], $map->legend()),
        );
    }

    public function testAPlateWithAGroundButNoBoundaryStillStatesItsZonesRow(): void
    {
        $map = self::atlas()->ground(new Ground(null));

        self::assertNull($map->toArray()['boundary']);
        self::assertSame(['Zones'], array_map(static fn (LegendItem $row): string => $row->label, $map->legend()));
        self::assertSame(0, $map->legend()[0]->count);
    }

    /**
     * ONE GROUND PER PLATE. Handing a second one over replaces the first
     * rather than drawing the area twice.
     */
    public function testASecondGroundReplacesTheFirst(): void
    {
        $map = self::atlas()
            ->ground(new Ground(self::BOUNDARY, [['name' => 'North', 'geometry' => self::ZONE]]))
            ->ground(new Ground(null))
        ;

        self::assertNull($map->toArray()['boundary']);
        self::assertCount(1, $map->toArray()['layers']);
        self::assertCount(1, $map->legend());
        self::assertSame(0, $map->legend()[0]->count);
    }

    private static function atlas(): AtlasMap
    {
        return new AtlasMap(new UxMap());
    }
}
