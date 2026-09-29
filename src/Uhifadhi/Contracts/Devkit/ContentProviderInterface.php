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

namespace Uhifadhi\Contracts\Devkit;

/**
 * How a module contributes seed/sample content that is seeded ONLY in a dev
 * install.
 *
 * devkit-module (evolving from fixtures-module) is the dev-only collector: it
 * installs through `require-dev`, so it and its machinery are in no production
 * build. A module ships an INERT provider implementing this interface — an
 * ordinary tagged service that knows how to seed a slice of seed content — and
 * devkit's `fixtures:seed` command `tagged_iterator`s every one of them, orders
 * them, and calls load() on each. Because devkit is require-dev, none of that
 * seeding machinery exists in production; the provider that ships in an
 * always-installed module is inert data until a dev tool comes to run it.
 *
 * THE INTERFACE LIVES HERE, NOT IN DEVKIT. The inert provider classes ship
 * inside always-installed modules (areas, patrol, incidents), so their
 * `implements` clause must resolve at runtime even when devkit — being
 * require-dev — is absent. A contract those modules point at therefore has to
 * live in a package they always have: this one. devkit depends on the same
 * interface to collect and order the providers, so neither the module nor the
 * platform needs to know the other exists.
 *
 *     incident-module ──implements──▶ ContentProviderInterface ◀──reads── devkit-module
 *
 * THE TAG IS `uhifadhi.devkit.content_provider`, and a module writes it as a
 * LITERAL STRING in its own service definition — never as a constant of
 * devkit's, which would load a class that is not installed in production. It is
 * named here, in the package both ends always have, so the two can agree on it
 * without either naming the other. {@see CommandProviderInterface} is collected
 * by `uhifadhi.devkit.command_provider` for the same reasons.
 *
 * IT ASKS FOUR QUESTIONS AND OFFERS ONE VERB. devkit has to identify each
 * contribution (key, label, description), order it against the others
 * (dependsOn), and run it (load). Ordering is expressed as DEPENDENCIES, not a
 * priority number, because that is the real relationship: an incidents seed
 * needs areas and patrols to hang its records on, and "after area, after patrol"
 * says exactly that, where a hand-tuned integer only approximates it and two
 * modules picking the same number says nothing at all. devkit topologically
 * sorts the providers by their keys and dependsOn edges before seeding.
 *
 * IT NAMES NO PERSISTENCE TYPE, for the same reason nothing else in this package
 * does: load() takes no entity manager. A content provider is an ordinary
 * service and injects whatever it needs to seed — an entity manager,
 * repositories, a faker — through its constructor, exactly as any service does.
 * Keeping Doctrine out of the signature is what lets an always-installed module
 * implement this without the contract dragging an ORM into a package whose whole
 * claim is that depending on it costs nothing.
 *
 * For dev/maintenance COMMANDS a module wants runnable at the console — beyond
 * seeding content — use {@see CommandProviderInterface} instead.
 */
interface ContentProviderInterface
{
    /**
     * Stable machine identity, unique across content providers and referenced by
     * other providers' dependsOn() (e.g. "area", "patrol", "incident"). It is
     * the name of the content, not of the module — a module that seeds two
     * distinct slices ships two providers with two keys.
     */
    public function key(): string;

    /** Human display name devkit prints while seeding (e.g. "Areas", "Incidents"). */
    public function label(): string;

    /** One line saying what this contribution seeds, shown alongside the label. */
    public function description(): string;

    /**
     * The keys of the content providers that must be seeded BEFORE this one —
     * the seed content this content is built on top of. An incidents provider
     * that hangs its records on areas and patrols returns ['area', 'patrol'];
     * a provider that stands alone returns []. devkit topologically sorts on
     * these edges, so the order is stated as a relationship rather than guessed
     * at with a priority number.
     *
     * @return list<string>
     */
    public function dependsOn(): array;

    /**
     * Seed the content. Called by devkit's `fixtures:seed`, once, after
     * everything this provider dependsOn() has been seeded — and never in
     * production, where the collector that calls it does not exist. Returns
     * nothing: the seeded records are the effect. The provider does the work
     * through the dependencies it was constructed with.
     */
    public function load(): void;
}
