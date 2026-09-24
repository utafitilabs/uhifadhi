# The architecture

**Uhifadhi is one skeleton and a set of modules.**
[`uhifadhi/uhifadhi`](https://github.com/utafitilabs/uhifadhi) is the project
skeleton — copied once, never updated; everything else arrives as a module,
updated forever. A module **registers with the registry**
([`RegistryBundle`](../../../RegistryBundle/README.md)) and
**renders in the shell**
(`ShellBundle`).

The registry carries the modules, the shell is what you see — and this is **how a
module's screen arranges itself**.

## Contents

- [How modules connect](#how-modules-connect)
- [Widgets are module-specific; machinery is not](#widgets-are-module-specific-machinery-is-not)
- [Who depends on it](#who-depends-on-it)
- [Uninstall: prune, not purge](#uninstall-prune-not-purge)
- [What it owns](#what-it-owns)

## How modules connect

A dashboard takes three things, and they are owned by three different places.

**1. A module DECLARES.** It writes a class implementing
`Uhifadhi\Bundle\ShellBundle\Widget\Registry\WidgetSurfaceInterface` — a catalogue naming its
widgets, the headed sections its library files them under, and the whole layouts
a person may adopt in a click — and tags the service. That is the same
plug-point pattern a module registers everything else through: a published
interface, a tag, and nothing that has to be edited on the other side.

**2. The shell COLLECTS AND STORES.** The registry reads every tagged
declaration live from the container, so an installation's set of dashboards is
exactly the set of modules it has. The two tables hold what each person adopted
— which preset is on, and the layout it produced. The resolver merges a
catalogue with a stored row and hands back the layout a page renders.

**3. A shared socket RENDERS.** The library's templates ship here, so every
dashboard in an installation is arranged through the same screen. A module's
page includes `@Shell/widget/_library.html.twig` with its own
catalogue and its own routes and gets the whole component.

The split earns itself twice over. **This is machinery every module with a
dashboard would otherwise have to write** — the merge, the clamping, the
validation of a layout that arrived from a browser, the drag-and-drop, the
preset strip — and machinery written five times is machinery that behaves five
ways. And **preferences belong in one table, not one per module**: a person's
dashboards are one kind of thing, and a schema that grew a preferences table per
module would be answering "what has this person arranged?" with a join across
however many modules happen to be installed.

## Widgets are module-specific; machinery is not

**What a widget shows belongs entirely to the module that declared it** — its
query, its template, its numbers, its words. The shell never sees any of them.
It knows a widget's id, its label, the section it files under and how wide it may
sit, because those are the things ARRANGING a dashboard needs, and it knows
nothing else.

So **the shell ships zero widgets of its own**, exactly as the registry ships zero
modules and for the same reason: a mechanism that also supplies content is a
mechanism competing with the things it serves.

State it plainly, because it is the boundary a future contributor will be
tempted by: **a KPI card added here would be the shell breaking its own
boundary.** If a widget seems to belong to "the platform", it belongs to whichever
module owns the surface it appears on — and if no module owns that surface, the
widget has nowhere to be declared, which is the answer.

## Who depends on it

- **Declaring alone needs only the contracts.** A module that describes widgets
  and does not render them needs
  `uhifadhi/contracts`
  and nothing here.
- **A module that RENDERS a widget surface hard-requires
  `uhifadhi/uhifadhi`.** Put it in `require`, not `suggest`. Rendering is a
  runtime need: without the resolver there is no layout, and without the library
  there is no screen. There is deliberately **no graceful-absence mode** — a
  half-present dashboard is a worse answer than a dependency, and the branching
  it would take is complexity every module would pay for.
- **A module with no dashboard never touches it.**

## Uninstall: prune, not purge

**Removing a module leaves its stored layouts behind, on purpose.**
`composer remove` runs no migrations and un-configuration touches configuration;
neither has any business deleting an organization's data.

And the rows are harmless where they are. Every one is keyed by a surface
**string**, nothing reads a surface no registry claims, and putting the module
back gives everybody the dashboard they had — including the presets they named
themselves.

Deliberate cleanup is [`widget:prune`](schema.md#widgetprune): operator-invoked,
never automatic, wired to no event and no schedule. It deletes the layouts of
surfaces no registered provider claims, after saying which ones and asking.

## What it owns

- **The surface registry** — `WidgetSurfaceInterface` and the service that
  collects every implementation, read live from the container.
- **The catalogue vocabulary** — `WidgetCatalog`, `Widget`, `WidgetGroup`,
  `WidgetPreset`: what a module says when it declares a dashboard.
- **The stored layouts** — `WidgetPreference` (which preset a person has on, and
  the layout it produced) and `WidgetCustomPreset` (the arrangements they saved
  under names of their own).
- **The resolver** — `WidgetService`: a catalogue plus a stored row, merged,
  validated and clamped into the layout a page renders.
- **The write endpoints** — `WidgetEndpoint`: save, adopt, copy, rename, delete,
  reset. A controller hands its catalogue in and returns what comes back.
- **The library** — templates, `assets/widgets.js`, `public/widget.css`, and
  `WidgetDom`, the attribute contract the two sides meet on. The script is
  published as the bare specifier **`uhifadhi/widgets`**, which is the one thing a
  module's library page imports; see
  [declaring a surface](declaring-a-surface.md#the-script-and-the-one-line-that-loads-it).
- **`widget:prune`.**
