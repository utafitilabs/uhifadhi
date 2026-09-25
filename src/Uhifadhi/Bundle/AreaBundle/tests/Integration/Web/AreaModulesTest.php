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

use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Uhifadhi\Bundle\AreaBundle\Controller\AreaModulesController;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\Service\AreaComposition;
use Uhifadhi\Bundle\AreaBundle\Shell\AreaNavigation;
use Uhifadhi\Bundle\RegistryBundle\Entity\Module;
use Uhifadhi\Bundle\RegistryBundle\Enum\ModuleCategory;
use Uhifadhi\Bundle\RegistryBundle\Enum\ModuleStatus;
use Uhifadhi\Bundle\ShellBundle\Model\NavItem;

/**
 * THE PER-AREA MODULES SCREEN — what this area has switched on, and the shop it
 * is composed in.
 *
 * THE ROUTE NAME IS THE CONTRACT, not the path. `area_modules` is the name
 * three other packages already generate blind: this bundle's own tab strip lists
 * it, and the patrol module's breadcrumb and dashboard back-button ask for it and
 * print plain text when it does not answer. Mounting it here is what lights all
 * three, which is why {@see testTheTabStripLightsTheModulesTab} exists at all.
 *
 * THE LEDGER IS THE REGISTRY'S. Nothing here writes an `area_module` row by hand —
 * every switch goes through the registry's published service, because the invariant
 * that a pinned module cannot be parked lives there and a second writer would
 * eventually disagree with it.
 */
#[CoversClass(AreaModulesController::class)]
#[CoversClass(AreaComposition::class)]
#[CoversClass(AreaNavigation::class)]
final class AreaModulesTest extends WebTestCase
{
    private const string REORDER = 'uhifadhi--shell-bundle--reorder';

    /** Everything an ordinary admin holds, plus the module pairs this screen asks for. */
    private const array ALL = [...self::ALL_AREA_PERMISSIONS, 'modules.read', 'modules.configure'];

    public function testTheGridShowsWhatTheAreaHasSwitchedOn(): void
    {
        $this->boot(self::ALL);
        $this->signIn();
        $area = $this->anArea();
        $this->aCatalogue();
        $this->install($area, 'patrols');

        $body = $this->body($this->modulesPath($area));

        self::assertStringContainsString('Patrols', $body);
        // Parked modules are the shop's business, not the grid's: a tile for
        // something that is switched off would open onto nothing.
        self::assertStringNotContainsString('Incidents', $body);
    }

    /** The category heading the registry files the module under, not one invented here. */
    public function testTheGridGroupsTilesByCatalogueCategory(): void
    {
        $this->boot(self::ALL);
        $this->signIn();
        $area = $this->anArea();
        $this->aCatalogue();
        $this->install($area, 'patrols');
        $this->install($area, 'forest-loss');

        $body = $this->body($this->modulesPath($area));

        self::assertStringContainsString('Pressure', $body);
        self::assertStringContainsString('Flux', $body);
    }

    /**
     * A TILE WITH NOWHERE TO GO IS NOT A LINK. A catalogue row whose bundle
     * declares no entry route has no pages yet; the tile stays inert rather than
     * 404ing on a click.
     */
    public function testATileWithoutAnEntryRouteIsNotALink(): void
    {
        $this->boot(self::ALL);
        $this->signIn();
        $area = $this->anArea();
        $this->aCatalogue();
        $this->install($area, 'forest-loss');

        self::assertStringContainsString('mtile-inert', $this->body($this->modulesPath($area)));
    }

    /** An area with nothing switched on is new, not broken. */
    public function testAnAreaWithNothingSwitchedOnSaysSo(): void
    {
        $this->boot(self::ALL);
        $this->signIn();
        $area = $this->anArea();
        $this->aCatalogue();

        self::assertStringContainsString('No modules', $this->body($this->modulesPath($area)));
    }

    /**
     * THE NAME OTHER PACKAGES GENERATE. A module's breadcrumb and its dashboard
     * back-button ask the router for `area_modules`; the path is this bundle's
     * convention and may move, the name may not.
     */
    public function testTheGridAnswersToItsContractRouteName(): void
    {
        $this->boot(self::ALL);
        $area = $this->anArea();

        /** @var UrlGeneratorInterface $urls */
        $urls = static::getContainer()->get('router');

        self::assertSame(
            $this->modulesPath($area),
            $urls->generate('area_modules', ['uuid' => $area->getUuidString()]),
        );
    }

    /**
     * THE TAB IS THE ROUTE'S, NOT THE STRIP'S. AreaShellSource lists an
     * `area_modules` tab and drops it silently where no route answers, so
     * an installation that unmounts the screen gets a shorter strip rather than
     * a broken page — and mounting it lights the tab with no edit anywhere.
     */
    public function testTheTabStripLightsTheModulesTab(): void
    {
        $this->boot(self::ALL);
        $this->signIn();
        $area = $this->anArea();
        $this->aCatalogue();

        $body = $this->body($this->modulesPath($area));

        self::assertStringContainsString($this->modulesPath($area), $body);
        self::assertStringContainsString('Modules', $body);
    }

    /** The tab is absent, not greyed, for somebody who may not see modules. */
    public function testTheModulesTabIsAbsentWithoutModulesRead(): void
    {
        $this->boot(self::ALL_AREA_PERMISSIONS);
        $this->signIn();
        $area = $this->anArea();
        $this->aCatalogue();

        self::assertStringNotContainsString($this->modulesPath($area), $this->body('/areas/'.$area->getUuidString()));
    }

    public function testTheGridIsClosedWithoutModulesRead(): void
    {
        $this->boot(self::READ_ONLY_AREA_PERMISSIONS);
        $this->signIn();
        $area = $this->anArea();
        $this->aCatalogue();

        self::assertSame(403, $this->get($this->modulesPath($area))->getStatusCode());
    }

    /**
     * A ROW WHOSE BUNDLE IS GONE IS NOT A MODULE ANY MORE.
     *
     * The registry's catalogue is an INTERSECTION — rows in the table AND providers
     * registered — precisely so uninstalling a bundle removes its capability
     * without deleting anybody's data. The `area_module` row survives that, and
     * a screen reading rows straight would keep drawing a tile for a module the
     * machine no longer has: a card that can never open, forever, with no way to
     * clear it because the shop cannot offer to park something it cannot see.
     *
     * So both sides of this screen are read through the catalogue. The row stays
     * where it is, ready for the day the bundle comes back.
     */
    public function testAModuleWhoseBundleIsGoneLeavesTheScreenEntirely(): void
    {
        $this->boot(self::ALL);
        $this->signIn();
        $area = $this->anArea();
        $this->aCatalogue();
        $this->install($area, 'patrols');
        $this->aGhostRow($area);

        self::assertStringNotContainsString('Ghost', $this->body($this->modulesPath($area)));
        self::assertStringNotContainsString('Ghost', $this->body($this->configurePath($area)));
        // And the one whose bundle IS installed is untouched by the exclusion.
        self::assertStringContainsString('Patrols', $this->body($this->modulesPath($area)));
    }

    // ── The configure section ──────────────────────────────────────────────

    /**
     * ONE ROW PER CATALOGUED MODULE, RUNNING FIRST. The rows that run are in
     * the area's own order; the parked ones follow in the catalogue's. A
     * module the area has never had a row for is parked exactly like one it
     * switched off, because both are switched on the same way.
     */
    public function testTheSectionListsTheCatalogueRunningFirst(): void
    {
        $this->boot(self::ALL);
        $this->signIn();
        $area = $this->anArea();
        $this->aCatalogue();
        $this->install($area, 'forest-loss');
        $this->install($area, 'patrols');

        $body = $this->body($this->configurePath($area));

        $rows = array_map(static fn (string $slug): int|false => strpos($body, 'data-row-slug="'.$slug.'"'), ['forest-loss', 'patrols', 'incidents']);
        $sorted = $rows;
        sort($sorted);
        self::assertSame($sorted, $rows, 'running rows come first, in the area\'s order, then the parked ones');
        self::assertStringContainsString('2 running', $this->cardHead($body));
        self::assertStringContainsString('1 parked', $this->cardHead($body));
    }

    /** The name cell says what the module is, under its name, where the module says. */
    public function testARowSaysWhatTheModuleIs(): void
    {
        $this->boot(self::ALL);
        $this->signIn();
        $area = $this->anArea();
        $this->aCatalogue();

        $body = $this->body($this->configurePath($area));

        self::assertStringContainsString('<span class="cmwhat">Ranger patrols: tracks, observations and station duty.</span>', $body);
        // A module that says nothing beyond its name gets no line, not an empty one.
        self::assertSame(1, substr_count($body, 'class="cmwhat"'));
    }

    /** A running row holds a grip and its position; a parked row holds neither. */
    public function testARunningRowHoldsItsPositionAndAParkedRowNone(): void
    {
        $this->boot(self::ALL);
        $this->signIn();
        $area = $this->anArea();
        $this->aCatalogue();
        $this->install($area, 'patrols');

        $body = $this->body($this->configurePath($area));

        $patrols = $this->row($body, 'patrols');
        self::assertStringContainsString('<span class="chip ok">running</span>', $patrols);
        self::assertStringContainsString('cmgrip', $patrols);
        self::assertStringContainsString('data-position>1</span>', $patrols);
        self::assertStringContainsString('data-slug="patrols"', $patrols);

        $incidents = $this->row($body, 'incidents');
        self::assertStringContainsString('<span class="chip idle">parked</span>', $incidents);
        self::assertStringNotContainsString('cmgrip', $incidents);
        self::assertStringContainsString('<span class="none">', $incidents);
        self::assertStringNotContainsString('data-slug=', $incidents);
        self::assertStringContainsString('class="cmparked"', $incidents);
    }

    /**
     * THE DOOR IS THE MODULE'S OWN CONFIGURE PAGE, drawn only where the
     * module declares sections; a module that declares none says "no
     * settings" rather than opening onto a 404.
     */
    public function testTheOwnSettingsColumnIsADoorWhereTheModuleDeclaresSections(): void
    {
        $this->boot(self::ALL);
        $this->signIn();
        $area = $this->anArea();
        $this->aCatalogue();

        $body = $this->body($this->configurePath($area));

        $patrols = $this->row($body, 'patrols');
        self::assertStringContainsString('class="cmdoor" href="/areas/'.$area->getUuidString().'/modules/patrols/configure"', $patrols);
        self::assertStringContainsString('title="Its own settings: Widget library · Patrol types"', $patrols);

        self::assertStringContainsString('<span class="cmnodoor"', $this->row($body, 'incidents'));
    }

    /** The filters count the whole set, and the line under them says what is shown. */
    public function testTheFiltersCountTheWholeSet(): void
    {
        $this->boot(self::ALL);
        $this->signIn();
        $area = $this->anArea();
        $this->aCatalogue();
        $this->install($area, 'patrols');

        $body = $this->body($this->configurePath($area));

        self::assertSame(2, substr_count($body, '<details class="i-dd"'));
        self::assertStringContainsString('data-filter="state" data-value="running"', $body);
        self::assertStringContainsString('data-filter="settings" data-value="has"', $body);
        self::assertMatchesRegularExpression('#data-value="running"[^>]*>.*?<span class="i-ddopt-n">1</span>#s', $body);
        self::assertMatchesRegularExpression('#data-value="parked"[^>]*>.*?<span class="i-ddopt-n">2</span>#s', $body);
        self::assertMatchesRegularExpression('#data-value="has"[^>]*>.*?<span class="i-ddopt-n">1</span>#s', $body);
        self::assertMatchesRegularExpression('#data-value="none"[^>]*>.*?<span class="i-ddopt-n">2</span>#s', $body);
        self::assertStringContainsString('placeholder="Find a module"', $body);
        self::assertStringContainsString('showing <b data-uhifadhi--area-bundle--module-register-target="count">3</b> of 3', $body);
    }

    /** A freshly created area has everything parked and nothing in the order. */
    public function testAFreshAreaHasEverythingParked(): void
    {
        $this->boot(self::ALL);
        $this->signIn();
        $area = $this->anArea();
        $this->aCatalogue();

        $body = $this->body($this->configurePath($area));

        self::assertStringContainsString('0 running', $this->cardHead($body));
        self::assertStringContainsString('3 parked', $this->cardHead($body));
        self::assertStringNotContainsString('cmgrip', $body);
        self::assertSame(3, substr_count($body, 'class="cmparked"'));
    }

    /** The strip on the area's configure page carries the section, at its address. */
    public function testTheConfigureStripCarriesTheModulesSection(): void
    {
        $this->boot(self::ALL);
        $this->signIn();
        $area = $this->anArea();
        $this->aCatalogue();

        $body = $this->body($this->configurePath($area));

        self::assertStringContainsString('href="'.$this->configurePath($area).'" class="on">Modules</a>', $body);
    }

    /** The grid's affordance points at the section and is hidden from somebody who may only look. */
    public function testTheGridsAffordanceCarriesItsPermission(): void
    {
        $this->boot([...self::ALL_AREA_PERMISSIONS, 'modules.read']);
        $this->signIn();
        $area = $this->anArea();
        $this->aCatalogue();

        self::assertStringNotContainsString('/configure/modules', $this->body($this->modulesPath($area)));

        $this->boot(self::ALL);
        $this->signIn();
        $area = $this->anArea();
        $this->aCatalogue();

        self::assertStringContainsString($this->configurePath($area), $this->body($this->modulesPath($area)));
    }

    public function testTheSectionIsClosedWithoutModulesConfigure(): void
    {
        $this->boot([...self::ALL_AREA_PERMISSIONS, 'modules.read']);
        $this->signIn();
        $area = $this->anArea();
        $this->aCatalogue();

        self::assertSame(403, $this->get($this->configurePath($area))->getStatusCode());
    }

    public function testSwitchingAModuleOnRunsItHere(): void
    {
        $this->boot(self::ALL);
        $this->signIn();
        $area = $this->anArea();
        $this->aCatalogue();

        $response = $this->post($area, 'patrols/toggle', ['to' => 'on']);

        self::assertSame(302, $response->getStatusCode());
        self::assertSame($this->configurePath($area), $response->headers->get('Location'));
        self::assertSame(['patrols'], $this->activeSlugs($area));
    }

    public function testSwitchingAModuleOffParksIt(): void
    {
        $this->boot(self::ALL);
        $this->signIn();
        $area = $this->anArea();
        $this->aCatalogue();
        $this->install($area, 'patrols');

        $response = $this->post($area, 'patrols/toggle', ['to' => 'off']);

        self::assertSame(302, $response->getStatusCode());
        self::assertSame([], $this->activeSlugs($area));
    }

    /**
     * THE DATA STAYS. Parking a module removes it from the area's composition
     * and nothing else — the row survives switched off, which is what lets
     * switching it back on be a re-activation rather than a fresh install.
     */
    public function testParkingAModuleKeepsItsRow(): void
    {
        $this->boot(self::ALL);
        $this->signIn();
        $area = $this->anArea();
        $this->aCatalogue();
        $this->install($area, 'patrols');

        $this->post($area, 'patrols/toggle', ['to' => 'off']);
        $this->post($area, 'patrols/toggle', ['to' => 'on']);

        self::assertSame(['patrols'], $this->activeSlugs($area));
        self::assertCount(1, $this->em->getRepository(\Uhifadhi\Bundle\RegistryBundle\Entity\AreaModule::class)->findAll());
    }

    /** The switch carries the state it means, so a resubmitted form does not flip it back. */
    public function testTheSwitchIsIdempotent(): void
    {
        $this->boot(self::ALL);
        $this->signIn();
        $area = $this->anArea();
        $this->aCatalogue();

        $this->post($area, 'patrols/toggle', ['to' => 'on']);
        $this->post($area, 'patrols/toggle', ['to' => 'on']);

        self::assertSame(['patrols'], $this->activeSlugs($area));
    }

    /**
     * THE RUNNING ROWS MOVE BY THE SHELL'S REORDER CONTROL — ruled 2026-09-25.
     * A row is dragged by its grip, stepped by its carets or by the arrow keys
     * on the grip, and every move posts the new order to the reorder route.
     */
    public function testTheRunningRowsMoveByTheShellsReorderControlWithCaretsAtBothEnds(): void
    {
        $this->boot(self::ALL);
        $this->signIn();
        $area = $this->anArea();
        $this->aCatalogue();
        $this->install($area, 'patrols');
        $this->install($area, 'incidents');
        $this->install($area, 'forest-loss');

        $crawler = new Crawler($this->body($this->configurePath($area)), 'http://localhost');
        $card = $crawler->filter('[data-controller~="'.self::REORDER.'"]');
        self::assertCount(1, $card);
        self::assertStringContainsString('uhifadhi--area-bundle--module-register', (string) $card->attr('data-controller'));
        self::assertSame($this->configurePath($area).'/reorder', $card->attr('data-'.self::REORDER.'-url-value'));
        self::assertNotSame('', (string) $card->attr('data-'.self::REORDER.'-token-value'));
        self::assertSame('1', $card->attr('data-'.self::REORDER.'-first-value'), 'no pinned row holds the front here, so the first movable row is number 1');

        $rows = $card->filter('tr[data-'.self::REORDER.'-target="row"]');
        self::assertSame(['patrols', 'incidents', 'forest-loss'], $rows->each(static fn (Crawler $row): string => (string) $row->attr('data-reorder-key')));
        $names = ['Patrols', 'Incidents', 'Forest loss'];
        foreach ($names as $i => $name) {
            $row = $rows->eq($i);
            self::assertSame($name, $row->attr('data-reorder-name'));

            $grip = $row->filter('button.cmgrip');
            self::assertCount(1, $grip, 'the grip is a button, so the keyboard reaches it');
            self::assertSame('button', $grip->attr('type'));
            self::assertSame('Move '.$name, $grip->attr('aria-label'));
            self::assertSame('grip', $grip->attr('data-'.self::REORDER.'-target'));
            $actions = (string) $grip->attr('data-action');
            foreach (['pointerdown->'.self::REORDER.'#grab', 'pointermove->'.self::REORDER.'#move', 'pointerup->'.self::REORDER.'#drop', 'pointercancel->'.self::REORDER.'#cancel', 'keydown.up->'.self::REORDER.'#up:prevent', 'keydown.down->'.self::REORDER.'#down:prevent', 'keydown.esc->'.self::REORDER.'#cancel'] as $action) {
                self::assertStringContainsString($action, $actions);
            }

            $carets = $row->filter('.cmord .reorder button');
            self::assertCount(2, $carets);
            self::assertSame('Move '.$name.' up', $carets->eq(0)->attr('aria-label'));
            self::assertSame('Move '.$name.' down', $carets->eq(1)->attr('aria-label'));
            self::assertSame(self::REORDER.'#up', $carets->eq(0)->attr('data-action'));
            self::assertSame(self::REORDER.'#down', $carets->eq(1)->attr('data-action'));
            self::assertSame(0 === $i, null !== $carets->eq(0)->attr('disabled'), 'only the first running row\'s up is disabled');
            self::assertSame(2 === $i, null !== $carets->eq(1)->attr('disabled'), 'only the last running row\'s down is disabled');
            self::assertCount(2, $carets->filter('svg'), 'lucide chevron-up and chevron-down');

            self::assertSame('number', $row->filter('[data-position]')->attr('data-'.self::REORDER.'-target'));
        }

        $status = $card->filter('[data-'.self::REORDER.'-target="status"]');
        self::assertCount(1, $status);
        self::assertSame('polite', $status->attr('aria-live'));
        self::assertStringContainsString('visually-hidden', (string) $status->attr('class'));

        self::assertStringNotContainsString('module-order', $crawler->html(), 'the retired controller is named nowhere on the page');
    }

    /** A parked row has no place in the order, so nothing on it moves. */
    public function testAParkedRowCarriesNoCaretsAndIsNoRow(): void
    {
        $this->boot(self::ALL);
        $this->signIn();
        $area = $this->anArea();
        $this->aCatalogue();
        $this->install($area, 'patrols');

        $incidents = $this->row($this->body($this->configurePath($area)), 'incidents');
        self::assertStringNotContainsString('class="reorder"', $incidents);
        self::assertStringNotContainsString('-target="row"', $incidents);

        $patrols = $this->row($this->body($this->configurePath($area)), 'patrols');
        self::assertSame(2, substr_count($patrols, ' disabled'), 'the only running row is both ends: up and down both sleep');
    }

    /** The order the rows are dragged into is persisted, and the page is redrawn in it. */
    public function testTheOrderTheRowsAreDraggedIntoIsPersistedAndRedrawn(): void
    {
        $this->boot(self::ALL);
        $this->signIn();
        $area = $this->anArea();
        $this->aCatalogue();
        $this->install($area, 'patrols');
        $this->install($area, 'incidents');
        $this->install($area, 'forest-loss');

        $this->post($area, 'reorder', ['order' => ['forest-loss', 'incidents', 'patrols']]);

        self::assertSame(['forest-loss', 'incidents', 'patrols'], $this->activeSlugs($area));

        $rows = $this->body($this->configurePath($area));
        $positions = array_map(static fn (string $slug): int|false => strpos($rows, 'data-slug="'.$slug.'"'), ['forest-loss', 'incidents', 'patrols']);
        $sorted = $positions;
        sort($sorted);
        self::assertSame($sorted, $positions, 'The section is not redrawn in the order it was just given.');
        self::assertStringContainsString('data-position>3</span>', $this->row($rows, 'patrols'));
    }

    /** A write without the permission is refused, and nothing moves. */
    public function testTogglingIsClosedWithoutModulesConfigure(): void
    {
        $this->boot([...self::ALL_AREA_PERMISSIONS, 'modules.read']);
        $this->signIn();
        $area = $this->anArea();
        $this->aCatalogue();

        $this->browser()->request('POST', $this->configurePath($area).'/patrols/toggle', ['to' => 'on', '_token' => 'x']);

        self::assertSame(403, $this->browser()->getResponse()->getStatusCode());
        self::assertSame([], $this->activeSlugs($area));
    }

    /** A forged POST from another page is refused before it writes. */
    public function testAWriteWithoutAValidTokenIsRefused(): void
    {
        $this->boot(self::ALL);
        $this->signIn();
        $area = $this->anArea();
        $this->aCatalogue();

        $this->browser()->request('POST', $this->configurePath($area).'/patrols/toggle', ['to' => 'on', '_token' => 'forged']);

        self::assertSame(403, $this->browser()->getResponse()->getStatusCode());
        self::assertSame([], $this->activeSlugs($area));
    }

    /** A slug that is in no catalogue writes nothing and does not explode. */
    public function testAnUnknownModuleIsIgnored(): void
    {
        $this->boot(self::ALL);
        $this->signIn();
        $area = $this->anArea();
        $this->aCatalogue();

        self::assertSame(302, $this->post($area, 'nonesuch/toggle', ['to' => 'on'])->getStatusCode());
        self::assertSame([], $this->activeSlugs($area));
    }

    /**
     * THE SIDEBAR'S MODULES BRANCH UNFOLDS TO THE AREA'S OWN MODULES.
     *
     * The reported miss: the tree drew "Modules" but nothing under it, so a
     * ranger on a module page could not see where they were. The branch is the
     * SAME set the grid draws (one reader, {@see AreaComposition::moduleLinksFor}),
     * so a module cannot be in the grid and absent from the tree. On a module's
     * page the leaf is the lit row and "Modules" is only the open branch above it.
     */
    public function testTheSidebarModulesBranchUnfoldsToTheAreasOwnModules(): void
    {
        $this->boot(self::ALL);
        $this->signIn();
        $area = $this->anArea('Northern Reserve');
        $this->aCatalogue();
        $this->install($area, 'patrols');

        // Stand inside the modules space — on the patrols module's own page.
        /** @var RequestStack $stack */
        $stack = static::getContainer()->get('request_stack');
        $stack->push(Request::create($this->modulesPath($area).'/patrols'));

        /** @var AreaNavigation $nav */
        $nav = static::getContainer()->get('test_public.area.navigation');

        $sections = array_values([...$nav->sections()]);
        $areaRow = $sections[0]->items[0]->children[0]; // Observatory › Areas › Northern Reserve
        $modules = $this->childNamed($areaRow, 'Modules');

        self::assertNotNull($modules, 'the Modules screen carries a branch of the area\'s modules');
        self::assertContains(
            'Patrols',
            array_map(static fn (NavItem $i): string => $i->label, $modules->children),
            'the area\'s active modules hang under Modules',
        );

        $patrols = $this->childNamed($modules, 'Patrols');
        self::assertNotNull($patrols);
        self::assertTrue($patrols->current, 'the module you are on is the lit leaf');
        self::assertFalse($modules->current, 'the parent yields the light to the leaf it is showing');
        self::assertTrue($modules->open, 'the branch is unfolded while you are inside it');
    }

    private function childNamed(NavItem $item, string $label): ?NavItem
    {
        foreach ($item->children as $child) {
            if ($child->label === $label) {
                return $child;
            }
        }

        return null;
    }

    // ── Fixtures and helpers ──────────────────────────────────────────────

    /**
     * A CATALOGUE OF ROWS ONLY. The registry's ModuleCatalogue intersects rows with
     * registered providers, so this kernel registers the matching providers —
     * see {@see WebKernel}. What is asserted is this bundle's reading of the
     * ledger, not the registry's own catalogue rules.
     */
    private function aCatalogue(): void
    {
        foreach ([
            ['patrols', 'Patrols', ModuleCategory::Pressure, ModuleStatus::Live, 'GPS field tracks', 'Ranger patrols: tracks, observations and station duty.'],
            ['incidents', 'Incidents', ModuleCategory::Pressure, ModuleStatus::Live, 'field reports', null],
            ['forest-loss', 'Forest loss', ModuleCategory::Flux, ModuleStatus::Template, 'Hansen GFC', null],
        ] as $i => [$slug, $name, $category, $status, $source, $description]) {
            $this->em->persist(new Module()
                ->setSlug($slug)
                ->setName($name)
                ->setDescription($description)
                ->setCategory($category)
                ->setStatus($status)
                ->setDataSource($source)
                ->setPosition($i));
        }
        $this->em->flush();
    }

    /**
     * A `module` row with no provider behind it, switched on for this area — the
     * state an installation is in the moment it removes a module bundle. Written
     * directly because the registry's own install() refuses a slug the catalogue
     * cannot see, which is the rule being relied on here.
     */
    private function aGhostRow(AreaOfInterest $area): void
    {
        $ghost = new Module()
            ->setSlug('ghost')
            ->setName('Ghost')
            ->setCategory(ModuleCategory::Operations)
            ->setStatus(ModuleStatus::Live)
            ->setDataSource('a bundle that was uninstalled')
            ->setPosition(9);
        $this->em->persist($ghost);
        $this->em->persist(new \Uhifadhi\Bundle\RegistryBundle\Entity\AreaModule()->setArea($area)->setModule($ghost)->setActive(true)->setPosition(9));
        $this->em->flush();
    }

    private function install(AreaOfInterest $area, string $slug): void
    {
        /** @var \Uhifadhi\Bundle\RegistryBundle\Service\AreaModuleService $modules */
        $modules = static::getContainer()->get('test_public.registry.area_modules');
        $modules->install($area, $slug);
    }

    /** @return list<string> */
    private function activeSlugs(AreaOfInterest $area): array
    {
        $this->em->clear();
        $fresh = $this->em->getRepository(AreaOfInterest::class)->find((int) $area->getId());
        self::assertInstanceOf(AreaOfInterest::class, $fresh);

        /** @var \Uhifadhi\Bundle\RegistryBundle\Service\AreaModuleService $modules */
        $modules = static::getContainer()->get('test_public.registry.area_modules');

        return array_map(
            static fn (\Uhifadhi\Bundle\RegistryBundle\Entity\AreaModule $am): string => (string) $am->getModule()?->getSlug(),
            $modules->activeFor($fresh),
        );
    }

    private function modulesPath(AreaOfInterest $area): string
    {
        return '/areas/'.$area->getUuidString().'/modules';
    }

    private function get(string $path): Response
    {
        $this->browser()->request('GET', $path);

        return $this->browser()->getResponse();
    }

    private function body(string $path): string
    {
        return (string) $this->get($path)->getContent();
    }

    private function configurePath(AreaOfInterest $area): string
    {
        return '/areas/'.$area->getUuidString().'/configure/modules';
    }

    /** The register's card head: what it is called and what it counts. */
    private function cardHead(string $body): string
    {
        preg_match('#<span class="tab">.*?</span>\s*<span class="lib">.*?</span>#s', $body, $m);

        return $m[0] ?? '';
    }

    /** One row of the register, by the slug the template stamps on it. */
    private function row(string $body, string $slug): string
    {
        preg_match('#<tr[^>]*data-row-slug="'.$slug.'".*?</tr>#s', $body, $m);
        self::assertNotSame('', $m[0] ?? '', 'no row for '.$slug);

        return $m[0];
    }

    /**
     * @param array<string, string|list<string>> $parameters
     */
    private function post(AreaOfInterest $area, string $action, array $parameters): Response
    {
        // The token the section mints for this area, read back from the page
        // the control lives on rather than generated here: a test that mints
        // its own proves nothing about the form.
        preg_match('#name="_token" value="([^"]+)"#', $this->body($this->configurePath($area)), $m);
        $parameters['_token'] = $m[1] ?? '';
        $this->browser()->request('POST', $this->configurePath($area).'/'.$action, $parameters);

        return $this->browser()->getResponse();
    }
}
