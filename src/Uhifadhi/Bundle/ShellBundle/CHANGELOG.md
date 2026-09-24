# Changelog — ShellBundle

## Contents

- [1.0.0](#100)

## 1.0.0

Not released yet.

 * THE ORGANIZATION IS NAMED IN THE TOP BAR — the brand's accent rule, the
   organization's full name, a hairline and the short name, in the bar's left
   half, on every page. The chrome said UHIFADHI and never said whose
   installation it was; it says both now, and the mark and the wordmark in the
   sidebar head are untouched. The name arrives from the organization identity
   contract through the settings section, so the bar and Settings ›
   Organization cannot disagree; an installation nobody has named draws no
   lockup rather than repeating the wordmark. A name that will not fit ends in
   an ellipsis and carries the whole of itself as its title.

   The short name falls back to the name's initials — the capitalised words'
   first letters — until somebody sets one; it is a starting guess, never what
   the setting is pinned to. The viewer chip's second line now opens with it
   ("UCA · operator"), and the browser title ends with the organization rather
   than the wordmark.

 * EVERY CONFIGURE SECTION IS ADDRESSED BY NAME in the strip —
   `…/configure/widgets` as much as `…/configure/settings`. The bare
   `…/configure` stays the Configure action's way in and opens on the first
   section.

 * THE BRANDMARK'S DEFAULT DESTINATION IS THE ORGANIZATION DASHBOARD.
   `shell.home_route` now defaults to `organization_dashboard`, the page the
   core ships at `/`; an installation that puts something else at its front
   door says so in one line, as before.

 * `/favicon.ico` IS ANSWERED, from the very file the document head already
   links, as a fourth route resource (`ShellBundle::FAVICON_ROUTES`) an
   application imports in one line. Every browser asks for that address
   whatever the head declares — before the first page, on a redirect, on an
   error page — and nothing answered: on a staging installation the only
   error group telemetry had ever captured was `NotFoundHttpException …
   /favicon.ico`, which is a log nobody reads and a real error lost in it

 * THE SETTINGS SECTION, in the area idiom: `/settings` with Installation,
   Modules and Organization as its tabs, one head on every screen and a
   subline per screen, shipped as a third route resource
   (`ShellBundle::SETTINGS_ROUTES`) an application imports in one line. Its
   door is the sidebar's last group, holding one row whose children are the
   tabs. The screens are composed from `Uhifadhi\Contracts\Settings\*`:
   figures (`shell.settings_figure`), health checks
   (`shell.settings_check`), the queue (`shell.settings_decision`), changes
   (`shell.settings_change`), and two aliases with one answer each — what
   runs where and whose installation this is. A source that throws becomes a
   row saying so rather than a 500 on the one screen that reports trouble
 * ITS FIRST SCREEN SAYS WHAT THIS INSTALLATION GIVES YOU — the welcome
   page's content, kept somewhere a reader can come back to now that `/` is
   the organization dashboard. The parts of the core are READ from each
   one's own manifest, what a module adds comes off the same matrix the
   tables do, and the set-up checklist is assembled from
   `shell.settings_step`: every row states WHERE this installation is rather
   than whether it has begun, so the page is as worth opening in year three
   as in week one
 * ONE THING THAT NEEDS SOMEBODY, as a row: `.ao-att` moves out of the area
   overview's own sheet into the frame's, because three surfaces draw one
   from three different owners' items. Nothing about the markup changed
 * a package's own one-line description is read from its manifest and drawn
   beside it, so the installation screen quotes each package rather than the
   shell describing any of them

 * THE SHELL MOUNTS A MODULE'S ORGANIZATION-LEVEL PAGES: a row in
   Observatory after Performance per module tagged `shell.org_pages`, its
   screens as the tabs under it, and the scope control (`shell.scope_source`,
   `?area=`) in the action row. `.ov-ctl` is unscoped — it is the shell's
   control, not the performance page's — and `.orgarea`, `.orgband` and
   `.lfilt-n` join the vocabulary
 * a specification that every Stimulus controller the core ships actually
   REACHES an installation: declared in its bundle's `assets/package.json`,
   its package keyworded `symfony-ux`, and named in the application's own
   manifest. Miss any of the three and the page renders, the markup carries
   `data-controller`, and nothing happens — with no error anywhere
 * `--c-failT`/`--failT` — the ink that survives on the fail fill, stated once
   because that colour is saturated in both palettes (unlike the accent, which
   is deep jade on paper and mint at night and therefore takes ink of two kinds)
 * THE PAGE HINT (ruled): a page that has something to explain says it ONCE,
   as a fragment at the BOTTOM — `@Shell/_page_hint.html.twig` and `.pghint`,
   the design's `.f-say` values. A hint above the content is read by everybody
   on every visit; one below it is read by the person still wondering. The
   conformance suite refuses a module's own copy, which is the fifth the same
   card was drawn as before the design hoisted it
 * the chart primitives (`.ch` and its axis, gridlines and tick labels) are
   the shell's now. They were on loan in a module's sheet under a name that is
   nobody's module, restated there the way `.kpi` once was
 * the quiet door (`.more`) carries NO padding on its base rule — the band's
   `9px 15px` moved to `.factband .more`, so the first bare door written
   outside a card or a band no longer renders taller and wider than its row
 * A RUNNING STATE WEARS THE ACCENT, FILLED (ruled): `.chip.run` beside the
   outline `.chip.acc`, so "in progress" stops borrowing a category token in
   one module and a module hue in another. The conformance suite refuses a
   rule whose selector names a running state and colours it with `--cat-*` or
   a `--dept-*` hue
 * THE WIDGET LIBRARY TAKES SEVERAL SURFACES: a module with two compositions —
   the roster's Overview and its Live plate rail — passes `surfaces` and gets
   one library page with a section each, every section carrying its own
   catalogue, presets, write routes and CSRF token, and an optional `anchor`
   rendered as the section's id so a door can land on one surface
   (`…/widgets#rail`). The single-surface call is unchanged; the body moved to
   `_library_surface.html.twig`
 * `initWidgetLibraries()` arms EVERY library on the page, and a surface's
   reset button is matched by `data-widget-reset="<surface>"` — arming the
   first root left a second section's cards rendered and dead, and a bare
   reset button reset whichever surface happened to be first
 * PAST NINE, A SECOND LIGHTNESS RING (ruled): `--cat-10..18` and
   `--cat-p-10..18` are the same nine hues one lightness step further from the
   ground — never a tenth hue — with their `[data-cat]` assignments and their
   plate repaint pairs, so a set of eleven gets eleven distinct marks instead
   of nine and two repeats
 * `.livedot` — the one mark the accent is reserved for, in two presentations
   of one primitive: `svg .livedot` on a plate (plate palette) and `i.livedot`
   inline in a list or a legend row (theme palette), with `.stale` for a
   position older than two ping intervals and `.none` for nobody's position.
   Reduced motion keeps the ring and drops the breathing
 * THE SIDEBAR TREE OPENS ONLY THE PATH TO THE PAGE (ruled): the shell derives
   what is open from the row a source marks `current` — the ancestor path and
   the current row's own children, one rung at a time — and `NavItem::$open`
   is ignored, so a page can no longer decide how its own sidebar reads
 * ONE GROUND PER TREE: `on` is the row the viewer is on (accent, ground, left
   focus line, once per sidebar) and `path` is every rung above it (accent ink
   only). `.nta.cur` is retired; the collapsed rail states the path as a dot on
   the section holding the current row, read off the same `path`
 * the sidebar is its own scroll region — the brand row and the foot are pinned
   outside the nav, which takes the overflow and brings the current row into
   view, so the page never scrolls to serve the sidebar
 * a fold the viewer makes is kept for the TAB'S SESSION and no longer, keyed
   by `data-nav-key` (the row's place in the tree) — `sidebar_tree` restores it
   over the derived tree and writes nothing to the account
 * a strip of figures is FOUR to a row and eight is two rows of four (ruled):
   `.kstrip` states its columns instead of folding on content width, which is
   how five came to be drawn — four plates and an orphan on a small laptop
 * a sheet that does not declare the palette may name no colour of its own —
   the fleet rule, with the shell and the atlas's ground exempt because they
   declare one
 * `--lift`, the shadow an overlay casts, beside `--scrim`
 * `--c-crit`/`--crit` and `--scrim` — the fourth state (a thing that is still
   going wrong, not a louder `fail`) and an overlay's ground, dark in both
   themes. Both were in the design's palette and in no sheet here, so modules
   were declaring one and spending a literal for the other
 * `.cal-nav .sp` — the stepper row's spacer, so a surface's own picker sits at
   the trailing end of the month's one line of chrome
 * `Contract\StylesheetSourceInterface` — a package whose COMPONENTS are drawn
   inside other people's pages publishes the sheets they need and the head
   links them, because a stylesheet link outside the head is not conforming
   HTML and a page cannot link one for a component it has never heard of
 * every caption ported from a design `font:` shorthand states its leading:
   the shorthand resets line-height to normal and the longhand port inherited
   1.5, which is where the action row's three pixels came from
 * the vocabulary conformance base also answers for `shell:` icon names: a
   mark nobody shipped was an empty box on a deployment with fetching off and
   green in every suite
 * the action row's captions keep the design's own leading (`line-height:
   normal`), which is the three pixels the control row had grown
 * a nav row may say its children are its own SCREENS rather than places
   (`NavItem::$screens`), so a section with three tabs under it draws them at
   the screen rung instead of inventing a place between the two
 * AN ORG-LEVEL SECTION GETS ITS `Configure` ACTION. The shell's own configure
   page renders a section into an AREA's frame, so a surface with no area in
   its address got no action at all and read as a place you could not set up.
   A surface that declares configure screens of its own now has its action
   open the first of them — the same control, in the same place, opening the
   same kind of screen as everywhere else.

 * THE HOUSE CREATE CARD is shell vocabulary: `.dcadd` and `.crcard` with their
   parts. Three sheets carried a copy of it and the three had drifted apart by
   a border radius and a label size.

 * TOP-LEVEL SECTION MARKS — the ranked bars, the bound a bounded card ends on,
   the attachment matrix and its dot, the doors at the foot of an overview, a
   card's footer strip and its lead, a grouped table's band row, a vocabulary
   row's quiet edit, and the strip entry for a section that is named but not
   drawn yet. A section is a house surface, so its marks are house marks.

 * THE DELTA PILL: a figure's movement against the previous period, said once
   here instead of in three sheets.

 * the document, the page frame, the navigation contracts and the theme
 * the design-system stylesheet every module's own sheet is written against
 * the widget machinery under `Widget/`: the surface registry, the stored
   layouts and the library component every dashboard is arranged through
 * the chosen chip is FILLED, not outlined (`.mchip.on`, with the grip and the
   remove cross taking the accent's own ink), and `.staddrow` — a form's
   actions as the last row of its body — is the frame's rather than the area's
 * `.btn`, `.cta` and `.tgl` share one base: one line box, one 32px minimum,
   so the three sit on one baseline whatever element each is written on
 * ONE PALETTE for every category in the product: `--cat-1..9` in both themes,
   `--cat-p-1..9` for imagery, the `[data-cat]` indirection, the `.viewer`
   repaint rules, `.catsw`, the plate tokens, and the nine `--dept-*` as
   aliases — a module writes an index and never a colour
 * `.focusline`, the one left mark a card may carry — it means focus, it is
   paint rather than box, and the conformance suite now fails a module sheet
   that draws a left rail on a card of its own
 * `.btn` and `.cta` render identically on `<a>`, `<button>` and
   `<input type="submit">` — appearance, font and line box neutralised, so a
   Discard beside a Save is not the browser's grey button (`.cta` named no
   font at all), and the duplicated `.btn` block is one block again
 * `.c > .more`, the card's one quiet door pinned to its top edge, so a module
   that draws a way out of a card does not pin it with a rule of its own
 * `.mchip.ghost`, the quiet chip a month stepper's arrows wear, and the mark's
   hue read from `--pill-hue` so a finished problem is a hollow red mark
 * no library door on a surface: `.w-addtile` is gone and the dashed add tile
   belongs to the library's composer as `.w-addwidget`, so a module that draws
   "Add widgets — open the library" at the foot of a page writes a class the
   shell does not ship and fails its vocabulary test — a page reaches its
   library through the action in its page header
 * every `<time>` on the page read in the viewer's own timezone, in the compact
   stamps the designs draw, with a conformance base a module adopts to keep an
   instant from ever being formatted server-side
