# Changelog — AtlasBundle

## Contents

- [1.0.0](#100)

## 1.0.0

Not released yet.

 * EVERY CHART WEARS THE HOUSE'S TICKS: tick labels in the mono face at 6.7px in `--fog`, the
   value axis's grid in the fog at 22% and 0.6px, the index axis's line in the fog at 55% and
   0.8px, and no tooltips — stated by `ChartBuilder` as tokens and resolved by the chart plate
   at mount and on a theme flip, which now re-reads the tokens from a copy of the stated
   configuration. `AtlasChart::$barRadius` rounds every bar (`borderRadius`, `borderSkipped:
   false`), and a nought's stub is rounded at 1. Documented in docs/components.md, "The bar
   radius" and "Ticks, grid and axis line"

 * AN AREA'S FACE IS AN ATLAS COMPONENT: `atlas_thumbnail(Thumbnail)` draws the satellite
   snippet for the boundary's box and the boundary outline over it, filling the caller's
   frame; `Model\Thumbnail` projects the outline and builds the image address, and `.ax-sat`
   and `.ax-outline` are map.css's. Documented in docs/components.md, "An area's face"

 * THE HEAT TABLE IS AN ATLAS COMPONENT: `atlas_heatmap(HeatTable, openLabel, openMark)` draws
   the sortable head with each column's published total, the band rules, a row per thing and a
   heat cell per column — five tints and the dashed absence, a figure with its movement and
   sparkline, a run of state chips, or a blank in words — and `atlas_heat_legend(HeatLegend)`
   the legend under it. Its models are `Model\Heatmap\*` and its rules are its own sheet,
   `heat.css` (`AtlasBundle::HEAT_STYLESHEET`), published to every head beside the other three.
   Documented in docs/components.md, "The heat table and its legend"

 * RANKED BARS ARE AN ATLAS COMPONENT: `atlas_bars(RankedBars)` draws the design's rows — a
   162px label, a 14px track, the bold figure and its note, a quiet row dimmed, the optional
   rest beside the fill and the dot key under them — with every width worked out by
   `RankedBars::rows()` against the largest row or a row's own `of`. `atlas_key(DotKey)` draws
   the key alone, for a matrix of dots. `.sxbars`, `.sxbar`, `.sxdot` and `.sxmxkey` are
   chart.css's, and the key's dots wear classes (`.sxdot.v`, `.sxdot.b`) rather than a colour
   on the page. Server-drawn, on Twig alone. Documented in docs/components.md, "Ranked bars
   and the dot key"
 * THE LIVE MARKS KEEP MOVING: `AtlasMap::liveStream(LiveStream)` hands the
   plate a Mercure hub's public address and the topics to hold open
   (`extra.atlas.live`), and the plate opens one credentialed `EventSource`
   on them; each frame — `LiveMarks::frame()`, the live layer's own feature
   with `at` and `staleAfterSeconds` added, or `LiveMarks::gone()` — moves,
   adds or removes one mark and recounts the legend; the age labels and the
   stale state are re-read every thirty seconds while a stream is open. A
   key row in the legend wears `data-atlas-shape`. Without a stream the plate
   reads as the page drew it. Documented in docs/components.md, "The live
   stream"

 * THE AREA'S GROUND IS AN ATLAS COMPONENT: `AtlasMap::ground(Ground)` takes
   the boundary geometry and the zone geometries (`Ground::fromGeoJson()` for
   the text a geometry column returns) and draws them the one way: the
   boundary with its scrim and a "Boundary" row, the zones as one quiet line
   layer (`area.zones`, `PlatePalette::DIM`, each zone wearing its name)
   listed under every other layer, and a "Zones · N" row that is there at
   nought — both rows first in the legend under "The area". The atlas reads
   no database. Documented in docs/components.md, "The ground"

 * A COLUMN CHART CAN MEASURE ONE THING THAT IS NO CATEGORY: `ChartSeries(accent: true)`
   wears the house accent and its chip is the accent pill; `ChartNoughts::Hairline` keeps a
   nought's column as a two-pixel stub on the axis (`minBarLength`) that the plate fades to .28
   per bar; `AtlasChart::$barWidth` caps a column's width (`maxBarThickness`). Documented in
   docs/components.md, "The accent, the nought and the column width"

 * A FIGURE'S HISTORY IS AN ATLAS COMPONENT: `atlas_sparkline(Sparkline)`
   draws the line under a KPI or topic card's figure (`SparkSize::Card`,
   100×26) and beside a matrix cell's movement (`SparkSize::Cell`, 70×18) —
   one polyline per unbroken run, a period nobody wrote down a break rather
   than a dip, the tone (`SparkTone`: good, bad, flat) a class the chart sheet
   paints. Server-drawn, on Twig alone. The rules are in chart.css, which
   reaches every head. Documented in docs/components.md, "The sparkline"

 * A CHART CAN BE A RANKING, FIGURED, SCALED AND KEYED IN CHIPS — the four
   things a module's design drew that `atlas_chart()` could not: `ChartKind::Ranked`
   (bars sideways, the name on the left — Chart.js `indexAxis: 'y'`),
   `AtlasChart::$figures` (`ChartFigures`: the figure past every bar's end,
   drawn by an inline plugin the plate puts on in `chartjs:pre-connect`, no
   dependency added to a host), `AtlasChart::$axis` (`AxisScale`: the value
   scale's `max` and `ticks.stepSize`, with `AxisScale::covering()` for the
   smallest covering multiple), and `AtlasChart::$legend` (`ChartLegend::Chips`:
   the house pills under the box through the shell's `data-cat` door, the
   library's legend off). Documented in docs/components.md, "Charts"

 * A ZONE LABEL FOLLOWS ITS PLATE WHEN THE PLATE CHANGES SIZE. A label is a
   permanent tooltip, and Leaflet re-places one only on `zoom` and
   `viewreset` — neither of which the plate's own catching-up fires, since
   `invalidateSize({pan: false})` fires `resize` and the `fitBounds` after it
   usually lands on the zoom and centre the map was already at. On a plate
   composed onto a widget grid that narrows it after the map is built, the
   labels stayed at the pixels of the wider frame, which put them at negative
   x — outside the plate — until the page was reloaded. `refit()` now asks
   every tooltip to update itself on each of its three paths out, which
   covers fullscreen and swap too because `refit()` is the one funnel every
   re-frame goes through

 * a live position's breathing ring is no longer CLIPPED: the marker's box is
   sized by the ring at full breath (2.05 × the radius plus half its stroke)
   rather than by the dot, so the pulse is round instead of a square-ish
   flicker. The box passes pointer events through and the dot takes them back,
   so a marker four times the size of its mark does not steal the cursor from
   the map under it
 * A CHART SERIES IS A CATEGORY, NOT A COLOUR: `ChartSeries::$cat` (1-18),
   resolved where the chart is drawn by the new `chart-plate` controller — at
   mount and again when the theme flips, the same door the map plate's layers
   go through. `ChartBuilder`'s six hex colours are gone; `$swatch` is
   deprecated and honoured for one release
 * A LINK CHANGES THE PLATE WITHOUT NAVIGATING, wherever it sits and whether
   or not the plate is fullscreen: `data-atlas-swap` on a same-origin link
   swaps the plate's subtrees out of the fetched page, and
   `data-atlas-swap-also="#selector"` brings one region beside it across so a
   caller's own marked row moves with the map. The `href` stays the fallback,
   history is pushed, and the server still computes the focus — a module
   writes an attribute and no JavaScript
 * WHERE PEOPLE ARE, ON THE PLATE: `AtlasMap::livePositions()` draws a
   `LivePresence` as the shell's live dot — one marker a position, stale where
   the contract says stale, nothing at all for a person with no fix — and adds
   the key the map-legend contract requires ("On the plate": the live position,
   the stale one, and the people who are on no ground at all)
 * `LayerShape::Live`/`LiveStale`/`LiveAbsent`, one mark in three states, and a
   legend row whose swatch is a drawing rather than a colour
 * a plate's legend comes out IN THE ORDER IT WAS BUILT — a layer's own row
   where the layer was added, a stated key row where it was stated. Sorting
   the layer rows above the stated ones put a caller's own mark in the middle
   of somebody else's key
 * a calendar pill's dot is painted with a COLOUR token and not a channel one
   — the roles mapped to `--c-acc`/`--c-ok`/…, which resolve to a bare `62 217
   168` in a `background` and painted nothing at all, so every pill dot on
   every calendar drew empty while the markup was exactly right
 * the plate RESOLVES a token swatch where it draws — `var(--plate-ok)` handed
   to Leaflet painted nothing at all, so a legend read right while the map drew
   empty — and resolves it again when the theme flips
 * `atlas_calendar()` takes the surface's own control, drawn at the trailing
   end of the stepper row: a month is ONE line of chrome, and a component with
   nowhere to put a ranger picker made every caller draw a second toolbar
 * the month and the chart get sheets of their own, `bundles/atlas/calendar.css`
   and `bundles/atlas/chart.css`, published to the shell so they reach every
   head: both are drawn INSIDE somebody else's page, which cannot link a sheet
   for a component it has never heard of — the roster's Calendar tab drew a
   month as a list of days, and no test in the fleet could see it. The map's
   sheet stays the one a page links for itself, because a page that draws a map
   knows it does

 * the month grid as a component, the plate's and the chart's third sibling:
   `atlas_calendar(feed, '2026-09')` draws the grid, the day heads, the cells
   at one fixed height, the day numbers, the "+N more" and the stepper, and a
   module implements `Atlas\CalendarFeedInterface` to say what happened on
   which day and what each thing should read as

 * the map builder and the map model: GeoJSON layers, the boundary, the base
   layers, the legend, fullscreen — a UX Map map with the atlas around it
 * render_map(): the plate, its filter row and its legend, rendered through the
   configured UX Map renderer
 * one Stimulus controller for every map in the product, driven by UX Map's own
   pre-connect and connect events
 * the basemap contract: a configurable satellite provider, published to the
   browser as one attribute
 * the boundary treatment and the map chrome every map in the product wears
 * the plate stylesheet, which owns the fullscreen layout so a consumer cannot
   break it
 * per-feature styling, declared: a layer's base `LayerStyle` and `StyleRule`s
   keyed on the features' own properties — stroke colour, width and opacity,
   fill on/off/colour/opacity, dash array, point radius and z-index — which is
   what replaced the `style(feature)` callback a module used to write in
   JavaScript
 * a layer states the property a hover reads (`tooltip`) and the properties a
   click opens (`popup`, a `FeaturePopup`); the plate writes the markup and
   escapes the values
 * the spotlight: any element carrying
   `data-atlas-highlight="<layer>:<featureId>"` lifts that feature and pushes
   its siblings back, wired by the plate by delegation — no module JavaScript
 * a plate is as tall as it says it is and never as tall as the row it sits in:
   one custom property, `--map-plate-height`, which a card sets or `render_map()`
   is handed — and what it sizes is the MAP BODY (the filter row and the imagery),
   with the legend adding its own height below it
 * the legend is drawn BELOW the map, as the design's `.maplegend` row under a
   `.viewer`, and floats over the imagery's bottom-right corner in fullscreen
   only — a floating legend covered the ground it described on a short plate
 * removed the `.viewer .ol` caption rule, which no template writes
