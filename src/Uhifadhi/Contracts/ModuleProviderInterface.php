<?php

declare(strict_types=1);

/*
 * This file is part of the Uhifadhi core.
 *
 * (c) Ezekiel Mjema <https://github.com/eemjema>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Uhifadhi\Contracts;

/**
 * How a uhifadhi module declares itself to the host — its catalogue metadata plus
 * an optional own entry route. One implementation = one module (the per-area
 * capability shown in an area's module grid). By convention a bundle provides
 * exactly one module named after itself; anything the module contains (a sightings
 * module's "surveys", say) is the module's OWN internal concern and invisible here.
 *
 * A module an installation defines for itself and a module it installs from a
 * package implement this identically, so the registry collects both through one
 * tag. `entryRoute()` decides where a module's tile leads: return null to render
 * through the generic module page, or a route name to own your pages.
 *
 * The defaults for the optional methods live in {@see ModuleProviderTrait}; a
 * typical provider only defines slug(), name() and category().
 */
interface ModuleProviderInterface
{
    /**
     * Stable identity + routing key: lowercase letters only, unique across
     * modules (e.g. "forest", "sightings").
     */
    public function slug(): string;

    /** Human display name shown in the grid and sub-nav (e.g. "Forest loss", "Sightings"). */
    public function name(): string;

    /**
     * WHAT THE MODULE IS, in one sentence a stranger understands — "Ranger
     * patrols: tracks, observations and station duty." — or null for a module
     * that says nothing beyond its name. The area's modules register prints it
     * under the name, and the settings overview quotes it for what the module
     * adds; the tile and the sub-nav never do.
     */
    public function description(): ?string;

    /**
     * Catalogue category the host files this module under — a category value
     * string the host understands, such as "flux", "pressure" or
     * "biodiversity" (the host maps + validates it, falling back to a default
     * for anything it does not recognise).
     */
    public function category(): string;

    /** Lifecycle status string the host understands, e.g. "live" or "template". */
    public function status(): string;

    /** Short provenance line for the tile (e.g. "Hansen GFC"), or null. */
    public function dataSource(): ?string;

    /** Whether the module is pinned (always-on, first) in an area — e.g. the hub. */
    public function pinned(): bool;

    /**
     * BASE, i.e. a capability module that ships switched ON rather than parked.
     *
     * This is a distinction WITHIN THE CAPABILITY TIER — the tier of modules that
     * carry a provider, take a catalogue tile and live per-area. An installable
     * module (the default) is INSTALLED but not switched on: the host seeds it
     * parked, and an admin enables it per area. A base module is seeded ACTIVE in
     * every area instead. The word means "on by default", not "cannot be turned
     * off": the host's Customize page still governs an area's modules.
     *
     * base() is NOT how platform infrastructure is expressed. Machinery every
     * relevant screen already relies on — the map platform, whose absence means
     * "broken screens" rather than "fewer features" — is INFRASTRUCTURE: it
     * contributes no provider at all, so there is nothing to catalogue, nothing to
     * ledger, and nothing to call base() on. It is guaranteed present by the
     * composer graph, not written into a per-area row.
     *
     * THE WORD IS "BASE", not "core". "Core" says a thing is
     * important without saying what it IS, and it is the word every codebase
     * reaches for twice — once for the runtime at the centre and once for the
     * things that ship by default. This platform has both, so it gives them
     * separate words: the runtime at the centre is the CORE (RegistryBundle, the
     * bundle this provider registers with, and its siblings), and a capability
     * that arrives switched on is BASE.
     *
     * Almost every module answers false (the trait's default). Say true only when
     * your module is a capability that should default on in every area.
     */
    public function base(): bool;

    /** Ordering hint within the catalogue; lower sorts first. */
    public function position(): int;

    /** Lucide icon name for the tile/nav, or null for the host default. */
    public function icon(): ?string;

    /**
     * Null = the module renders through the host's generic module page
     * (the legacy behaviour every built-in uses). A route name = the module
     * owns its pages; the host links to it with the area's uuid, i.e.
     * path(entryRoute(), {uuid: area.uuid}).
     */
    public function entryRoute(): ?string;
}
