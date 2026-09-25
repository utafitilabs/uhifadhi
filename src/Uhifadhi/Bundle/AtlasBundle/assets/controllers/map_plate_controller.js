/*
 * This file is part of the Uhifadhi core.
 *
 * (c) Ezekiel Mjema <https://github.com/eemjema>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

import { Controller } from '@hotwired/stimulus';
import { satelliteLayer, streetLayer } from 'uhifadhi/basemaps';
import { drawBoundary } from 'uhifadhi/boundary';
import { mountMapChrome } from 'uhifadhi/map-chrome';

/*
 * THE PLATE — the platform's ONE map controller.
 *
 * A module writes no JavaScript. It builds a map in PHP and calls
 * `render_map()`; this controller is what turns that into a map with the
 * deployment's imagery under it, the boundary drawn the platform's one way, the
 * control stack every map wears, and a legend whose rows actually switch
 * something.
 *
 * IT EXTENDS UX MAP, IT DOES NOT REPLACE IT. The map itself is created by the
 * Leaflet bridge's own controller on the element inside this one, and this
 * controller works entirely through the two extension points UX Map documents
 * for exactly this:
 *
 *   ux:map:pre-connect — the map has not been created yet, and its
 *                        `bridgeOptions` are still writable.
 *   ux:map:connect     — the map exists; `event.detail` carries it, the Leaflet
 *                        namespace, and the `extra` payload PHP sent.
 *
 *   https://symfony.com/bundles/ux-map/current/index.html#advanced-passing-extra-data-from-php-to-the-stimulus-controller
 *
 * Both events bubble, so this controller sits on the PLATE ROOT — the element
 * that goes fullscreen, and the element the filter row and the legend are
 * inside — and still hears a map created one level down.
 *
 * LEAFLET COMES FROM THE EVENT. `event.detail.L` is the very namespace the
 * bridge built the map with, so there is exactly one Leaflet on the page and
 * this file neither imports it nor reads a global.
 *
 * WHAT A FEATURE LOOKS LIKE, SAYS AND ANSWERS TO IS ALSO DATA. A module states
 * a base style and rules keyed on a feature's own properties, the property a
 * hover reads, the properties a popup reads, and the property that identifies a
 * feature; this controller evaluates them. No callback crosses the wire, which
 * is exactly why one controller can draw every module's layers.
 *
 * IT PICKS A POINT INTO A FORM where PHP asked it to — see THE PICK MODE
 * below — so a page that needs a point clicked writes no JavaScript either.
 *
 * IT DISPATCHES ITS OWN LIFECYCLE — `atlas:map:connect` and
 * `atlas:map:layer:added` — so an installation can extend a plate without
 * forking it. A module should not need to.
 */

/** The one key of UX Map's `extra` payload the atlas owns. */
const ATLAS = 'atlas';

/** The id the drawn boundary is kept under, so a legend row can switch it. */
const BOUNDARY_LAYER = 'atlas.boundary';

/* THE HOUSE PADDING between what a plate is about and the plate's own edge. */
const FIT_PADDING = [26, 26];

/* HOW FINELY A PLATE MAY ZOOM.
 *
 * Leaflet's default is whole levels, and a whole level is a factor of two:
 * fitting an area to a frame, one level in overflowed the plate and one
 * level out drew the boundary at half the frame's height, centred, with the
 * subject filling a quarter of the card. Quarter levels let `fitBounds`
 * actually reach the padding, and the +/- controls step by the same amount
 * so the two cannot disagree about what a zoom is. */
const ZOOM_SNAP = 0.25;

/* How close a plate comes to a subject that is one point and nothing else,
 * where the surface did not say. */
const POINT_ZOOM = 13;

/** How each shape is drawn. One answer for the whole platform. */
const STYLES = {
    line: (color) => ({ color, weight: 2.2, opacity: 0.95, fill: false }),
    fill: (color) => ({ color, weight: 1, fillColor: color, fillOpacity: 0.22 }),
    point: (color) => ({ radius: 6, color, weight: 1.5, fillColor: color, fillOpacity: 0.85 }),
};

/* THE LIVE MARK'S BOX, AND IT IS SIZED BY THE RING AT FULL BREATH.
 *
 * The ring animates to 2.05× (`lv-breathe`), and an SVG clips to its own
 * viewport — so a box drawn to fit the DOT cut the breathing ring off at the
 * top, the bottom and the left, and the pulse came out as a square-ish
 * flicker instead of the round one the design draws. The design's own dots
 * live inside the plate's big SVG, where there is room; a marker has only the
 * box it is given.
 *
 * SO THE HALF-EXTENT IS THE RING'S: 2.05 × (r + half the stroke), rounded up.
 * `cx`/`cy` sit at that half-extent, the anchor sits on them, and the width
 * adds the age label's room to the right. The stale ring (`scale(1.35)`) is
 * well inside it.
 */
const LIVE_RING_SCALE = 2.05;
const LIVE_RING_STROKE = 1.6;
const LIVE_R = 8;
/** How far the ring reaches from the core's centre at full breath. */
const LIVE_REACH = Math.ceil(LIVE_RING_SCALE * (LIVE_R + LIVE_RING_STROKE / 2));
/** Room for "4 h 21" beside the dot, at the 8px the sheet draws it. */
const LIVE_AGE_ROOM = 37;
const LIVE_MARK = {
    width: LIVE_REACH + LIVE_R + 3 + LIVE_AGE_ROOM,
    height: LIVE_REACH * 2,
    cx: LIVE_REACH,
    cy: LIVE_REACH,
    r: LIVE_R,
};

/*
 * HOW OFTEN A STREAMING PLATE RE-READS ITS LIVE MARKS' AGES. The mark prints
 * "4 min" beside itself and dims past two ping intervals; once the page is
 * drawn and the marks move on their own, that reading has to move too, or a
 * ranger whose phone went quiet stays bright for ever. Half a minute is
 * finer than the label ("4 min") can show and far coarser than the wire.
 * This clock runs ONLY while a stream is open: a plate given no stream reads
 * exactly as the page drew it.
 */
const CLOCK_TICK_MS = 30000;

/** The attribute a key row wears saying which shape it is the key for. */
const KEY_SHAPE = 'data-atlas-shape';

/*
 * THE SPOTLIGHT, ONE ANSWER FOR THE WHOLE PLATFORM. Hovering a row in a list
 * beside a map lifts the feature that row is about and pushes the rest back. How
 * far it is lifted and how far the rest fall back is the plate's, not a
 * module's, so a hovered patrol track and a hovered anything else read alike.
 */
const SPOTLIGHT = { weight: 1.6, opacity: 1 };
const SHADOW = { opacity: 0.25 };

/** The attribute any element on the page wears to spotlight a feature: "<layer>:<id>". */
const HIGHLIGHT = 'data-atlas-highlight';

/** The pane a stated z-index is drawn in. One pane per value, built on demand. */
const PANE = 'atlas-z-';

/**
 * THE PLATE'S IDENTITY IN A PAGE — the attribute PHP marks the plate root with
 * (Twig\MapPlateRuntime::PLATE_HOOK). It is how a plate finds ITSELF in a
 * fetched copy of the page it is on, and which plate is which is their order in
 * the document.
 */
const PLATE = 'data-atlas-plate';

/**
 * THE VERB A LINK WEARS TO CHANGE A PLATE WITHOUT NAVIGATING.
 *
 * `data-atlas-swap` on a same-origin link means: fetch where this goes, take
 * the plate's subtrees out of the answer and put them in place of the live
 * ones. The link's `href` is the page it would have gone to, and it still is
 * — with no script, or on a middle click, or when anything at all goes wrong,
 * the browser navigates and the same page arrives the ordinary way.
 *
 * ITS VALUE NAMES A PLATE, or is empty for "the one this link is about": the
 * plate the link sits inside, or the page's only plate. A page with two
 * plates and an unnamed link is ambiguous, and an ambiguous swap is not
 * performed — the link navigates, which is always correct.
 *
 * `data-atlas-swap-also` names ONE more region, by selector, to bring across
 * from the same answer: the caller's own list beside the plate, whose marked
 * row has to move with the map. One, deliberately — a link that re-renders
 * half a page is a navigation with extra steps, and the page it fetched is
 * right there.
 *
 * THE SERVER STILL DECIDES EVERYTHING. What is focused, what the plate frames
 * itself on, which row is marked: all of it is computed where it was always
 * computed, and this fetches the answer. A module writes an attribute and no
 * JavaScript, and no map maths anywhere.
 */
const SWAP = 'data-atlas-swap';
const SWAP_ALSO = 'data-atlas-swap-also';

/**
 * WHICH PLATE A SWAP LINK IS ABOUT — the one its value names as a selector,
 * or the one it sits inside, or the page's only plate.
 *
 * NULL WHERE IT CANNOT BE SAID. A page with two plates and a link that sits
 * in neither and names neither is asking a question with two answers, and
 * the honest thing is to let the browser navigate rather than to guess which
 * map the reader meant.
 */
function plateElementFor(link) {
    const named = link.getAttribute(SWAP);
    if (named) {
        return document.querySelector(named);
    }

    const inside = link.closest(`[${PLATE}]`);
    if (inside) {
        return inside;
    }

    const plates = document.querySelectorAll(`[${PLATE}]`);

    return 1 === plates.length ? plates[0] : null;
}

/*
 * THE PICK MODE — a plate that writes a point into a form.
 *
 * PHP states `AtlasMap::pickPoint(PointPick)`, which arrives as
 * `extra.atlas.pick`: the id of the form a click at rest writes into, what the
 * caption calls that point, the names of the two inputs that hold it and how
 * many decimals are written. The plate owns everything else — the pin, the
 * click on the ground, the drag of the pin, the point a form already holds,
 * bringing the pin into view, and the caption's three states — so the page
 * that asked for it writes no JavaScript and extends no plate.
 *
 * A CONTROL ANYWHERE ON THE PAGE ARMS THE PLATE FOR ANOTHER FORM by wearing
 * `data-atlas-pick="<form id>"`, with `data-atlas-pick-mode` (`add` or
 * `move`) and `data-atlas-pick-name`; heard from the document, like the swap
 * verb, because such a control is somebody else's markup and is very often
 * not inside the plate. ONE PICKING PLATE A PAGE: every picking plate answers
 * an arming control.
 *
 * ADDING WRITES STRAIGHT THROUGH — the click is the answer. MOVING PROPOSES:
 * a point that exists is moved on purpose, so the click or the drag places
 * the pin and "Use this point" commits it.
 *
 * THE PIN IS A MARKER, NOT A CIRCLE. Leaflet drags a Marker — `draggable`,
 * "Whether the marker is draggable with mouse/touch or not", and its
 * `dragend` event — and never a CircleMarker; its drawing is the sheet's
 * (`.atlas-pin`), handed over as a DivIcon whose `className` replaces
 * Leaflet's own white square.
 *
 * @see https://leafletjs.com/reference.html#marker-draggable
 * @see https://leafletjs.com/reference.html#marker-dragend
 * @see https://leafletjs.com/reference.html#divicon — `html`, `className`, `iconSize`, `iconAnchor`
 * @see leaflet 1.9.4 dist/leaflet-src.js, Marker.options (`draggable: false`, `autoPan: false`) and DivIcon.options (`className: 'leaflet-div-icon'`)
 */
const PICK = 'data-atlas-pick';
const PICK_MODE = 'data-atlas-pick-mode';
const PICK_NAME = 'data-atlas-pick-name';
const PICK_NOTE = 'data-atlas-pick-note';

/** The caption's parts, written by the plate template under a picking plate. */
const PICK_STATE = 'data-atlas-pick-state';
const PICK_NAMED = 'data-atlas-pick-named';
const PICK_POINT = 'data-atlas-pick-point';
const PICK_USE = 'data-atlas-pick-use';

/* THE PIN'S BOX: the sheet draws a 16px teardrop turned onto its point, and
   the point is the anchor — 8√2 below the centre, less the tip's rounding. */
const PIN_SIZE = 16;
const PIN_TIP = 18;

/*
 * WHAT A FILTER CHANGE CAN CHANGE INSIDE THE PLATE — the chips themselves (their
 * counts and which one is pressed), the map element (the new features and the
 * whole atlas payload with them) and the legend (its rows and their counts).
 * Everything else in the plate is chrome that does not depend on the query.
 *
 * `into`/`at` say where a part belongs when the plate did not have one before: a
 * query that empties a map can drop its legend, and the next one has to be able
 * to put it back.
 */
const SWAPPED = [
    { selector: '.map-filters', into: (plate) => plate.querySelector('.map-body'), at: 'afterbegin' },
    { selector: '.map-canvas', into: (plate) => plate.querySelector('.viewer'), at: 'beforeend' },
    { selector: '.map-legend', into: (plate) => plate, at: 'beforeend' },
];

export default class extends Controller {
    static targets = ['frame', 'legend'];

    connect() {
        this.layers = new Map();
        // What the map said about each layer, kept because a spotlight has to
        // put a feature back exactly the way it was stated.
        this.specs = new Map();
        // layer id → (feature id → the drawn features carrying it), so an
        // element anywhere on the page can spotlight a feature by name.
        this.byFeatureId = new Map();
        this.spotlit = null;
        // Whether the plate has been refiltered in place, and the page behind it
        // is therefore answering a query that is no longer the one in the bar.
        this.stale = false;
        /*
         * WHAT A TOKEN NAME RESOLVES TO, cached for this paint.
         *
         * A module publishes `var(--plate-ok)` and never a colour, because
         * a plate's palette is picked to survive satellite ground and turns
         * over with the theme. Leaflet takes a real colour and nothing else:
         * a `var(...)` string handed to it paints NOTHING, which is how a
         * legend could read right while the map drew empty. So the name is
         * resolved here, against the plate itself, at the moment of drawing.
         */
        this.swatches = new Map();

        /*
         * AND AGAIN WHEN THE LIGHTS CHANGE. The theme is a class on <html>,
         * flipped without a reload, so every resolved colour on the map is
         * wrong from that moment until something redraws it.
         */
        this.onThemeFlip = () => this.repaint();
        this.themeWatch = new MutationObserver(this.onThemeFlip);
        this.themeWatch.observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });

        this.onPreConnect = (event) => this.beforeMap(event);
        this.onConnect = (event) => this.afterMap(event);
        this.onFullscreenChange = () => this.catchUp();
        document.addEventListener('fullscreenchange', this.onFullscreenChange);

        this.element.addEventListener('ux:map:pre-connect', this.onPreConnect);
        this.element.addEventListener('ux:map:connect', this.onConnect);

        /*
         * THE SPOTLIGHT IS WIRED ONCE, BY DELEGATION, so a module writes an
         * attribute and no JavaScript. The listeners are on the document rather
         * than on the rows because the rows are somebody else's widget — a log,
         * a feed, a table — which may be re-rendered, paginated or swapped by
         * Turbo long after this controller connected.
         *
         * mouseover/mouseout rather than mouseenter/mouseleave: only the former
         * bubble, and delegation needs them to.
         */
        /*
         * THE SWAP VERB IS DELEGATED for the same reason the spotlight is: the
         * links wearing it are somebody else's markup — a rail, a list, a row
         * in a table — which may be re-rendered or paginated long after this
         * controller connected, and which is very often not inside the plate
         * at all.
         */
        this.onSwapClick = (event) => this.swapLink(event);
        document.addEventListener('click', this.onSwapClick);

        // THE PICK MODE'S ARMING CONTROLS, and the typed pair it keeps the pin on.
        this.onPickClick = (event) => this.armFrom(event);
        this.onPickTyped = (event) => this.typedPoint(event);
        document.addEventListener('click', this.onPickClick);
        document.addEventListener('change', this.onPickTyped);

        this.onOver = (event) => this.spotlightFrom(event.target);
        this.onOut = (event) => this.releaseFrom(event);
        document.addEventListener('mouseover', this.onOver);
        document.addEventListener('mouseout', this.onOut);
        document.addEventListener('focusin', this.onOver);
        document.addEventListener('focusout', this.onOut);
    }

    disconnect() {
        this.unsubscribe();
        document.removeEventListener('click', this.onSwapClick);
        document.removeEventListener('click', this.onPickClick);
        document.removeEventListener('change', this.onPickTyped);
        this.pin = null;
        this.element.removeEventListener('ux:map:pre-connect', this.onPreConnect);
        this.element.removeEventListener('ux:map:connect', this.onConnect);
        document.removeEventListener('mouseover', this.onOver);
        document.removeEventListener('mouseout', this.onOut);
        document.removeEventListener('focusin', this.onOver);
        document.removeEventListener('focusout', this.onOut);
        document.removeEventListener('fullscreenchange', this.onFullscreenChange);
        this.chrome?.destroy();
        this.chrome = null;
        // The frame watch outlives the map otherwise, and a detached plate
        // would go on invalidating a map nobody is looking at.
        this.frameWatch?.disconnect();
        this.frameWatch = null;
        this.themeWatch?.disconnect();
        this.themeWatch = null;
        if (this.onMapResize) {
            this.map?.off('resize', this.onMapResize);
            this.onMapResize = null;
        }
        this.layers.clear();
        this.specs.clear();
        this.byFeatureId.clear();
        this.swatches.clear();
        this.map = null;
    }

    /**
     * The last word on how the map is constructed, before it is.
     *
     * The zoom control is refused here as well as in PHP: a plate wears one
     * control stack, and a second pair of buttons in the opposite corner is not
     * a thing a module should be able to reintroduce by handing the renderer
     * its own options.
     *
     * AND THE PLATE ZOOMS IN QUARTER LEVELS, so that framing a subject can
     * actually reach the padding rather than stopping at whichever whole
     * level happens to fit — see {@link ZOOM_SNAP}.
     */
    beforeMap(event) {
        event.detail.bridgeOptions = {
            ...(event.detail.bridgeOptions ?? {}),
            zoomControl: false,
            zoomSnap: ZOOM_SNAP,
            zoomDelta: ZOOM_SNAP,
        };
    }

    /**
     * The map exists. Draw the ground, the boundary, the layers, and dress it.
     *
     * What is DRAWN must never be able to kill the map: a throw while drawing
     * leaves the tiles and the chrome standing and the console says what broke.
     *
     * A PLATE MAY BE HANDED A SECOND MAP — a filter change in fullscreen swaps
     * the map element and the bridge mounts a new one — so this starts from
     * nothing every time: what was drawn for the old map is not a layer of this
     * one, and a second control stack in the corner is not a feature.
     */
    afterMap(event) {
        const { map, L, extra } = event.detail;
        const atlas = extra?.[ATLAS] ?? {};

        // A second map is a second subscription; the first is closed with the
        // map it fed.
        this.unsubscribe();
        this.chrome?.destroy();
        this.chrome = null;
        this.layers.clear();
        this.specs.clear();
        this.byFeatureId.clear();
        this.spotlit = null;
        this.scrim = null;

        this.map = map;
        this.L = L;
        this.bounds = L.latLngBounds([]);
        this.shouldFit = false !== atlas.fit;
        this.subject = this.subjectOf(atlas.subject);

        this.mountBases(atlas.baseLayers ?? []);

        try {
            this.drawBoundary(atlas.boundary);
            for (const layer of atlas.layers ?? []) {
                this.drawLayer(layer);
            }
        } catch (error) {
            console.error('[atlas] the plate failed to draw', error);
        }

        // After the drawing, so the DIM pill has this plate's scrim to switch.
        // Fullscreen expands the PLATE, not the map: the filter row and the
        // legend are inside it and must come along.
        this.chrome = mountMapChrome(L, map, this.frame(), {
            bases: this.bases,
            scrim: this.scrim ?? null,
            scrimOn: Boolean(this.scrim) && map.hasLayer(this.scrim),
            fullscreen: false !== atlas.fullscreen,
            fullscreenTarget: this.element,
            onResize: () => this.refit(),
        });

        this.refit();

        // A PLATE THAT PICKS A POINT is armed after the fit: the pin is what
        // is being placed, never what the plate is framed on.
        try {
            this.startPick(atlas.pick);
        } catch (error) {
            console.error('[atlas] the plate could not start picking', error);
        }

        /*
         * AND AGAIN ONCE THE PAGE HAS SETTLED. A plate is fitted while its
         * card is still being laid out — the filter row and the legend take
         * their height after this runs — so the frame it was measured in is
         * not the frame it ends up in, and the subject overflowed the plate
         * by the difference. Leaflet is told its size changed and the frame
         * is taken again, here and whenever the frame changes size after.
         */
        this.watchFrame();

        // AND THE MARKS KEEP MOVING, where the builder said where from. A
        // stream that cannot be opened is a plate drawn once, never a broken
        // plate: the map, the chrome and the legend are all standing by now.
        try {
            this.subscribe(atlas.live);
        } catch (error) {
            console.error('[atlas] the plate could not open its live stream', error);
        }

        this.dispatch('connect', {
            prefix: 'atlas:map',
            detail: { map, L, layers: this.layers },
        });
    }

    /**
     * The ground, in the order the map asked for it. The first entry is what
     * the plate opens on; the rest are what the base-layer menu offers.
     */
    mountBases(names) {
        this.bases = {};
        for (const name of names) {
            const layer = 'satellite' === name ? satelliteLayer(this.L, this.map) : streetLayer(this.L);
            this.bases[name] = layer;
        }
        this.bases[names[0]]?.addTo(this.map);
    }

    /**
     * The area outline and the scrim outside it — the platform's one treatment,
     * so it is unmistakable where the area is on any imagery.
     */
    drawBoundary(boundary) {
        if (!boundary?.geojson) {
            return;
        }

        const drawn = drawBoundary(this.L, this.map, boundary.geojson, { scrim: false !== boundary.scrim });
        if (!drawn) {
            return;
        }

        // The scrim covers the world, so it is never part of what the plate is
        // framed on; only the outline is.
        this.scrim = drawn.scrimLayer ?? null;
        this.layers.set(BOUNDARY_LAYER, drawn);
        this.bounds.extend(drawn.getBounds());
    }

    /**
     * One GeoJSON layer, drawn from what the module stated about it: the shape,
     * the colour, the base style over that, and the rules each feature's own
     * properties earn. A feature may carry its own `color` where a module
     * colours features individually.
     *
     * A layer with a url is BUILT empty and filled when the fetch answers, so a
     * plate never waits on a request to become a map. The options below are the
     * layer's, so features arriving later are dressed exactly like the ones that
     * came in the page.
     */
    drawLayer(layer) {
        const drawn = this.L.geoJSON(layer.features ?? null, {
            style: (feature) => this.styleFor(layer, feature),
            pointToLayer: (feature, latlng) => ('live' === layer.shape
                ? this.L.marker(latlng, { icon: this.liveIcon(feature), keyboard: false })
                : this.L.circleMarker(latlng, this.styleFor(layer, feature))),
            onEachFeature: (feature, drawnFeature) => this.dressFeature(layer, feature, drawnFeature),
        });

        this.layers.set(layer.id, drawn);
        this.specs.set(layer.id, layer);
        if (false !== layer.visible) {
            drawn.addTo(this.map);
        }
        if (layer.features) {
            this.extend(drawn);
        }

        if (layer.url) {
            this.fetchLayer(layer, drawn);
        }

        this.dispatch('layer:added', { prefix: 'atlas:map', detail: { id: layer.id, layer: drawn } });
    }

    /**
     * A layer whose features live behind a url. A refusal leaves the layer
     * empty and the plate standing: one layer that did not arrive is not a
     * broken map.
     */
    async fetchLayer(layer, drawn) {
        try {
            const response = await fetch(layer.url, { headers: { Accept: 'application/geo+json, application/json' } });
            if (!response.ok) {
                return;
            }
            drawn.addData(await response.json());
        } catch (error) {
            console.error(`[atlas] the layer "${layer.id}" could not be fetched`, error);

            return;
        }

        this.extend(drawn);
        this.refit();
    }

    /**
     * SOMEBODY'S LAST KNOWN POSITION, as the one mark the accent is reserved
     * for: the dot, the ring breathing out of it, their initials inside it and
     * the age of the fix beside it.
     *
     * A DIV ICON AND NOT A CIRCLE MARKER, because the mark is a drawing rather
     * than a colour — a ring that animates, two labels and a title — and a
     * circle marker is one path with a fill. The sheet owns every line of it
     * (`.livedot` in the shell): nothing here names a colour, a size or a
     * typeface, so the mark reads the same in a legend row, in a list and here.
     *
     * STALE IS THE SAME MARK, dimmed and still. The state arrives as a feature
     * property because the contract decides it — two ping intervals, and the
     * plate has no business re-deciding what "current" means.
     */
    liveIcon(feature) {
        const properties = feature?.properties ?? {};
        const stale = true === properties.stale;
        const name = properties.name ?? '';
        const age = properties.age ?? '';
        const { width, height, cx, cy, r } = LIVE_MARK;

        return this.L.divIcon({
            className: '',
            iconSize: [width, height],
            iconAnchor: [cx, cy],
            html: `<svg width="${width}" height="${height}" viewBox="0 0 ${width} ${height}" aria-hidden="true">`
                + `<g class="livedot${stale ? ' stale' : ''}">`
                + `<title>${escapeHtml(name)} · last ping ${escapeHtml(age)} · ${stale ? 'stale' : 'live'}</title>`
                + `<circle class="lv-ring" cx="${cx}" cy="${cy}" r="${r}"/>`
                + `<circle class="lv-core" cx="${cx}" cy="${cy}" r="${r}"/>`
                + `<text class="lv-ini" x="${cx}" y="${cy + 2}" text-anchor="middle">${escapeHtml(properties.initials ?? '')}</text>`
                + `<text class="lv-age" x="${cx + r + 3}" y="${cy + 3}">${escapeHtml(age)}</text>`
                + '</g></svg>',
        });
    }

    /**
     * WHAT ONE FEATURE IS DRAWN WITH — the shape's own answer, then the layer's
     * base style, then every rule the feature's properties satisfy, each merged
     * over the last in the order the module wrote them.
     *
     * NO CALLBACK CROSSED THE WIRE to get here. A rule is a property name, the
     * values that satisfy it and the style they earn, which is why one
     * controller can draw every module's layers and no module ships a second.
     */
    styleFor(layer, feature) {
        const properties = feature?.properties ?? {};
        const paint = STYLES[layer.shape] ?? STYLES.fill;
        let style = { ...paint(this.colour(properties.color ?? layer.swatch)), ...(layer.style ?? {}) };

        for (const rule of layer.rules ?? []) {
            if ((rule.values ?? []).includes(properties[rule.property])) {
                style = { ...style, ...(rule.style ?? {}) };
            }
        }

        /*
         * EVERY COLOUR IN THE MERGED STYLE, RESOLVED — not only the layer's
         * own swatch. A per-feature rule ("this zone is category three") and
         * a module's `style` block carry token names through the same door,
         * and one of them left unresolved is one feature drawn as nothing.
         */
        for (const key of ['color', 'fillColor', 'fill', 'stroke']) {
            if (undefined !== style[key]) {
                style[key] = this.colour(style[key]);
            }
        }

        // z-index is a PANE, which is the only way Leaflet lets one vector sit
        // above another regardless of the order they were added in.
        const { zIndex, ...options } = style;

        return undefined === zIndex ? options : { ...options, pane: this.pane(zIndex) };
    }

    /**
     * A COLOUR LEAFLET CAN PAINT WITH, out of what a module published.
     *
     * A TOKEN NAME IS RESOLVED AND ANYTHING ELSE IS PASSED THROUGH. The
     * platform's own layers publish `var(--plate-ok)`; a feature may still
     * carry a literal in its own `color` property, and an installation's
     * own data is not this controller's to refuse.
     *
     * RESOLVED AGAINST THE PLATE, not the document, so a token an
     * installation redefined for one surface resolves to what that
     * surface actually paints.
     */
    colour(value) {
        if ('string' !== typeof value || !value.startsWith('var(')) {
            return value;
        }

        if (this.swatches.has(value)) {
            return this.swatches.get(value);
        }

        const name = value.slice(4, -1).trim().split(',')[0].trim();
        const resolved = getComputedStyle(this.element).getPropertyValue(name).trim();
        // A NAME NOBODY DECLARED PAINTS NOTHING, and nothing is what
        // Leaflet would have drawn anyway — but the name is kept out of
        // the cache so a sheet that arrives late still gets a chance.
        if ('' === resolved) {
            return value;
        }

        this.swatches.set(value, resolved);

        return resolved;
    }

    /**
     * EVERY DRAWN LAYER, RE-STYLED — what a theme flip needs, because
     * the colours on the map were resolved under the other palette.
     */
    repaint() {
        this.swatches.clear();
        if (!this.map) {
            return;
        }

        for (const [id, drawn] of this.layers) {
            const spec = this.specs.get(id);
            if (!spec || 'function' !== typeof drawn.setStyle) {
                continue;
            }

            drawn.setStyle((feature) => this.styleFor(spec, feature));
        }
    }

    /** The pane for a stated z-index, built the first time one is asked for. */
    pane(zIndex) {
        const name = PANE + zIndex;
        if (!this.map.getPane(name)) {
            this.map.createPane(name).style.zIndex = String(zIndex);
        }

        return name;
    }

    /**
     * WHAT A FEATURE SAYS AND ANSWERS TO: the permanent halo a feature that
     * names itself wears, the floating label a hover reads, the popup a click
     * opens, and the id an element elsewhere on the page spotlights it by.
     *
     * The popup's markup is written HERE, from property names — never handed
     * over as a string by a module — so a property carrying a stray angle
     * bracket cannot become an element on somebody's map.
     */
    dressFeature(layer, feature, drawnFeature) {
        const properties = feature?.properties ?? {};

        // A feature that names itself wears its name: a permanent halo label
        // over the shape, which is how a zone is read on imagery.
        // — unless the layer declines labels: where the shapes are the ground
        // under another subject (stations on zones), their names would only
        // collide with the subject's.
        if (properties.label && layer.labels !== false) {
            drawnFeature.bindTooltip(properties.label, { permanent: true, direction: 'center', className: 'zone-label' });
        }

        const hovered = layer.tooltip ? properties[layer.tooltip] : null;
        if (hovered) {
            drawnFeature.bindTooltip(String(hovered), { sticky: true, direction: 'top' });
        }

        if (layer.popup) {
            const content = popupMarkup(layer.popup, properties);
            if (content) {
                drawnFeature.bindPopup(content);
            }
        }

        if (layer.featureId && undefined !== properties[layer.featureId]) {
            const index = this.byFeatureId.get(layer.id) ?? new Map();
            const key = String(properties[layer.featureId]);
            index.set(key, [...(index.get(key) ?? []), { feature, drawnFeature }]);
            this.byFeatureId.set(layer.id, index);
        }
    }

    /**
     * SPOTLIGHT WITHOUT A LINE OF MODULE JAVASCRIPT. Any element on the page
     * carrying data-atlas-highlight="<layer>:<featureId>" lifts that feature and
     * pushes its siblings back while the cursor (or the focus) is on it.
     */
    spotlightFrom(target) {
        const source = target?.closest?.(`[${HIGHLIGHT}]`);
        if (!source) {
            return;
        }

        const [layerId, ...rest] = String(source.getAttribute(HIGHLIGHT)).split(':');
        const index = this.byFeatureId.get(layerId);
        if (!index) {
            return;
        }

        this.spotlight(layerId, rest.join(':'));
    }

    /** The cursor left the row it was on — every feature back to how it was drawn. */
    releaseFrom(event) {
        const source = event.target?.closest?.(`[${HIGHLIGHT}]`);
        if (source && !source.contains(event.relatedTarget)) {
            this.release();
        }
    }

    spotlight(layerId, featureId) {
        const drawn = this.layers.get(layerId);
        const index = this.byFeatureId.get(layerId);
        if (!drawn || !index) {
            return;
        }

        // Moving from a row about one layer to a row about another leaves the
        // first layer dimmed unless it is put back first.
        if (this.spotlit && this.spotlit !== layerId) {
            this.release();
        }

        this.spotlit = layerId;
        const lifted = new Set((index.get(featureId) ?? []).map((entry) => entry.drawnFeature));

        drawn.eachLayer((drawnFeature) => {
            const base = this.styleFor(this.specFor(layerId), drawnFeature.feature);
            drawnFeature.setStyle?.(lifted.has(drawnFeature)
                ? { ...base, weight: (base.weight ?? 1) * SPOTLIGHT.weight, opacity: SPOTLIGHT.opacity }
                : { ...base, ...SHADOW });
            if (lifted.has(drawnFeature)) {
                drawnFeature.bringToFront?.();
            }
        });
    }

    /** Every feature of the spotlit layer back to the style it was drawn with. */
    release() {
        if (!this.spotlit) {
            return;
        }

        const spec = this.specFor(this.spotlit);
        this.layers.get(this.spotlit)?.eachLayer((drawnFeature) => {
            drawnFeature.setStyle?.(this.styleFor(spec, drawnFeature.feature));
        });
        this.spotlit = null;
    }

    /** What the map SAID about a layer, kept so a restored style is the stated one. */
    specFor(layerId) {
        return this.specs.get(layerId) ?? {};
    }

    /**
     * A LEGEND ROW IS A SWITCH. Clicking one shows or hides its layer and the
     * row says which it now is, so what the legend claims and what the plate
     * draws cannot drift apart.
     */
    toggleLayer(event) {
        const drawn = this.layers.get(event.params.layer);
        if (!drawn) {
            return;
        }

        const showing = this.map.hasLayer(drawn);
        if (showing) {
            this.map.removeLayer(drawn);
        } else {
            drawn.addTo(this.map);
        }

        const row = event.currentTarget;
        row.classList.toggle('off', showing);
        row.setAttribute('aria-pressed', showing ? 'false' : 'true');
        const state = row.querySelector('.tog');
        if (state) {
            state.textContent = showing ? 'off' : 'on';
        }
    }

    /**
     * A FILTER CHANGE IN FULLSCREEN, ANSWERED WITHOUT LEAVING IT.
     *
     * The filter row is a GET form, and a form submission is a navigation, and a
     * navigation ends fullscreen — so comparing two filters on an expanded map
     * meant expanding it again after every chip. In fullscreen the plate answers
     * the submission itself: it fetches the SAME address with the new query,
     * takes its own subtrees out of the answer, swaps them in place, and writes
     * the new address into the bar without going anywhere.
     *
     * OUTSIDE FULLSCREEN NOTHING IS INTERCEPTED. A plain submission reloads the
     * page, which is the only thing that keeps the log, the counts and everything
     * else on it in step with the filter — so it is what happens by default and
     * what happens again the moment fullscreen ends ({@see catchUp}).
     *
     * The action is declared on the filter row and the submission reaches it by
     * bubbling, because the form is the module's own markup and the plate puts no
     * attribute on it: https://stimulus.hotwired.dev/reference/actions
     */
    filter(event) {
        if (!this.isFullscreen()) {
            return;
        }

        const form = event.target.closest('form');
        const address = form && this.addressOf(form, event.submitter);
        if (!address) {
            return;
        }

        event.preventDefault();
        this.refilter(address);
    }

    /**
     * THE SAME ANSWER FOR A CHIP THAT IS A LINK. A filter row is as often a row
     * of `<a href="?type=…">` chips as it is a form, and a click on one navigates
     * exactly as a submission does — so it left fullscreen exactly as a
     * submission did, and it is answered by the same path.
     *
     * ONLY A PLAIN LEFT CLICK. A middle click, a modified click or a chip with a
     * target of its own is the viewer asking for a SECOND page, and a plate that
     * swallowed that would have taken something that worked away from them.
     */
    filterLink(event) {
        if (!this.isFullscreen()) {
            return;
        }

        const link = event.target.closest('a[href]');
        if (!link || link.target || !isPlainClick(event)) {
            return;
        }

        const address = new URL(link.href, window.location.href);
        if (address.origin !== window.location.origin) {
            return;
        }

        event.preventDefault();
        this.refilter(address);
    }

    /**
     * A LINK THAT CHANGES THE PLATE, WHEREVER IT SITS AND WHETHER OR NOT THE
     * PLATE IS FULLSCREEN.
     *
     * This is the general form of what the filter row does. A ranger in the
     * roster's rail, a station in a list, a zone in a register: clicking one
     * is "show me THIS on the map", and it was a full navigation — the page
     * rebuilt, the scroll position lost, the map torn down and mounted again
     * — for a change the server could answer with the same page it always
     * answers with.
     *
     * WHAT IS INTERCEPTED IS NARROW ON PURPOSE. A plain left click, no
     * modifier, no `target`, same origin, on a link wearing the verb and
     * naming THIS plate. Everything else is the viewer asking for a real
     * navigation or a second tab, and taking that away would be taking away
     * something that worked.
     *
     * IT IS A PLACE, SO IT IS PUSHED. A filter is a view of one page and uses
     * replaceState; choosing which ranger the map is about is somewhere the
     * viewer went, and Back must bring them to the one before. The fetched
     * page is the same page, so Back landing on it is honest.
     */
    swapLink(event) {
        if (!isPlainClick(event)) {
            return;
        }

        const link = event.target.closest(`a[href][${SWAP}]`);
        if (!link || link.target || this.element !== plateElementFor(link)) {
            return;
        }

        const address = new URL(link.href, window.location.href);
        if (address.origin !== window.location.origin) {
            return;
        }

        event.preventDefault();
        this.swapTo(address, link.getAttribute(SWAP_ALSO));
    }

    /**
     * THE PLATE AT ANOTHER ADDRESS, and one region beside it — or the
     * navigation the link asked for, which is what happens whenever the
     * answer is not a page this plate can be found in.
     */
    async swapTo(address, alsoSelector) {
        const page = await this.fetchPage(address);
        const fresh = page && (page.querySelectorAll(`[${PLATE}]`)[this.ordinal()] ?? null);
        if (!fresh) {
            window.location.assign(address);

            return;
        }

        this.swap(fresh);
        this.swapAlso(page, alsoSelector);
        history.pushState(history.state, '', address);
    }

    /**
     * THE ONE REGION OUTSIDE THE PLATE the link named, replaced from the same
     * answer — the caller's own list, whose marked row moves with the map.
     *
     * A REGION THE ANSWER DOES NOT HAVE IS LEFT ALONE. Removing it would be
     * this controller deciding that somebody else's list is over, and the
     * page it fetched is the authority on what that list contains, not on
     * whether it exists.
     */
    swapAlso(page, selector) {
        if (!selector) {
            return;
        }

        const next = page.querySelector(selector);
        const live = document.querySelector(selector);
        if (!next || !live) {
            return;
        }

        live.replaceWith(document.importNode(next, true));
    }

    /**
     * THIS PLATE, AT ANOTHER QUERY, WITHOUT LEAVING FULLSCREEN — the one path
     * both kinds of chip take.
     */
    async refilter(address) {
        const fresh = await this.fetchPlate(address);
        if (!fresh) {
            // The answer was a refusal or no page at all. The honest fallback is
            // the behaviour we intercepted: go there.
            window.location.assign(address);

            return;
        }

        this.swap(fresh);
        // replaceState, never pushState: a filter is not a place in the viewer's
        // history, and twenty chips must not become twenty presses of Back.
        // https://developer.mozilla.org/en-US/docs/Web/API/History/replaceState
        history.replaceState(history.state, '', address);
        this.stale = true;
    }

    /**
     * WHAT THE FORM IS ASKING FOR, as an address — this page with a new query.
     *
     * The submitter is part of the question: a chip is a submit button carrying
     * its own name and value, and FormData takes the submitter for exactly that
     * reason. https://developer.mozilla.org/en-US/docs/Web/API/FormData/FormData
     *
     * A form pointing somewhere else entirely is nobody's business of this
     * plate's, and null sends it back to the browser to submit.
     */
    addressOf(form, submitter) {
        const address = new URL(form.getAttribute('action') || window.location.href, window.location.href);
        if (address.origin !== window.location.origin) {
            return null;
        }

        address.search = new URLSearchParams([...new FormData(form, submitter)]).toString();

        return address;
    }

    /**
     * THIS PLATE, IN A FRESHLY FETCHED COPY OF THE PAGE — or null, which means
     * the swap does not happen and the browser navigates instead.
     *
     * `credentials: 'same-origin'` is fetch's own default and is written out
     * because the request carries the viewer's session by necessity: it is the
     * same page, and an anonymous copy of it would be a sign-in screen.
     * https://developer.mozilla.org/en-US/docs/Web/API/RequestInit
     */
    async fetchPlate(address) {
        const page = await this.fetchPage(address);

        return page && (page.querySelectorAll(`[${PLATE}]`)[this.ordinal()] ?? null);
    }

    /**
     * THE WHOLE ANSWER, PARSED — because a swap may want a region beside the
     * plate as well as the plate, and both come out of one request.
     */
    async fetchPage(address) {
        try {
            const response = await fetch(address, {
                headers: { Accept: 'text/html' },
                credentials: 'same-origin',
            });
            if (!response.ok) {
                return null;
            }

            return new DOMParser().parseFromString(await response.text(), 'text/html');
        } catch (error) {
            console.error('[atlas] the page behind this plate could not be fetched', error);

            return null;
        }
    }

    /** Which plate of the page this is — the position it holds in the answer too. */
    ordinal() {
        return [...document.querySelectorAll(`[${PLATE}]`)].indexOf(this.element);
    }

    /**
     * THE ANSWER'S SUBTREES, IN PLACE OF THIS PLATE'S — and the plate root itself
     * untouched, because it is the element that is fullscreen and replacing it
     * would end fullscreen, which is the whole thing being avoided.
     *
     * The new map element brings its own atlas payload, so the bridge mounts a
     * map on it and this controller hears `ux:map:connect` again and draws the
     * new query's layers ({@see afterMap}).
     */
    swap(fresh) {
        /*
         * The map being replaced is destroyed here rather than left to the
         * bridge: the bridge's controller creates the Leaflet map and has no
         * disconnect, so an orphaned map would keep its document listeners and
         * its tile requests. https://leafletjs.com/reference.html#map-remove
         */
        this.unsubscribe();
        this.chrome?.destroy();
        this.chrome = null;
        this.map?.remove();
        this.map = null;

        for (const { selector, into, at } of SWAPPED) {
            const next = fresh.querySelector(selector);
            const live = this.element.querySelector(selector);
            if (!next) {
                live?.remove();
                continue;
            }

            // A node still owned by the parsed document cannot be inserted in
            // this one, so it is imported rather than moved.
            // https://developer.mozilla.org/en-US/docs/Web/API/Document/importNode
            const adopted = document.importNode(next, true);
            if (live) {
                live.replaceWith(adopted);
            } else {
                into(this.element)?.insertAdjacentElement(at, adopted);
            }
        }
    }

    /**
     * LEAVING FULLSCREEN AFTER A SWAP CATCHES THE PAGE UP. Only the plate was
     * refiltered; the log, the counts and everything else behind it still answer
     * the query the page was served with, and a page that disagreed with its own
     * map would be worse than the reload it saved. One exit, one navigation, to
     * the address the chips already wrote.
     */
    catchUp() {
        if (this.isFullscreen() || !this.stale) {
            return;
        }

        this.stale = false;
        window.location.reload();
    }

    /** Whether this plate is the element the browser is showing fullscreen. */
    isFullscreen() {
        return true === document.fullscreenElement?.contains(this.element);
    }

    /**
     * THE PLATE IS FRAMED ON ITS SUBJECT, and on everything it drew only
     * when nothing said what it is about. A zone's page draws the whole area
     * as context and is about one zone.
     */
    refit() {
        if (!this.shouldFit || this.fitting) {
            return;
        }

        /*
         * MEASURE, THEN FRAME — always, on every path into this method.
         *
         * Leaflet frames against the size it last measured, and it measures
         * when the map is created. Everything that happens to the card
         * after that — a two-column grid narrowing it, a legend taking its
         * height, a sidebar restoring its width — leaves that measurement
         * stale, and the fit is then computed for a frame that no longer
         * exists: measured on a zone's record, a plate 765 wide framed as
         * though it were 1141, so the zone sat off to the left and hung
         * below the plate.
         *
         * ASKING FIRST RATHER THAN BEING TOLD. Hooks fire in whatever order
         * the page settles in, and every one of them was a guess about
         * when; this cannot be out of date, because it is the same call the
         * hooks were there to make. It costs a cached read when nothing has
         * changed, and `invalidateSize` is a no-op then — it only fires
         * `resize` when the size really moved, which is what the guard
         * above keeps from recursing.
         */
        this.fitting = true;
        try {
            this.map?.invalidateSize({ animate: false, pan: false });
        } finally {
            this.fitting = false;
        }

        const subject = this.subject;
        const bounds = subject?.bounds?.isValid() ? subject.bounds : this.bounds;
        if (!bounds?.isValid()) {
            // NOTHING TO FRAME IS NOT NOTHING TO DO. The size was invalidated
            // a few lines above, so whatever is already drawn is now in a
            // frame of a different width — and a label does not follow one.
            this.replaceLabels();

            return;
        }

        // A SUBJECT WITH NO EXTENT — one point — cannot be fitted to: fitting
        // a degenerate box goes to the map's maximum zoom, which is a street
        // corner. The surface says how close to come.
        /*
         * NEVER ANIMATED. A fit that eases into place is a fit that is still
         * moving when the next one is asked for, and Leaflet answers the
         * second from where the first was going rather than from the frame
         * as it now is: the zone record settled at the size its card had
         * BEFORE the page finished, and its subject overflowed the plate by
         * the difference. A plate arriving at its subject is not an
         * animation anybody asked for.
         */
        if (!bounds.getNorthEast().equals(bounds.getSouthWest())) {
            this.map?.fitBounds(bounds, {
                padding: FIT_PADDING,
                maxZoom: subject?.zoom ?? undefined,
                animate: false,
            });
            this.replaceLabels();

            return;
        }

        this.map?.setView(bounds.getCenter(), subject?.zoom ?? POINT_ZOOM, { animate: false });
        this.replaceLabels();
    }

    /**
     * EVERY PERMANENT LABEL, PUT BACK WHERE ITS FEATURE IS.
     *
     * A PATH IS PROJECTED ON EVERY DRAW AND A TOOLTIP IS NOT. Leaflet places
     * a permanent tooltip when it opens it and moves it again only on `zoom`
     * and `viewreset`. Nothing the fit above does is either of those: the
     * `invalidateSize({pan: false})` fires `resize`, and the `fitBounds`
     * after it very often lands on the zoom and the centre the map was
     * already at — the frame got narrower, not further away. So the boundary
     * redraws correctly and its zone labels stay pinned to the pixels of a
     * frame that no longer exists, which on a plate composed onto a widget
     * grid means negative x: outside the plate, until somebody reloads the
     * page and the card happens to have its final width before the map is
     * built.
     *
     * ASKING EACH TOOLTIP RATHER THAN FIRING `viewreset`. Firing the event
     * would work and would also wake every other listener on it — the
     * chrome's, a module's, whatever is added next — to move some labels.
     * `update()` is exactly the work that is wanted, it is what Leaflet's own
     * handler calls, and it is a no-op on a tooltip that is already right, so
     * the path where the fit DID change the zoom costs a re-layout nobody
     * sees.
     *
     * IT IS ON THE FIT'S PATH, NOT ON A HOOK OF ITS OWN, which is what makes
     * fullscreen and swap correct without either of them knowing that labels
     * exist: {@see refit} is the one funnel every re-frame goes through.
     *
     * AND IT CANNOT TAKE THE PLATE DOWN. A label is worth less than a map, so
     * a tooltip that throws while re-placing itself is reported and the rest
     * are still asked.
     */
    replaceLabels() {
        try {
            this.map?.eachLayer((drawnLayer) => drawnLayer.getTooltip?.()?.update());
        } catch (error) {
            console.error('[atlas] the plate could not re-place its labels', error);
        }
    }

    /**
     * THE SUBJECT'S BOUNDS, drawn off the map so nothing of it is added to
     * the plate: it is what the plate is ABOUT, and it is already drawn by
     * whoever stated it — as the boundary, as a zone, as a mark.
     */
    subjectOf(subject) {
        if (!subject?.geojson) {
            return null;
        }

        try {
            const bounds = this.L.geoJSON(subject.geojson).getBounds();

            return { bounds, zoom: subject.zoom ?? null };
        } catch (error) {
            console.error('[atlas] the plate could not read its subject', error);

            return null;
        }
    }

    /**
     * THE FRAME SETTLES AFTER THE MAP IS BUILT, and the plate is framed for
     * the frame it ends up in. Without this the subject overflowed the plate
     * by whatever the legend and the filter row took after the fit.
     */
    watchFrame() {
        const frame = this.frame();

        // THE FIT MEASURES FOR ITSELF, so settling is simply fitting again.
        this.settle = () => this.refit();

        /*
         * AND THE MAP'S OWN ANSWER IS THE LAST WORD. `invalidateSize` tells
         * Leaflet to measure again and it fires `resize` once it has — after
         * which the frame is whatever it really is, so the fit taken there
         * is taken against the final size rather than against the size the
         * card had while it was still being laid out.
         */
        this.onMapResize = () => this.refit();
        this.map?.on('resize', this.onMapResize);

        requestAnimationFrame(this.settle);

        if ('undefined' !== typeof ResizeObserver) {
            this.frameWatch = new ResizeObserver(() => this.settle());
            this.frameWatch.observe(frame);
        }
    }

    /**
     * THE PICK MODE, started on every map the plate is handed: at rest, on
     * the form PHP named, with the pin wherever that form's point already is
     * — or, on a second map, wherever the pin was.
     */
    startPick(pick) {
        this.pick = pick?.form ? pick : null;
        this.pin = null;
        if (!this.pick) {
            return;
        }

        this.picking ??= { mode: 'rest', form: document.getElementById(this.pick.form), name: this.pick.name ?? '', point: null };
        this.picking.point ??= this.pointIn(this.picking.form);
        this.map.on('click', (event) => this.pickAt(event.latlng.lat, event.latlng.lng));

        if (this.picking.point) {
            this.placePin(this.picking.point);
        }
        this.pickCaption();
        this.pickReadout();
    }

    /**
     * ARM THE PLATE FOR ONE FORM. The control says which form, what the
     * caption calls the point and whether it is added or moved, so the plate
     * never guesses which pair of boxes a click is about.
     */
    armFrom(event) {
        const control = event.target.closest(`[${PICK}]`);
        if (!control || !this.pick || !this.map) {
            return;
        }

        const form = document.getElementById(control.getAttribute(PICK));
        if (!form) {
            return;
        }

        event.preventDefault();
        this.picking = {
            mode: 'move' === control.getAttribute(PICK_MODE) ? 'move' : 'add',
            form,
            name: control.getAttribute(PICK_NAME) ?? '',
            point: this.pointIn(form),
        };

        if (this.picking.point) {
            this.placePin(this.picking.point);
            this.bringPinIntoView();
        } else {
            this.pin?.remove();
            this.pin = null;
        }

        this.pickCaption();
        this.pickReadout();
        this.element.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    }

    /** A click on the ground, or the pin let go of: one point, one readout. */
    pickAt(lat, lng) {
        if (!this.picking) {
            return;
        }

        // A CLICK AT REST IS AN ADD, into the form PHP named.
        if ('rest' === this.picking.mode) {
            this.picking.mode = 'add';
            this.pickCaption();
        }

        this.picking.point = { lat, lng };
        this.placePin(this.picking.point);
        this.pickReadout();

        if ('add' === this.picking.mode) {
            this.writePoint();
        }
    }

    /** "Use this point" — the armed form takes what the pin is standing on. */
    usePoint(event) {
        event.preventDefault();
        this.writePoint();
    }

    /** The point, into the stated inputs at the stated precision, and the form's note told. */
    writePoint() {
        const form = this.picking?.form;
        const point = this.picking?.point;
        if (!form || !point) {
            return;
        }

        const lat = form.querySelector(`[name="${this.pick.latitude}"]`);
        const lng = form.querySelector(`[name="${this.pick.longitude}"]`);
        if (lat) {
            lat.value = point.lat.toFixed(this.pick.precision);
        }
        if (lng) {
            lng.value = point.lng.toFixed(this.pick.precision);
        }

        const note = form.querySelector(`[${PICK_NOTE}]`);
        if (note) {
            note.textContent = this.pointText(point);
        }
    }

    /** A pair typed into the armed form moves the pin, so the two never disagree. */
    typedPoint(event) {
        const form = this.picking?.form;
        if (!form || !form.contains(event.target)) {
            return;
        }

        const point = this.pointIn(form);
        if (point) {
            this.picking.point = point;
            this.placePin(point);
            this.pickReadout();
        }
    }

    /** The pin: one on the plate, the sheet's drawing, dragged to move it. */
    placePin({ lat, lng }) {
        if (!this.map) {
            return;
        }

        if (this.pin) {
            this.pin.setLatLng([lat, lng]);

            return;
        }

        this.pin = this.L.marker([lat, lng], {
            icon: this.L.divIcon({ className: 'atlas-pin', html: '<i></i>', iconSize: [PIN_SIZE, PIN_SIZE], iconAnchor: [PIN_SIZE / 2, PIN_TIP] }),
            draggable: true,
            autoPan: true,
            keyboard: false,
            zIndexOffset: 1000,
        }).addTo(this.map);
        this.pin.on('dragend', () => {
            const at = this.pin.getLatLng();
            this.pickAt(at.lat, at.lng);
        });
    }

    /** A pin off the plate is brought on, at the zoom the reader is at. */
    bringPinIntoView() {
        const at = this.pin?.getLatLng();
        if (at && !this.map.getBounds().contains(at)) {
            this.map.panTo(at, { animate: false });
        }
    }

    /** What a form already holds, or null where it holds no point. */
    pointIn(form) {
        if (!form || !this.pick) {
            return null;
        }

        const lat = Number.parseFloat(form.querySelector(`[name="${this.pick.latitude}"]`)?.value ?? '');
        const lng = Number.parseFloat(form.querySelector(`[name="${this.pick.longitude}"]`)?.value ?? '');

        return Number.isFinite(lat) && Number.isFinite(lng) ? { lat, lng } : null;
    }

    pointText(point) {
        return `${point.lat.toFixed(this.pick.precision)}, ${point.lng.toFixed(this.pick.precision)}`;
    }

    /** One caption is shown, and it names the point it is about. */
    pickCaption() {
        for (const state of this.element.querySelectorAll(`[${PICK_STATE}]`)) {
            const shown = state.getAttribute(PICK_STATE) === this.picking.mode;
            state.hidden = !shown;
            const named = state.querySelector(`[${PICK_NAMED}]`);
            if (shown && named) {
                named.textContent = this.picking.name;
            }
        }
    }

    /** The readout and the commit, once there is a point to read. */
    pickReadout() {
        const point = this.picking?.point;
        const readout = this.element.querySelector(`[${PICK_POINT}]`);
        const use = this.element.querySelector(`[${PICK_USE}]`);
        if (readout) {
            readout.textContent = point ? this.pointText(point) : '';
            readout.hidden = !point;
        }
        if (use) {
            use.hidden = !point;
        }
    }

    /** The imagery frame the chrome is mounted in; the plate root if there is none. */
    frame() {
        return this.hasFrameTarget ? this.frameTarget : this.element;
    }

    /** A drawn layer with no features has no bounds to widen anything with. */
    extend(drawn) {
        const bounds = drawn.getBounds();
        if (bounds.isValid()) {
            this.bounds.extend(bounds);
        }
    }

    /*
     * THE LIVE STREAM — how the marks keep moving after the page is drawn.
     *
     * The builder that knows the area handed the plate two facts under
     * `extra.atlas.live`: the hub's PUBLIC address and the topics to hold
     * open. This opens ONE credentialed EventSource on it and nothing else:
     * no polling, no second request, no map maths. The page set a subscriber
     * cookie for those topics before this ran, which is what `withCredentials`
     * hands the hub.
     *
     * Patterned on the documented subscription — "To subscribe to private
     * updates, subscribers must provide to the Hub a JWT containing a topic
     * selector matching the topic of the update. To provide this JWT, the
     * subscriber can use a cookie, or an `Authorization` HTTP header" — whose
     * example connects with `new EventSource(url, { withCredentials: true })`.
     *   https://symfony.com/doc/current/mercure.html — "Authorization"
     *
     * Each frame is ONE live mark: the very feature the live layer was drawn
     * from at page load (its person key as the feature id, the point, the
     * name, the initials, the age, whether it is stale and what makes it so),
     * or the same key with no geometry and `gone: true` for somebody who left
     * the ground. A frame that is not that is dropped, never a broken plate.
     *
     * RECONNECTION IS THE BROWSER'S. An EventSource that loses the hub retries
     * on its own with the same credentials; `onerror` has nothing to add.
     *
     * A PLATE WITH NO LIVE LAYER OPENS NOTHING, whatever it was handed: a
     * frame could move no mark and the legend has no row to count.
     */
    subscribe(stream) {
        if (!stream?.hub || !(stream.topics ?? []).length) {
            return;
        }
        if (!this.liveLayer()) {
            return;
        }

        const url = new URL(stream.hub);
        for (const topic of stream.topics) {
            url.searchParams.append('topic', topic);
        }

        this.stream = new EventSource(url, { withCredentials: true });
        this.stream.onmessage = (event) => {
            let frame;
            try {
                frame = JSON.parse(event.data);
            } catch (error) {
                return;
            }
            this.receiveFrame(frame);
        };

        this.startClock();
    }

    /** The connection and the clock go with the plate, the map, or the swap that replaced them. */
    unsubscribe() {
        this.stream?.close();
        this.stream = null;
        this.stopClock();
    }

    /**
     * The live layer this plate draws — its id, the drawn layer and what the
     * map said about it — or null on a plate that has none. Found by SHAPE,
     * the way the marks are drawn, so the builder's layer id is its own.
     */
    liveLayer() {
        for (const [id, spec] of this.specs) {
            const drawn = this.layers.get(id);
            if ('live' === spec.shape && drawn) {
                return { id, spec, drawn };
            }
        }

        return null;
    }

    /**
     * ONE FRAME, ONE MARK: the mark with the same key comes off, the new one
     * goes on unless the frame says the person is gone, and the legend's
     * counts follow. Anything that is not a point feature with a string id is
     * dropped here — the wire is somebody else's and the plate checks what it
     * is handed before it draws it.
     */
    receiveFrame(frame) {
        const live = this.liveLayer();
        if (!live || 'Feature' !== frame?.type || 'string' !== typeof frame.id) {
            return;
        }

        const gone = true === frame.properties?.gone;
        if (!gone && !isPointFeature(frame)) {
            return;
        }

        const previous = [];
        live.drawn.eachLayer((mark) => {
            if (mark.feature?.id === frame.id) {
                previous.push(mark);
            }
        });
        for (const mark of previous) {
            live.drawn.removeLayer(mark);
        }

        if (!gone) {
            live.drawn.addData(frame);
        }

        this.recountLive();
    }

    startClock() {
        this.stopClock();
        this.clock = setInterval(() => this.tick(), CLOCK_TICK_MS);
    }

    stopClock() {
        if (this.clock) {
            clearInterval(this.clock);
            this.clock = null;
        }
    }

    /**
     * EVERY LIVE MARK RE-READ AGAINST NOW: the age it prints and whether it
     * has gone stale, from the instant of its fix and the silence its own
     * area calls stale — both stated on the feature by whoever drew it, so the
     * plate re-decides nothing about what "current" means. A mark whose
     * reading did not change is left alone.
     */
    tick() {
        const live = this.liveLayer();
        if (!live) {
            return;
        }

        const now = Date.now();
        live.drawn.eachLayer((mark) => {
            const feature = mark.feature;
            const properties = feature?.properties;
            const at = properties ? Date.parse(properties.at) : NaN;
            if (!Number.isFinite(at)) {
                return;
            }

            const seconds = Math.max(0, Math.round((now - at) / 1000));
            const age = ageLabel(seconds);
            const stale = Number.isFinite(properties.staleAfterSeconds)
                ? seconds > properties.staleAfterSeconds
                : true === properties.stale;
            if (age === properties.age && stale === properties.stale) {
                return;
            }

            properties.age = age;
            properties.stale = stale;
            mark.setIcon?.(this.liveIcon(feature));
        });

        this.recountLive();
    }

    /**
     * THE LEGEND SAYS WHAT THE PLATE SHOWS: the live row counts the marks
     * nobody should doubt, the stale key counts the rest. Both are read off
     * the drawn marks, so the numbers under the plate and the dots on it
     * cannot disagree.
     */
    recountLive() {
        const live = this.liveLayer();
        if (!live || !this.hasLegendTarget) {
            return;
        }

        let current = 0;
        let stale = 0;
        live.drawn.eachLayer((mark) => {
            if (true === mark.feature?.properties?.stale) {
                stale += 1;
            } else {
                current += 1;
            }
        });

        const row = this.legendTarget.querySelector(`[data-${this.identifier}-layer-param="${live.id}"] em`);
        if (row) {
            row.textContent = String(current);
        }
        const key = this.legendTarget.querySelector(`[${KEY_SHAPE}="live-stale"] em`);
        if (key) {
            key.textContent = String(stale);
        }
    }
}

/** A GeoJSON point feature with two finite coordinates: the one shape a live mark is. */
function isPointFeature(feature) {
    const coordinates = feature?.geometry?.coordinates;

    return 'Point' === feature?.geometry?.type
        && Array.isArray(coordinates)
        && coordinates.length >= 2
        && Number.isFinite(coordinates[0])
        && Number.isFinite(coordinates[1]);
}

/**
 * HOW OLD A FIX IS, in the words the mark prints: "4 min" up to an hour, then
 * "4 h 21" — the same shape Model\LiveMarks::age() prints at page load, so a
 * mark re-read here reads as the mark that arrived.
 */
function ageLabel(seconds) {
    const minutes = Math.floor(Math.max(0, seconds) / 60);
    if (minutes < 60) {
        return `${minutes} min`;
    }

    return `${Math.floor(minutes / 60)} h ${String(minutes % 60).padStart(2, '0')}`;
}

/**
 * A POPUP, WRITTEN FROM PROPERTY NAMES. A module says which properties to read;
 * the markup and the escaping are the plate's, so a value somebody typed into a
 * form cannot become an element on a map.
 *
 * A popup whose title property is empty on this feature is no popup at all —
 * an empty bubble says less than no bubble.
 */
function popupMarkup(popup, properties) {
    const title = properties[popup.title];
    if (undefined === title || null === title || '' === title) {
        return null;
    }

    const lines = (popup.lines ?? [])
        .map((name) => properties[name])
        .filter((value) => undefined !== value && null !== value && '' !== value)
        .map((value) => escapeHtml(value));

    let markup = `<b>${escapeHtml(title)}</b>`;
    if (lines.length > 0) {
        markup += `<br><small>${lines.join(' &middot; ')}</small>`;
    }

    const href = popup.href ? properties[popup.href] : null;
    if (href) {
        markup += `<br><a href="${escapeHtml(href)}">${escapeHtml(popup.linkLabel ?? href)}</a>`;
    }

    return markup;
}

/**
 * WHOSE CLICK IT IS. A plain left click on a chip is the viewer changing the
 * filter; every other way of clicking one is them asking for a second page, and
 * that belongs to the browser.
 * https://developer.mozilla.org/en-US/docs/Web/API/MouseEvent/button
 */
function isPlainClick(event) {
    return 0 === event.button && !event.metaKey && !event.ctrlKey && !event.shiftKey && !event.altKey;
}

function escapeHtml(value) {
    return String(value).replace(/[&<>"']/g, (character) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
    }[character]));
}
