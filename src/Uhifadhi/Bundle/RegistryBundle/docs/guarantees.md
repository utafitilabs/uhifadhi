# What it guarantees

## Contents

- [The behaviour table](#the-behaviour-table)

## The behaviour table

Every row below is a test, and the table is the order they were written in:

| # | Behaviour | Where |
|---|---|---|
| 1 | A module whose provider carries the `uhifadhi.module` tag appears in the catalogue; the trait defaults (`base()` = false, live, unpinned, generic page, no permissions) hold all the way through | `Integration/Module` (the registry half) + `Integration/Catalogue/ModuleCatalogueTest` |
| 2 | Zero modules: the catalogue boots, is empty, and the sync succeeds writing nothing | `Integration/EmptyCatalogueTest` + `Integration/Catalogue/ModuleCatalogueTest` |
| 3 | Per-area install/uninstall flips state for that area alone; `base()` reconciles active and installable reconciles parked; a pinned module can never be switched off; uninstalling removes the module's presence from the area's ledger immediately, and keeps its data | `Integration/Area/AreaModuleInstallationTest` |
| 4 | Category and status are coerced, never trusted; an unknown category falls back to **Operations** | `Unit/Catalogue/ProviderCatalogueMapperTest` |
| 5 | The sync is idempotent and **create-only** for per-area rows: a deploy never overrules an admin's on/off or ordering, and never deletes an uninstalled module's history | `Integration/Sync/RegistrySyncTest` |
| 7 | Boundaries: no module named in `src/`, no host namespace, no templates, no controllers, no routes | `Unit/BoundaryTest` |
| 8 | A module's entry route is read live from its provider, never from a stored column | `Integration/Routing/ModuleEntryRouteResolverTest` |
| 9 | The catalogue tables keep their production names; the area association is resolved to a real entity | `Integration/Area/CataloguePersistenceTest` |
| 10 | Installability: the registry alone boots without an area and cannot be schema'd without one; the migration tool arrives with the bundle, and so does the version that creates its two tables | `Integration/InstallabilityTest` |
| 11 | The registry answers its own area contract for nobody — it yields, which is what lets an answer-module state the resolution and an installation write no doctrine line | `Integration/InstallabilityTest` |
| 13 | `registry:sync` reconciles the catalogue and reports what it added, kept and retired; typed before the first migration it exits non-zero naming `doctrine:migrations:migrate`; typed again it changes nothing; a web request reconciles nothing and opens no connection; the cache commands read no database | `Integration/Sync/RegistrySyncCommandTest` + `Integration/Sync/RequestReconcilesNothingTest` + `Integration/Sync/PristineCacheWarmUpTest` |
| 12 | A module an area has parked — or never took — answers **404** on its own routes, before its controller runs; an active module is untouched, a non-module route costs nothing, and the area's own screens on the same path shape stay open | `Integration/Routing/ParkedModuleRouteTest` |

## Parking closes the routes

Parking a module is a decision, not a tidier menu: where an area has parked a
module, every one of that module's pages answers **404**, and it answers before
the module's controller is asked anything.

**404, not 403.** A 403 confirms the thing exists and is being withheld; parking
withholds nothing. The module is not part of this area — which is exactly what
the area's own screens already say, with the module sitting in the shop rather
than the sub-nav. The URL now agrees with them.

**One enforcement point, no per-module code.** The registry owns the per-area
ledger, so the registry is the only thing that can answer "is this switched on
here". A check written into each module is a check each module can forget, and
the ones likeliest to forget it are the ones written after everybody stopped
thinking about it.

**How a request is recognised as a module's**, in this order:

1. **The marker** — the route default `_uhifadhi_module: <slug>`
   (`RegistryBundle::MODULE_ROUTE_DEFAULT`), which a module writes once per
   controller. Precise, self-declaring, works wherever the route lives, and
   costs an array lookup. A route that carries its area's uuid in a parameter
   not called `uuid` names that parameter with
   `_uhifadhi_module_area: <parameter>`.
2. **The fleet's path shape** — `/areas/{uuid}/modules/{slug}/…` — as the safety
   net under a module that has not added its line, and **only when the segment
   names a module the catalogue actually has**. That last clause is not a
   detail: an installation may mount a screen of its own under the same shape,
   and a gate that read the shape alone would close it.

**What it costs.** One indexed row read (`area_module` joined to `module` and
the area, `LIMIT 1`) per recognised request. A request with the marker pays only
that; a request without one that wears the path shape pays the catalogue read as
well, which is the price of the safety net and the reason the marker is the
recommended line. Every other request — every asset, every page outside an area
— exits on an anchored string comparison. Nothing is cached, deliberately: see
the attention-list promise below.

**What it does not do.** It does not preempt anything else. An area that does
not exist, a page whose own entity is missing and a caller without permission
are all still answered where they were answered before. It runs on main requests
only — what was ruled is that a parked module must not be reachable by URL, and
a sub-request is a render the application asked for itself. And where an
installation resolves the area contract to a class with no uuid, the gate cannot
read a URL and stands aside rather than guessing.

**An area created between two syncs holds no rows at all**, so every module
reads as absent there — in the shop on its screens, and 404 on its URLs. One
click on the customize screen, or the next `the registry sync`, gives it rows.

## The attention-list promise

Every contribution a module makes to a shared surface — an attention item, a
now-tile, a map layer, a KPI — is keyed by its module slug, and the promise the
platform makes to an area manager is that switching a module off takes its
contributions off the page **the same day**, not on the next deploy or after a
cache warm.

The registry cannot test the attention list itself: it draws nothing and knows no
module. What it can pin is what that list is derived from — the moment a module
is uninstalled for an area, that area's ledger stops counting it present and
starts counting it absent, read from the database, with no interval in between.
Anything that caches that reading breaks the promise, and the test is where it
would show.
