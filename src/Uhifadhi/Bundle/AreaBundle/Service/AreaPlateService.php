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

use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\Model\ZoneFeaturePlan;
use Uhifadhi\Bundle\AreaBundle\Model\ZonePalette;
use Uhifadhi\Bundle\AreaBundle\Model\ZoneRow;
use Uhifadhi\Bundle\AreaBundle\Repository\ZoneRepository;
use Uhifadhi\Bundle\AtlasBundle\Map\MapBuilderInterface;
use Uhifadhi\Bundle\AtlasBundle\Model\AtlasMap;
use Uhifadhi\Bundle\AtlasBundle\Model\Boundary;
use Uhifadhi\Bundle\AtlasBundle\Model\GeoJsonLayer;
use Uhifadhi\Bundle\AtlasBundle\Model\LayerShape;
use Uhifadhi\Bundle\AtlasBundle\Model\LegendItem;
use Uhifadhi\Bundle\AtlasBundle\Model\PointPick;
use Uhifadhi\Bundle\AtlasBundle\Model\StyleRule;
use Uhifadhi\Contracts\Atlas\PlatePalette;

/**
 * THE AREA'S GROUND, DRAWN — every plate the area's own pages read their
 * geography off: the zone set, a file being previewed, and the ground around
 * one post.
 *
 * THE PLATE IS THE ATLAS'S, wearing the house contract: the boundary with its
 * scrim, one layer of rings, the controls where every other map in the product
 * puts them, and the key beneath. Nothing about this map is drawn differently
 * because it is a configure page.
 *
 * A PREVIEW IS THE SAME PLATE WITH DIFFERENT RINGS. The features a file carries
 * are drawn exactly as stored zones are, so what somebody approves looks like
 * what they will get.
 *
 * SEPARATE FROM {@see ZoneSetService}, because the atlas is the PLATE's
 * dependency and not the SET's: counting a zone set needs a database, drawing
 * one needs a map builder, and an installation that carries the area model and
 * mounts no page has the first without the second.
 */
final readonly class AreaPlateService
{
    /** The id of the stations section's add form, which a click on the picker writes into. */
    public const string ADD_FORM = 'station-add';

    /** The key's heading — the zones are the whole of it on this page. */
    public const string ZONES_GROUP = 'The zones';

    /** What the preview's key is headed, so nobody reads it as the live set. */
    public const string ARRIVING_GROUP = 'Arriving';

    public const string ZONES_LAYER = 'area.zones.set';

    /** The posts, as one layer: this one accented, the rest quiet. */
    public const string STATIONS_LAYER = 'area.stations';
    public const string STATIONS_GROUP = 'The posts';

    /** What the design draws around a post, so distance is read and not guessed. */
    public const array RINGS_KM = [5, 10];

    /**
     * HOW CLOSE A RECORD'S PLATE COMES TO ITS POST.
     *
     * A point has no extent to be fitted to, so the zoom is the statement.
     * It is the design's: the station record draws a window 196 units wide
     * over a park 768 units across — a quarter of the area, about thirty
     * kilometres, which on a plate this size is this zoom.
     */
    public const int POST_ZOOM = 12;

    /** The property every feature carries so a style rule can colour it by zone. */
    private const string HUE_PROPERTY = 'zone';

    /** The post the page is about, in the platform's own accent. */
    private const string HERE_SWATCH = PlatePalette::ACCENT;

    public function __construct(
        private ZoneRepository $zones,
        private MapBuilderInterface $maps,
    ) {
    }

    /**
     * The live set on the area's own ground.
     *
     * @param list<ZoneRow> $rows the rows {@see ZoneSetService::view()} produced, so the hues agree
     */
    public function plate(AreaOfInterest $area, array $rows, bool $zoneLabels = true): AtlasMap
    {
        $named = [];
        foreach ($this->zones->zonesFor($area) as $zone) {
            $named[(string) $zone->getName()] = (string) $zone->getGeom();
        }

        $features = [];
        foreach ($rows as $row) {
            $features[] = [$row->name, $named[$row->name] ?? null, PlatePalette::category($row->cat), $row->km2];
        }

        return $this->draw($area, $features, self::ZONES_GROUP, $zoneLabels);
    }

    /**
     * The same plate, drawn from a file nobody has confirmed yet. Only the
     * features that would ARRIVE are drawn: a flagged ring over the zone it
     * clashes with would be read as a zone that exists.
     *
     * @param list<ZoneFeaturePlan> $arriving
     */
    public function previewPlate(AreaOfInterest $area, array $arriving): AtlasMap
    {
        $features = [];
        foreach ($arriving as $position => $feature) {
            $features[] = [$feature->name, $feature->geom, PlatePalette::category(ZonePalette::catFor($position)), $feature->km2 ?? 0];
        }

        return $this->draw($area, $features, self::ARRIVING_GROUP);
    }

    /**
     * THE GROUND AROUND ONE POST: the area's zones behind it, every station in
     * the area, this one accented, and the two rings the design draws so a
     * distance is read rather than guessed.
     *
     * THE ZONES ARE STILL THE ZONES. A station's plate is not a different map
     * — same hues, same key, same contract — with the post's own surroundings
     * added. The same ground rendered two ways on two pages is the defect this
     * avoids by having one service draw both.
     *
     * @param list<ZoneRow>                                                                        $rows  the area's zones, hued as everywhere else
     * @param list<array{uuid: string, name: string, point: string|null, posted: int, here: bool}> $posts every station in the area
     */
    public function aroundStation(AreaOfInterest $area, array $rows, array $posts): AtlasMap
    {
        // THE STATION IS THE SUBJECT: the zones are the ground under it and keep
        // their names for the legend, not for the imagery.
        return $this->withPosts($this->plate($area, $rows, false), $posts, true);
    }

    /**
     * THE PICKER: the same ground, with every post on it as a plain mark.
     *
     * IT IS NOT A READING MAP. Nothing on it is hued by a figure, sized by a
     * count or ringed — a configuration surface that drew one would be a
     * second, worse version of the Stations tab. It is here so that a point
     * can be put somewhere on purpose, and the marks are there so that the
     * somewhere is not on top of a post that already exists.
     *
     * THE PLATE PICKS THE POINT. A click on the ground writes into the add
     * form ({@see self::ADD_FORM}) and a station's "Move on the map" arms it
     * for that station's form; the atlas draws the pin, the caption and the
     * pin's key row, and this page writes no JavaScript.
     *
     * @param list<ZoneRow>                                                                        $rows  the area's zones, hued as everywhere else
     * @param list<array{uuid: string, name: string, point: string|null, posted: int, here: bool}> $posts every station in the area
     */
    public function picker(AreaOfInterest $area, array $rows, array $posts): AtlasMap
    {
        return $this->withPosts($this->plate($area, $rows), $posts, false)
            ->pickPoint(new PointPick(self::ADD_FORM, 'the new station'));
    }

    /**
     * EVERY POST OF THE AREA, HUED BY THE ZONE IT STANDS IN — the Stations
     * tab's plate.
     *
     * HUE IS THE ZONE, HERE TOO. The same colour names the same ground on
     * every plate in the product, so a reader who learnt the key on the zones
     * tab has already learnt this one. A post on unzoned ground keeps the
     * neutral swatch, which is the honest answer rather than a twelfth colour
     * that means "none".
     *
     * @param list<ZoneRow>                                                                                                            $rows  the area's zones, categorised as everywhere else
     * @param list<array{uuid: string, name: string, point: string|null, posted: int, here: bool, zone?: string|null, cat?: int|null}> $posts
     */
    public function stationsPlate(AreaOfInterest $area, array $rows, array $posts): AtlasMap
    {
        $swatches = [];
        $counted = [];
        foreach ($rows as $row) {
            $swatches[$row->name] = PlatePalette::category($row->cat);
            $counted[$row->name] = 0;
        }

        foreach ($posts as $post) {
            $zone = $post['zone'] ?? null;
            if (null !== $zone && \array_key_exists($zone, $counted)) {
                ++$counted[$zone];
            }
        }

        // THE STATIONS ARE THE SUBJECT; the zones keep their names for the legend.
        $map = $this->withPosts($this->plate($area, $rows, false), $posts, false);

        // THE KEY SAYS HOW MANY STAND IN EACH ZONE, because on this page that
        // is what the colour is being counted for.
        foreach ($counted as $name => $count) {
            if ($count > 0) {
                $map->addLegendItem(new LegendItem(
                    label: \sprintf('%s · %d %s', $name, $count, 1 === $count ? 'station' : 'stations'),
                    swatch: $swatches[$name],
                    group: self::STATIONS_GROUP,
                ));
            }
        }

        return $map;
    }

    /**
     * THE PLATE IS ABOUT THIS ONE THING, and the rest of what it draws is
     * context.
     *
     * A ZONE'S PAGE DRAWS THE WHOLE AREA and is about one zone; a post's
     * page draws every post and is about one. Stated here rather than by
     * building a different map, because a second map of the same ground is
     * a second map that drifts.
     *
     * @param string|null $geometry the subject's GeoJSON, as the database holds it
     * @param int|null    $zoom     how close to come to a subject with no extent
     */
    public function focusOn(AtlasMap $map, ?string $geometry, ?int $zoom = null): AtlasMap
    {
        $subject = self::decode($geometry);
        if (null !== $subject) {
            $map->fitTo($subject, $zoom);
        }

        return $map;
    }

    /**
     * THE POSTS, AS ONE LAYER — one legend row, one thing to switch off.
     *
     * @param list<array{uuid: string, name: string, point: string|null, posted: int, here: bool, zone?: string|null, hue?: string|null}> $posts
     * @param bool                                                                                                                        $accentHere whether the post the page is about is drawn apart from the rest
     */
    private function withPosts(AtlasMap $map, array $posts, bool $accentHere): AtlasMap
    {
        $pins = [];
        foreach ($posts as $post) {
            $point = self::decode($post['point']);
            if (null === $point) {
                continue;
            }

            $pins[] = [
                'type' => 'Feature',
                'properties' => [
                    'label' => $post['name'],
                    'posted' => $post['posted'],
                    // The one the page is about is drawn apart from the rest,
                    // by a property rather than by a second layer: one layer
                    // means one legend row and one thing to switch off.
                    'here' => $post['here'],
                    // THE ZONE THE POINT FELL IN, so a plate that hues by zone
                    // can, and one that does not simply ignores it.
                    'zone' => $post['zone'] ?? null,
                    'hue' => $post['hue'] ?? null,
                ],
                'geometry' => $point,
            ];
        }

        $map->addLayer(new GeoJsonLayer(
            id: self::STATIONS_LAYER,
            label: 'Stations',
            features: ['type' => 'FeatureCollection', 'features' => $pins],
            swatch: self::HERE_SWATCH,
            shape: LayerShape::Fill,
            visible: [] !== $pins,
            count: \count($pins),
            group: self::STATIONS_GROUP,
            rules: $accentHere
                ? [StyleRule::when('here', true)->color(self::HERE_SWATCH)->fillColor(self::HERE_SWATCH)]
                : self::huedByZone($pins),
        ));

        return $map;
    }

    /**
     * ONE RULE PER ZONE THE PINS ACTUALLY FALL IN, so a post is drawn in the
     * colour its own ground is drawn in. A post on unzoned ground matches no
     * rule and keeps the layer's neutral swatch, which is the honest answer
     * rather than a colour that means "none".
     *
     * @param list<array{type: string, properties: array<string, mixed>, geometry: mixed}> $pins
     *
     * @return list<StyleRule>
     */
    private static function huedByZone(array $pins): array
    {
        $rules = [];
        foreach ($pins as $pin) {
            $zone = $pin['properties']['zone'] ?? null;
            $hue = $pin['properties']['hue'] ?? null;
            if (\is_string($zone) && \is_string($hue) && !\array_key_exists($zone, $rules)) {
                $rules[$zone] = StyleRule::when('zone', $zone)->color($hue)->fillColor($hue);
            }
        }

        return array_values($rules);
    }

    /**
     * ONE LAYER, COLOURED BY THE ZONE PROPERTY, and one key row per zone. A
     * layer each would give the key eleven switches where the design has a key,
     * and a zone set is read as one thing.
     *
     * @param list<array{0: string, 1: string|null, 2: string, 3: int}> $features name, geometry, hue, size
     */
    private function draw(AreaOfInterest $area, array $features, string $group, bool $zoneLabels = true): AtlasMap
    {
        $map = $this->maps->createMap();

        $boundary = self::decode($area->getGeom());
        if (null !== $boundary) {
            // EVERY LAYER SHIPS A LEGEND ROW, the boundary included: a line on
            // a plate that nothing in the key accounts for is a line nobody can
            // name.
            $map->boundary(new Boundary($boundary));

            // AND THE AREA IS WHAT THE PLATE IS ABOUT, unless the page says
            // otherwise. Framed on everything it drew, a plate opened on the
            // union of the boundary and whatever stood outside it; the house
            // rule is that where there is a boundary, the whole of it is
            // visible.
            $map->fitTo($boundary);
            $map->addLegendItem(new LegendItem(
                label: 'Boundary',
                swatch: AreaMapService::BOUNDARY_SWATCH,
                shape: LayerShape::Line,
                group: AreaMapService::OWN_GROUP,
                layerId: AtlasMap::BOUNDARY_LAYER_ID,
            ));
        }

        $collection = [];
        $rules = [];
        foreach ($features as [$name, $geometry, $hue, $km2]) {
            $decoded = self::decode($geometry);
            if (null === $decoded) {
                continue;
            }

            $collection[] = [
                'type' => 'Feature',
                'properties' => [self::HUE_PROPERTY => $name, 'label' => $name],
                'geometry' => $decoded,
            ];
            $rules[] = StyleRule::when(self::HUE_PROPERTY, $name)->color($hue)->fillColor($hue);

            // THE KEY CARRIES THE SIZE, because on this page the key is the only
            // place a ring is named at all — the plate itself draws colour and
            // nothing else.
            $map->addLegendItem(new LegendItem(
                label: \sprintf('%s · %s km²', $name, number_format($km2)),
                swatch: $hue,
                group: $group,
            ));
        }

        /*
         * THE LAYER'S OWN ROW IS THE SWITCH; the rows above it are the key. The
         * atlas gives every layer a row whether or not a caller asks, so the
         * count goes on it and the colours stay on the zones, where hue means
         * something.
         */
        $map->addLayer(new GeoJsonLayer(
            id: self::ZONES_LAYER,
            label: 'Zones',
            features: ['type' => 'FeatureCollection', 'features' => $collection],
            visible: [] !== $collection,
            count: \count($collection),
            group: $group,
            rules: $rules,
            labels: $zoneLabels,
        ));

        return $map;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function decode(?string $geoJson): ?array
    {
        if (null === $geoJson || '' === $geoJson) {
            return null;
        }

        $decoded = json_decode($geoJson, true);
        if (!\is_array($decoded)) {
            return null;
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
