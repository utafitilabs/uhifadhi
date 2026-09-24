# Boundaries: what the registry is not

## Contents

- [The module grid is not here](#the-module-grid-is-not-here)
- [It draws no route, and it closes them](#it-draws-no-route-and-it-closes-them)

## The module grid is not here

**The module grid is not here, and neither is the customize screen.** This was
the decision worth arguing when the registry became a bundle of its own, so here
is the argument.

The test is independent life: can this bundle live alone and still be useful? The
runtime can — a catalogue, a per-area install record, a permission collector, an
entry-route resolver, the parking gate and the deploy hook that keeps the
catalogue in step with the installed providers are complete and meaningful with
nothing rendering them, and a CLI or an API can use every one of them. A module
grid cannot live alone: it is a *picture* of those answers, and it needs a
layout, a stylesheet, a department lens over the ordering, and the viewer's
identity — none of which the registry has or should acquire.

So the split is:

| Belongs to the registry | Belongs to the shell |
|---|---|
| the catalogue, in catalogue order | the module grid, its cards, its category pills |
| per-area active/parked state and ordering | the customize screen and its forms |
| the ledger: what an area has and has not | the "modules in this area" and "not installed here" widgets |
| a module's entry route, resolved | the link built from it, with the area's uuid |
| the permissions modules declare | the permission matrix that assigns them |

The shell's module-grid service is the case that looks borderline: it returns
arrays, not HTML. It stays out, because what it actually does is group cards by
category **and by the viewer's department**, which is a reading for a person on
a page — a view-model, and the registry has no viewer.

Concretely: this bundle ships **no `templates/` directory, no controllers, no
routes and one console command**, `registry:sync`, and `tests/Unit/BoundaryTest.php`
fails the build if that changes.

## It draws no route, and it closes them

There is one place the line looks blurred and is not. The registry carries
a `kernel.request` listener that answers **404** for a module route in an area
that has parked the module (see `docs/guarantees.md`). It defines no route,
generates no URL and renders no page — it answers a question about the ledger,
which is the registry's table, at the only moment the answer can still prevent a
controller from running.

The alternative was a check inside every module, and it fails the same test the
grid fails, from the other side: it is not a picture, it is the *same* answer
computed in a dozen places, each free to forget. Whoever knows the answer
enforces it.
