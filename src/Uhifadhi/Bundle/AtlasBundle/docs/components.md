# The atlas components

The atlas is the component library every module's visuals are drawn with. A module states what
is on a visual in PHP and calls one Twig function; it writes no JavaScript, holds no opinion
about imagery, chrome or fullscreen, and cannot make its own map look different from anybody
else's.

The atlas ships **maps** and **charts**; calendars are the same shape and are documented with
their API once it settles.

## Contents

- [How a module gets a map](#how-a-module-gets-a-map)
- [The map builder](#the-map-builder)
- [Layers](#layers)
- [Styling a layer's features](#styling-a-layers-features)
- [Tooltips and popups](#tooltips-and-popups)
- [Spotlighting a feature from elsewhere on the page](#spotlighting-a-feature-from-elsewhere-on-the-page)
- [Changing the plate from a link](#changing-the-plate-from-a-link)
- [How tall a plate is](#how-tall-a-plate-is)
- [The boundary](#the-boundary)
- [The ground](#the-ground)
- [The legend](#the-legend)
  - [Where it is drawn](#where-it-is-drawn)
- [The live stream](#the-live-stream)
- [Base layers, fullscreen and fitting](#base-layers-fullscreen-and-fitting)
- [What UX Map already models](#what-ux-map-already-models)
- [render_map()](#render_map)
  - [A filter change keeps fullscreen](#a-filter-change-keeps-fullscreen)
- [The events](#the-events)
- [A whole module template](#a-whole-module-template)
- [Charts](#charts)
  - [The five kinds](#the-five-kinds)
  - [A ranking](#a-ranking)
  - [The figure on the bar](#the-figure-on-the-bar)
  - [A stated axis](#a-stated-axis)
  - [The chip legend](#the-chip-legend)
  - [The accent, the nought and the column width](#the-accent-the-nought-and-the-column-width)
  - [How tall a chart is](#how-tall-a-chart-is)
  - [What each statement becomes in Chart.js](#what-each-statement-becomes-in-chartjs)
- [The sparkline](#the-sparkline)
- [Ranked bars and the dot key](#ranked-bars-and-the-dot-key)
- [The heat table and its legend](#the-heat-table-and-its-legend)
- [An area's face](#an-areas-face)
- [What a module must not do](#what-a-module-must-not-do)

## How a module gets a map

Four steps, and the fourth is a line of Twig.

1. Take `MapBuilderInterface` in a service's constructor and call `createMap()`.
2. Add layers, a boundary, legend rows.
3. Hand the map to the template.
4. `{{ render_map(map) }}`.

## The map builder

```php
// src/Service/SightingsMap.php (your module)
namespace YourVendor\Sightings\Service;

use Uhifadhi\Bundle\AtlasBundle\Map\MapBuilderInterface;
use Uhifadhi\Bundle\AtlasBundle\Model\AtlasMap;

final readonly class SightingsMap
{
    public function __construct(private MapBuilderInterface $maps)
    {
    }

    public function forArea(string $areaUuid): AtlasMap
    {
        return $this->maps->createMap();
    }
}
```

Wire it the way a reusable bundle wires anything — explicitly, in the bundle's own PHP config:

```php
// config/services.php (your module)
$services->set('sightings.map', SightingsMap::class)
    ->args([service(MapBuilderInterface::class)]);
```

What comes back is an `AtlasMap`: a UX Map map with the deployment's imagery underneath, the
bridge's own tiles and zoom control switched off, and the atlas's control stack, legend and
fullscreen around it. It opens on a neutral view and re-frames itself on whatever gets drawn.

## Layers

A layer is a whole GeoJSON FeatureCollection that shares one colour, one meaning and one switch.
It names exactly one source: `features`, which the server already has, or `url`, which the plate
fetches once it is mounted. Naming both, or neither, is refused where you wrote it.

```php
use Uhifadhi\Bundle\AtlasBundle\Model\GeoJsonLayer;
use Uhifadhi\Bundle\AtlasBundle\Model\LayerShape;

$map->addLayer(new GeoJsonLayer(
    id: 'sightings.recent',      // <module>.<layer>, and the id its legend row switches
    label: 'This week',
    features: $collection,       // …or url: '/sightings/features.geojson'
    swatch: '#E5C15A',
    shape: LayerShape::Point,    // Line | Fill | Point
    visible: true,
    count: 42,                   // what the legend prints after the label
    group: 'Sightings',          // the legend heading the row sits under
));
```

Three shapes and no more. A module says what geometry MEANS; stroke widths, opacities and radii
are the plate's, so two modules cannot disagree about what "a line" looks like. A feature may
override its own colour with a `color` property, and a feature with a `label` property wears it
as a permanent halo over the shape.

A layer with `visible: false` is still built, so its first switch costs no round trip. A layer
with a `url` is built empty and filled when the fetch answers — the plate never waits on a
request to become a map.

## Styling a layer's features

A shape settles what a line, a fill and a point look like for the whole platform. On top of that
a layer states the few things that carry MEANING rather than house style — a hollow mark for a
closed case, a dashed ring for the serious end, a wider stroke for one route.

Two statements, and both are data:

- **`style`** — a `LayerStyle` every feature of the layer wears, merged over the shape's answer;
- **`rules`** — `StyleRule`s keyed on the features' own properties, merged over `style` in the
  order they are written.

```php
use Uhifadhi\Bundle\AtlasBundle\Model\LayerStyle;
use Uhifadhi\Bundle\AtlasBundle\Model\StyleRule;

$map->addLayer(new GeoJsonLayer(
    id: 'sightings.recent',
    label: 'This week',
    features: $collection,
    swatch: '#E5C15A',
    shape: LayerShape::Point,
    style: new LayerStyle(radius: 5.5, weight: 1.6, fillOpacity: 0.85),
    rules: [
        // a finished sighting is HOLLOW
        StyleRule::when('open', false)->fillOpacity(0.0),
        // and the serious end wears a dashed ring
        StyleRule::when('severity', ['high', 'critical'])->radius(7.0)->weight(2.4)->dashArray('3 3'),
    ],
));
```

A style states **only what it changes**; an unstated property keeps the shape's own answer. The
vocabulary is Leaflet's own path options, so what you write and what the browser receives are the
same word:

| Statement | What it sets |
|---|---|
| `color(string)` | the stroke colour |
| `weight(float)` | the stroke width, in pixels |
| `opacity(float)` | the stroke opacity, 0–1 |
| `fill(bool)` | whether the shape is filled at all |
| `fillColor(string)` / `fillOpacity(float)` | the fill, where it differs from the stroke |
| `dashArray(string)` | an SVG dash pattern, e.g. `'4 3'` |
| `radius(float)` | the circle radius of a point feature |
| `zIndex(int)` | which pane it is drawn in — higher is nearer the reader |

`zIndex` is the only way to say "this layer sits over that one" independently of the order the
layers were added in: the plate builds one Leaflet pane per stated value.

**No callback crosses the wire.** A rule is a property name, the values that satisfy it and the
style they earn — which is a thing JSON can carry and one controller can evaluate. That is
precisely why your module ships no map JavaScript.

## Tooltips and popups

Both are stated as **property names**. The plate reads the property, writes the markup and escapes
the value, so a headline somebody typed into a form cannot become an element on a map.

```php
use Uhifadhi\Bundle\AtlasBundle\Model\FeaturePopup;

new GeoJsonLayer(
    id: 'sightings.recent',
    label: 'This week',
    features: $collection,
    // read on HOVER — a floating label, following the cursor
    tooltip: 'summary',
    // opened on CLICK
    popup: new FeaturePopup(
        title: 'title',
        lines: ['category', 'statusLabel'],
        href: 'href',
        linkLabel: 'Open the sighting →',
    ),
);
```

`FeaturePopup::of('title', 'href')` is the short way to say the two properties a popup nearly
always has. A popup whose `title` property is empty on a given feature is not drawn: an empty
bubble says less than no bubble.

A feature that carries a **`label`** property still wears it as a permanent halo over the shape —
that is how a zone is read on imagery, and it is unrelated to `tooltip`.

## Spotlighting a feature from elsewhere on the page

A log row beside a map should lift the track it is about. That needs no JavaScript from you: the
layer declares which property identifies a feature, and any element on the page carrying
`data-atlas-highlight="<layer id>:<feature id>"` spotlights it on hover or focus.

```php
new GeoJsonLayer(id: 'patrol.tracks.foot', label: 'foot', features: $tracks, featureId: 'ref');
```

```twig
<a class="row" href="{{ path('patrol_show', {…}) }}" data-atlas-highlight="patrol.tracks.foot:{{ patrol.ref }}">…</a>
```

The plate raises that feature's stroke and pushes its siblings back while the cursor is on the
element, and puts every one of them back on leave. How far it lifts and how far the rest fall
back is the plate's answer, so a spotlit patrol track and a spotlit anything else read alike.

The listeners are delegated from `document`, so rows re-rendered, paginated or swapped in after
the map mounted still work.

## Changing the plate from a link

A ranger in a rail, a station in a list, a zone in a register: clicking one means *show me this
one on the map*. That was a full navigation — the page rebuilt, the scroll position lost, the map
torn down and mounted again — for a change the server answers with the same page it always
answers with.

Mark the link `data-atlas-swap` and it stops being one:

```twig
<a class="row" href="{{ path('roster_live', {focus: person.uuid}) }}" data-atlas-swap>…</a>
```

The plate fetches where the link goes, takes its own subtrees out of the answer and puts them in
place of the live ones. **The `href` is unchanged and still does the whole job**: with no script,
on a middle click, on a modified click, or if anything at all goes wrong, the browser navigates
and the same page arrives the ordinary way.

**The server still decides everything.** What is focused, what the plate frames itself on, which
row is marked — all of it is computed where it was always computed (`AreaPlateService::focusOn()`
and the rest), and this fetches the answer. You write an attribute; you write no JavaScript and no
map maths.

### The row that has to move with it

A list beside the plate usually marks the row the map is about, and that mark lives outside the
plate. Name that region and it comes across from the same answer:

```twig
<a href="…" data-atlas-swap data-atlas-swap-also="#live-rail">…</a>
```

One region, deliberately. A link that re-renders half a page is a navigation with extra steps, and
the page it just fetched is right there. A region the answer does not contain is left standing
rather than removed: the fetched page is the authority on what your list *contains*, not on
whether it exists.

### Two plates on one page

The verb's value names the plate, as a selector, when the link is not inside the one it is about:

```twig
<a href="…" data-atlas-swap="#coverage-plate" data-atlas-swap-also="#live-rail">…</a>
```

With no value the plate is the one the link sits inside, or the page's only plate. A page with two
plates and a link that names neither is a question with two answers, so nothing is swapped and the
browser navigates — which is always correct.

**It is a place, so it is pushed.** A filter is a view of one page and replaces the history entry;
choosing which thing the map is about is somewhere the reader went, and Back brings them to the one
before.

## How tall a plate is

**A plate is as tall as it says it is, never as tall as the row it sits in.** It has a real
`height` — never `auto`, and never an `align-self`, which inside a card that stacks a plate under
a heading would read across the other axis and shrink the plate off its width — so a stretch row
cannot grow it; the only thing that does is fullscreen.

```css
--map-plate-height   /* default: min(58vh, 560px) */
```

**What the property sizes is the MAP: the filter row and the imagery under it (`.map-body`). The
legend is drawn below that box and adds its own height to the plate.** So a screen that states
400px gets 400px of map, and the plate it sits in is 400px plus the legend's rows — a card that
gives a plate a fixed box gets a box of that size plus the legend, which is therefore never
clipped. A plate with no legend is exactly its stated height.

Set it wherever it inherits from — the card the plate is in:

```css
.your-module .case-file-where { --map-plate-height: min(46vh, 440px); }
```

or hand it to `render_map()`, which lifts any custom property in the attributes onto the plate
rather than onto the map element inside it:

```twig
{{ render_map(map, {'--map-plate-height': 'min(46vh,440px)', 'role': 'img', 'aria-label': 'Where'}) }}
```

Never size a plate with `min-height` plus `flex: 1` on your own card. That is the rule this
replaced, and it is how a map ends up over a thousand pixels tall beside a long column of facts.

## The boundary

The ground a plate is about, with everything outside it dimmed:

```php
use Uhifadhi\Bundle\AtlasBundle\Model\Boundary;

$map->boundary(new Boundary($geoJson, scrim: true));
```

It is not a layer: it has the platform's one treatment (a white casing under a jade line, no
fill), and its scrim covers the world with the outline punched out of it, so the scrim's bounds
are the planet and fitting a map to them would zoom every plate out to nothing. The DIM control
switches the scrim; `scrim: false` only decides whether it starts on.

## The ground

The area a plate stands on — its boundary and its zones — drawn the one way every plate draws it:

```php
use Uhifadhi\Bundle\AtlasBundle\Model\Ground;

// $payload is the area's answer, AreaMapPayload::forArea():
// ['boundary' => GeoJSON text|null, 'zones' => list<['name' => ?string, 'geom' => ?string]>]
$map->ground(Ground::fromGeoJson($payload['boundary'], $payload['zones'], scrim: true));
```

`new Ground($boundary, $zones, $scrim)` takes the same thing decoded: a GeoJSON geometry (or
`null`) and a list of `['name' => string, 'geometry' => array]`. `fromGeoJson()` decodes the text
a geometry column returns and drops anything that will not parse; a zone with no name is drawn
without a caption. The atlas reads no database: it is handed geometry.

What the plate then carries, whatever else is on it:

| What | How |
|---|---|
| the boundary | the [boundary](#the-boundary) treatment, with the scrim the caller chose |
| a row **Boundary** | a line swatch in `PlatePalette::ACCENT`, switching `AtlasMap::BOUNDARY_LAYER_ID`; absent where there is no boundary |
| the zones | one line layer, `Ground::ZONES_LAYER_ID` (`area.zones`), in `PlatePalette::DIM`, each zone wearing its name |
| a row **Zones · N** | under the boundary row, counting the zones; present at `0`, switched off, where the area has none |

Both rows sit under the heading `Ground::GROUP` (**The area**). The zones layer is listed first
and the two rows open the legend, whenever `ground()` was called: the plate draws layers in the
order they are listed, so the zones are under every mark a module adds. A module's own rows
about the area — a station layer — join the group by naming `Ground::GROUP`.

A module never draws zones itself and never names a zone swatch. One ground per plate: a second
`ground()` replaces the first.

## The legend

Every layer states its own row. A map adds rows for colours that have no layer behind them:

```php
use Uhifadhi\Bundle\AtlasBundle\Model\LegendItem;

$map->addLegendItem(new LegendItem(label: 'Boundary only', swatch: '#B9C8BD'));
```

A row with a `layerId` is a switch; a row without one is a key. The boundary is reachable as a
switch under `AtlasMap::BOUNDARY_LAYER_ID`:

```php
$map->addLegendItem(new LegendItem(
    label: 'Boundary',
    swatch: '#49E6B4',
    shape: LayerShape::Line,
    group: 'The area',
    layerId: AtlasMap::BOUNDARY_LAYER_ID,
));
```

Rows sharing a `group` are drawn together under that heading, in the order their first row
appeared — which is how a plate with four contributors stays readable.

### Where it is drawn

**Below the map, as a wrapping row of groups** — the design workspace's `.maplegend` under its
`.viewer`: 22px between groups, 12px under the plate, on the page's own ground. A legend floating
in the imagery covers the ground it describes, and on a short plate — an incident report's 176px
rail, a thumbnail — it covers most of it.

**In fullscreen it floats**, back in the imagery's bottom-right corner on a dark panel, under the
control stack's z-index so the controls stay clickable: there the screen is all map, so the legend
has imagery to spare and nothing on a page to sit beside. It is the same element and the same
switches in both — only the stylesheet changes, and a module says nothing about either.

## The live stream

A plate that draws live positions (`AtlasMap::livePositions()`) can keep them moving after the
page is drawn. The builder states two facts and the atlas asks nothing about them:

```php
use Uhifadhi\Bundle\AtlasBundle\Model\LiveStream;

$map->liveStream(new LiveStream(
    $hub->getPublicUrl(),          // the Mercure hub as the BROWSER reaches it
    ['area/…/presence'],           // the topics to hold open — at least one
));
```

They travel as `extra.atlas.live` (`{hub, topics}`, or `null`). The plate then opens **one
credentialed `EventSource`** on the hub with every topic as a `topic` query parameter
(`withCredentials: true`, so the subscriber cookie the page set reaches the hub) — no polling,
no second request. The browser reconnects on its own when the hub drops.

**A frame is one live mark**: the same feature `LiveMarks::frame()` builds — the person key as
the feature `id`, a `Point`, and `properties` with `name`, `initials`, `age`, `stale`, `at`
(the instant of the fix) and `staleAfterSeconds` (the silence its own area calls stale). The
plate takes the mark with that key off, draws the new one, and updates the legend's live and
stale counts. A frame with `"geometry": null` and `"properties": {"gone": true}` takes the mark
off and draws nothing. A frame that is not that shape — not JSON, no string id, no point — is
dropped; the plate stands.

**The clock runs only while a stream is open.** Every thirty seconds each mark's age label and
its stale state are re-read from `at` and `staleAfterSeconds`, so a phone that goes quiet dims
without a reload. A plate with no stream reads exactly as the page drew it.

**Who sets the cookie is the builder's business.** The atlas carries the hub and the topics; the
page that draws the plate authorizes the browser for those topics on its own response (the area
bundle's `PresenceStreamService` does this for its pages). A plate given a stream and no cookie
connects and is refused, and draws what the page gave it.

## Base layers, fullscreen and fitting

```php
use Uhifadhi\Bundle\AtlasBundle\Model\BaseLayer;

$map
    ->baseLayers(BaseLayer::Satellite, BaseLayer::Street)  // the first is what it opens on
    ->fullscreen(true)   // off for a plate that already is the whole screen
    ->fit(true)          // off for a plate whose centre and zoom are the point
;
```

With `fit(false)`, say where to look through the UX Map map underneath.

## What UX Map already models

Markers, polygons, polylines, circles and rectangles stay UX Map's. Use its classes and add them
to the map underneath:

```php
use Symfony\UX\Map\InfoWindow;
use Symfony\UX\Map\Marker;
use Symfony\UX\Map\Point;

$map->ux()->addMarker(new Marker(
    position: new Point(-3.2, -29.5),
    title: 'The eastern station',
    infoWindow: new InfoWindow(content: '<a href="/stations/7">Open the station &rarr;</a>'),
));
```

`ux()` is also where centre, zoom, min and max zoom live.

Your own data for the browser rides beside the atlas's, never underneath it:

```php
$map->extra(['sightings' => ['season' => 'dry']]);
```

## render_map()

```twig
{{ render_map(map) }}
```

The second argument is attributes for the map element — an aria-label, a role, a data attribute
of your own:

```twig
{{ render_map(map, {'role': 'img', 'aria-label': 'Sightings this week'}) }}
```

The third is the filter row, one row above the map and inside the plate, so it comes along into
fullscreen:

```twig
{% set filters %}
    <a class="chip on" href="?since=week">This week</a>
    <a class="chip" href="?since=month">This month</a>
{% endset %}

{{ render_map(map, {'role': 'img', 'aria-label': 'Sightings'}, filters) }}
```

What it emits is the plate: a flex column carrying the map body — the filter row and the imagery
frame with the UX Map element inside it — and the legend below it. The plate root is the
fullscreen element and the body grows to fill it, which is why a module never writes those rules
— the atlas stylesheet owns them, and a card that clamps a height cannot break a plate inside it.

### A filter change keeps fullscreen

A filter row needs nothing else to work on an expanded map, whether its chips are a GET form or
same-origin links. While the plate is fullscreen it answers the submission — or the click — itself:
it fetches that address, swaps its own three subtrees — the filter row, the map element and the
legend — out of the answer, and writes the new address into the bar without navigating. Leaving
fullscreen then navigates once, because the log and the counts outside the plate are still
answering the query the page was served with. Outside fullscreen nothing is intercepted and the
row behaves as its markup says.

A chip clicked with a modifier, with the middle button, or carrying a `target` of its own is left
to the browser: that is somebody asking for a second page, not for a different filter.

A module writes no JavaScript for this and no attribute either. What it must not do is take the
plate's hook (`data-atlas-plate`) off the root or wrap the filter row in another element of its
own — the swap finds this plate in the fetched page by that hook, and its subtrees by their
classes.

## The events

The plate dispatches its own lifecycle, for an installation that needs to extend a map without
forking anything. A module should not need these.

| Event | Detail | When |
|---|---|---|
| `atlas:map:connect` | `{map, L, layers}` | the plate has drawn everything and mounted its chrome |
| `atlas:map:layer:added` | `{id, layer}` | one layer has been built, before it may have been filled |

`layers` is a `Map` keyed by layer id, with the boundary under `atlas.boundary`.

UX Map's own events — `ux:map:pre-connect`, `ux:map:connect`, `ux:map:*:before-create` — fire on
the same element and are documented upstream.

## A whole module template

```twig
{# templates/sightings/plate.html.twig (your module) #}
{% extends '@Shell/page.html.twig' %}

{% block stylesheets %}
    {{ parent() }}
    {# The platform's one map stylesheet: the plate, the chrome, the legend and
       the fullscreen rules. Leaflet's own sheet arrives with the map. #}
    <link rel="stylesheet" href="{{ asset(constant('Uhifadhi\\Bundle\\AtlasBundle\\AtlasBundle::STYLESHEET')) }}">
{% endblock %}

{% block shell_page %}
    <div class="c">
        <span class="tab">Sightings<span class="src">&middot; this week</span></span>

        {% set filters %}
            <a class="chip on" href="?since=week">This week</a>
            <a class="chip" href="?since=month">This month</a>
        {% endset %}

        {{ render_map(map, {'role': 'img', 'aria-label': 'Sightings this week'}, filters) }}
    </div>
{% endblock %}
```

And the screen behind it:

```php
// src/Controller/SightingsController.php (your module)
return new Response($this->twig->render('@Sightings/sightings/plate.html.twig', [
    'map' => $this->map->forArea($areaUuid),
]));
```

That is the whole of it. No controller file in `assets/`, no `controllers.json` entry, no
Leaflet, no chrome markup, no legend markup.

## Charts

A chart is stated the way a map is: a module builds an `AtlasChart` in PHP — a kind, the axis
labels, its series, perhaps a target — and writes one line of Twig. The colours, the grid, the
axes, the legend and the height are the atlas's; a series is a category (`cat`, 1 to 18, its
position in the palette), never a colour.

```php
use Uhifadhi\Bundle\AtlasBundle\Model\AtlasChart;
use Uhifadhi\Bundle\AtlasBundle\Model\ChartKind;
use Uhifadhi\Bundle\AtlasBundle\Model\ChartSeries;

$chart = new AtlasChart(
    ChartKind::Bar,
    ['W1', 'W2', 'W3'],
    [
        new ChartSeries('foot', [34.0, 28.0, 41.0], cat: 1),
        new ChartSeries('vehicle', [18.0, 22.0, 30.0], cat: 2),
    ],
    unit: 'patrols',
);
```

```twig
{{ atlas_chart(chart, 'Patrols per week', 'One bar per type, every week of the month.') }}
```

The fourth argument is attributes for the canvas — an `aria-label`, a data attribute of your own.
A chart nobody published a point in is not drawn: the plate says so in the house's own words.

### The five kinds

| Kind | What the data is | Chart.js |
|---|---|---|
| `Line` | a run over time | `type: 'line'` |
| `Bar` | a comparison across categories | `type: 'bar'` |
| `Stacked` | parts of a whole, period by period | `type: 'bar'`, both scales `stacked` |
| `Diverging` | a movement either side of nought | `type: 'bar'`, value axis not pinned at nought |
| `Ranked` | a comparison read as a league table — sideways, the name on the left, the longest on top | `type: 'bar'`, `indexAxis: 'y'` |

### A ranking

`ChartKind::Ranked` is the horizontal bar. The caller hands the rows already ordered — the atlas
draws them top to bottom as given — and the value axis becomes the horizontal one.

```php
new AtlasChart(
    ChartKind::Ranked,
    ['Endulen', 'Nainokanoka', 'Lerai'],
    [new ChartSeries('Patrols', [46.0, 38.0, 27.0])],
    unit: 'patrols',
    axis: AxisScale::covering(46.0, 3),
    figures: new ChartFigures(),
);
```

Chart.js: "To achieve this, you will have to set the `indexAxis` property in the options object
to `'y'`. The default for this property is `'x'` and thus will show vertical bars"
([charts/bar — Horizontal Bar Chart](https://www.chartjs.org/docs/latest/charts/bar.html#horizontal-bar-chart)).
The builder writes `indexAxis: 'y'` and swaps which letter carries the names and which the values.

### The figure on the bar

`figures: new ChartFigures(unit: 'h', precision: 0)` writes the figure past the end of every
bar — `46`, `128 h` — so a ranking is read without a hover. A null point gets no figure.

Chart.js core draws no value labels, and the host's importmap ships `chart.js` alone — no
datalabels plugin, and the atlas adds no dependency to a host. What the docs give instead is an
**inline plugin**: "`new Chart(ctx, { plugins: [{ … }] })`", where "plugins must define a unique
id in order to be configurable" and "plugin options are located under the `options.plugins`
config and are scoped by the plugin ID"
([developers/plugins](https://www.chartjs.org/docs/latest/developers/plugins.html)). So the
builder writes the options under `options.plugins.figures` and the plate's own controller puts an
inline plugin of that id on the config in `chartjs:pre-connect` — the event the UX bridge fires
with the whole config before it calls `new Chart()`
(`vendor/symfony/ux-chartjs/assets/dist/controller.js`). The plugin's `afterDatasetsDraw`
([api/interfaces/Plugin](https://www.chartjs.org/docs/latest/api/interfaces/Plugin.html)) walks
`chart.getDatasetMeta(index).data` for every visible dataset
([developers/api](https://www.chartjs.org/docs/latest/developers/api.html)) and writes each
figure in the plate's own ink and mono face, read from the element it is mounted on — so the
figures turn over with the theme like every other word. The builder also adds `layout.padding`
past the last bar ([configuration/layout](https://www.chartjs.org/docs/latest/configuration/layout.html))
so the longest bar's figure is not clipped by the canvas edge.

A module never writes a plugin of its own; if a figure needs to say something else, the gap is
in `ChartFigures`.

### A stated axis

`axis: new AxisScale(max: 45.0, step: 15.0)` pins the top of the value axis and the distance
between its gridlines. The rule is the caller's — a ranking wants its gridlines on whole numbers
and a module may have a rule of its own for where the top lands; `AxisScale::covering($largest,
3)` is the common one: the smallest multiple of three that still covers the largest value, never
below three, so an empty month keeps a width to measure against. Left unstated, the library picks
its own ticks.

Chart.js: `max` — "User defined maximum number for the scale, overrides maximum value from
data" (`options.scales[scaleId]`); `ticks.stepSize` — "User-defined fixed step size for the
scale" (`options.scales[scaleId].ticks`)
([axes/cartesian/linear](https://www.chartjs.org/docs/latest/axes/cartesian/linear.html)). The
builder writes both on the value scale — `y` on a standing chart, `x` on a ranking.

### The chip legend

`legend: ChartLegend::Chips` draws the legend as a row of the house's `.chip` pills under the box,
one per series in order, each wearing its category through the shell's `data-cat` door — the same
token the bars above it were drawn in, so the pill and the bar cannot disagree. The target line,
where there is one, is the idle pill last. When the chips are drawn the library's own legend is
switched off, so no series is named twice: `plugins.legend.display: false` — "Is the legend
shown?", default true
([configuration/legend](https://www.chartjs.org/docs/latest/configuration/legend.html)).

`ChartLegend::Canvas` (the default) leaves the library's legend inside the canvas, drawn where
more than one thing is plotted; `ChartLegend::None` draws none — a single series named in the
title needs no key.

```html
<div class="chart-legend">
    <span class="chip" data-cat="1">foot</span>
    <span class="chip" data-cat="2">vehicle</span>
    <span class="chip idle">Target</span>
</div>
```

The plate's sheet writes the row and the ink (`.chart-plate > .chart-legend > .chip[data-cat]`);
the pill's shape stays the shell's and is not restated.

### The accent, the nought and the column width

Three statements for a column chart that measures ONE thing across things that are not
categories — assignments across areas, say:

```php
new AtlasChart(
    ChartKind::Bar,
    ['north', 'south'],
    [new ChartSeries('Assignments', [31.0, 0.0], accent: true)],
    axis: new AxisScale(32.0, 16.0),
    legend: ChartLegend::None,
    noughts: ChartNoughts::Hairline,
    barWidth: 40.0,
);
```

- **`ChartSeries(accent: true)`** — the series wears the house accent (`var(--acc)`) rather than a
  category; its chip, where chips are drawn, is the accent pill. A series is a category or the
  accent, never both.
- **`noughts: ChartNoughts::Hairline`** — a nought keeps its column as a two-pixel stub on the axis,
  faded to .28, so a thing with none reads as "none here" and not as missing. Chart.js:
  `minBarLength`, "Set this to ensure that bars have a minimum length in pixels"
  ([charts/bar](https://www.chartjs.org/docs/latest/charts/bar.html#dataset-properties)); a bar whose
  value is the base is moved half that length and clamped inside the scale, so the stub stands on
  the axis (`BarController::_calculateBarValuePixels`, chart.js 4.5.1). The fade is the plate's: it
  reads `options.plugins.noughts.opacity` and turns each bar series' fill and stroke into one color
  per bar — Chart.js "indexable options"
  ([general/options](https://www.chartjs.org/docs/latest/general/options.html#indexable-options)).
- **`barWidth: 40.0`** — no column is drawn wider than that. Chart.js: `maxBarThickness`, "Set this
  to ensure that bars are not sized thicker than this".

### How tall a chart is

The box is 196px, the platform's, and a caller changes it only through the same custom-property
door a plate's height comes through: `{{ atlas_chart(chart, '', '', {'--chart-height': '240px'}) }}`.

Known difference, for the design side to settle: the module designs draw their chart plots at
209px (a 470×176 viewBox at the card's width); the platform's box is 196px and stays so until the
design rules one number for every chart.

### What each statement becomes in Chart.js

| Statement | Chart.js option | Documented at |
|---|---|---|
| `ChartKind::Ranked` | `options.indexAxis: 'y'`; value scale `x`, index scale `y` | [charts/bar](https://www.chartjs.org/docs/latest/charts/bar.html#horizontal-bar-chart) |
| `AxisScale(max, step)` | `options.scales.<value>.max`, `options.scales.<value>.ticks.stepSize` | [axes/cartesian/linear](https://www.chartjs.org/docs/latest/axes/cartesian/linear.html) |
| `ChartFigures(unit, precision)` | `options.plugins.figures = {unit, precision}`, `options.layout.padding`, and the plate's inline plugin `{id: 'figures', afterDatasetsDraw}` | [developers/plugins](https://www.chartjs.org/docs/latest/developers/plugins.html), [configuration/layout](https://www.chartjs.org/docs/latest/configuration/layout.html) |
| `ChartSeries(accent: true)` | `backgroundColor`/`borderColor` as `var(--acc)`, resolved by the plate like a category | [ux-chartjs `chartjs:pre-connect`](https://symfony.com/bundles/ux-chartjs/current/index.html) |
| `ChartNoughts::Hairline` | `datasets[].minBarLength: 2`, `options.plugins.noughts = {opacity: 0.28}`, and the plate's per-bar colors | [charts/bar](https://www.chartjs.org/docs/latest/charts/bar.html#dataset-properties), [general/options](https://www.chartjs.org/docs/latest/general/options.html#indexable-options) |
| `AtlasChart::$barWidth` | `datasets[].maxBarThickness` | [charts/bar](https://www.chartjs.org/docs/latest/charts/bar.html#dataset-properties) |
| `ChartLegend::Chips` / `None` | `options.plugins.legend.display: false`, plus the plate's own `.chart-legend` markup | [configuration/legend](https://www.chartjs.org/docs/latest/configuration/legend.html) |
| `ChartSeries::$cat` | `backgroundColor`/`borderColor` as `var(--cat-n)`, resolved by the plate at mount and on theme flip | [ux-chartjs `chartjs:pre-connect`](https://symfony.com/bundles/ux-chartjs/current/index.html) |

## The sparkline

A figure's recent history, drawn as a line under it. The caller hands the history — oldest
first, one per period, `null` where nobody wrote one down — and says what the movement MEANS;
where each point lands in the box is the atlas's.

```php
use Uhifadhi\Bundle\AtlasBundle\Model\SparkSize;
use Uhifadhi\Bundle\AtlasBundle\Model\SparkTone;
use Uhifadhi\Bundle\AtlasBundle\Model\Sparkline;

new Sparkline([4.0, 5.0, null, 6.0, 7.0], SparkTone::Good, SparkSize::Cell);
```

```twig
{{ atlas_sparkline(spark) }}
```

| Statement | What it draws |
|---|---|
| `SparkSize::Card` | the line under a card's figure: 100×26, stretched to the card's width |
| `SparkSize::Cell` | the line beside a matrix cell's movement: 70×18 |
| `SparkTone::Good` / `Bad` / `Flat` | the stroke, as a class — what the movement means, never which way it points |

A period nobody wrote down breaks the line into two polylines rather than dipping it to nought,
and every run is scaled against the whole history. Fewer than two readings is not a line, and
`atlas_sparkline()` then prints nothing. It is drawn on the server, on Twig alone: a handful of
coordinates needs no chart engine per cell.

## Ranked bars and the dot key

One row per thing, the value read off the end of the bar rather than off an axis. The caller
states each row's reading and words, in the order they are drawn; how long each bar is, is the
atlas's.

```php
use Uhifadhi\Bundle\AtlasBundle\Model\Bar;
use Uhifadhi\Bundle\AtlasBundle\Model\DotKey;
use Uhifadhi\Bundle\AtlasBundle\Model\KeyEntry;
use Uhifadhi\Bundle\AtlasBundle\Model\KeyMark;
use Uhifadhi\Bundle\AtlasBundle\Model\RankedBars;

new RankedBars(
    [
        new Bar('North', 31.0, rest: 4.0, figure: '31', note: '/35 · 4 vacant'),
        new Bar('South', 0.0, note: 'no position yet'),
    ],
    key: new DotKey([new KeyEntry('filled'), new KeyEntry('vacant', KeyMark::Rest)]),
    empty: 'No department yet.',
);
```

```twig
{{ atlas_bars(bars) }}
{{ atlas_key(key) }}   {# the key alone, under a matrix of dots #}
```

| Statement | What it draws |
|---|---|
| `Bar($label, $value)` | a row: the label in a 162px column, the fill in a 14px track, the figure and note in 10.5px mono |
| `Bar(rest: n)` | the two-part bar: the rest beside the fill, in the faded fail |
| `Bar(figure: '31', note: ' · 38 %')` | `<b>31</b> · 38 %` at the end of the row; the note is read as written |
| `Bar(quiet: true)` | the row dimmed; a row holding nothing is dimmed without being asked |
| `Bar(of: n)` | the row read against its own whole; without it, against the largest row |
| `RankedBars(fill: BarFill::Soft)` | the fill in the accent at 42% rather than the accent |
| `KeyEntry($label, KeyMark::…)` | a dot and a word: `Solid`, `Rest`, `Soft`, `Inherited`, `Absent`; `null` is words alone |

Drawn on the server, on Twig alone, as the design's own rows (`.sxbars > .sxbar > .l, .t, .n`);
the rules are in chart.css. A matrix cell writes the same dot as `<span class="sxdot">`, with
`inh` or `no` beside it, and the key under the matrix is `atlas_key()`.

## The heat table and its legend

A table of things against measures, each cell tinted by where its figure stands in its column,
in bands that say what it was placed among. A component of its own: its models are
`Model\Heatmap\*`, its sheet is `heat.css`, and it shares nothing with the other components
but the sparkline a figure cell draws.

```php
use Uhifadhi\Bundle\AtlasBundle\Model\Heatmap\{HeatBand, HeatCell, HeatColumn, HeatLegend, HeatLegendEntry, HeatRow, HeatTable, HeatTint};

$table = new HeatTable(
    [new HeatColumn('pace', 'Pace', unit: 'd', total: '12')],
    [new HeatBand('Org-wide', [
        new HeatRow('North', 'NO', [HeatCell::figure('4', HeatTint::Leads, unit: 'd', sort: 4.0)], url: '/d/north'),
        new HeatRow('South', 'SO', [HeatCell::blank('no figure', 'No figure to read')]),
    ], note: 'each reads every area')],
);
$legend = new HeatLegend([new HeatLegendEntry(HeatTint::Leads, 'leads the column')], 'A placing, not a verdict.');
```

```twig
<div class="hscroll" data-controller="…your sort controller…">
    {{ atlas_heatmap(table, 'Open', ux_icon('shell:chevron-right')) }}
</div>
{{ atlas_heat_legend(legend) }}
```

| Statement | What it draws |
|---|---|
| `HeatTint::Leads`…`Trails` | `.hcell.h5`…`.h1`: jade for the top of a column, amber and red for the bottom |
| `HeatTint::None` | `.h0`, dashed rather than pale: an absence, never a small figure |
| `HeatCell::figure()` | the figure, its unit, its movement toned by the column, the cell's sparkline |
| `HeatCell::marks()` | a run of state chips (`.cmark`), never placed |
| `HeatCell::blank($word)` | a dashed cell that says which absence it is |
| `HeatColumn(total: …)` | the column's own published total under its name, never summed by the page |
| `HeatBand` | the rule a placing was made inside, with its count and note |

The table and the legend are two calls because a card puts them in two places. The frame, the
sideways scroll and the sort are the caller's: the table carries what a sort controller reads
(`th.sortable[data-sort]`, `td[data-v]`, `tr.pfscope`). The open door's mark is the caller's
markup, since the atlas ships no icons.

## An area's face

The satellite snippet for a boundary's box with the boundary outlined over it — a register
card's face, a flagship's ground. Static: one keyless image and one pre-projected path, no map.

```php
use Uhifadhi\Bundle\AtlasBundle\Model\Thumbnail;

$face = Thumbnail::fromGeoJson($boundaryGeoJson);   // Thumbnail::neutral() where there is none
```

```twig
<div class="my-frame">{{ atlas_thumbnail(face) }}</div>
```

The frame is the caller's and must be positioned: the image and the outline fill it
(`.ax-sat`, `.ax-outline`, in map.css). The outline is projected into a 320×118 box
(`Thumbnail::VIEW_BOX`), longitudes compressed by the cosine of the mid-latitude and the y axis
flipped; the image is Esri's World Imagery export for the padded box. A face with no boundary
draws nothing, and the frame's own neutral ground shows.

## What a module must not do

- **Do not create a map yourself.** `new Map()` from UX Map skips the imagery, the control stack
  and the fullscreen rules, and the result looks like a different product.
- **Do not ship a map Stimulus controller.** If a plate cannot say what you need, the gap is in
  the atlas and belongs here.
- **Do not link Leaflet.** There is one on the page and the UX Map bridge brings it.
- **Do not style the plate.** `.map-plate`, `.map-body`, `.viewer`, `.map-filters`, `.map-legend` and the
  chrome classes are the atlas's vocabulary; a module that restyles them makes its own map the
  odd one out, and a module that clamps a height around one breaks its fullscreen.
- **Do not draw a chart of your own.** No `<svg>` bar, `<polyline>` or `.sxbar` row in a template, no Chart.js plugin, no chart
  options: if a design draws something `AtlasChart` cannot state, the gap is in the atlas and is
  filled here for every module at once.
