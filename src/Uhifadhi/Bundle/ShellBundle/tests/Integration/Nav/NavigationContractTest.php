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

use Uhifadhi\Bundle\ShellBundle\Contract\NavigationSourceInterface;
use Uhifadhi\Bundle\ShellBundle\Model\NavItem;
use Uhifadhi\Bundle\ShellBundle\Model\NavSection;
use Uhifadhi\Bundle\ShellBundle\Service\Navigation;
use Uhifadhi\Bundle\ShellBundle\ShellBundle;
use Uhifadhi\Bundle\ShellBundle\Tests\Integration\ContractTestCase;
use Uhifadhi\Bundle\ShellBundle\Tests\Integration\Fixtures\HostKernel;
use Uhifadhi\Contracts\Shell\NavGroup;

/**
 * SPEC 2 — THE NAV CONTRACT.
 *
 * How a module's entry gets into the sidebar without the sidebar knowing that
 * modules exist.
 *
 * THE RULING. The shell owns the nav's SHAPE — sections, rows, a location tree,
 * carets, the current-row treatment, the collapsed rail. It owns none of the
 * nav's CONTENT. Content arrives through {@see NavigationSourceInterface},
 * implemented by whoever knows something worth putting there:
 *
 *   - the HOST implements one, and that is where contract data enters the shell.
 *     The host has the areas, the viewer, the permission voters and the contract's
 *     per-area ledger; folding those four into "these rows, in this order" is a
 *     reading for a person on a page, which is the host's job by the same
 *     argument by which the registry hands the module grid away.
 *   - a MODULE BUNDLE may implement one too, tagged shell.nav_section, for the
 *     rare platform-wide row that belongs to nobody's area.
 *
 * The shell never asks "which modules are installed", because it has no way to
 * ask that question that does not end in requiring the registry. It asks "what
 * goes in the nav", and whatever answers, answers.
 *
 * THE ENFORCEMENT is negative and it is in Unit/BoundaryTest: no module slug
 * appears in src/ or templates/, ever, in any of the twelve real module names
 * the platform has. This file is the positive half — that a nav can be fully built
 * out of names the shell has never heard of.
 */
final class NavigationContractTest extends ContractTestCase
{
    private function navigation(): Navigation
    {
        $navigation = $this->service('shell.navigation');
        \assert($navigation instanceof Navigation);

        return $navigation;
    }

    /**
     * THE WHOLE CONTRACT IN ONE TEST. Two sources, neither of them known to the
     * shell, both rendered — and the ROWS are invented on purpose, because a
     * contract that only works for the modules that exist today is a hardcoded list
     * with extra steps. The GROUP each row joins is not invented: it is one of
     * the five {@see NavGroup} names, which is the half the shell does own.
     */
    public function testASourceContributesASectionAndTheShellRendersIt(): void
    {
        HostKernel::$navSources = [
            'observatory' => new NavSection(NavGroup::OBSERVATORY, [
                new NavItem(label: 'Areas', url: '/areas', icon: 'shell:map'),
            ]),
            'ferries' => new NavSection(NavGroup::SYSTEM, [
                new NavItem(label: 'Ferries', url: '/ferries', icon: 'shell:ship'),
            ]),
        ];

        $crawler = $this->crawl('@fixtures/body_only_page.html.twig');

        self::assertSame(
            ['Observatory', 'System'],
            $crawler->filter('nav.nav div.nav-hd')->each(static fn ($n): string => trim($n->text())),
        );
        self::assertSame(
            ['Areas', 'Ferries'],
            $crawler->filter('nav.nav a.nav-item span')->each(static fn ($n): string => trim($n->text())),
        );
    }

    /**
     * AND A GROUP THE CONTRACT DOES NOT KNOW NEVER REACHES THE PAGE. The old
     * rule — any label makes a heading — meant a mistyped group grew a fifth
     * one in whoever's installation had that module, silently. The refusal
     * names the five, at the moment the author is choosing between them.
     */
    public function testAnInventedGroupIsRefusedAndTheFourAreNamed(): void
    {
        HostKernel::$navSources = [
            'ferries' => new NavSection('Fleet', [
                new NavItem(label: 'Ferries', url: '/ferries', icon: 'shell:ship'),
            ]),
        ];

        try {
            $this->navigation()->sections();
            self::fail('"Fleet" is not one of the five groups and must be refused.');
        } catch (\InvalidArgumentException $refusal) {
            self::assertStringContainsString('"Fleet" is not a sidebar group', $refusal->getMessage());

            foreach (NavGroup::ORDER as $group) {
                self::assertStringContainsString($group, $refusal->getMessage());
            }
        }
    }

    /**
     * THE HEADINGS COME OUT IN THE CONTRACT'S ORDER — what the organization
     * watches, what it is and holds, what the system raises to you,
     * configuration, and the manual at the foot — whatever order they were registered in and whatever
     * position each contribution declared. A position is a contributing
     * module's answer to "where among the rows", which it can know; where
     * System sits among the headings is the shell's, which it cannot.
     */
    public function testTheGroupsComeOutInTheContractsOrderWhateverWasDeclared(): void
    {
        HostKernel::$navSources = [
            'help' => new NavSection(NavGroup::HELP, [], position: 0),
            'settings' => new NavSection(NavGroup::SETTINGS, [], position: 1),
            'system' => new NavSection(NavGroup::SYSTEM, [], position: 30),
            'org' => new NavSection(NavGroup::ORGANIZATION, [], position: 20),
            'obs' => new NavSection(NavGroup::OBSERVATORY, [], position: 40),
        ];

        self::assertSame(
            NavGroup::ORDER,
            array_map(static fn (NavSection $s): string => $s->label, $this->navigation()->sections()),
        );
    }

    /**
     * ONE HEADING PER LABEL, IN THE DRAWING. A label names a place in the
     * sidebar rather than a thing a source owns, so two modules filing rows
     * under "System" — storage's Files and telemetry's Telemetry — get one
     * heading with both rows under it, exactly as the design draws it. A
     * heading rendered twice tells a reader there are two Systems.
     */
    public function testTwoContributionsToTheSameHeadingRenderUnderOneOfIt(): void
    {
        HostKernel::$navSources = [
            'files' => new NavSection('System', [
                new NavItem(label: 'Files', url: '/files', icon: 'shell:image'),
            ], position: 30),
            'telemetry' => new NavSection('System', [
                new NavItem(label: 'Telemetry', url: '/telemetry', icon: 'shell:activity'),
            ], position: 90),
        ];

        $crawler = $this->crawl('@fixtures/body_only_page.html.twig');

        self::assertSame(
            ['System'],
            $crawler->filter('nav.nav div.nav-hd')->each(static fn ($n): string => trim($n->text())),
        );
        self::assertSame(
            ['Files', 'Telemetry'],
            $crawler->filter('nav.nav a.nav-item span')->each(static fn ($n): string => trim($n->text())),
        );
    }

    /**
     * GATING IS THE SOURCE'S JOB, NOT THE SHELL'S. The shell holds no
     * AuthorizationChecker and calls is_granted on nothing — a renderer that
     * decides who may see a row is a renderer that has opinions about the team
     * model, and it would be the second place in the platform where a
     * permission is interpreted.
     *
     * A row the viewer may not have is absent from what the source returns.
     * There is no "hidden" flag, because a hidden row is a row that leaks its
     * existence to whoever reads the HTML.
     */
    public function testTheShellDecidesNothingAboutWhoMaySeeARow(): void
    {
        HostKernel::$navSources = [
            'org' => new NavSection('Organization', [
                new NavItem(label: 'Departments', url: '/departments', icon: 'shell:building'),
            ]),
        ];

        $html = $this->render('@fixtures/body_only_page.html.twig');

        self::assertStringContainsString('Departments', $html);
        self::assertStringNotContainsString('Team', $html, 'A row the source withheld must be absent, not hidden.');

        // And the shell must not have the means to ask.
        $source = file_get_contents(__DIR__.'/../../../Service/Navigation.php');
        self::assertIsString($source);
        self::assertStringNotContainsString('AuthorizationChecker', $source);
        self::assertStringNotContainsString('isGranted', $source);
    }

    /**
     * A ROW THAT EXISTS BUT HAS NOWHERE TO GO renders inert rather than
     * vanishing. The host already needs this — a surface whose route has not
     * merged yet — and the honest treatment is the one the design settled: the
     * row is visible, dimmed, and not a link, so the product tells you the
     * thing is coming instead of pretending it was never planned.
     */
    public function testARowWithNoDestinationRendersInertRatherThanDisappearing(): void
    {
        HostKernel::$navSources = [
            'system' => new NavSection('System', [
                new NavItem(label: 'Alerts', url: null, icon: 'shell:bell', hint: 'Alerts — planned'),
            ]),
        ];

        $crawler = $this->crawl('@fixtures/body_only_page.html.twig');

        self::assertCount(0, $crawler->filter('nav.nav a.nav-item'));
        $inert = $crawler->filter('nav.nav span.nav-item.off');
        self::assertCount(1, $inert);
        self::assertSame('Alerts — planned', $inert->attr('title'));
    }

    /**
     * THE LOCATION TREE. A row may carry children, and a child may carry
     * children — which is what the settled sidebar does with areas: area → its
     * tabs → the modules under Modules. The shell renders the nesting; the
     * source decides the nesting, because "which tabs does an area have" is not
     * something a layout can know (spec 3).
     */
    public function testARowMayCarryATreeAndTheShellRendersTheNestingItIsGiven(): void
    {
        HostKernel::$navSources = [
            'obs' => new NavSection('Observatory', [
                new NavItem(label: 'Areas', url: '/areas', icon: 'shell:map', children: [
                    new NavItem(label: 'Test Area', url: '/areas/x', current: true, children: [
                        new NavItem(label: 'Modules', url: '/areas/x/modules', children: [
                            new NavItem(label: 'Sightings', url: '/areas/x/modules/sightings'),
                        ]),
                    ]),
                ]),
            ]),
        ];

        $crawler = $this->crawl('@fixtures/body_only_page.html.twig');

        // The nesting the design settled: a place (depth 2, .nta) → its own
        // screens (depth 3, .ntt) → the module rows under one of them (depth 4,
        // .ntm). The shell renders whatever nesting it is given; here it is given
        // the four levels the sidebar really has.
        self::assertCount(1, $crawler->filter('nav.nav .ntree'));
        self::assertSame('Test Area', trim($crawler->filter('nav.nav .ntree .nta b')->text()));
        self::assertSame('Modules', trim($crawler->filter('nav.nav .ntree .ntt')->text()));
        self::assertSame('Sightings', trim($crawler->filter('nav.nav .ntree .ntm')->text()));
    }

    /**
     * A SECTION WHOSE CHILDREN ARE ITS OWN SCREENS SKIPS THE PLACE RUNG.
     *
     * The rung is the depth wherever the tree is a tree of places — Areas
     * → an area → that area's screens. A section that simply HAS three
     * screens has no place between it and them, and drawing them as
     * places would give a reader two kinds of row for one kind of thing.
     * The source says so; the shell keeps its one rule and draws the
     * named exception.
     */
    public function testARowMaySayItsChildrenAreScreensRatherThanPlaces(): void
    {
        HostKernel::$navSources = [
            'obs' => new NavSection('Observatory', [
                new NavItem(label: 'Performance', url: '/performance', icon: 'shell:trending-up', screens: true, children: [
                    new NavItem(label: 'Overview', url: '/performance'),
                    new NavItem(label: 'Topics', url: '/performance/topics'),
                ]),
            ]),
        ];

        $crawler = $this->crawl('@fixtures/body_only_page.html.twig');

        self::assertSame(
            ['Overview', 'Topics'],
            $crawler->filter('nav.nav .ntree a.ntt')->each(static fn ($node): string => trim($node->text())),
        );
        self::assertCount(0, $crawler->filter('nav.nav .ntree .nta'));
    }

    /**
     * A ROW'S DOT TAKES A CLASS OR A VALUE, and the shell draws both without
     * knowing what either means.
     *
     * A MODULE'S HUE IS A CLASS it declares in its own stylesheet; a zone's is
     * a VALUE its source computed from a palette, because there is no sheet
     * that could know how many zones an installation has. The two live side by
     * side on one rung, which is the whole point: the sidebar draws module rows
     * and zone rows the same way.
     */
    public function testADotTakesAModulesClassOrARowsOwnColour(): void
    {
        HostKernel::$navSources = [
            'obs' => new NavSection('Observatory', [
                new NavItem(label: 'Areas', url: '/areas', children: [
                    new NavItem(label: 'Test Area', url: '/areas/x', current: true, children: [
                        new NavItem(label: 'Modules', url: '/areas/x/modules', children: [
                            new NavItem(label: 'Incidents', url: '/areas/x/modules/incidents', tone: 'inc'),
                        ]),
                        new NavItem(label: 'Zones', url: '/areas/x/zones', children: [
                            new NavItem(label: 'Crater', url: '/areas/x/zones/c', swatch: '#3FC7D4'),
                        ]),
                    ]),
                ]),
            ]),
        ];

        $crawler = $this->crawl('@fixtures/body_only_page.html.twig');

        $dots = $crawler->filter('nav.nav .ntree .ntm .mdot');
        self::assertCount(2, $dots);

        // The module keeps its class and carries no inline colour.
        self::assertSame('mdot inc', $dots->eq(0)->attr('class'));
        self::assertNull($dots->eq(0)->attr('style'));

        // The zone brings its own, and takes no class it did not ask for.
        self::assertSame('mdot', $dots->eq(1)->attr('class'));
        self::assertSame('background:#3FC7D4', $dots->eq(1)->attr('style'));
    }

    /**
     * ONE ACCENTED ROW IN THE TREE, AND EVERY RUNG ABOVE IT MARKED AS INK.
     *
     * The design draws a module dashboard with the accent and the ground
     * (`on`) on the leaf alone; every rung the leaf hangs off carries `path` —
     * the accent as ink, no ground. So the shell reads the whole branch, not
     * only the flag a source set: a source marks the path, and the shell turns
     * the path into the two classes the design draws.
     */
    public function testTheTreeAccentsOneRowAndMarksThePlaceAboveItQuietly(): void
    {
        HostKernel::$navSources = [
            'obs' => new NavSection('Observatory', [
                new NavItem(label: 'Areas', url: '/areas', icon: 'shell:map', children: [
                    new NavItem(label: 'Test Area', url: '/areas/x', current: true, children: [
                        new NavItem(label: 'Screens', url: '/areas/x/screens'),
                        new NavItem(label: 'Modules', url: '/areas/x/modules', children: [
                            new NavItem(label: 'Sightings', url: '/areas/x/modules/sightings', children: [
                                new NavItem(label: 'Overview', url: '/areas/x/modules/sightings', current: true),
                                new NavItem(label: 'Records', url: '/areas/x/modules/sightings/records'),
                            ]),
                        ]),
                    ]),
                ]),
            ]),
        ];

        $crawler = $this->crawl('@fixtures/body_only_page.html.twig');

        self::assertCount(1, $crawler->filter('.ntree .on'), 'the tree accents exactly one row');
        self::assertSame('Overview', trim($crawler->filter('.ntree .nts.on')->text()));

        // The place rung: marked as ink, never given the ground.
        self::assertCount(1, $crawler->filter('.ntree .nta.path'));
        self::assertCount(0, $crawler->filter('.ntree .nta.on'));

        // And so is every rung between them — `par` is the parent affordance,
        // `path` is the location, and neither is a second ground.
        self::assertCount(0, $crawler->filter('.ntree .ntt.on'));
        self::assertCount(0, $crawler->filter('.ntree .ntm.on'));
        self::assertSame('Modules', trim($crawler->filter('.ntree .ntt.path')->text()));
        self::assertSame('Sightings', trim($crawler->filter('.ntree .ntm.path')->text()));
        self::assertSame('Areas', trim($crawler->filter('.nav .nav-item.path span')->text()));
        self::assertSame('Modules', trim($crawler->filter('.ntree .ntt.par')->text()));
        self::assertSame('Sightings', trim($crawler->filter('.ntree .ntm.par')->text()));
    }

    /**
     * FOLDING IS A CLASS, NEVER AN OMISSION. The host's sidebar learned this the
     * hard way: a caret that folds by not rendering its children has nothing to
     * reopen, and the row becomes a one-way door. The contract states it, so a
     * later performance-minded refactor cannot quietly reintroduce the bug.
     */
    public function testAFoldedBranchIsStillInTheDocument(): void
    {
        HostKernel::$navSources = [
            'obs' => new NavSection('Observatory', [
                new NavItem(label: 'Areas', url: '/areas', children: [
                    new NavItem(label: 'Test Area', url: '/areas/x', open: false, children: [
                        new NavItem(label: 'Modules', url: '/areas/x/modules', children: [
                            new NavItem(label: 'Sightings', url: '/areas/x/modules/sightings'),
                        ]),
                    ]),
                ]),
            ]),
        ];

        $crawler = $this->crawl('@fixtures/body_only_page.html.twig');

        // Test Area is folded, so its group of screens is hidden — but the module
        // row three levels down is still in the document, ready to reopen.
        self::assertCount(1, $crawler->filter('.ntm'), 'A folded child is present and classed, never dropped.');
        self::assertStringContainsString('closed', (string) $crawler->filter('.nta-group')->attr('class'));
    }

    /**
     * EXACTLY ONE ROW IS CURRENT. "Where am I" is the sidebar's whole job, and
     * two lit rows answer it worse than none. A parent never steals the state
     * from the child it is showing — the host's rule, kept.
     */
    public function testTwoCurrentRowsAreARefusalRatherThanARender(): void
    {
        HostKernel::$navSources = [
            'obs' => new NavSection('Observatory', [
                new NavItem(label: 'Areas', url: '/areas', current: true),
                new NavItem(label: 'Fleet', url: '/fleet', current: true),
            ]),
        ];

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/exactly one/i');
        $this->navigation()->sections();
    }

    /**
     * UNINSTALLING TAKES THE ROW WITH IT, THIS REQUEST. The registry's
     * attention-list promise, at the shell's end: sources are read live, per
     * render, from the container's tagged iterator — nothing between the source
     * and the sidebar is allowed to cache, or "switch the module off" becomes
     * "switch it off after a deploy".
     */
    public function testTheNavIsReadLiveSoARowVanishesTheSameDayItIsSwitchedOff(): void
    {
        HostKernel::$navSources = [
            'obs' => new NavSection('Observatory', [new NavItem(label: 'Sightings', url: '/s')]),
        ];
        self::assertStringContainsString('Sightings', $this->render('@fixtures/body_only_page.html.twig'));

        HostKernel::$navSources = [
            'obs' => new NavSection('Observatory', []),
        ];
        self::assertStringNotContainsString('Sightings', $this->render('@fixtures/body_only_page.html.twig'));
    }

    /**
     * The tag is a published constant on the bundle, for the reason the contract's
     * is: a contributor writes the string by hand (a reusable bundle's services
     * are not autoconfigured) and should not be retyping it.
     */
    public function testTheContractsTagIsPublished(): void
    {
        self::assertSame('shell.nav_section', ShellBundle::NAV_TAG);
        self::assertTrue(interface_exists(NavigationSourceInterface::class));
    }

    /**
     * ZERO SOURCES IS A WORKING INSTALLATION — the contract's rule, inherited. A
     * shell with nothing to navigate renders a sidebar with a brand and no
     * rows, not an error and not a hole where the aside should be.
     */
    public function testAShellWithNothingToNavigateStillRenders(): void
    {
        HostKernel::$navSources = [];

        $crawler = $this->crawl('@fixtures/body_only_page.html.twig');

        self::assertCount(1, $crawler->filter('aside.side'));
        self::assertCount(1, $crawler->filter('a.brand'));
        self::assertCount(0, $crawler->filter('.nav-item'));
    }
}
