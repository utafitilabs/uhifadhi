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

namespace Uhifadhi\Bundle\AtlasBundle\Model;

use Symfony\UX\Map\Map as UxMap;
use Uhifadhi\Contracts\Area\LivePresence;
use Uhifadhi\Contracts\Atlas\PlatePalette;

/**
 * A map as the platform draws it: a UX Map map, plus everything the atlas adds
 * on top of it.
 *
 * IT WRAPS, IT DOES NOT REPLACE. Markers, polygons, polylines, circles and
 * rectangles are UX Map's own model and stay there — {@see self::ux()} hands
 * the underlying map over so a module uses `Symfony\UX\Map\Marker` and friends
 * directly. What this class adds is what UX Map has no model for: GeoJSON
 * layers, the area outline and the area's zones ({@see Ground}), which
 * grounds the base-layer menu offers, the legend, and whether the plate wears
 * fullscreen.
 *
 * ALL OF IT TRAVELS UNDER ONE KEY. UX Map forwards a map's `extra` payload to
 * the browser untouched and documents it as the extension point for exactly
 * this; the atlas writes its whole payload under `extra.atlas` so a module's own
 * extra data sits beside it and neither can overwrite the other.
 *
 * @see https://symfony.com/bundles/ux-map/current/index.html#advanced-passing-extra-data-from-php-to-the-stimulus-controller
 * @see vendor/symfony/ux-map/src/Map.php
 */
final class AtlasMap
{
    /** The one key of `extra` the atlas owns. Everything else there is the module's. */
    public const string EXTRA_KEY = 'atlas';

    /**
     * The id the plate keys the drawn boundary by.
     *
     * The boundary is not a layer — it has its own treatment and its own scrim —
     * but it is still something a person may want to switch off, so it is
     * reachable by a legend row like any layer is. A map states that row itself,
     * because only the map knows what to call it and which heading it sits
     * under.
     */
    public const string BOUNDARY_LAYER_ID = 'atlas.boundary';

    /** @var list<GeoJsonLayer> */
    private array $layers = [];

    /**
     * THE LEGEND IN THE ORDER IT WAS BUILT — a layer's own row and a stated
     * key row, interleaved.
     *
     * Kept as one list rather than derived from the two sources, because the
     * order rows come out in is the order the caller wrote them and nothing
     * else. Sorting the layer rows above the stated ones put a caller's own
     * mark in the middle of somebody else's key: the posts row landed between
     * the live position and the state that says it is stale, which reads as
     * three states of one mark and is not.
     *
     * @var list<GeoJsonLayer|LegendItem>
     */
    private array $legendRows = [];

    private ?Boundary $boundary = null;

    /**
     * THE AREA UNDER EVERYTHING ELSE — held apart from the layers and the
     * legend rows the caller adds, so it is listed first in both whenever it
     * was handed over. The plate draws layers in the order they are listed
     * (`for (const layer of atlas.layers)` in the plate controller), so first
     * is under every mark.
     *
     * @see assets/controllers/map_plate_controller.js
     */
    private ?Ground $ground = null;

    /** @var list<BaseLayer> */
    private array $baseLayers = [BaseLayer::Satellite, BaseLayer::Street];

    private bool $fullscreen = true;

    private bool $fit = true;

    /**
     * WHAT THE PLATE IS ABOUT — the geometry it frames itself on, when that
     * is not simply everything it drew.
     *
     * @var array<string, mixed>|null
     */
    private ?array $subject = null;

    /** How close the plate may come to a subject with no extent of its own. */
    private ?int $subjectZoom = null;

    /**
     * WHERE THE LIVE MARKS KEEP COMING FROM after the page is drawn — the
     * hub and the topics, or nothing, which is the ordinary plate.
     */
    private ?LiveStream $live = null;

    /** Which form a click on the ground writes a point into, or nothing. */
    private ?PointPick $pick = null;

    /** @var array<string, mixed> */
    private array $extra = [];

    public function __construct(
        private readonly UxMap $map,
    ) {
    }

    /**
     * The UX Map map underneath, for everything UX Map already models: the
     * centre and zoom, markers, polygons, polylines, circles, rectangles.
     */
    public function ux(): UxMap
    {
        return $this->map;
    }

    public function boundary(Boundary $boundary): self
    {
        $this->boundary = $boundary;

        return $this;
    }

    /**
     * THE AREA'S GROUND, drawn the platform's one way: the boundary with its
     * row, the zones as one quiet line layer with a row that counts them, both
     * under "The area" and beneath every other layer on the plate.
     *
     * One ground per plate: a second call replaces the first, boundary and
     * all.
     */
    public function ground(Ground $ground): self
    {
        $this->ground = $ground;
        $this->boundary = null === $ground->boundary ? null : new Boundary($ground->boundary, $ground->scrim);

        return $this;
    }

    public function addLayer(GeoJsonLayer $layer): self
    {
        $this->layers[] = $layer;
        $this->legendRows[] = $layer;

        return $this;
    }

    /**
     * WHERE PEOPLE ARE — the live layer and the key that must come with it, in
     * one call.
     *
     * Two things arrive together because the map-legend contract says a layer
     * ships a legend, and here the legend says more than the layer can: the
     * mark has a state the plate draws dimmed and a state it draws NOWHERE,
     * and a caller left to remember the second would ship a plate that is
     * silent about the people it is not showing.
     *
     * @param int $withoutPosition how many people the caller knows of that this
     *                             read had no fix for
     */
    public function livePositions(LivePresence $presence, int $withoutPosition = 0): self
    {
        $this->addLayer(LiveMarks::layer($presence));
        foreach (LiveMarks::key($presence, $withoutPosition) as $row) {
            $this->addLegendItem($row);
        }

        return $this;
    }

    /**
     * KEEP THE LIVE MARKS MOVING: the hub the browser can reach and the topics
     * to hold open on it. The plate subscribes with credentials and each
     * frame moves, adds or removes one mark of the live layer; the legend's
     * counts follow.
     *
     * The builder that knows the area states this; the atlas carries two
     * strings and asks nothing about what a topic means. One stream per
     * plate: a second call replaces the first.
     */
    public function liveStream(LiveStream $stream): self
    {
        $this->live = $stream;

        return $this;
    }

    /**
     * THE PLATE PICKS A POINT INTO A FORM: a click on the ground, or a drag of
     * the pin, writes a latitude and a longitude into the inputs the pick
     * names. The plate draws the pin, the caption under the legend in its
     * three states, and a key row for the pin — here, after whatever the
     * caller has stated so far, which is the design's order.
     *
     * One pick per plate: a second call replaces the form and leaves the one
     * key row.
     */
    public function pickPoint(PointPick $pick): self
    {
        if (null === $this->pick) {
            $this->legendRows[] = new LegendItem(label: 'The pin', swatch: PlatePalette::ACCENT, shape: LayerShape::Pin, note: 'being placed');
        }
        $this->pick = $pick;

        return $this;
    }

    /** What the plate picks into, for the template that draws its caption. */
    public function pick(): ?PointPick
    {
        return $this->pick;
    }

    /**
     * A legend row that switches nothing — a key for a colour the plate uses
     * without a layer behind it.
     */
    public function addLegendItem(LegendItem $item): self
    {
        $this->legendRows[] = $item;

        return $this;
    }

    /**
     * Which grounds the base-layer menu offers, in the order it offers them.
     * The first is the one the plate opens on.
     */
    public function baseLayers(BaseLayer ...$layers): self
    {
        $this->baseLayers = array_values($layers);

        return $this;
    }

    /**
     * Whether the plate wears the fullscreen control. Off for a plate that is
     * already the whole screen, or one small enough that expanding it says
     * nothing.
     */
    public function fullscreen(bool $enable = true): self
    {
        $this->fullscreen = $enable;

        return $this;
    }

    /**
     * Whether the plate re-frames itself on what it drew.
     *
     * On by default, because a map is nearly always about its content rather
     * than about a coordinate. Off for a plate whose centre and zoom are the
     * point — a fixed view of one place, a locator inset.
     */
    public function fit(bool $enable = true): self
    {
        $this->fit = $enable;

        return $this;
    }

    /**
     * WHAT THE PLATE IS ABOUT, so it opens on its subject rather than on
     * everything it happens to have drawn.
     *
     * A ZONE'S PAGE DRAWS THE AREA and is not about the area: framed on
     * everything, the zone came out at a quarter of the plate's width with
     * the rest of the park around it. The surface states its subject and the
     * context stays context.
     *
     * A SUBJECT WITH NO EXTENT — a station's point — cannot be fitted to, so
     * a zoom says how close the plate may come; the design draws a record's
     * plate at that zoom, not at the map's maximum.
     *
     * @param array<string, mixed> $geojson a geometry, feature or collection
     */
    public function fitTo(array $geojson, ?int $zoom = null): self
    {
        $this->subject = $geojson;
        $this->subjectZoom = $zoom;

        return $this;
    }

    /**
     * The module's own data for the browser, forwarded beside the atlas key.
     *
     * @param array<string, mixed> $extra
     */
    public function extra(array $extra): self
    {
        $this->extra = $extra;

        return $this;
    }

    /**
     * The whole legend as one list, IN THE ORDER IT WAS BUILT — a layer's own
     * row where the layer was added, a stated row where it was stated.
     *
     * The order is the caller's sentence about their own plate, so the map
     * does not rewrite it: a key that groups "live position · stale · no
     * position" and then names the posts reads as one mark's states and then
     * another mark, and the same rows in any other order do not.
     *
     * @return list<LegendItem>
     */
    public function legend(): array
    {
        $ground = [];
        if (null !== $this->ground) {
            $boundary = $this->ground->boundaryRow();
            if (null !== $boundary) {
                $ground[] = $boundary;
            }
            $ground[] = $this->ground->zonesLayer()->legendItem();
        }

        return [...$ground, ...array_map(
            static fn (GeoJsonLayer|LegendItem $row): LegendItem => $row instanceof GeoJsonLayer ? $row->legendItem() : $row,
            $this->legendRows,
        )];
    }

    /**
     * The atlas payload, as the plate controller reads it off
     * `event.detail.extra.atlas`.
     *
     * @return array{
     *     layers: list<array<string, mixed>>,
     *     boundary: array{geojson: array<string, mixed>, scrim: bool}|null,
     *     baseLayers: list<string>,
     *     fullscreen: bool,
     *     fit: bool,
     *     subject: array{geojson: array<string, mixed>, zoom: int|null}|null,
     *     live: array{hub: string, topics: list<string>}|null,
     *     pick: array{form: string, name: string, latitude: string, longitude: string, precision: int}|null,
     * }
     */
    public function toArray(): array
    {
        return [
            'layers' => array_map(
                static fn (GeoJsonLayer $layer) => $layer->toArray(),
                null === $this->ground ? $this->layers : [$this->ground->zonesLayer(), ...$this->layers],
            ),
            'boundary' => $this->boundary?->toArray(),
            'baseLayers' => array_map(static fn (BaseLayer $base) => $base->value, $this->baseLayers),
            'fullscreen' => $this->fullscreen,
            'fit' => $this->fit,
            'subject' => null === $this->subject
                ? null
                : ['geojson' => $this->subject, 'zoom' => $this->subjectZoom],
            'live' => $this->live?->toArray(),
            'pick' => $this->pick?->toArray(),
        ];
    }

    /**
     * The UX Map map with the atlas payload written onto it — what the renderer
     * is handed.
     */
    public function toUxMap(): UxMap
    {
        return $this->map->extra([...$this->extra, self::EXTRA_KEY => $this->toArray()]);
    }
}
