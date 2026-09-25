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
use Symfony\UX\Map\Marker;
use Symfony\UX\Map\Point;
use Uhifadhi\Bundle\AtlasBundle\Model\AtlasMap;
use Uhifadhi\Bundle\AtlasBundle\Model\BaseLayer;
use Uhifadhi\Bundle\AtlasBundle\Model\Boundary;
use Uhifadhi\Bundle\AtlasBundle\Model\GeoJsonLayer;
use Uhifadhi\Bundle\AtlasBundle\Model\LayerShape;
use Uhifadhi\Bundle\AtlasBundle\Model\LegendItem;
use Uhifadhi\Bundle\AtlasBundle\Model\LiveStream;
use Uhifadhi\Contracts\Atlas\PlatePalette;

/**
 * THE SERIALISATION IS THE CONTRACT. Everything the plate controller knows
 * arrives under ONE namespaced key of the ux-map map's own `extra` payload, so
 * a module's own extra data and the atlas's never collide, and so an
 * installation reading `event.detail.extra.atlas` reads a documented shape
 * rather than whatever this class happened to emit.
 */
final class AtlasMapTest extends TestCase
{
    private const array BOUNDARY = [
        'type' => 'Polygon',
        'coordinates' => [[[-29.5, -3.2], [-29.4, -3.2], [-29.4, -3.1], [-29.5, -3.1], [-29.5, -3.2]]],
    ];

    public function testAnEmptyMapStillStatesTheBaseLayersAndTheChrome(): void
    {
        $atlas = new AtlasMap(self::uxMap());

        self::assertSame([
            'layers' => [],
            'boundary' => null,
            'baseLayers' => ['satellite', 'osm'],
            'fullscreen' => true,
            'fit' => true,
            // NOTHING SAID WHAT THE PLATE IS ABOUT, so it frames itself on
            // everything it drew — which is right for a map whose content
            // IS its subject, and stated as a null rather than left out.
            'subject' => null,
            // NO STREAM WAS STATED, so the marks stay where the page put them
            // — stated as a null rather than left out, like the subject.
            'live' => null,
        ], $atlas->toArray());
    }

    /**
     * A PLATE GIVEN A HUB AND TOPICS CARRIES THEM TO THE BROWSER, under the
     * atlas key like everything else the plate reads. The atlas knows no
     * area and no topic vocabulary: two facts in, the same two facts out.
     */
    public function testAPlateGivenAHubAndTopicsHandsThemToTheBrowser(): void
    {
        $stream = new LiveStream('https://hub.example.test/.well-known/mercure', ['area/a/presence']);
        $atlas = new AtlasMap(self::uxMap())->liveStream($stream);

        self::assertSame(
            ['hub' => 'https://hub.example.test/.well-known/mercure', 'topics' => ['area/a/presence']],
            $atlas->toArray()['live'],
        );
    }

    /**
     * A PLATE THAT IS ABOUT ONE THING SAYS SO, and the browser is told what
     * and how close. A subject with an extent needs no zoom; a point cannot
     * be fitted to and the surface states one.
     */
    public function testAPlateCarriesTheSubjectItOpensOn(): void
    {
        $atlas = new AtlasMap(self::uxMap())->fitTo(self::BOUNDARY);

        self::assertSame(['geojson' => self::BOUNDARY, 'zoom' => null], $atlas->toArray()['subject']);

        $point = ['type' => 'Point', 'coordinates' => [-29.5, -3.2]];
        $post = new AtlasMap(self::uxMap())->fitTo($point, 12);

        self::assertSame(['geojson' => $point, 'zoom' => 12], $post->toArray()['subject']);
    }

    public function testTheWholePayloadTravelsUnderOneNamespacedExtraKey(): void
    {
        $atlas = new AtlasMap(self::uxMap())->boundary(new Boundary(self::BOUNDARY));

        $extra = $atlas->toUxMap()->toArray()['extra'];

        self::assertIsArray($extra);
        self::assertSame([AtlasMap::EXTRA_KEY], array_keys($extra));
        self::assertSame(['geojson' => self::BOUNDARY, 'scrim' => true], $atlas->toArray()['boundary']);
    }

    /**
     * A module's own extra data rides alongside, never underneath: the atlas
     * key is the atlas's and everything else is the module's.
     */
    public function testAModuleKeepsItsOwnExtraDataBesideTheAtlasKey(): void
    {
        $extra = new AtlasMap(self::uxMap())
            ->extra(['sightings' => ['season' => 'dry']])
            ->toUxMap()
            ->toArray()['extra'];

        self::assertIsArray($extra);
        self::assertSame(['season' => 'dry'], $extra['sightings']);
        self::assertArrayHasKey(AtlasMap::EXTRA_KEY, $extra);
    }

    /**
     * Markers, polygons and polylines are ux-map's own model and are passed
     * straight through — the atlas adds nothing to them and takes nothing away.
     */
    public function testUxMapsOwnElementsPassStraightThrough(): void
    {
        $atlas = new AtlasMap(self::uxMap());
        $atlas->ux()->addMarker(new Marker(position: new Point(-3.2, -29.5), title: 'A station'));

        $markers = $atlas->toUxMap()->toArray()['markers'];

        self::assertIsArray($markers);
        self::assertCount(1, $markers);
    }

    public function testEveryLayerIsSerialisedInTheOrderItWasAdded(): void
    {
        $atlas = new AtlasMap(self::uxMap())
            ->addLayer(new GeoJsonLayer(id: 'a', label: 'A', url: '/a.geojson'))
            ->addLayer(new GeoJsonLayer(id: 'b', label: 'B', url: '/b.geojson'));

        self::assertSame(['a', 'b'], array_column($atlas->toArray()['layers'], 'id'));
    }

    public function testTheBaseLayerMenuIsWhateverTheMapSaysItIs(): void
    {
        $atlas = new AtlasMap(self::uxMap())->baseLayers(BaseLayer::Satellite);

        self::assertSame(['satellite'], $atlas->toArray()['baseLayers']);
    }

    public function testFullscreenAndFittingCanBeTurnedOff(): void
    {
        $atlas = new AtlasMap(self::uxMap())->fullscreen(false)->fit(false);

        self::assertFalse($atlas->toArray()['fullscreen']);
        self::assertFalse($atlas->toArray()['fit']);
    }

    /**
     * THE LEGEND IS ONE LIST. Each layer states its own row, and a map may add
     * rows that toggle nothing — a key for what a colour means — so the plate
     * template iterates a single uniform list and the grouping is data.
     */
    public function testTheLegendIsTheLayersRowsFollowedByTheStatedOnes(): void
    {
        $legend = new AtlasMap(self::uxMap())
            ->addLayer(new GeoJsonLayer(id: 'tracks', label: 'Tracks', url: '/t.geojson', group: 'Patrol', shape: LayerShape::Line))
            ->addLegendItem(new LegendItem(label: 'Live', swatch: PlatePalette::ACCENT, group: 'Areas'))
            ->legend();

        self::assertSame(['Tracks', 'Live'], array_map(static fn (LegendItem $i) => $i->label, $legend));
        self::assertSame('tracks', $legend[0]->layerId);
        self::assertNull($legend[1]->layerId);
    }

    public function testTheBoundaryScrimCanStartSwitchedOff(): void
    {
        $atlas = new AtlasMap(self::uxMap())->boundary(new Boundary(self::BOUNDARY, scrim: false));

        $boundary = $atlas->toArray()['boundary'];

        self::assertIsArray($boundary);
        self::assertFalse($boundary['scrim']);
    }

    private static function uxMap(): UxMap
    {
        return new UxMap(center: new Point(0.0, 0.0), zoom: 2.0);
    }
}
