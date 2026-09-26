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

namespace Uhifadhi\Bundle\AreaBundle\Service;

use Symfony\UX\Map\Icon\Icon;
use Symfony\UX\Map\InfoWindow;
use Symfony\UX\Map\Marker;
use Symfony\UX\Map\Point;
use Uhifadhi\Bundle\AreaBundle\Overview\MapLayer;
use Uhifadhi\Bundle\AtlasBundle\Map\MapBuilderInterface;
use Uhifadhi\Bundle\AtlasBundle\Model\AtlasMap;
use Uhifadhi\Bundle\AtlasBundle\Model\GeoJsonLayer;
use Uhifadhi\Bundle\AtlasBundle\Model\Ground;
use Uhifadhi\Bundle\AtlasBundle\Model\LayerShape;
use Uhifadhi\Bundle\AtlasBundle\Model\LiveStream;
use Uhifadhi\Contracts\Area\LivePresence;
use Uhifadhi\Contracts\Atlas\PlatePalette;

/**
 * THE AREA'S TWO PLATES, STATED IN PHP.
 *
 * The area draws two maps: the one an area overview opens on — the boundary,
 * the zones inside it, and every layer the installed modules contributed — and
 * the map of the network, where each area is a shape and a point on the org's
 * own ground.
 *
 * Both are built here and drawn by the atlas. This bundle holds no opinion about
 * what satellite imagery looks like, how a boundary is cased, how a zone is
 * drawn and legended, where the zoom buttons sit or what fullscreen does; it
 * states what is on its maps and the platform draws them the one way it draws
 * every map. The overview's boundary and zones are handed over as the atlas
 * {@see Ground}, which every module's plate of the same area stands on too.
 *
 * THE GEOMETRY ARRIVES AS TEXT, exactly as the geometry column returns it
 * (ST_AsGeoJSON, through the postgis type). It is decoded here — once, on the
 * server — and anything unusable is simply not drawn: a boundary that will not
 * parse is a plate without a boundary, never a page that fails.
 */
final readonly class AreaMapService
{
    /** The area's own legend heading, present whatever is installed — the atlas ground's. */
    public const string OWN_GROUP = Ground::GROUP;

    /** The zones layer's id, which is also what its legend row switches — the atlas ground's. */
    public const string ZONES_LAYER = Ground::ZONES_LAYER_ID;

    /** The register's two layers: the areas that are running, and the rest. */
    public const string LIVE_LAYER = 'area.live';
    public const string SETUP_LAYER = 'area.setup';

    private const string LIVE_SWATCH = PlatePalette::OK;
    private const string QUIET_SWATCH = PlatePalette::DIM;
    /** Public: a second plate of the same ground draws the same edge in the same colour. */
    public const string BOUNDARY_SWATCH = Ground::BOUNDARY_SWATCH;

    public function __construct(
        private MapBuilderInterface $maps,
    ) {
    }

    /**
     * The operational plate: the area's own base content, then every module's
     * layer beneath it under that module's own heading.
     *
     * @param array{boundary: string|null, zones: list<array{name: string|null, geom: string|null}>} $payload
     * @param list<MapLayer>                                                                         $layers
     * @param LiveStream|null                                                                        $stream   where the plate's live marks keep
     *                                                                                                         coming from, or null on a deployment
     *                                                                                                         with no hub
     * @param LivePresence|null                                                                      $presence the marks to draw ON LOAD, already
     *                                                                                                         narrowed to what the viewer may see;
     *                                                                                                         without it the plate stays empty until
     *                                                                                                         the first ping arrives
     */
    public function overview(array $payload, array $layers = [], ?LiveStream $stream = null, ?LivePresence $presence = null): AtlasMap
    {
        $map = $this->maps->createMap();

        // THE AREA'S GROUND IS THE ATLAS'S TO DRAW: the boundary and its row,
        // the zones as one quiet layer with a row that counts them, under
        // "The area" and beneath every module's layer.
        $ground = Ground::fromGeoJson($payload['boundary'], $payload['zones']);
        $map->ground($ground);

        // THE AREA IS WHAT THIS PLATE IS ABOUT. A module's layer may reach
        // outside the boundary — a track that left the park — and a plate
        // framed on everything it drew would open on that.
        if (null !== $ground->boundary) {
            $map->fitTo($ground->boundary);
        }

        foreach ($layers as $layer) {
            $map->addLayer(new GeoJsonLayer(
                id: $layer->id,
                label: $layer->label,
                features: $layer->features,
                swatch: $layer->swatch,
                shape: MapLayer::STYLE_FILL === $layer->style ? LayerShape::Fill : LayerShape::Line,
                visible: $layer->on,
                count: $layer->count,
                group: $layer->groupLabel,
            ));
        }

        // THE MARKS ARE DRAWN ON LOAD, above every module's layer, and the
        // stream keeps them moving — the same two halves the organization's
        // plate has. A stream with nothing drawn first is an empty plate
        // until somebody's phone next pings.
        if (null !== $presence) {
            $map->livePositions($presence);
        }

        if (null !== $stream) {
            $map->liveStream($stream);
        }

        return $map;
    }

    /**
     * THE ORGANIZATION'S PLATE — every area's ground, with everybody on it.
     *
     * IT IS THE NETWORK MAP PLUS THE MARKERS, and nothing else: the
     * boundaries, the live/quiet split and the per-area pins are already
     * {@see register()}'s, so this adds the one layer that makes it a
     * control room rather than drawing a second map that would drift from
     * the first.
     *
     * @param list<array{name: string, live: bool, href: string, boundary: string|null}> $areas
     * @param int                                                                        $withoutPosition how many on duty have reported no fix, for the key
     * @param LiveStream|null                                                            $stream          where the marks keep coming from, or null
     *                                                                                                    on a deployment with no hub
     */
    public function organization(array $areas, LivePresence $presence, int $withoutPosition = 0, ?LiveStream $stream = null): AtlasMap
    {
        $map = $this->register($areas)->livePositions($presence, $withoutPosition);
        if (null !== $stream) {
            $map->liveStream($stream);
        }

        return $map;
    }

    /**
     * The map of the network: every area's boundary, drawn bold where the area
     * is running and quiet where it is only mapped, with a marker on each that
     * opens it.
     *
     * An area with no boundary has no place on a map. It is not drawn, and the
     * register beside the plate is where it is found.
     *
     * @param list<array{name: string, live: bool, href: string, boundary: string|null}> $areas
     */
    public function register(array $areas): AtlasMap
    {
        $map = $this->maps->createMap();

        $live = [];
        $setup = [];
        foreach ($areas as $area) {
            $geometry = self::decode($area['boundary']);
            if (null === $geometry) {
                continue;
            }

            if ($area['live']) {
                $live[] = self::feature($geometry);
            } else {
                $setup[] = self::feature($geometry);
            }

            $centre = self::centre($geometry);
            if (null === $centre) {
                continue;
            }

            $map->ux()->addMarker(new Marker(
                position: $centre,
                title: $area['name'],
                infoWindow: new InfoWindow(
                    headerContent: htmlspecialchars($area['name'], \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8'),
                    content: \sprintf(
                        '<a href="%s">Open the area &rarr;</a>',
                        htmlspecialchars($area['href'], \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8'),
                    ),
                ),
                icon: self::dot($area['live'] ? self::LIVE_SWATCH : self::QUIET_SWATCH, $area['live']),
            ));
        }

        $map
            ->addLayer(new GeoJsonLayer(
                id: self::LIVE_LAYER,
                label: 'Live',
                features: self::collection($live),
                swatch: self::LIVE_SWATCH,
                count: \count($live),
            ))
            ->addLayer(new GeoJsonLayer(
                id: self::SETUP_LAYER,
                label: 'Boundary only',
                features: self::collection($setup),
                swatch: self::QUIET_SWATCH,
                shape: LayerShape::Line,
                count: \count($setup),
            ))
        ;

        return $map;
    }

    /**
     * The centre of a geometry's bounding box — where a marker for the whole
     * shape belongs.
     *
     * Not a centroid: a centroid of a crescent-shaped area falls outside it, and
     * a point outside the shape it names is worse than an approximate one
     * inside the frame. Null where the geometry carries no coordinates at all.
     *
     * @param array<string, mixed> $geometry
     */
    private static function centre(array $geometry): ?Point
    {
        $lngs = [];
        $lats = [];
        array_walk_recursive($geometry, static function (mixed $value, int|string $key) use (&$lngs, &$lats): void {
            // A GeoJSON position is [lng, lat] at the deepest level of an
            // arbitrarily nested coordinates array; walking it is what makes
            // this work for a Polygon and a MultiPolygon alike.
            if (!\is_int($key) || (!\is_int($value) && !\is_float($value))) {
                return;
            }
            if (0 === $key % 2) {
                $lngs[] = (float) $value;
            } else {
                $lats[] = (float) $value;
            }
        });

        if ([] === $lngs || [] === $lats) {
            return null;
        }

        return new Point(
            (min($lats) + max($lats)) / 2,
            (min($lngs) + max($lngs)) / 2,
        );
    }

    /**
     * A dot rather than a pin: the register's map is about where areas ARE, and
     * a filled dot on a live one reads as presence where a dropped pin reads as
     * an address.
     */
    private static function dot(string $colour, bool $filled): Icon
    {
        // The size is on the root element: SvgIcon reads it from the markup and
        // refuses to be told twice.
        return Icon::svg(\sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 16 16"><circle cx="8" cy="8" r="%s" fill="%s" stroke="%s" stroke-width="2"/></svg>',
            $filled ? '6' : '4.5',
            $filled ? $colour : 'none',
            $colour,
        ));
    }

    /**
     * @param array<string, mixed> $geometry
     * @param array<string, mixed> $properties
     *
     * @return array<string, mixed>
     */
    private static function feature(array $geometry, array $properties = []): array
    {
        return ['type' => 'Feature', 'properties' => $properties, 'geometry' => $geometry];
    }

    /**
     * @param list<array<string, mixed>> $features
     *
     * @return array<string, mixed>
     */
    private static function collection(array $features): array
    {
        return ['type' => 'FeatureCollection', 'features' => $features];
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function decode(?string $geoJson): ?array
    {
        if (null === $geoJson || '' === $geoJson) {
            return null;
        }

        try {
            $decoded = json_decode($geoJson, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!\is_array($decoded) || !\is_string($decoded['type'] ?? null)) {
            return null;
        }

        $geometry = [];
        foreach ($decoded as $key => $value) {
            if (\is_string($key)) {
                $geometry[$key] = $value;
            }
        }

        return $geometry;
    }
}
