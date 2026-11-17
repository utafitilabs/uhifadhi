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

namespace Uhifadhi\Bundle\ShellBundle\Model;

/**
 * A ROW IN THE SIDEBAR, and possibly a branch of it.
 *
 * A value object on purpose, and the reason the shell can draw the contract's
 * answers without depending on the registry: there is nothing on this class to
 * branch on that is not also on every other row. The moment a domain entity is
 * in scope inside a Twig file, somebody writes `{% if row.entity.slug == … %}`
 * and the module-blindness the platform promises is gone.
 *
 * `url` is nullable and that is a product decision, not laziness: a surface
 * whose route has not merged yet renders visible, dimmed and inert, so the
 * product says "this is planned" instead of pretending it was never planned.
 * Compare {@see AreaTab}, where the opposite rule holds and for a stated reason.
 */
final class NavItem
{
    /**
     * `tone` is the module row's identity-dot class, and it is a presentation
     * passthrough exactly like `icon`: an opaque string the shell prints onto the
     * dot (`<i class="mdot {tone}">`) and never interprets. A module carries its
     * own hue the map-legend way — it declares `.ntree .ntm .mdot.<tone>` in its
     * own stylesheet — and the source hands the shell that class name. Null keeps
     * the shell's jade default, which is exactly what the design gives a module
     * with no colour of its own. The shell names no module either way.
     *
     * `swatch` IS THE OTHER WAY TO COLOUR THAT DOT, for a row whose hue is a
     * VALUE rather than a class. A module's colour is fixed and can be one rule
     * in that module's stylesheet; a zone's comes from a palette by its position
     * in its area's set, so there is no class to declare and no sheet that could
     * know how many zones an installation will have. Declaring them as classes
     * would put the palette in two places, and the plate, the key and the cards
     * already read it from one.
     *
     * The shell interprets neither: one is printed into `class`, the other into
     * `style`. It is checked on the way in — see the constructor — because the
     * one thing a `style` passthrough must not become is a hole to write CSS
     * through.
     *
     * @param string      $label    what the row says
     * @param string|null $url      where it goes, or null for an inert row
     * @param string|null $icon     a ux-icons name, e.g. "shell:map"
     * @param string|null $hint     the title attribute — why a row is inert
     * @param bool        $current  whether the viewer is here — accented on the
     *                              row they are on, and drawn quieter on the
     *                              place rung they are inside (see
     *                              `_nav_item.html.twig`, which spends the accent
     *                              once per sidebar)
     * @param bool        $open     DERIVED, and passing it changes nothing: the
     *                              shell decides what is unfolded from where the
     *                              viewer is (see {@see \Uhifadhi\Bundle\ShellBundle\Service\Navigation}).
     *                              Kept on the signature for one release so an
     *                              installed module that still names it does not
     *                              fail to construct a row
     * @param list<self>  $children the branch under this row, if any
     * @param string|null $tone     a module row's dot class, or null for jade
     * @param string|null $swatch   a row's own dot colour, as a hex value or as
     *                              the palette token the host resolved a category
     *                              into; null when it has none
     * @param bool        $screens  whether the rows under this one are its own
     *                              sibling SCREENS rather than places inside
     *                              it — see the property's own note
     * @param bool        $path     DERIVED: whether the row the viewer is on
     *                              hangs off this one. Accent ink, no ground —
     *                              see the property's own note
     * @param string      $key      DERIVED: where this row sits in the tree,
     *                              which is how a manual fold is remembered
     * @param bool        $newTab   whether the row opens its address in a new
     *                              tab — a document read beside the app, such
     *                              as the installation's manual
     */
    public function __construct(
        public string $label,
        public ?string $url = null,
        public ?string $icon = null,
        public ?string $hint = null,
        public bool $current = false,
        public bool $open = true,
        public array $children = [],
        public ?string $tone = null,
        public ?string $swatch = null,
        /**
         * WHETHER THIS ROW'S CHILDREN ARE SCREENS OR PLACES, which is the
         * one thing about a branch the shell cannot see for itself.
         *
         * The rung a row is drawn at is its DEPTH, and that is right
         * wherever the tree is a tree of places: Areas → an area → its
         * screens. A section whose children are its OWN screens — the same
         * three the tab strip carries — has no place rung between the two,
         * and drawing them as places would give a reader two kinds of row
         * for one kind of thing. Saying so here keeps the depth-to-rung
         * rule intact and names the exception.
         */
        public bool $screens = false,
        /**
         * WHETHER THE CURRENT ROW HANGS OFF THIS ONE — one background per
         * tree, ruled 2026-09-20.
         *
         * `current` and this are two different things and carry two different
         * classes on purpose. The row the viewer is ON keeps the ground, the
         * accent ink and the left focus line, and there is exactly one of it
         * in the sidebar. A row it hangs OFF — the section above a screen, the
         * area above its tabs, Modules above a module — carries the accent as
         * INK and nothing else: no ground, no second left line. Two grounds in
         * one tree is the sidebar answering "where am I" twice.
         *
         * Derived by the shell from the marked row, never by a source: a page
         * that wrote this would be deciding how another page's tree reads.
         */
        public bool $path = false,
        /**
         * WHERE THIS ROW SITS IN THE TREE — its labels from the section down,
         * joined. The one thing a manual fold can be remembered by.
         *
         * A fold is the viewer's, so it has to survive the next page, and the
         * next page renders a tree built by different sources in a different
         * order. What does not change is the row's PLACE, so that is the key:
         * the same node keys the same on every page of the app, and a key that
         * no longer matches any row simply decides nothing.
         *
         * Derived by the shell, like `path`.
         */
        public string $key = '',
        public bool $newTab = false,
    ) {
        /*
         * ONLY A COLOUR. The value is printed into a `style` attribute, so
         * anything else a source handed over would be CSS written into the page
         * through a hole the shell opened for it. Checked here rather than
         * escaped at the template, because a row that cannot be drawn is a
         * mistake in the source and should fail where it was made.
         */
        /*
         * A HEX, OR ONE OF THE PALETTE'S OWN CATEGORY TOKENS. The second is
         * what the host writes when a contributor handed it a CATEGORY rather
         * than a colour: a module never knows what green is here — the palette
         * turns over with the theme and again on imagery — so it says "the
         * third category" and the resolution happens on this side.
         */
        if (null !== $swatch
            && 1 !== preg_match('/^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/i', $swatch)
            && 1 !== preg_match('/^var\\(--cat-(?:[1-9]|1[0-8])\\)$/', $swatch)
        ) {
            throw new \InvalidArgumentException(\sprintf('A nav row\'s swatch is a hex colour or a category token the shell prints onto its dot; "%s" is neither.', $swatch));
        }
    }
}
