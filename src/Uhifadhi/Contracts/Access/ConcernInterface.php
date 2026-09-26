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

namespace Uhifadhi\Contracts\Access;

/**
 * A THING THE PRODUCT LETS SOMEBODY ACT ON.
 *
 * The roster's concerns are watches, check-ins, rotations and its settings.
 * The ground's are areas, zones, stations and assignments. The team's are the
 * directory, personal details, positions and departments. A module declares
 * its own.
 *
 * A CONCERN EXISTS ONLY BY DECLARATION. The code that owns a thing says this
 * is a concern of mine, these are the verbs it supports, and this one is
 * sensitive; the positions page is generated from those declarations and
 * shows nothing else. There is no list of permissions maintained by hand in
 * the middle of the product, and therefore no way for a module's power to
 * appear on a page without its owner having said so - or to survive the
 * module being uninstalled.
 *
 * IMPLEMENT IT BY CONSTRUCTING {@see Concern} unless you have a reason not
 * to: the interface is here so a module may answer with an enum case or a
 * type of its own, and the value object is here so that almost nobody needs
 * to.
 */
interface ConcernInterface
{
    /**
     * The key a route, a door and a grant all name it by - lowercase, digits
     * and hyphens (e.g. "zones", "personal-details"). Unique across the whole
     * installation: the catalogue refuses a second declaration of one key and
     * names both declarers.
     */
    public function key(): string;

    /** The row label in the grants matrix, in the product's words. */
    public function label(): string;

    /**
     * One sentence saying what this concern is about, printed under the row.
     * Required, for the reason every declared row gives: a matrix half of
     * whose rows explain themselves is one an administrator stops reading.
     */
    public function description(): string;

    /**
     * Which of the six this concern supports. The matrix draws a cell only
     * where the verb is here.
     *
     * @return list<Verb>
     */
    public function verbs(): array;

    /**
     * Which placements a grant on this concern may be exercised at.
     *
     * @return list<ScopeKind>
     */
    public function scopeKinds(): array;

    /**
     * Whether this is a fact about a person or about a case that an
     * organization may reasonably want withheld without withholding the page
     * it sits on - live positions, case files, money, personal details, the
     * bytes of a file. Marked in the matrix.
     */
    public function isSensitive(): bool;

    /**
     * The module's own words for {@see ScopeKind::Own} - "own shift", "own
     * team's patrols", "own department's people" - shown as the scope value
     * under that module's group. Null when the concern does not offer it.
     */
    public function ownWords(): ?string;

    /**
     * The slug of the module this concern belongs to, or null for a core
     * bundle's own. It is how the third question - does the placement cover
     * the department - is answered: a department runs a set of modules, so a
     * concern with no module has no department dimension to ask about.
     */
    public function moduleSlug(): ?string;

    /**
     * THE RULE A GRANT ON THIS CONCERN LIFTS, in the declarer's words ("the
     * rank rule"), or null for an ordinary concern.
     *
     * A concern that lifts a rule is an EXCEPTION: the product applies that
     * rule to everybody, and holding the grant takes a seat out of it. The
     * core draws an exception apart from the matrix, lets only a Super Admin
     * give or take it, keeps a written reason with who gave it and when, and
     * lists every seat holding one for review. An exception is always
     * sensitive.
     */
    public function lifts(): ?string;

    public function supports(Verb $verb): bool;

    public function offers(ScopeKind $kind): bool;
}
