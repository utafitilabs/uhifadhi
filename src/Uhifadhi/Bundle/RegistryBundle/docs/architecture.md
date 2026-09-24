# Architecture

What the registry owns, what a fresh installation looks like with nothing on it,
and where each piece lives in the bundle.

## Contents

- [What it owns](#what-it-owns)
- [What is here](#what-is-here)

## What it owns

**The registry carries; it does not show.** It owns five things and no more:

- **The catalogue** — what modules exist in this deployment. Not a list anyone
  edits: a module is in the catalogue because it is installed and its provider
  carries the `uhifadhi.module` tag.
- **Per-area install state** — which modules an area has switched on, in which
  order. Deliberately a different table from the catalogue: a deployment can
  have a module that only one of its areas wants.
- **Declared permissions** — the granular permissions modules declare, gathered
  for the host to fold into its matrix. Declared, never granted.
- **The sync** — the reconciliation that brings the catalogue into step with what
  is installed, without ever overruling an admin. `registry:sync` runs it and
  reports what it did; it is typed after `doctrine:migrations:migrate` and before
  `cache:warmup`, and never runs on a request, so serving a page opens the
  registry no connection of its own.
- **The route gate** — where an area has parked a module, that module's routes
  answer 404 there. One enforcement point, no per-module code, and nothing
  rendered: see `docs/guarantees.md` for how a request is recognised and
  `docs/boundaries.md` for why closing a route is not drawing one.

**Zero modules is a working installation.** A fresh installation with the registry
on it boots, has an empty catalogue, and is harmless. A runtime that only
functions once somebody installs a module has a hidden dependency on its own
modules.

**It knows no module by name.** Not one — not even the pinned hub every
installation has. A module is whatever tagged itself, and everything the registry
treats specially (`pinned`, `base`) is a flag the provider declares, never a
slug the runtime recognises. A test sweeps `src/` for that property.

## Two tiers: infrastructure and capability

Everything the registry catalogues is a **capability** module — patrol, incident —
the per-area grid an admin governs: switched on and off per area, arriving
default off, and written as an `area_module` row. That is the only tier the registry
sees, and it is right for a capability an area may not want.

The fleet's other tier never reaches the registry at all. An **infrastructure**
module — map, widget, storage, area, team — is machinery a screen already relies
on: installed means on, everywhere, and there is no honest per-area choice to
offer for something whose absence breaks screens rather than removing features.
An infrastructure module therefore carries **no** `uhifadhi.module` provider. It
is guaranteed present by the composer graph (AreaBundle hard-requires map, for
one), not by a ledger row, so it appears in **no** catalogue, in **no** per-area
grid, and in **no** `area_module` row. The distinction is a property of the
bundle — whether it tags a provider — not a flag the registry reads; the registry simply
never learns such a module exists, which is exactly the point.

> This replaces the earlier `base()` treatment of map. `base()` still exists and
> still means "reconciled active rather than parked" for a capability module that
> genuinely is one; it is not how infrastructure is expressed. "On by default in
> the ledger" and "not in the ledger at all" are different claims, and for
> machinery every map-bearing screen imports, the honest claim is the latter.

**Upgrade note — a stale ledger row is inert, not a bug.** A deployment
reconciled before this ruling, while map was a `base()` provider, holds an active
`area_module` row for map in every area, and a `module` catalogue row too.
Nothing cleans either up and nothing needs to. The sync is create-only: it never
deletes an uninstalled module's rows and never revisits a per-area row it already
wrote. What retires map is not deletion but the catalogue itself — `ModuleCatalogue`
is the *intersection* of the registered providers and the table, so with map's
provider gone it drops out of `all()` and `find()` returns null for it, exactly
as if the row were absent. Every offer is drawn from that catalogue, so the
Customize grid no longer lists map: there is no toggle to switch it on and none
to park it. `AreaModuleService::install()` is unreachable for map anyway — with
no route to it, nothing calls it — and were it called for a fresh slug the
catalogue miss returns null. Map also has no routes, so requests to it 404 for
want of a route, never through the parked-module gate.

The one visible residue is that raw per-area "what's on" reads
(`AreaModuleService::activeFor()`, `AreaModuleLedger.installed`) are *not*
intersected with the catalogue, so a still-active map row can linger in those
lists until it is cleaned up out of band. It carries no behaviour — it is a name
in a list, nothing more.

## What is here

| Piece | File |
|---|---|
| The Symfony plug, and the `uhifadhi.module` tag | `RegistryBundle.php` |
| Config tree (`registry:`) | `DependencyInjection/RegistryConfiguration.php` |
| Static service wiring, and the published ids | `config/services.php` |
| The area contract, which a bundle answers | `Uhifadhi\Contracts\Entity\AreaInterface` |
| The catalogue and the per-area ledger | `Entity/`, `Repository/` |
| The runtime | `Service/` |
| The route gate, applied to every request | `EventListener/ParkedModuleListener.php` |
| The create-only sync, and the command that runs and reports it | `Service/RegistrySyncService.php`, `Command/RegistrySyncCommand.php` |
| Test host app | `tests/Integration/TestKernel.php` |
| A host, minimally: area entity + resolved target entity | `tests/Integration/Fixtures/HostKernel.php` |

The bundle maps its own entity directory, so a host never needs to write a
doctrine mappings block for the catalogue tables.
