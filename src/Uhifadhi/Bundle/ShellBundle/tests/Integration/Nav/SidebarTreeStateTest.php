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

namespace Uhifadhi\Bundle\ShellBundle\Tests\Integration\Nav;

use Symfony\Component\DomCrawler\Crawler;
use Uhifadhi\Bundle\ShellBundle\Model\NavItem;
use Uhifadhi\Bundle\ShellBundle\Model\NavSection;
use Uhifadhi\Bundle\ShellBundle\Tests\Integration\ContractTestCase;
use Uhifadhi\Bundle\ShellBundle\Tests\Integration\Fixtures\HostKernel;

/**
 * THE SIDEBAR TREE'S STATE — the seven frames the design draws, rendered.
 *
 * RULED 2026-09-20 (nav-states, frames A–G). The whole of it:
 *
 *   1. On a fresh load only the ANCESTOR PATH of the current row is open.
 *      Every other section is closed to its own row.
 *   2. Open means ONE RUNG: a row shows its children, never its
 *      grandchildren. The row the viewer is ON shows its own children too.
 *   3. A manual open lasts the tab's SESSION, and it is the browser's
 *      business — the server renders the derived tree every time.
 *   4. The collapsed rail states rule 1 as a DOT on the section that holds
 *      the current row.
 *   5. ONE GROUND PER TREE: `on` on the row the viewer is on, `path` — ink
 *      only — on every row it hangs off.
 *   6. The sidebar is its own scroll region.
 *
 * Each test below is one frame, built out of the same invented tree so the
 * frames differ in exactly one thing: where the viewer is. The whole point of
 * the ruling is that nothing else may differ, so nothing else does here.
 *
 * NO PAGE HARD-CODES ANY OF IT. The trees the sources hand over below mark the
 * path the way a source naturally does and pass no fold state at all; one of
 * the tests hands over a tree that asks for the opposite of the rule, to prove
 * a page cannot decide how its own sidebar reads.
 */
final class SidebarTreeStateTest extends ContractTestCase
{
    /** Where the viewer is, as a path of row labels. */
    private string $at = '';

    /**
     * THE SIDEBAR THE FRAMES ARE DRAWN FROM — every section the design shows,
     * with the path to `$at` marked the way a source marks it.
     *
     * `$at` is a path of labels, "Areas/Kilimani Crater/Modules/Roster/Today". A
     * source lights every rung of it, because the shell's own invariant is one
     * lit row among SIBLINGS: the path is what a source can see, and turning
     * it into one ground and a line of ink is the shell's job.
     */
    private function sidebarAt(string $at): void
    {
        // A row is lit when the viewer is on it or under it, which is what a
        // source can see of its own subtree — and the whole path lights, not
        // just the leaf. Turning that into one ground and a line of ink is the
        // shell's job, and is what these frames check.
        $this->at = $at;

        HostKernel::$navSources = [
            'observatory' => new NavSection('Observatory', [
                new NavItem(label: 'Areas', url: '/areas', icon: 'shell:map', current: $this->lit('Areas'), children: [
                    $this->area('Kilimani Crater', true),
                    $this->area('Olkeju', false),
                ]),
                new NavItem(label: 'Performance', url: '/performance', icon: 'shell:trending-up', current: $this->lit('Performance'), children: [
                    new NavItem(label: 'Overview', url: '/performance', current: $this->lit('Performance/Overview')),
                    new NavItem(label: 'Topics', url: '/performance/topics', current: $this->lit('Performance/Topics'), children: [
                        new NavItem(label: 'Staffing', url: '/performance/topics/staffing', current: $this->lit('Performance/Topics/Staffing')),
                        new NavItem(label: 'Goals', url: '/performance/topics/goals', current: $this->lit('Performance/Topics/Goals')),
                    ]),
                    new NavItem(label: 'Briefing', url: '/performance/briefing', current: $this->lit('Performance/Briefing')),
                ], screens: true),
            ]),
            'organization' => new NavSection('Organization', [
                new NavItem(label: 'Departments', url: '/departments', icon: 'shell:building-2', current: $this->lit('Departments'), children: [
                    new NavItem(label: 'Overview', url: '/departments/overview', current: $this->lit('Departments/Overview')),
                    new NavItem(label: 'Departments', url: '/departments', current: $this->lit('Departments/Departments'), children: [
                        new NavItem(label: 'Ecology', url: '/departments?d=ecology', current: $this->lit('Departments/Departments/Ecology')),
                        new NavItem(label: 'Tourism', url: '/departments?d=tourism', current: $this->lit('Departments/Departments/Tourism')),
                    ]),
                    new NavItem(label: 'Positions', url: '/departments/positions', current: $this->lit('Departments/Positions')),
                ], screens: true),
                $this->team(false),
            ]),
            'system' => new NavSection('System', [
                new NavItem(label: 'Files', url: '/files', icon: 'shell:image', current: $this->lit('Files'), children: [
                    new NavItem(label: 'Overview', url: '/files/overview', current: $this->lit('Files/Overview')),
                    new NavItem(label: 'Sources', url: '/files/sources', current: $this->lit('Files/Sources')),
                ], screens: true),
            ]),
        ];
    }

    /** Whether the viewer is on this row or under it — what a source can see. */
    private function lit(string $key): bool
    {
        return $this->at === $key || str_starts_with($this->at, $key.'/');
    }

    /** The Team section, optionally asking for its own tree to be unfolded. */
    private function team(bool $asksToBeOpen): NavItem
    {
        return new NavItem(
            label: 'Team',
            url: '/team',
            icon: 'shell:users',
            current: $this->lit('Team'),
            open: $asksToBeOpen,
            children: [
                new NavItem(label: 'Overview', url: '/team/overview', current: $this->lit('Team/Overview')),
                new NavItem(label: 'People', url: '/team', current: $this->lit('Team/People')),
            ],
            screens: true,
        );
    }

    /** One area, with its screens and — where a module is installed — its modules. */
    private function area(string $name, bool $installed): NavItem
    {
        $under = 'Areas/'.$name;
        $at = '/areas/'.strtolower($name);

        return new NavItem(
            label: $name,
            url: $at,
            current: $this->lit($under),
            children: [
                new NavItem(label: 'Overview', url: $at, current: $this->lit($under.'/Overview')),
                new NavItem(
                    label: 'Modules',
                    url: $at.'/modules',
                    current: $this->lit($under.'/Modules'),
                    children: $installed ? $this->modules($under.'/Modules') : [],
                ),
                new NavItem(label: 'Zones', url: $at.'/zones', current: $this->lit($under.'/Zones')),
            ],
        );
    }

    /**
     * The installed modules, one of which has screens of its own — the fifth
     * rung, which is where the frames get interesting.
     *
     * @return list<NavItem>
     */
    private function modules(string $under): array
    {
        $modules = [];
        foreach (['Patrols', 'Incidents', 'Roster'] as $name) {
            $modules[] = new NavItem(
                label: $name,
                url: '/modules/'.strtolower($name),
                current: $this->lit($under.'/'.$name),
                children: 'Roster' === $name ? $this->screens($under.'/Roster') : [],
            );
        }

        return $modules;
    }

    /**
     * A module's own screens.
     *
     * @return list<NavItem>
     */
    private function screens(string $under): array
    {
        $screens = [];
        foreach (['Overview', 'Today', 'Week', 'Live'] as $label) {
            $screens[] = new NavItem(
                label: $label,
                url: '/'.strtolower($label),
                current: $this->lit($under.'/'.$label),
            );
        }

        return $screens;
    }

    /** The row the viewer is on — there is exactly one, and this refuses if there is not. */
    private function theRowTheyAreOn(Crawler $crawler): string
    {
        $on = $crawler->filter('nav.nav .on');
        self::assertCount(1, $on, 'One ground per tree: the sidebar marks the row the viewer is on exactly once.');

        return trim($on->text());
    }

    /**
     * Every row drawn as an ancestor of the current one, in document order.
     *
     * @return list<string>
     */
    private function thePathAbove(Crawler $crawler): array
    {
        return $crawler->filter('nav.nav .path')->each(static fn (Crawler $n): string => trim($n->text()));
    }

    /**
     * Every branch the render leaves open, named by the row it hangs from —
     * which is the line the design prints under each frame.
     *
     * @return list<string>
     */
    private function whatIsOpen(Crawler $crawler): array
    {
        return $crawler
            ->filter('nav.nav [data-nav-key]:not(.closed)')
            ->each(static fn (Crawler $n): string => (string) $n->attr('data-nav-key'));
    }

    /** FRAME A — on an area overview. */
    public function testFrameAnAreaOverview(): void
    {
        $this->sidebarAt('Areas/Kilimani Crater/Overview');
        $crawler = $this->crawl('@fixtures/body_only_page.html.twig');

        self::assertSame('Overview', $this->theRowTheyAreOn($crawler));
        self::assertSame(['Areas', 'Kilimani Crater'], $this->thePathAbove($crawler));

        // The area is open to its tabs. Modules is a row, not a list: its
        // modules are one rung too deep. The other area is shut to its name.
        self::assertSame(['Areas', 'Areas/Kilimani Crater'], $this->whatIsOpen($crawler));
    }

    /** FRAME B — on a module's own screen, four rungs down. */
    public function testFrameAModuleScreen(): void
    {
        $this->sidebarAt('Areas/Kilimani Crater/Modules/Roster/Today');
        $crawler = $this->crawl('@fixtures/body_only_page.html.twig');

        self::assertSame('Today', $this->theRowTheyAreOn($crawler));
        self::assertSame(['Areas', 'Kilimani Crater', 'Modules', 'Roster'], $this->thePathAbove($crawler));

        // Four rungs open, because the viewer is standing on the fourth.
        // Patrols and Incidents stay shut: siblings of the path, not on it.
        self::assertSame([
            'Areas',
            'Areas/Kilimani Crater',
            'Areas/Kilimani Crater/Modules',
            'Areas/Kilimani Crater/Modules/Roster',
        ], $this->whatIsOpen($crawler));

        // And the leaf is the fifth rung, drawn with no marker of its own.
        self::assertSame('Today', trim($crawler->filter('nav.nav .nts.on')->text()));
    }

    /** FRAME C — on a performance topic record. */
    public function testFrameAPerformanceTopicRecord(): void
    {
        $this->sidebarAt('Performance/Topics/Staffing');
        $crawler = $this->crawl('@fixtures/body_only_page.html.twig');

        self::assertSame('Staffing', $this->theRowTheyAreOn($crawler));
        self::assertSame(['Performance', 'Topics'], $this->thePathAbove($crawler));

        // Areas is closed to its row even though the topic reads area figures,
        // and Departments is closed too: a page is in one place at a time.
        self::assertSame(['Performance', 'Performance/Topics'], $this->whatIsOpen($crawler));
    }

    /** FRAME D — on a section overview, which is what most pages look like. */
    public function testFrameASectionOverview(): void
    {
        $this->sidebarAt('Files/Overview');
        $crawler = $this->crawl('@fixtures/body_only_page.html.twig');

        self::assertSame('Overview', $this->theRowTheyAreOn($crawler));
        self::assertSame(['Files'], $this->thePathAbove($crawler));

        // One section open, one rung deep, every other section a single row.
        self::assertSame(['Files'], $this->whatIsOpen($crawler));
    }

    /** FRAME E — on a department record, which hangs off the register. */
    public function testFrameADepartmentRecord(): void
    {
        $this->sidebarAt('Departments/Departments/Ecology');
        $crawler = $this->crawl('@fixtures/body_only_page.html.twig');

        self::assertSame('Ecology', $this->theRowTheyAreOn($crawler));
        self::assertSame(['Departments', 'Departments'], $this->thePathAbove($crawler));

        // The register row is open because the record hangs off it; Positions
        // and Overview stay rows.
        self::assertSame(['Departments', 'Departments/Departments'], $this->whatIsOpen($crawler));
    }

    /**
     * FRAME F — a section the viewer is not in is closed to its row, and no
     * page may say otherwise.
     *
     * The frame itself is "Team manually opened while on Files", and the
     * manual half is the browser's: it is a fold the viewer makes, kept in
     * sessionStorage for the tab and applied by the controller. What the
     * SERVER must guarantee is the half under it — that the render it hands
     * over has Team closed however loudly the source asks for it open, so the
     * only thing that can open it is the viewer.
     */
    public function testFrameASectionTheViewerIsNotInIsClosedHoweverTheSourceAsks(): void
    {
        $this->sidebarAt('Files/Overview');

        $organization = HostKernel::$navSources['organization'];
        HostKernel::$navSources['organization'] = new NavSection(
            'Organization',
            // The page asks for its own tree, and is overruled.
            [$organization->items[0], $this->team(true)],
            $organization->position,
        );

        $crawler = $this->crawl('@fixtures/body_only_page.html.twig');

        self::assertSame(['Files'], $this->whatIsOpen($crawler));
    }

    /**
     * FRAME G — the collapsed rail, on the same module screen.
     *
     * A rail has no room for a path, so rule 1 is stated as a dot on the
     * section that holds the current row. That section is exactly the one
     * drawn `path`, which is why there is no second flag to keep in step: the
     * dot is one CSS rule reading the mark the expanded tree already carries,
     * and expanding restores frame B unchanged.
     */
    public function testFrameTheCollapsedRailMarksTheSectionHoldingTheCurrentRow(): void
    {
        $this->sidebarAt('Areas/Kilimani Crater/Modules/Roster/Today');
        $crawler = $this->crawl('@fixtures/body_only_page.html.twig');

        $sections = $crawler->filter('nav.nav > .nav-item')->each(
            static fn (Crawler $n): string => trim($n->filter('span')->text())
                .':'.(str_contains(' '.(string) $n->attr('class').' ', ' path ') ? 'dot' : '-'),
        );
        self::assertSame(['Areas:dot', 'Performance:-', 'Departments:-', 'Team:-', 'Files:-'], $sections);

        $css = (string) file_get_contents(__DIR__.'/../../../public/shell.css');
        self::assertStringContainsString('.side.rail, html.shell-rail .side) .nav-item.path::after', $css);
    }

    /**
     * RULE 2, THE HALF THAT IS EASY TO GET WRONG: the row the viewer is ON
     * shows its own children.
     *
     * Standing on Modules shows the installed modules, standing on the
     * departments register shows the records — the page being looked at and
     * the rows under it then say the same thing. The stricter reading would
     * stop at the row and show nothing under it, and the design settled the
     * looser one.
     */
    public function testTheRowTheViewerIsOnShowsItsOwnChildrenAndNotItsGrandchildren(): void
    {
        $this->sidebarAt('Areas/Kilimani Crater/Modules');
        $crawler = $this->crawl('@fixtures/body_only_page.html.twig');

        self::assertSame('Modules', $this->theRowTheyAreOn($crawler));
        self::assertSame([
            'Areas',
            'Areas/Kilimani Crater',
            'Areas/Kilimani Crater/Modules',
        ], $this->whatIsOpen($crawler));

        // One rung, and no further: the three module rows are drawn, and
        // Roster's own screens are in the document and folded, because the
        // viewer is not standing in them.
        self::assertSame(
            ['Patrols', 'Incidents', 'Roster'],
            $crawler->filter('nav.nav .ntree:not(.closed) .ntm')->each(
                static fn (Crawler $n): string => trim($n->text()),
            ),
        );
        self::assertCount(4, $crawler->filter('nav.nav .ntgroup.closed .nts'));
    }

    /**
     * A SIDEBAR WITH NOWHERE MARKED IS A SIDEBAR OF ROWS. A viewer can be
     * somewhere the nav does not list — a sign-in page, a 404 — and the tree
     * then opens nothing at all rather than guessing.
     */
    public function testNothingIsOpenWhenTheViewerIsNowhereTheNavLists(): void
    {
        $this->sidebarAt('Nowhere');
        $crawler = $this->crawl('@fixtures/body_only_page.html.twig');

        self::assertCount(0, $crawler->filter('nav.nav .on'));
        self::assertCount(0, $crawler->filter('nav.nav .path'));
        self::assertSame([], $this->whatIsOpen($crawler));
    }

    /**
     * RULE 3 — A MANUAL FOLD IS THE TAB'S, NOT THE ACCOUNT'S. Written to
     * sessionStorage and never to localStorage, so it follows the viewer from
     * page to page and dies with the tab: a fresh load reads the same for
     * everyone, which is the whole reason the state is derived server-side.
     */
    /**
     * A MANUAL FOLD LASTS THE PAGE AND NO LONGER (ruled 2026-09-22, derived
     * only): the controller stores nothing, so the next navigation derives
     * the tree afresh and every page reads the same for everyone.
     */
    public function testAManualFoldLastsThePageAndIsStoredNowhere(): void
    {
        $controller = (string) file_get_contents(__DIR__.'/../../../assets/controllers/sidebar_tree_controller.js');

        self::assertStringNotContainsString('sessionStorage', $controller);
        self::assertStringNotContainsString('localStorage', $controller);
        self::assertStringContainsString("classList.toggle('closed'", $controller);
    }
}
