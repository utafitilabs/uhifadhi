# `ModuleProviderInterface`

The interface a bundle implements to declare itself a uhifadhi module, and the one tier
distinction the platform draws between modules.

## Contents

- [What the interface asks for](#what-the-interface-asks-for)
- [The one sentence that says what the module is](#the-one-sentence-that-says-what-the-module-is)
- [First, the tier: capability or infrastructure](#first-the-tier-capability-or-infrastructure)
- [Installable and base (capability modules)](#installable-and-base-capability-modules)
- [Why "base" and not "core"](#why-base-and-not-core)

## What the interface asks for

Catalogue metadata (`slug`, `name`, `description`, `category`, `status`, `dataSource`, `pinned`,
`position`, `icon`, `base`) plus the one capability beyond the legacy catalogue: **`entryRoute()`** — return `null`
to render through the host's generic module page (what every built-in does today), or a route
name to own your pages (the host links with the area's uuid).

One implementation = one module (the per-area capability shown in an area's module grid). By
convention a bundle provides exactly one module named after itself; whatever lives *inside* a
module (a sightings module's "surveys", for instance) is the module's own internal concern and never
appears in this contract.

```php
// src/Module/SightingsModuleProvider.php (your bundle)
use Uhifadhi\Contracts\ModuleProviderInterface;
use Uhifadhi\Contracts\ModuleProviderTrait;

final class SightingsModuleProvider implements ModuleProviderInterface
{
    use ModuleProviderTrait; // defaults for the optional methods

    public function slug(): string     { return 'sightings'; }
    public function name(): string     { return 'Sightings'; }
    public function description(): ?string { return 'Every animal seen in the field, and where.'; }
    public function category(): string { return 'biodiversity'; }
    public function entryRoute(): ?string { return 'sightings_area'; }
}
```

The host autoconfigures every implementation (tag `uhifadhi.module`) and RegistryBundle's registry
sync ingests them on the next cache warm-up. A module shipped as a *reusable bundle* is **not**
autoconfigured — tag `uhifadhi.module` explicitly in your extension.

## The one sentence that says what the module is

`description()` is what the module is, in one sentence a stranger understands: somebody who has
never used the module reads it and knows what switching it on gives an area. Write it in plain
words, for that reader, not for the module's own team:

| Reads well | Reads badly |
|---|---|
| Ranger patrols: tracks, observations and station duty. | Patrol module. |
| Every photograph, document and track the area holds. | S3-backed file storage with retention policies. |
| Who is on duty, where, and who checked in. | Roster v2 (see docs). |

- **One sentence, at most 160 characters**, ending with a full stop. The catalogue row keeps it in a
  column of that length.
- **Not the name again.** The name stands beside it; a line that only repeats the name is no line.
- **Not the data source.** `dataSource()` says what the module reads from; the description says
  what it is.
- **Null is allowed** and is what `ModuleProviderTrait` answers. A module that returns null says
  nothing beyond its name, and no screen draws an empty line for it.

`registry:sync` keeps the sentence on the module's catalogue row and refreshes it on every run, so
a reworded sentence replaces the old one and a removed one is cleared. Two screens print it: the
area's **Configure › Modules** section, under the module's name in its row, and the settings
overview's **What a module adds** card, which falls back to `dataSource()` for a module that
returns null. The tile and the sidebar never print it.

## First, the tier: capability or infrastructure

A provider is how a **capability** module (patrol, incident) joins the platform — a capability an
area may not want, so an admin governs it per area from a catalogue tile. **Infrastructure** is the
other tier: machinery every relevant screen already relies on, installed-means-on everywhere, never
a per-area choice. The core's five bundles (RegistryBundle, ShellBundle, TeamBundle, AreaBundle,
AtlasBundle) are infrastructure by construction, and a module can be infrastructure too — storage
is. Infrastructure contributes **no** provider — nothing to catalogue, nothing to ledger — and is
guaranteed present by the composer graph instead. So if you are reading this to write a provider,
you are (almost certainly) writing a capability module. The full test is in
[module-development.md](module-development.md#two-tiers-infrastructure-and-capability).

## Installable and base (capability modules)

Within the capability tier, `base()` decides the initial per-area state. An **installable** module
(`base()` is `false`, the default) is synced into the catalogue *parked*: installed, but switched on
per area by an admin. A **ships-on** module (`base()` is `true`) is synced *active* in every area.

`base()` means "on by default", not "cannot be turned off": the host's Customize page still governs
an area's modules, and this is a distinction *among capability modules only* — it is not how
infrastructure is expressed, because infrastructure has no provider to call it on. Maps are the
clearest illustration: AtlasBundle draws on patrol plates, incident plates, the area overview and
the zones editor, so it is infrastructure and ships no provider at all rather than a provider
returning `base()` of `true`.

## Why "base" and not "core"

The word is **base**. "Core" marks a thing as important without saying what it is, and here the word
is already taken twice over: the runtime at the centre is the **core** package itself
(`uhifadhi/uhifadhi`, whose RegistryBundle your provider registers with), so reusing it for the
always-present tier of ordinary modules would make "a core module" mean two different things in one
sentence. "Base" also names the real test — a base module is one whose absence makes the
installation *not uhifadhi*.
