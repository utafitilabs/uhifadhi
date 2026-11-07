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

namespace Uhifadhi\Bundle\AreaBundle\Tests\Integration\Web;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\Shell\AreaNavigation;
use Uhifadhi\Bundle\RegistryBundle\Entity\Module;
use Uhifadhi\Bundle\RegistryBundle\Enum\ModuleCategory;
use Uhifadhi\Bundle\RegistryBundle\Enum\ModuleStatus;
use Uhifadhi\Bundle\RegistryBundle\Service\AreaModuleService;
use Uhifadhi\Bundle\ShellBundle\Model\NavItem;
use Uhifadhi\Bundle\ShellBundle\Model\NavSection;

/**
 * THE AREAS SECTION OF THE SIDEBAR — the register, and under it every area with
 * its own screens unfolded.
 *
 * GATING IS THIS BUNDLE'S JOB, not the shell's: the shell holds no
 * authorization service and asks nothing about the viewer. A row somebody may
 * not have is ABSENT from what this returns, never hidden — a hidden row is a
 * row that leaks its existence to whoever reads the HTML.
 */
final class AreaNavigationTest extends WebTestCase
{
    /**
     * A viewer who may also see the area's modules. The Modules screen is the
     * only one of the area's own that carries a gate, and it is the branch the
     * fourth rung hangs from.
     *
     * @var list<string>
     */
    private const array WITH_MODULES = [...self::ALL_AREA_PERMISSIONS, 'modules.read'];

    /** @param list<string> $grants */
    private function navAt(string $path, array $grants = self::ALL_AREA_PERMISSIONS): AreaNavigation
    {
        if (!isset($this->em)) {
            $this->boot($grants);
        }
        $this->signIn();

        /** @var RequestStack $stack */
        $stack = static::getContainer()->get('request_stack');
        $stack->push(Request::create($path));

        /** @var AreaNavigation $nav */
        $nav = static::getContainer()->get('test_public.area.navigation');

        return $nav;
    }

    /** @return list<NavSection> */
    private function sections(AreaNavigation $nav): array
    {
        return array_values([...$nav->sections()]);
    }

    public function testItContributesAnAreasRowUnderItsOwnHeading(): void
    {
        $this->boot();
        $this->anArea();

        $sections = $this->sections($this->navAt('/areas'));

        self::assertCount(1, $sections);
        self::assertSame(AreaNavigation::SECTION, $sections[0]->label);
        self::assertSame('Areas', $sections[0]->items[0]->label);
    }

    /**
     * THE MARK IS THE DESIGN'S, AND IT IS LUCIDE'S. The section row wears
     * the house map mark — the same glyph the register and every plate are
     * read under — and the areas under it wear NONE: the design gives a
     * place its name in the place rung's own weight, and a glyph repeated
     * down a branch reads as a second kind of thing rather than the same
     * thing twice.
     */
    public function testTheSectionRowWearsTheMapMarkAndThePlacesUnderItWearNone(): void
    {
        $this->boot();
        $area = $this->anArea('Northern Conservation Reserve');

        $row = $this->sections($this->navAt('/areas/'.$area->getUuidString()))[0]->items[0];

        self::assertSame('shell:map', $row->icon);
        self::assertSame([null], array_map(static fn (NavItem $i): ?string => $i->icon, $row->children));
    }

    /** A declared position, not a hope about container compilation order. */
    public function testItDeclaresWhereItSitsAmongTheOtherSections(): void
    {
        $this->boot();
        $this->anArea();

        self::assertSame(AreaNavigation::POSITION, $this->sections($this->navAt('/areas'))[0]->position);
    }

    /**
     * EVERY AREA UNFOLDS TO ITS OWN SCREENS, and they are the SAME list the tab
     * strip is drawn from — so a screen cannot appear in one and not the other.
     */
    public function testEachAreaUnfoldsToTheScreensTheTabStripWouldShow(): void
    {
        $this->boot();
        $area = $this->anArea('Northern Conservation Reserve');

        $areas = $this->sections($this->navAt('/areas/'.$area->getUuidString()))[0]->items[0]->children;

        self::assertCount(1, $areas);
        self::assertSame('Northern Conservation Reserve', $areas[0]->label);
        self::assertSame(
            ['Overview', 'Zones', 'Stations'],
            array_map(static fn (NavItem $i): string => $i->label, $areas[0]->children),
        );
    }

    /** Areas are listed by name — insertion order is invisible to anybody reading. */
    public function testTheAreasAreListedByName(): void
    {
        $this->boot();
        $this->anArea('Western Reserve');
        $this->anArea('Eastern Reserve');

        $areas = $this->sections($this->navAt('/areas'))[0]->items[0]->children;

        self::assertSame(
            ['Eastern Reserve', 'Western Reserve'],
            array_map(static fn (NavItem $i): string => $i->label, $areas),
        );
    }

    /**
     * ONLY THE AREA BEING VIEWED IS MARKED, and that is the whole of this
     * source's answer about folding. An installation with eight areas would
     * otherwise open with forty rows — but WHICH branch is unfolded is the
     * shell's derivation now (ruled 2026-09-20, one tree state for every
     * section), taken from exactly this mark and pinned frame by frame in
     * ShellBundle's SidebarTreeStateTest. A source that also decided it gave
     * the same page two trees depending on which bundle drew it.
     */
    public function testOnlyTheAreaBeingViewedIsMarked(): void
    {
        $this->boot();
        $here = $this->anArea('Aardvark Area');
        $this->anArea('Zebra Area');

        $areas = $this->sections($this->navAt('/areas/'.$here->getUuidString()))[0]->items[0]->children;

        self::assertTrue($areas[0]->current, 'the area being viewed is where the viewer is');
        self::assertFalse($areas[1]->current, 'the others are not');
    }

    /** The register row lights on the register itself, never on an area beneath it. */
    public function testTheRegisterRowLightsOnlyOnTheRegister(): void
    {
        $this->boot();
        $area = $this->anArea();

        self::assertTrue($this->sections($this->navAt('/areas'))[0]->items[0]->current);

        self::assertFalse(
            $this->sections($this->navAt('/areas/'.$area->getUuidString()))[0]->items[0]->current,
        );
    }

    /**
     * ABSENT, NEVER HIDDEN. Somebody without `areas.read` gets no section at all,
     * rather than a section whose rows all close in their face.
     */
    public function testSomebodyWhoMayNotSeeAnAreaGetsNoSectionAtAll(): void
    {
        $this->boot([]);
        $this->anArea();

        self::assertSame([], $this->sections($this->navAt('/areas', [])));
    }

    /**
     * NO TOKEN, NO QUESTION. A page can render outside any firewall — an error
     * page, a console-rendered template — and asking the authorization checker
     * there THROWS rather than answering false. A viewer nobody can identify
     * holds nothing, which is the same answer without the 500.
     */
    public function testThereIsNoSectionWhereThereIsNoViewerToAskAbout(): void
    {
        $this->boot();
        $this->anArea();

        /** @var RequestStack $stack */
        $stack = static::getContainer()->get('request_stack');
        $stack->push(Request::create('/areas'));

        /** @var AreaNavigation $nav */
        $nav = static::getContainer()->get('test_public.area.navigation');

        // Deliberately NOT signed in.
        self::assertSame([], $this->sections($nav));
    }

    /** An installation with no areas yet still offers the register itself. */
    public function testTheRegisterRowIsThereBeforeAnyAreaIs(): void
    {
        $this->boot();

        $sections = $this->sections($this->navAt('/areas'));

        self::assertCount(1, $sections);
        self::assertSame([], $sections[0]->items[0]->children);
    }

    /**
     * THE SIDEBAR'S FOURTH RUNG — a module unfolds to its OWN data places, and
     * they are the same list the strip under the module's head is drawn from,
     * because there is only one declaration for both to read.
     */
    public function testTheModuleBeingViewedUnfoldsToItsOwnDataPlaces(): void
    {
        $this->boot(self::WITH_MODULES);
        $area = $this->anArea('Northern Conservation Reserve');
        $this->aCatalogue();
        $this->install($area, 'patrols');
        $uuid = (string) $area->getUuidString();

        $modules = $this->modulesBranch($this->navAtRoute('/areas/'.$uuid.'/modules/patrols', 'test_module_entry', self::WITH_MODULES));

        self::assertSame('Patrols', $modules[0]->label);
        self::assertSame(
            ['Overview', 'Patrols'],
            array_map(static fn (NavItem $i): string => $i->label, $modules[0]->children),
        );
    }

    /**
     * THE MODULE IS THE OPEN ANCESTOR AND THE PLACE UNDER IT CARRIES THE LIGHT.
     * Exactly one row in the branch is lit, and on a module's list screen it is
     * the leaf rather than the module.
     */
    public function testTheLeafCarriesTheLightAndTheModuleIsOnlyTheBranchAboveIt(): void
    {
        $this->boot(self::WITH_MODULES);
        $area = $this->anArea('Northern Conservation Reserve');
        $this->aCatalogue();
        $this->install($area, 'patrols');
        $uuid = (string) $area->getUuidString();

        $modules = $this->modulesBranch($this->navAtRoute('/areas/'.$uuid.'/modules/patrols/patrols', 'test_module_list', self::WITH_MODULES));

        self::assertFalse($modules[0]->current, 'the module is the ancestor, not the lit row');
        self::assertTrue($modules[0]->open);
        self::assertSame(
            ['Patrols'],
            array_map(
                static fn (NavItem $i): string => $i->label,
                array_values(array_filter($modules[0]->children, static fn (NavItem $i): bool => $i->current)),
            ),
        );
    }

    /**
     * ONE LIT ROW IN THE WHOLE TREE, AND IT IS THE DEEPEST ONE. An ancestor is
     * an ancestor however many rungs down the light is: the module's own screen
     * is lit, so neither the module nor the area's `Modules` screen above it may
     * claim the light as well. Three accented rows in one branch answer "where
     * am I" three times.
     */
    public function testTheTreeLightsTheDeepestRowAndNoAncestorOfIt(): void
    {
        $this->boot(self::WITH_MODULES);
        $area = $this->anArea('Northern Conservation Reserve');
        $this->aCatalogue();
        $this->install($area, 'patrols');
        $uuid = (string) $area->getUuidString();

        $row = $this->areaRow($this->navAtRoute('/areas/'.$uuid.'/modules/patrols', 'test_module_entry', self::WITH_MODULES));

        self::assertSame(['Overview'], self::litUnder($row));
    }

    /**
     * AND ON A RECORD PAGE THE LIT ROW IS THE LIST SCREEN THAT LED THERE — still
     * one row, still the deepest. A record is inside a place rather than being
     * one, so the place it belongs to is what the tree names.
     */
    public function testARecordPageLightsTheListScreenAndNothingAboveIt(): void
    {
        $this->boot(self::WITH_MODULES);
        $area = $this->anArea('Northern Conservation Reserve');
        $this->aCatalogue();
        $this->install($area, 'patrols');
        $uuid = (string) $area->getUuidString();

        $row = $this->areaRow($this->navAtRoute('/areas/'.$uuid.'/modules/patrols/patrols', 'test_module_list', self::WITH_MODULES));

        self::assertSame(['Patrols'], self::litUnder($row));
    }

    /** EVERY PAGE OF THE STATIONS HUB LIGHTS THE STATIONS ROW, the register's and the postings' as the Overview's. */
    public function testEveryPageOfTheStationsHubLightsTheStationsRow(): void
    {
        $this->boot();
        $uuid = (string) $this->anArea('Northern Conservation Reserve')->getUuidString();

        foreach (['area_stations' => '', 'area_stations_list' => '/list', 'area_stations_postings' => '/postings'] as $route => $suffix) {
            $lit = [];
            foreach ($this->areaRow($this->navAtRoute('/areas/'.$uuid.'/stations'.$suffix, $route))->children as $screen) {
                if ($screen->current || [] !== self::litUnder($screen)) {
                    $lit[] = $screen->label;
                }
            }
            self::assertSame(['Stations'], $lit, $route);
        }
    }

    /**
     * THE AREA ROW MARKS THE PLACE THE VIEWER IS IN, LIT LEAF OR NOT. The
     * design draws the place rung as `cur` rather than as the accent, so it says
     * "you are inside here" alongside the one accented row below it, and stops
     * saying it the moment the viewer leaves the area.
     */
    public function testTheAreaRowMarksThePlaceTheViewerIsInEvenWithALitLeafBelowIt(): void
    {
        $this->boot(self::WITH_MODULES);
        $area = $this->anArea('Northern Conservation Reserve');
        $this->aCatalogue();
        $this->install($area, 'patrols');
        $uuid = (string) $area->getUuidString();

        $row = $this->areaRow($this->navAtRoute('/areas/'.$uuid.'/modules/patrols', 'test_module_entry', self::WITH_MODULES));

        self::assertTrue($row->current, 'the place the viewer is in is marked');
        self::assertFalse(
            $this->areaRow($this->navAt('/areas'))->current,
            'and it is not marked from outside the area',
        );
    }

    /**
     * A MODULE THE VIEWER IS NOT IN STAYS FOLDED AND EMPTY. Drilling every
     * module of every area would build rows nobody can see, and the point of the
     * rung is to show where you are.
     */
    public function testAModuleTheViewerIsNotInsideDoesNotUnfold(): void
    {
        $this->boot(self::WITH_MODULES);
        $area = $this->anArea('Northern Conservation Reserve');
        $this->aCatalogue();
        $this->install($area, 'patrols');

        $modules = $this->modulesBranch($this->navAtRoute('/areas/'.$area->getUuidString().'/modules', 'area_modules', self::WITH_MODULES));

        self::assertNotSame([], $modules, 'the area has a module to fold');

        foreach ($modules as $module) {
            self::assertSame([], $module->children, $module->label.' unfolded from outside');
        }
    }

    /**
     * A CONFIGURE PAGE IS INSIDE THE PLACE IT CONFIGURES. The tree says where
     * you are, and on the area's configure page you are still in the area — so
     * its group stays open and its screens stay listed, with none of them lit,
     * exactly as on a data page. Collapsing the branch there tells a person they
     * have left the area, which they have not.
     */
    public function testTheAreasGroupStaysOpenOnItsConfigurePageWithNoScreenLit(): void
    {
        $this->boot(self::WITH_MODULES);
        $area = $this->anArea('Northern Conservation Reserve');
        $uuid = (string) $area->getUuidString();

        $row = $this->areaRow($this->navAtRoute('/areas/'.$uuid.'/configure', 'shell_area_configure', self::WITH_MODULES));

        self::assertTrue($row->open, 'the area group folds shut on its own configure page');
        self::assertSame(
            ['Overview', 'Modules', 'Zones', 'Stations'],
            array_map(static fn (NavItem $i): string => $i->label, $row->children),
        );
        self::assertSame([], array_values(array_filter(
            $row->children,
            static fn (NavItem $i): bool => $i->current,
        )), 'a configure page is none of the area\'s screens, so none is lit');
    }

    /**
     * AND ONE RUNG DEEPER, for the same reason: a module's configure page is
     * inside the module, so the module stays the open ancestor with its data
     * places listed under it — and none of them lit, because a configure page is
     * not one of them.
     */
    public function testTheModuleStaysOpenOnItsConfigurePageWithNoDataPlaceLit(): void
    {
        $this->boot(self::WITH_MODULES);
        $area = $this->anArea('Northern Conservation Reserve');
        $this->aCatalogue();
        $this->install($area, 'patrols');
        $uuid = (string) $area->getUuidString();

        $modules = $this->modulesBranch($this->navAtRoute(
            '/areas/'.$uuid.'/modules/patrols/configure',
            'shell_module_configure',
            self::WITH_MODULES,
            ['slug' => 'patrols'],
        ));

        self::assertSame('Patrols', $modules[0]->label);
        self::assertTrue($modules[0]->open, 'the module folds shut on its own configure page');
        self::assertFalse($modules[0]->current, 'the module is the open ancestor, never the lit row');
        self::assertSame(
            ['Overview', 'Patrols'],
            array_map(static fn (NavItem $i): string => $i->label, $modules[0]->children),
        );
        self::assertSame([], array_values(array_filter(
            $modules[0]->children,
            static fn (NavItem $i): bool => $i->current,
        )));
    }

    /**
     * THE FIFTH RUNG, AND THE ONE THAT IS COLOURED BY A VALUE. The zones of
     * the area being viewed hang under its Zones row, each carrying the hue
     * the plate and the key draw it in — a colour that comes from the set's
     * own order, so there is no class for a stylesheet to declare.
     */
    public function testTheZonesOfTheAreaBeingViewedHangUnderTheZonesRowWithTheirCategories(): void
    {
        $this->boot();
        $area = $this->anArea('Northern Conservation Reserve');
        $this->aZone($area, 'Western Sector', self::A_WEST_HALF);
        $uuid = (string) $area->getUuidString();

        $zones = $this->zonesBranch($this->navAt('/areas/'.$uuid.'/zones'));

        self::assertSame(['Western Sector'], array_map(static fn (NavItem $i): string => $i->label, $zones));
        // A CATEGORY AND NEVER A COLOUR: the register's order gave this zone
        // its place in the product's nine, and the shell resolves the token.
        self::assertMatchesRegularExpression('/^var\(--cat-[1-9]\)$/', (string) $zones[0]->swatch);
        self::assertStringContainsString('/zones/', (string) $zones[0]->url);
    }

    /** Standing on a zone, the zone carries the light and Zones is the branch above it. */
    public function testAPickedZoneCarriesTheLightAndZonesIsOnlyTheBranchAboveIt(): void
    {
        $this->boot();
        $area = $this->anArea('Northern Conservation Reserve');
        $zone = $this->aZone($area, 'Western Sector', self::A_WEST_HALF);
        $uuid = (string) $area->getUuidString();

        $nav = $this->navAtRoute(
            '/areas/'.$uuid.'/zones/'.$zone->getUuidString(),
            'area_zone_show',
            self::ALL_AREA_PERMISSIONS,
            ['uuid' => $uuid, 'zone' => (string) $zone->getUuidString()],
        );

        self::assertSame(['Western Sector'], self::litUnder($this->areaRow($nav)));
    }

    /** An area the viewer is not inside does not drill its zones. */
    public function testAnAreaTheViewerIsNotInsideDoesNotUnfoldItsZones(): void
    {
        $this->boot();
        $area = $this->anArea('Northern Conservation Reserve');
        $this->aZone($area, 'Western Sector', self::A_WEST_HALF);

        self::assertSame([], $this->zonesBranch($this->navAt('/areas')));
    }

    /**
     * A SCREEN ANOTHER BUNDLE UNFOLDS. The area's Zones row unfolds to its
     * zones and its Modules row to its modules, because this bundle owns
     * both; its Departments row unfolds to departments, which it may not
     * name — so the rungs are contributed, and the tree draws them the same
     * way it draws its own.
     */
    public function testAScreenAnotherBundleUnfoldsCarriesTheRungsItContributes(): void
    {
        $this->boot();
        $area = $this->anArea('Northern Conservation Reserve');

        $branch = $this->contributedBranch($this->navAt('/areas/'.$area->getUuidString()));

        self::assertSame(
            ['Contributed one', 'Contributed two'],
            array_map(static fn (NavItem $i): string => $i->label, $branch),
        );
    }

    /**
     * ONLY THE DEEPEST ROW CARRIES THE LIGHT, and the branch opens because
     * something inside it is lit — the rule every other branch of this tree
     * follows.
     */
    public function testTheScreenAboveALitRungIsOpenAndNotItselfLit(): void
    {
        $this->boot();
        $area = $this->anArea('Northern Conservation Reserve');

        $screen = $this->screenRow($this->navAt('/areas/'.$area->getUuidString().'/stations'), 'Stations');

        self::assertNotNull($screen);
        self::assertTrue($screen->open);
        self::assertFalse($screen->current, 'the lit rung is the one below');
        self::assertSame(['Contributed one'], self::litUnder($screen));
    }

    /** From outside the area nothing is drilled: a contribution costs a query. */
    public function testAnAreaNobodyIsLookingAtDoesNotAskItsContributors(): void
    {
        $this->boot();
        $this->anArea('Northern Conservation Reserve');

        self::assertSame([], $this->contributedBranch($this->navAt('/areas')));
    }

    /**
     * The contributed rows under the screen the stand-in names.
     *
     * @return list<NavItem>
     */
    private function contributedBranch(AreaNavigation $nav): array
    {
        $screen = $this->screenRow($nav, 'Stations');

        return null === $screen ? [] : $screen->children;
    }

    private function screenRow(AreaNavigation $nav, string $label): ?NavItem
    {
        foreach ($this->areaRow($nav)->children as $screen) {
            if ($label === $screen->label) {
                return $screen;
            }
        }

        return null;
    }

    /**
     * The zone rows under the area's Zones screen.
     *
     * @return list<NavItem>
     */
    private function zonesBranch(AreaNavigation $nav): array
    {
        foreach ($this->areaRow($nav)->children as $screen) {
            if ('Zones' === $screen->label) {
                return $screen->children;
            }
        }

        return [];
    }

    /** The row for the one area this suite creates. */
    private function areaRow(AreaNavigation $nav): NavItem
    {
        return $this->sections($nav)[0]->items[0]->children[0];
    }

    /**
     * Every lit row BENEATH a row, at any depth — the whole branch rather than
     * the one rung under it, because that is the difference a lit ancestor hides.
     *
     * @return list<string>
     */
    private static function litUnder(NavItem $row): array
    {
        $lit = [];
        foreach ($row->children as $child) {
            if ($child->current) {
                $lit[] = $child->label;
            }
            $lit = [...$lit, ...self::litUnder($child)];
        }

        return $lit;
    }

    /**
     * The catalogue this suite's modules are filed in. Written here rather than
     * read from a module bundle, because this bundle depends on none.
     */
    private function aCatalogue(): void
    {
        $this->em->persist(new Module()
            ->setSlug('patrols')
            ->setName('Patrols')
            ->setCategory(ModuleCategory::Pressure)
            ->setStatus(ModuleStatus::Live)
            ->setDataSource('GPS field tracks')
            ->setPosition(0));
        $this->em->flush();
    }

    /** Switch a module on for an area, through the registry's own ledger. */
    private function install(AreaOfInterest $area, string $slug): void
    {
        /** @var AreaModuleService $modules */
        $modules = static::getContainer()->get('test_public.registry.area_modules');
        $modules->install($area, $slug);
    }

    /**
     * The rows hanging off the area's Modules screen — the third rung's branch.
     *
     * @return list<NavItem>
     */
    private function modulesBranch(AreaNavigation $nav): array
    {
        $screens = $this->sections($nav)[0]->items[0]->children[0]->children;

        foreach ($screens as $screen) {
            if ('Modules' === $screen->label) {
                return $screen->children;
            }
        }

        self::fail('the area has no Modules screen to hang modules from');
    }

    /**
     * The nav as it renders on a named ROUTE, not merely a path: the fourth rung
     * lights by route name, which is how a module says which of its screens are
     * the same place.
     *
     * THE ROUTER'S OWN ATTRIBUTES ARE SET TOO — the route name and the area the
     * path names. A request built without them is a request no router produced,
     * and every source in the frame reads the area off exactly those attributes.
     *
     * @param list<string>          $grants
     * @param array<string, string> $attributes
     */
    private function navAtRoute(string $path, string $route, array $grants = self::ALL_AREA_PERMISSIONS, array $attributes = []): AreaNavigation
    {
        if (!isset($this->em)) {
            $this->boot($grants);
        }
        $this->signIn();

        $request = Request::create($path);
        $request->attributes->set('_route', $route);
        if (1 === preg_match('#^/areas/([^/]+)#', $path, $matched)) {
            $request->attributes->set('uuid', $matched[1]);
        }
        foreach ($attributes as $name => $value) {
            $request->attributes->set($name, $value);
        }

        /** @var RequestStack $stack */
        $stack = static::getContainer()->get('request_stack');
        $stack->push($request);

        /** @var AreaNavigation $nav */
        $nav = static::getContainer()->get('test_public.area.navigation');

        return $nav;
    }
}
