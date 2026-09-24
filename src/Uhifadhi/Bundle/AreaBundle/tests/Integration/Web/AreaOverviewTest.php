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
use Uhifadhi\Bundle\AreaBundle\Controller\AreaController;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\Service\StationService;
use Uhifadhi\Bundle\RegistryBundle\Entity\Module;
use Uhifadhi\Bundle\RegistryBundle\Enum\ModuleCategory;
use Uhifadhi\Bundle\RegistryBundle\Enum\ModuleStatus;
use Uhifadhi\Bundle\RegistryBundle\Service\AreaModuleService;

/**
 * THE AREA OVERVIEW — what is happening in this area right now.
 *
 * THE PAGE OWNS THE IDENTITY AND NOTHING OPERATIONAL. The band is the area's
 * own: what it measures, how it is divided, where it is and what stands on
 * it. Everything else is a module's contribution, and where nobody
 * contributed the page says so rather than drawing noughts.
 *
 * THE STRIP IS FIVE OR NONE, like every figure row in the product: a row of
 * three where the design has five is a different design, so the cards a
 * module has not filled say which.
 */
#[CoversClass(AreaController::class)]
final class AreaOverviewTest extends WebTestCase
{
    /**
     * THE BAND NAMES WHERE THE AREA IS AND WHAT STANDS ON IT. The centroid is
     * the database's answer, not a number computed from degrees in PHP, and
     * it is what somebody reads out to say roughly where this place is.
     */
    public function testTheBandStatesTheStationsAndWhereTheAreaIs(): void
    {
        $this->boot();
        $this->signIn();
        $area = $this->anArea();
        $this->stations()->add($area, 'Eastgate Post', -29.75, -3.2);

        $body = $this->body($area);

        self::assertStringContainsString('Stations', $body);
        self::assertStringContainsString('Centroid', $body);
        // The boundary of this fixture sits south of the equator and west of
        // Greenwich, and the band says so in the hemispheres, never in signs.
        self::assertMatchesRegularExpression('/\d+\.\d°S \d+\.\d°W/u', $body);
    }

    /** An area with no boundary has no centroid, and the cell says so. */
    public function testAnAreaWithNoBoundaryStatesNoCentroid(): void
    {
        $this->boot();
        $this->signIn();

        $area = new AreaOfInterest()->setName('Unmapped Reserve')->setSource('WDPA');
        $this->em->persist($area);
        $this->em->flush();

        self::assertStringContainsString('not set', $this->body($area));
    }

    /**
     * FOUR TILES OR NONE. Three of the five are typically a module's; where
     * no module publishes one, the card keeps its slot and says so.
     */
    public function testTheRightNowStripIsAlwaysFourTiles(): void
    {
        $this->boot();
        $this->signIn();

        $body = $this->body($this->anArea());

        self::assertSame(4, substr_count($body, 'class="c kpi'));
        self::assertStringContainsString('no module publishes this', $body);
    }

    /**
     * THE STRIP IS THE SHELL'S, WHOLE — its track and its spacing. This
     * bundle used to restate the track at 168px, which squeezed four cards
     * where the design fits them at 196, and then zeroed the strip's bottom
     * margin, which closed the twenty pixels between it and the card below.
     */
    public function testTheStripTakesTheShellsOwnTrack(): void
    {
        $this->boot();
        $this->signIn();

        // AND THE SURFACE'S OWN MODIFIER BESIDE IT, which is where this
        // strip says its qualifier is a line of text rather than a row.
        self::assertStringContainsString('class="grid kstrip ao-kstrip"', $this->body($this->anArea()));
    }

    /**
     * THE PLATE STATES ITS OWN HEIGHT, like every other plate in the product.
     *
     * IT IS THE DESIGN'S OWN EXPRESSION AND NOT A NUMBER: this card is the
     * page's subject, so it takes a share of the screen — capped, so that a
     * tall monitor gets a map and not a wall. The record and the tabs state
     * fixed pixels because their plates sit inside a stack of cards; this one
     * is the stack's reason. Either way the PAGE says it: a plate that
     * inherited the atlas's default would change height the day that default
     * did, on a page nobody was looking at.
     */
    public function testThePlateStatesTheHeightTheDesignDrawsItAt(): void
    {
        $this->boot();
        $this->signIn();

        self::assertStringContainsString('--map-plate-height:min(58vh, 560px)', $this->body($this->anArea()));
    }

    /**
     * THE PAGE IS COMPOSED, NOT AUTHORED — and this is the test that says so.
     *
     * The grid is the shell's, every cell is a contributor's, and the order
     * and the spans are the assembled default preset's: the area's own four
     * full-width cells, then the pair that read side by side. A module
     * switched on here adds its own cell to the same grid without this
     * bundle naming it.
     */
    public function testThePageIsAGridOfContributedCellsInTheDesignsOrder(): void
    {
        $this->boot();
        $this->signIn();

        $body = $this->body($this->anArea());

        self::assertStringContainsString('class="w-grid"', $body);
        self::assertSame(
            ['w-span-12', 'w-span-12', 'w-span-12', 'w-span-12', 'w-span-6', 'w-span-6'],
            self::spansOf($body),
        );
    }

    /**
     * A MODULE'S CELL RENDERS, AND IT RENDERS BY THE PUBLISHED CONTRACT.
     *
     * THE CONTRACT IS `by.<slug>`. A contributed partial is rendered with
     * `with_context: false` and everything it needs in ONE map, its own
     * figures under its own slug — the module templates say so in their
     * headers and the contract document says so in its table. A host that
     * merged those figures flat into the page's own context rendered its
     * own cells perfectly and fataled on every module's, and no test caught
     * it because the only area the suite rendered had nothing switched on.
     */
    public function testAModulesCellRendersFromItsOwnSlugInTheContext(): void
    {
        $this->boot();
        $this->signIn();
        $area = $this->anArea();
        $this->aModuleInstalledIn($area);

        $body = $this->body($area);

        self::assertStringContainsString('data-w="patrols_now"', $body);
        self::assertStringContainsString('3 open', $body);
        self::assertStringContainsString('96 km walked today', $body);
    }

    /**
     * AND THE SHARED HALF OF THE MAP IS ALL OF IT. A module template is
     * written against the names the contract publishes — `area`, `now`,
     * `tiles`, `attention`, `layers`, `legend` — so the host supplies every
     * one, not merely the ones today's modules happen to read. The next
     * module to read `legend` should not be the one that discovers it is
     * missing.
     */
    public function testTheSharedHalfOfTheContextCarriesEveryPublishedName(): void
    {
        $this->boot();
        $this->signIn();
        $area = $this->anArea();
        $this->aModuleInstalledIn($area);

        // The fixture's partial reads one of them and `by`; the rest are
        // proven by the page rendering with strict_variables on, which is
        // how a missing name fails here rather than in an installation.
        self::assertStringContainsString('data-w="patrols_now"', $this->body($area));

        $context = new \ReflectionClass(AreaController::class);
        $source = (string) file_get_contents((string) $context->getFileName());
        foreach (["'area' =>", "'now' =>", "'tiles' =>", "'attention' =>", "'layers' =>", "'legend' =>", "'by' =>"] as $name) {
            self::assertStringContainsString($name, $source, \sprintf('The shared map does not carry %s.', $name));
        }
    }

    /** And it joins the grid at the span its module asked for. */
    public function testAModulesCellTakesTheSpanItAskedFor(): void
    {
        $this->boot();
        $this->signIn();
        $area = $this->anArea();
        $this->aModuleInstalledIn($area);

        self::assertSame(
            ['w-span-12', 'w-span-12', 'w-span-12', 'w-span-12', 'w-span-6', 'w-span-6', 'w-span-6'],
            self::spansOf($this->body($area)),
        );
    }

    /**
     * THE ROSTER'S SLOT IS HELD OPEN, not dropped. The design's composition
     * has a roster card in that half-row; until roster publishes one the
     * cell says which module owes it, so the row keeps its shape and the
     * absence is legible rather than silent.
     */
    public function testTheRostersCellStatesItsAbsenceRatherThanVanishing(): void
    {
        $this->boot();
        $this->signIn();

        $body = $this->body($this->anArea());

        self::assertStringContainsString('Stations &amp; who is on', $body);
        self::assertStringContainsString('awaiting the roster', $body);
    }

    /** The registry's own cell says what is on here, out of what there is. */
    public function testTheModulesCellStatesWhatIsOnAgainstTheCatalogue(): void
    {
        $this->boot();
        $this->signIn();

        $body = $this->body($this->anArea());

        self::assertStringContainsString('Modules in this area', $body);
        self::assertStringContainsString('in the catalogue', $body);
    }

    /**
     * THE ATTENTION CARD IS BOUNDED. A list as long as the modules' day made
     * the card two thousand pixels tall and pushed the ground off the
     * screen; it draws the most urgent few and says what out of.
     */
    public function testTheAttentionCardIsBoundedAndSaysWhatOutOf(): void
    {
        $this->boot(attention: 9);
        $this->signIn();
        $area = $this->anArea();
        $this->aModuleInstalledIn($area);

        $body = $this->body($area);

        self::assertSame(6, substr_count($body, 'class="ao-att'));
        self::assertStringContainsString('6 of 9', $body);
        // AND NO LINK TO A PAGE THAT DOES NOT EXIST: an item belongs to the
        // module that raised it, so that is where the card sends you.
        self::assertStringContainsString('the rest are in the modules that raised them', $body);
    }

    /**
     * CONTRIBUTED CELLS COME IN THE AREA'S OWN MODULE ORDER.
     *
     * Measured on a rendered page: one installation drew incidents left of
     * patrols and another the other way about, because the order was
     * whatever order the container happened to tag the contributors in.
     * The order somebody arranged is the area's module order — the order
     * its Modules tab reads — and that is the page's order too.
     */
    public function testContributedCellsFollowTheAreasModuleOrder(): void
    {
        $this->boot();
        $this->signIn();
        $area = $this->anArea();
        $this->modulesInstalledIn($area, ['patrols', 'incidents']);

        self::assertSame(['patrols_now', 'incidents_now'], self::contributedOrder($this->body($area)));
    }

    /** And when the area runs them the other way about, so does the page. */
    public function testTheOtherInstallationOrderIsTheOtherPageOrder(): void
    {
        $this->boot();
        $this->signIn();
        $area = $this->anArea();
        $this->modulesInstalledIn($area, ['incidents', 'patrols']);

        self::assertSame(['incidents_now', 'patrols_now'], self::contributedOrder($this->body($area)));
    }

    /**
     * THE ATTENTION COUNT IS BROKEN DOWN — the urgent ones, then one figure
     * per module in the module's own word. "Six, across every installed
     * module" tells a reader nothing they could act on.
     */
    public function testTheAttentionTileBreaksTheCountDownByUrgencyAndModule(): void
    {
        $this->boot(attention: 4);
        $this->signIn();
        $area = $this->anArea();
        $this->aModuleInstalledIn($area);

        $body = $this->body($area);

        self::assertStringNotContainsString('across every installed module', $body);
        self::assertMatchesRegularExpression('/<span class="r">\d+ urgent<\/span>/', $body);
        self::assertMatchesRegularExpression('/\d+ [a-z]+/', $body);
    }

    /**
     * THE PLATE SAYS IT IS LIVE AND WHEN IT LAST READ, through the shell's
     * own element — a map with no time on it is a map somebody trusts an
     * hour after it went stale.
     */
    public function testTheWhereCardStatesLiveAndTheMomentItRead(): void
    {
        $this->boot();
        $this->signIn();

        $body = $this->body($this->anArea());

        self::assertStringContainsString('<span class="ao-live"><i></i>live</span>', $body);
        self::assertStringContainsString('data-localtime-format="stamp"', $body);
        self::assertStringNotContainsString('Where<span class="src">&middot; the area', $body);
    }

    /** @return list<string> the contributed cells, in the order they render */
    private static function contributedOrder(string $body): array
    {
        preg_match_all('#data-w="([a-z]+_now)"#', $body, $found);

        return $found[1];
    }

    /**
     * A MODULE'S CELL IS DRESSED BY THE MODULE'S OWN SHEET, which the page
     * links because the module published it.
     *
     * Measured on a rendered page: the incident cell's flow bar came out as
     * blue underlined links, because those rules live in the incident
     * module's stylesheet and the overview's chain was shell, atlas, widget,
     * area and nothing else. The module had been publishing its sheet
     * through `ContributesStylesheetInterface` the whole time; nothing
     * collected it. Copying the rules into the core would put every module's
     * vocabulary in the core and let the copy drift; collecting what the
     * module already publishes does not.
     */
    public function testEachContributingModulesStylesheetIsLinkedOnceAfterTheAreasOwn(): void
    {
        $this->boot();
        $this->signIn();
        $area = $this->anArea();
        $this->modulesInstalledIn($area, ['patrols', 'incidents']);

        $body = $this->body($area);

        self::assertSame(1, substr_count($body, 'bundles/patrols/patrols.css'));
        self::assertSame(1, substr_count($body, 'bundles/incidents/incidents.css'));
        self::assertTrue(
            strpos($body, 'bundles/area/area.css') < strpos($body, 'bundles/patrols/patrols.css'),
            'A module tunes what it owns, so its sheet is linked after the area’s own.',
        );
    }

    /** A module the area does not run dresses nothing: its sheet is absent. */
    public function testAModuleThisAreaDoesNotRunLinksNoSheet(): void
    {
        $this->boot();
        $this->signIn();
        $area = $this->anArea();
        $this->modulesInstalledIn($area, ['patrols']);

        self::assertStringNotContainsString('bundles/incidents/incidents.css', $this->body($area));
    }

    /**
     * THE MODULES CELL IS A TABLE OF WHAT EACH MODULE CONTRIBUTES HERE.
     *
     * A name and an "Open →" is true of a module switched on this morning
     * and of one that has published nothing for a year. The row counts what
     * the module actually puts on this page, from the seams it registered
     * with, and states the month the area took it on.
     */
    public function testTheModulesCellCountsWhatEachModuleContributesAndSinceWhen(): void
    {
        $this->boot();
        $this->signIn();
        $area = $this->anArea();
        $this->modulesInstalledIn($area, ['patrols']);

        $body = $this->body($area);

        self::assertStringContainsString('<th>contributes here</th>', $body);
        // The stand-in contributes exactly one cell, and the row says so.
        self::assertStringContainsString('1 widget', $body);
        // The month it was switched on, through the shell's own element.
        self::assertStringContainsString('data-localtime-format="day"', $body);
    }

    /** A module of the catalogue this area has not taken on says so. */
    public function testACatalogueModuleThisAreaDoesNotRunIsARowThatSaysSo(): void
    {
        $this->boot();
        $this->signIn();
        $area = $this->anArea();
        $this->modulesInstalledIn($area, ['patrols', 'incidents']);

        /** @var AreaModuleService $modules */
        $modules = static::getContainer()->get('test_public.registry.area_modules');
        $modules->uninstall($area, 'incidents');

        $body = $this->body($area);

        self::assertStringContainsString('nothing &mdash; not installed in this area', $body);
    }

    /**
     * AN ABSENCE IS ONE LINE. The roster's slot was three sentences and a
     * nested box about a card with nothing in it.
     */
    public function testTheRostersSlotStatesItsAbsenceInOneFragment(): void
    {
        $this->boot();
        $this->signIn();

        $body = $this->body($this->anArea());

        self::assertStringContainsString('Stations &amp; who is on', $body);
        self::assertStringContainsString('Nothing to show until the roster module is installed.', $body);
        self::assertStringNotContainsString('is the <b>roster</b>', $body);
    }

    /**
     * A MODULE SWITCHED ON HERE, so the stand-in's contributions reach the
     * page: nothing a module contributes is drawn for an area that does not
     * run it.
     */
    private function aModuleInstalledIn(AreaOfInterest $area): void
    {
        $this->em->persist(new Module()
            ->setSlug('patrols')
            ->setName('Patrols')
            ->setCategory(ModuleCategory::Pressure)
            ->setStatus(ModuleStatus::Live)
            ->setDataSource('GPS field tracks')
            ->setPosition(0));
        $this->em->flush();

        /** @var AreaModuleService $modules */
        $modules = static::getContainer()->get('test_public.registry.area_modules');
        $modules->install($area, 'patrols');
    }

    /**
     * TWO MODULES SWITCHED ON, in the order given — which is the order the
     * area's own Modules tab lists them in.
     *
     * @param list<string> $slugs
     */
    private function modulesInstalledIn(AreaOfInterest $area, array $slugs): void
    {
        $position = 0;
        foreach ($slugs as $slug) {
            $this->em->persist(new Module()
                ->setSlug($slug)
                ->setName(ucfirst($slug))
                ->setCategory(ModuleCategory::Pressure)
                ->setStatus(ModuleStatus::Live)
                ->setDataSource('the stand-in')
                ->setPosition($position++));
        }
        $this->em->flush();

        /** @var AreaModuleService $modules */
        $modules = static::getContainer()->get('test_public.registry.area_modules');
        foreach ($slugs as $slug) {
            $modules->install($area, $slug);
        }
    }

    /** @return list<string> */
    private static function spansOf(string $body): array
    {
        preg_match_all('#<div class="w-cell (w-span-\d+)"#', $body, $found);

        return $found[1];
    }

    private function body(AreaOfInterest $area): string
    {
        $this->browser()->request('GET', '/areas/'.$area->getUuidString());

        return (string) $this->browser()->getResponse()->getContent();
    }

    private function stations(): StationService
    {
        /** @var StationService $service */
        $service = static::getContainer()->get('test_public.area.stations');

        return $service;
    }
}
