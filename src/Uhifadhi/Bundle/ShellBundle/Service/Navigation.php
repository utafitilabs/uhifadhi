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

namespace Uhifadhi\Bundle\ShellBundle\Service;

use Uhifadhi\Bundle\ShellBundle\Contract\NavigationSourceInterface;
use Uhifadhi\Bundle\ShellBundle\Model\NavItem;
use Uhifadhi\Bundle\ShellBundle\Model\NavSection;
use Uhifadhi\Contracts\Shell\NavGroup;

/**
 * THE SIDEBAR'S CONTENT, COLLECTED — and nothing else.
 *
 * This class asks every registered source what goes in the nav, orders the
 * answers, checks the one invariant a nav has, and hands the result to a
 * template. It decides nothing about who may see a row: the shell holds no
 * authorization service and asks nothing about the viewer, because a renderer
 * with opinions about the team model would be the second place in the platform
 * where a permission is interpreted. A row the viewer may not have never
 * arrives here.
 *
 * READ LIVE. The sources are iterated on every call and nothing between them
 * and the sidebar caches, which is what makes "switch a module off" take effect
 * the same day rather than after a deploy.
 */
final class Navigation
{
    /**
     * @param iterable<NavigationSourceInterface> $sources the tagged contributors, in registration order
     */
    public function __construct(private readonly iterable $sources)
    {
    }

    /**
     * Every section, in declared-position order with registration as the
     * tie-break.
     *
     * ONE HEADING PER GROUP. A section label is a PLACE in the sidebar, not a
     * thing a source owns: two modules that both file a row under System have
     * named the same place, so the heading is drawn once with every contributed
     * row under it. A heading drawn twice would tell a reader there are two
     * Systems, which is a sidebar answering "where am I" with a lie.
     *
     * AND THERE ARE FIVE PLACES, NAMED IN THE CONTRACT. A label that is not one
     * of {@see NavGroup::ORDER} is refused here, by name, with the five in the
     * message: a near-miss ("Organization", "Org") used to grow a heading
     * that nobody designed, silently, in whoever's installation had that module.
     *
     * THE GROUPS ARE DRAWN IN THE CONTRACT'S ORDER — Observatory, Organization,
     * System, Settings, Help — and a contribution's `position` orders its ROWS inside
     * its group. That is the split a contributing module can actually answer:
     * where its row sits among the System rows is its business, where System
     * sits among the headings is the shell's.
     *
     * @return list<NavSection>
     */
    public function sections(): array
    {
        /** @var array<string, list<NavSection>> $contributions */
        $contributions = [];
        foreach ($this->sources as $source) {
            foreach ($source->sections() as $section) {
                $contributions[$section->label][] = $section;
            }
        }

        $sections = [];
        foreach ($contributions as $label => $contributed) {
            // Refused before anything is merged, so the message is about the
            // label that was typed and not about a heading nobody asked for.
            NavGroup::position((string) $label);

            // Stable since PHP 8.0, which is what makes registration the
            // tie-break rather than an accident of the sort implementation.
            usort($contributed, static fn (NavSection $a, NavSection $b): int => $a->position <=> $b->position);

            $items = [];
            foreach ($contributed as $section) {
                $items = [...$items, ...$section->items];
            }

            $merged = new NavSection((string) $label, $items, $contributed[0]->position);
            $this->assertOneCurrent($merged->items, \sprintf('the "%s" section', $merged->label));
            $sections[] = new NavSection($merged->label, $this->derive($merged->items, ''), $merged->position);
        }

        usort(
            $sections,
            static fn (NavSection $a, NavSection $b): int => NavGroup::position($a->label) <=> NavGroup::position($b->label),
        );

        return $sections;
    }

    /**
     * WHAT IS OPEN, AND WHICH ROW WEARS THE ACCENT — derived here, for the
     * whole sidebar, from the one thing that is true of a render: where the
     * viewer is. RULED 2026-09-20.
     *
     * Four rules, and nothing else decides:
     *
     *   1. Only the ANCESTOR PATH of the current row is open. Every other
     *      section is closed to its own row — Areas is one row while you are
     *      in Files, Performance is one row while you are in an area. A tree
     *      that opens everything it could open is a wall of rows, and the
     *      thing a sidebar is for is answering "where am I".
     *   2. Open means ONE RUNG. A row shows its children, never its
     *      grandchildren; only the path runs deeper, because the viewer is
     *      standing in it. The row the viewer is ON shows its own children
     *      too — the page and the rows under it then say the same thing.
     *   3. ONE GROUND PER TREE. The row the viewer is on is `current` — the
     *      accent, the ground, the focus line — and every row it hangs off is
     *      `path`: the accent as ink and nothing else. A source that marked
     *      the whole path (and they do, because the invariant above is stated
     *      per sibling list) marked the path, not four current rows, and this
     *      is where that becomes the two classes the design draws.
     *   4. NO PAGE DECIDES ITS OWN TREE. Whatever a source passed for `open`
     *      is overwritten here. It was the honest thing to pass while each
     *      source derived its own subtree; the result was that a page's tree
     *      read differently depending on which bundle drew it, and a reader
     *      cannot learn a sidebar that changes shape under them.
     *
     * The viewer's own folds are not here and cannot be: they are the tab's,
     * they arrive after the render, and the browser keeps them for the
     * session against the key this gives each row.
     *
     * @param list<NavItem> $items
     *
     * @return list<NavItem>
     */
    private function derive(array $items, string $prefix): array
    {
        $rows = [];
        foreach ($items as $item) {
            [$row] = $this->deriveRow($item, $prefix);
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * One row and its branch, rebuilt.
     *
     * A row is on the path when the marked row is somewhere below it, and the
     * marked row is the DEEPEST one a source lit — which is why this is
     * bottom-up: a rung only learns it is an ancestor from its children.
     *
     * @return array{NavItem, bool} the rebuilt row, and whether the viewer is on it or under it
     */
    private function deriveRow(NavItem $item, string $prefix): array
    {
        $key = '' === $prefix ? $item->label : $prefix.'/'.$item->label;

        $children = [];
        $below = false;
        foreach ($item->children as $child) {
            [$row, $holds] = $this->deriveRow($child, $key);
            $children[] = $row;
            $below = $below || $holds;
        }

        $here = !$below && $item->current;

        $row = new NavItem(
            label: $item->label,
            url: $item->url,
            icon: $item->icon,
            hint: $item->hint,
            current: $here,
            // Rules 1 and 2: the path, and the row the viewer is on.
            open: $below || $here,
            children: $children,
            tone: $item->tone,
            swatch: $item->swatch,
            screens: $item->screens,
            path: $below,
            key: $key,
            newTab: $item->newTab,
        );

        return [$row, $below || $here];
    }

    /**
     * EXACTLY ONE ROW IS CURRENT — among siblings, at every level of the tree.
     *
     * "Where am I" is the sidebar's whole job and two lit rows answer it worse
     * than none, so the shell refuses rather than rendering a nav that cannot
     * be read. The check is per sibling list rather than per sidebar, and the
     * distinction is the tree's: the place you are inside and the row you are on
     * within it are both marked, on purpose — that is one PATH, drawn, and the
     * template spends the accent on the deeper of the two. Two marked rows side
     * by side is a contradiction; a marked row inside a marked branch is a
     * location.
     *
     * Zero is allowed and always will be: a viewer can be somewhere the nav
     * does not list, and a shell that refused to draw a sidebar on a sign-in
     * page would be a shell nobody could sign in to.
     *
     * @param list<NavItem> $items
     */
    private function assertOneCurrent(array $items, string $where): void
    {
        $lit = [];
        foreach ($items as $item) {
            if ($item->current) {
                $lit[] = $item->label;
            }
            $this->assertOneCurrent($item->children, \sprintf('the children of "%s"', $item->label));
        }

        if (\count($lit) > 1) {
            throw new \LogicException(\sprintf('The sidebar must light exactly one row among siblings, and %s lights %d of them (%s). Two lit rows answer "where am I" worse than none — the source decides which one it is.', $where, \count($lit), implode(', ', $lit)));
        }
    }
}
