# Changelog — AreaBundle

## Contents

- [1.0.0](#100)

## 1.0.0

Not released yet.

 * the what-runs-where matrix quotes each module by the sentence it says it
   is, `ModuleProviderInterface::description()`, and by what it reads from
   where it says none, so the settings overview's "What a module adds" card
   prints what a module adds

 * THE MODULES SECTION OF THE AREA'S CONFIGURE PAGE, at
   `/areas/{uuid}/configure/modules`: a register of the catalogue as the area
   holds it — one row per module, running rows first in the area's order,
   the switch, the grip and the position, and the door to the module's own
   configure page. The module shop and its addresses are gone; the switch
   posts `…/configure/modules/{slug}/toggle` with the state it means, the
   order posts `…/configure/modules/reorder`.

 * EVERY AREA CONFIGURE SECTION ANSWERS AT `/areas/{uuid}/configure/<section>`
   — zones, stations, departments, modules, and the shell's widgets and
   settings. The per-section addresses (`…/zones/settings`,
   `…/stations/settings`, `…/settings`) are gone, not redirected.

 * A ZONE CARD OPENS IN THE BROWSER, the twin of the stations register's:
   the zones register is a native `<details>`, so a click costs no round
   trip, and `?open=` still decides which card ARRIVES open. The rename
   disclosure moved out of the card header into the body — as a `<summary>`
   the header would have held a `<details>` inside a `<details>`'s summary,
   invalid markup whose one certain behaviour is that clicking Rename
   toggles the card. An edit belongs in the open card, where the stations
   twin keeps its own.

 * THE DASHBOARD'S FIGURES STRIP IS THE MODULES', AND THE ORGANIZATION ONLY
   FILLS IT. With the roster, patrols, incidents and files all publishing,
   the host's "Areas" tile sat first and pushed "Open incidents" off the end
   of a four-wide row. Module figures lead, in their own priority; the
   organization's tile takes a slot nobody wanted and is not drawn at all
   when four modules publish; a fifth figure waits in the library rather
   than growing the row. An empty slot reads "nothing measured · no module
   publishes this".

 * WHICH MONTH A PAGE IS ABOUT COMES FROM ONE CLOCK-FED SOURCE. Six
   surfaces here each wrote `FigurePeriod::month(new \DateTimeImmutable())`
   and asked the wall clock, so each decided the period separately: correct
   on the 14th, turned over on the 1st, and two of them rendered either side
   of midnight could caption two different months in one reading. They ask
   `Uhifadhi\Contracts\Kpi\CurrentPeriodInterface` now, and the web suite's
   kernel pins its clock — so a month boundary is a thing a test can stand
   on rather than a date somebody has to change the server to reach.

 * THE ORGANIZATION SEAM IS EXERCISED BY A STAND-IN MODULE in this bundle's
   own suite: a tagged contributor whose cell is drawn from its OWN partial,
   reads its own figures under `by.<slug>`, publishes a figure into the
   four-to-a-row strip, names itself on the card and brings its own
   stylesheet. A dashboard rendered with only the host's cells proves the
   host and nothing about the seam the page exists for.

 * THE ORGANIZATION DASHBOARD IS `/`. A widget surface composed the way an
   area's overview is, one scope wider: five cells of its own — the figures
   strip, what needs a decision anywhere, the ground with everybody on it,
   the areas and what runs where — and every operational cell contributed
   through the new `uhifadhi.overview.org_widget_provider` seam
   (`Overview\OrgOverviewContributorInterface`). The five compositions are
   the design's to the twelfth and **E, everything in contributor order, is
   the one it ships on**; a composition may name a cell a module has not
   installed, and the catalogue composes it down rather than refusing it, so
   the same five designs get richer as an installation grows. Its library is
   `/widgets`, the shell's shared component on this surface's catalogue.
 * AN EMPTY DASHBOARD IS A REPORT, NOT A BROKEN PAGE. With no areas the
   figures keep their four slots and say what was not measured and why, no
   plate is drawn — a map of no areas is a map of nothing — and the hint
   carries the one door out, to what this installation gives you.
 * DASHBOARD IS THE FIRST ROW OF OBSERVATORY, and the brandmark points at it:
   `/` is a page now, and a page only reachable by clicking a logo is one
   most people never reach twice. It lights on `/` alone, because its
   address is the prefix of every other.
 * THE QUEUE READS ONE SCOPE WIDER: `AreaOverview::attentionForScope()` —
   the same per-area loop concatenated and sorted by the one rule, never a
   second aggregate. `AreaMapService::organization()` is the network map plus
   the live layer, and `AreaPresetLibrary::mapAreas()` is public so both
   surfaces turn a register row into a plate the same way.

 * A LINK THAT NAMES A CLOSED STATION SHOWS IT. The register rests on the
   active posts, so "Edit the station" on a closed post's record resolved
   the row, found it on no page and drew the register without it — a dead
   door. A link naming a row the RESTING filter hides now widens that filter
   to all for the request, and the chip says "all", so the controls never
   disagree with the rows. A filter the reader chose explicitly is never
   overruled.

 * A DEEP LINK INTO THE STATIONS REGISTER LANDS ON THE STATION IT NAMES.
   The register paginates eight cards to a page and `?open=<uuid>` only
   opened a card the FIRST page happened to hold, so from the ninth station
   on every link naming one — the record's "Edit the station", the empty
   state's "Post somebody", the redirect after a form posts — answered with
   page one and nothing open. `StationRegisterService::register()` takes the
   row the caller must see and returns the page it is actually on, worked
   out where the filtering and the ordering have just happened rather than
   in a caller that would need its own copy of all three.

 * A WATCH IS OVER WHEN THE MOMENT ASKED FOR SAYS SO, never when the server
   happens to be running. `PresenceService` read the WALL CLOCK to decide
   whether the roster had already ended an open watch, so a live plate asked
   for half past ten answered correctly all morning and emptied itself after
   the evening's rostered end had passed — a suite pinning its own clock
   failed every evening and passed on a re-run next morning. The moment is
   passed down from the caller now, and the one read with no moment of its
   own (a whole day's board) takes it from an injected `psr/clock`, once,
   so every row of one answer is judged at the same instant. The suite's
   kernel pins that clock LATE on purpose: a read that still consults it is
   wrong in every run rather than only after six.
 * A LIVE READING IS ANSWERABLE ONE SCOPE WIDER: `PresenceService::forScope()`
   and `LivePositionsInterface::forScope()` take the organization or one
   area. It is NOT a second aggregate — the wide answer walks the same
   per-area loop and concatenates it, which is asserted — and every position
   carries its own area's ping interval (`LivePosition::$pingIntervalMinutes`,
   read by `LivePresence::isStale()`), so one plate does not call a
   thirty-minute area's rangers stale beside a five-minute area's.

 * THE AREA'S THREE SET-UP STEPS, on the settings section's checklist —
   add an area, import zones, switch modules on — each stating where this
   installation is rather than whether it has begun, and each read off the
   same matrix the Installation tab's tables are.
 * A POSTING HAS A DOOR TO WHERE IT IS MADE. A post with nobody at it named
   the area's configure page and left the reader to find it; the empty state
   now opens that page on this very station, gated on `assignments.manage`
   — what posting somebody costs, not the `stations.read` that merely opens
   the page.
 * A STATION CARD OPENS IN THE BROWSER. The stations register is a native
   `<details>`: opening a card used to be a NAVIGATION (`?open=<uuid>`, a
   round trip to reveal markup the page could have carried). The whole head
   is the control, the closed summary states "N posted · post somebody
   inside", and `?open=` still decides which card ARRIVES open, so every deep
   link into a station keeps working.

 * A STATION'S CATCHMENT CAN BE SET. The read side derived "verified at a
   post" from it all along and nothing ever wrote one, so every post had no
   ring, every day claimed at one derived UNVERIFIED, and Live read "verified
   at a post 0 of 13" with people standing at theirs. Now: its own form on the
   station's Configure card (`StationService::setCatchment()`), the radius
   stated on the station record, the design's 1.5 km default on a post being
   created, and the demo posts carrying the design's own numbers. Null is
   still a real state — a post with no ring has no inside
 * `GET /api/areas/mine` says WHERE THE PERSON WORKS: `postedAreaId` (the area
   of their standing posting, null where they stand nowhere) and `posted: true`
   on that area's entry, so a phone opens the right ground instead of the first
   name in the alphabet. It is a pointer into the payload — somebody posted
   into ground they may not view gets null, because a posting is not a grant
 * `.tav` and `.stsep` are the design's, value for value: the avatar takes
   its accent-tinted ground and edge (it was a plain raised panel, so a stack
   read as a row of empty chips), `.tav.sm` is actually smaller than the base
   (it was the same 22px, a class that did nothing), the stack's separating
   ring is the CARD's ground rather than the canvas's, and the module
   separator's weights and colours match the drawn ones
 * the shipped demo content posts each person ONCE. It walked round the roster
   so that the same ranger stood at two gates, which the posting rule now
   refuses — the seeder was the first thing to hit that refusal, and it hit it
   in somebody else's pipeline. Posts past the end of the roster stand empty,
   which is the honest reading of an installation with more posts than people
 * `GET /api/areas/mine` carries THE AREA'S POSTS, in the same shape
   `/stations?near=` hands them over (uuid · name · code · lat · lon ·
   catchment). It was published empty while the platform had no station
   record; a phone whose cache said "no posts" had to be online to learn the
   names of the posts it works at, which is the one thing that endpoint exists
   to prevent
 * a zone name that arrives ENTIRELY UPPER CASE is title-cased at import, and
   a name with one lower-case letter in it is left exactly as it arrived: a
   GIS export shouts because its tool writes that way, not because anybody
   decided the zone is called CRATER — and a corrected name that is now wrong
   is worse than a shouting one. Where a name WAS retitled the import preview
   says so beside it ("Crater · file said CRATER"), which is the one place
   somebody can still object before anything is written
 * ONE POSTING A PERSON (ruled): one station, one area. `PostingService::post()`
   refuses somebody who already stands anywhere and names where, so moving
   them is two acts — end the posting they have, make the one they are going
   to — which is also what leaves last year's patrol with a crew
 * FOUR FIGURES TO A ROW, never five (ruled): the area overview's right-now
   strip is three module tiles and the attention count, and the zones and
   stations registers drop the count of the thing they list — the band above
   each already says it and the register below is the list
 * a zone's category wraps at EIGHTEEN, not nine: Kilimani Crater's eleven zones
   now draw eleven distinct marks, the last two reading as kin to the first
   two rather than as duplicates of them
 * every colour is gone from this bundle: `ZonePalette` publishes a CATEGORY
   (its position in the register's order, wrapping at nine) instead of eleven
   hexes of its own, the zone dots and station rows carry `data-cat`, and the
   plate names `PlatePalette::category()`. A zone is one of a set, and the set
   is the product's nine
 * **DEPRECATED, removed next release** — `ZoneRow::$hue`, `ZoneListRow::$hue`,
   `StationRow::$zoneHue` and `FilterOption::$hue`. Each resolves from the
   category and returns the token the palette would have given it; read `$cat`
   / `$zoneCat` instead. Two releases rather than one, because a shipped module
   reading a property that vanished is a 500 on somebody else's page
 * the station and zone captions take the design's own sizes as well as its
   leading (`.pnone`, `.zhwhen`, `.rb-ttl`, `.tav`, `.stsrc`, `.stwhen`,
   `.stsep`) — `.stsrc` had lost the mono voice and the uppercase entirely
 * a zone record's band is a LINE again: ONE fact a module (its first
   published figure, the module's own name on it, the period in three
   letters), and one door a module in the header. It took every figure every
   module published — twelve facts over four rows, each captioned with a
   sentence, and "See incidents" four times in the header
 * `/me/roster` says whether the person is `rostered` at all — a rest day and an
   unrostered ranger both answer with no watch, and a handset has to draw them
   differently; false where there is no roster module, because a platform that
   plans nobody may not stop anybody working
 * `/stations` sends each post's `code` — what a ranger says on the radio, which
   is what the picker and the confirm screen print beside the name
 * the area answers `LivePositionsInterface`: the latest fix of everybody on an
   open watch, with the distance to the post measured by PostGIS rather than
   from degrees in PHP — a module draws "where everybody is" without reading
   `duty_position` across a package boundary or deriving a second answer to
   "at post, verified"
 * the station and zone captions keep the leading their design shorthands set
   (`.fldlab`, `.stcardlead`, `.stcode`, `.stghm`, `.stnone`, `.stpin`)
 * the areas row wears the house MAP mark, as the design draws it, and the
   areas under it wear none: a glyph repeated down a branch reads as a second
   kind of thing rather than the same thing twice
 * the area answers `Area\StationDirectoryInterface`: every station and who
   stands at each, across every area at once, in two queries. Every per-area
   read it already published is scoped to one area, and a reader who has to
   open four areas to count the postings cannot count them at all.

 * **BREAKING for an installation** — the spatial base and the geometry types
   now come from `utafitilabs/postgis-bundle` instead of
   `fundistadi/postgis-bundle`. An installation replaces the bundle class in
   `config/bundles.php` with `UtafitiLabs\PostGISBundle\UtafitiLabsPostGISBundle`
   and renames the configuration root key `fundi_stadi_post_gis` to
   `utafiti_labs_post_gis`; both packages register the same DBAL type names, so
   the old one has to be removed, not merely superseded
 * the area: its identity, its gazetted facts and its boundary as a PostGIS
   multipolygon, with the zones inside it held to a no-shared-interior invariant
 * a whole zoning scheme from one GeoJSON FeatureCollection: names read from
   whichever property the export used, altitudes and every other property read
   past and then named in the summary, and a refusal that names the offending
   zone for a projected coordinate system, an overlap, a polygon outside the
   area boundary, a duplicate name or a missing geometry
 * the provenance of an imported scheme — the file's name, the moment, the
   person, the count and the property the names came out of — stored beside the
   geometry, while the uploaded file itself is read and let go
 * the day a ranger reports: the check-in a handset claims, the words an area
   lets them claim it with, the corrections appended to it and the duty pings —
   with `verified` and `unverified` derived on every read and never stored
 * the duty endpoints a handset writes through and the two reads its Duty tab
   lives on — the month, the words the area publishes and the ping interval it
   set, and the posts with the catchment each of them carries
 * the station sections a module contributes to a post's record and its
   configure card
 * the answer to the platform's area contract, so nothing has to be written by
   hand to point a module's record at an area
 * the area overview, composed from what the modules an area runs contribute:
   now-tiles, attention items, map layers, pulse events and copy
 * the areas register, the area's own map plate, and the widget surface behind
   the landing page's layouts
 * `GET /api/areas/mine`: the areas an account may work in, with their size,
   roster and simplified boundary — the cache a field client fills at sign-in
 * the Areas section in the sidebar and the tab strip above every area page
