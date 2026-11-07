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

namespace Uhifadhi\Bundle\TeamBundle\Tests\Functional;

use Symfony\Component\DomCrawler\Crawler;
use Uhifadhi\Bundle\TeamBundle\Entity\Department;
use Uhifadhi\Bundle\TeamBundle\Entity\Position;
use Uhifadhi\Bundle\TeamBundle\Enum\TeamRoleEnum;
use Uhifadhi\Bundle\TeamBundle\Repository\DepartmentScopeChangeRepository;

/**
 * THE AREA-AWARE DEPARTMENT MANAGER — the register, and the lens every row opens.
 *
 * A department carries a scope now: area-level (confined to one area) or
 * org-level (spanning every area). This screen draws the two apart — area-level
 * first, grouped by their area's name, then org-level — states each row's scope
 * in an explicit column, creates in either scope (area-first, the picker
 * enumerating the installation's areas through the contract), opens every
 * department to its own lens, and changes a scope with a reason recorded to the
 * audit trail.
 *
 * THE SCREEN IS THE CANONICAL REGISTER, not a card wall: there is no
 * `.dcard`/`.pitem`/Unassigned-card vocabulary. The rules that hold whatever it
 * is drawn as (a department grants nothing; DELETE and DEACTIVATE are not
 * drawn, so not here) are asserted below.
 *
 * A DEPARTMENT IS A PLACEMENT AND NOT AN OWNER. The register used to file
 * positions under departments — a Move control, and a "No department yet" band
 * for the loose ones — and the ruling replaced both with the placement: a
 * department sees the positions its members hold, so nothing is filed and there
 * is nothing left to be unfiled. Every fixture below therefore PLACES a holder
 * where the old one filed a position.
 */
final class DepartmentScreenTest extends WebTestCaseWithSchema
{
    /**
     * A LIST TAB CARRIES NO STRIP (ruled 7 Nov: strips and figures belong to
     * an Overview), and the count of what is listed is said at the end of the
     * filter row, where a count of what is being listed belongs.
     */
    public function testTheRegisterCarriesNoStripAndTheFootSaysWhatIsShown(): void
    {
        $crawler = $this->screen();

        self::assertCount(0, $crawler->filter('.factband'));
        self::assertCount(0, $crawler->filter('.ovstripnote'));
        self::assertCount(0, $crawler->filter('.tm-shown'));

        self::assertStringStartsWith('3 departments', trim(preg_replace('/\s+/', ' ', $crawler->filter('.rdf-foot > span')->first()->text()) ?? ''));
        self::assertSame('page 1 of 1', trim($crawler->filter('.rdf-foot .rdf-page')->text()));
        self::assertStringStartsWith('1 department', trim(preg_replace('/\s+/', ' ', $this->client->request('GET', '/departments?q=wetland')->filter('.rdf-foot > span')->first()->text()) ?? ''));
        self::assertCount(3, $this->named($this->client->request('GET', '/departments?page=9')), 'a page past the last is the last');
    }

    /**
     * THE SCOPE IS THE HOUSE DROPDOWN IN THE PAGE HEAD, and every option is an
     * address: it is the placement filter seen from the head, one parameter.
     */
    public function testTheHeaderCarriesTheScopeOnTheHouseDropdown(): void
    {
        $crawler = $this->screen();

        $scope = $crawler->filter('.pgact .dp-link .dd[data-dd="scope"]');
        self::assertCount(1, $scope);
        self::assertSame('Organization — all areas', $scope->filter('.dd-v')->text());
        self::assertSame(['Organization — all areas', 'Northern Reserve'], $scope->filter('.dd-o')->each(static fn (Crawler $c): string => (string) $c->attr('data-label')));
        self::assertCount(0, $crawler->filter('.pgact .ov-ctl'));
        self::assertCount(0, $crawler->filter('.pgact .periodpick'));

        $north = $this->north;
        self::assertNotNull($north, 'the register was seeded with an area');
        $urls = json_decode((string) $crawler->filter('.pgact .dp-link')->attr('data-uhifadhi--team-bundle--choice-link-urls-value'), true);
        $uuid = (string) $north->getUuidString();
        self::assertIsArray($urls);
        self::assertSame('/departments?placement='.$uuid, $urls[$uuid]);

        $narrowed = $this->client->request('GET', '/departments?placement='.$north->getUuidString());

        self::assertResponseIsSuccessful();
        self::assertSame('Northern Reserve', $narrowed->filter('.pgact .dd[data-dd="scope"] .dd-v')->text());
        self::assertCount(1, $narrowed->filter('.pgact .dd.dd-set[data-dd="scope"]'));
    }

    // ---- the register lists both scope groups, area-first -----------------

    /**
     * A DEPARTMENT'S ROW WEARS ITS OWN HUE, and it is the same category the
     * sidebar's dot reads: the row carries an index and never a colour.
     */
    public function testEachDepartmentsRowCarriesItsOwnCategory(): void
    {
        $this->administrator();
        $this->department('Ecology');
        $this->department('Tourism');
        $this->em->flush();

        $crawler = $this->client->request('GET', '/departments');
        self::assertResponseIsSuccessful();

        self::assertSame(['1', '2'], $crawler->filter('tr.drow')->each(static fn (Crawler $c): ?string => $c->attr('data-cat')));
        self::assertCount(2, $crawler->filter('tr.drow .dmark'));
    }

    /**
     * ONE TABLE, SORTED BY NAME ON ARRIVAL (ruled 2026-09-22): no groups,
     * no cards — every department is a row, and the rows are in name order
     * until a header is asked for another.
     */
    public function testTheRegisterIsOneTableSortedByNameOnArrival(): void
    {
        $crawler = $this->screen();

        self::assertCount(1, $crawler->filter('[data-dp] table.tbl.dreg'));
        self::assertCount(0, $crawler->filter('[data-dp] .dcard, [data-dp] details.nvsec'));
        self::assertSame(['Ecology', 'Protection Service', 'Wetland Management'], $this->named($crawler));
        self::assertSame('Department', $crawler->filter('th.sorted a')->text());
    }

    /**
     * EVERY HEADER SORTS EXACTLY ONE THING, and it is a link: the sorted
     * column says so, clicking it turns the direction over, and the address
     * carries both — a sorted register is a link somebody can send.
     */
    public function testEveryHeaderSortsItsOneColumnThroughTheAddress(): void
    {
        $crawler = $this->screen();

        self::assertSame(
            ['Department', 'Modules', 'Positions', 'Seats', 'Goals'],
            $crawler->filter('thead th a')->each(static fn (Crawler $c): string => $c->text()),
        );

        $byPositions = $crawler->filter('thead th a')->reduce(static fn (Crawler $c): bool => 'Positions' === $c->text())->first();
        self::assertStringContainsString('sort=positions', (string) $byPositions->attr('href'));

        $sorted = $this->client->request('GET', '/departments?sort=positions');
        self::assertSame('ascending', $sorted->filter('th.sorted')->attr('aria-sort'));
        self::assertStringContainsString('dir=desc', (string) $sorted->filter('th.sorted a')->attr('href'), 'the sorted header turns over');

        $reversed = $this->client->request('GET', '/departments?sort=name&dir=desc');
        self::assertSame(['Wetland Management', 'Protection Service', 'Ecology'], $this->named($reversed));
        self::assertSame('descending', $reversed->filter('th.sorted')->attr('aria-sort'));
    }

    /**
     * THE NAME CELL IS TWO LINES: the department over its placement — the
     * area's name for an area-level department, "org-wide" for one the
     * organization owns.
     */
    public function testEachRowStatesItsPlacementUnderTheName(): void
    {
        $crawler = $this->screen();

        self::assertStringContainsString('Northern Reserve', $this->row($crawler, 'Wetland Management')->filter('.sub')->text());
        self::assertStringContainsString('org-wide', $this->row($crawler, 'Ecology')->filter('.sub')->text());
    }

    /**
     * THE PLACEMENT DROPDOWN FILTERS THE REGISTER OFF THE ADDRESS — org-wide,
     * or one area by its uuid — and every option is a link with the count of
     * what picking it would leave.
     */
    public function testThePlacementFilterReadsTheAddress(): void
    {
        $crawler = $this->screen();

        self::assertSame(['Ecology', 'Protection Service'], $this->named($this->client->request('GET', '/departments?placement=org')));
        self::assertSame(['Wetland Management'], $this->named($this->client->request('GET', '/departments?placement='.$this->uuidOf('__area:Northern Reserve'))));

        self::assertSame(
            ['placement', 'module', 'goals', 'seats'],
            $crawler->filter('.lfilt details.i-dd')->each(static fn (Crawler $c): string => (string) $c->attr('data-axis')),
            'the four filters are the house chips',
        );
        self::assertCount(0, $crawler->filter('.lfilt .dd'), 'no searchable dropdown in a filter bar');
        $chip = $crawler->filter('.lfilt details.i-dd[data-axis="placement"]');
        self::assertSame('all placements · 3', trim($chip->filter('summary.mchip.i-ddt .i-ddval')->text()));
        $options = $chip->filter('.i-ddmenu a.i-ddopt');
        self::assertSame(['All placements', 'Org-wide', 'Northern Reserve'], $options->filter('.i-ddopt-l')->each(static fn (Crawler $c): string => $c->text()));
        self::assertSame(['3', '2', '1'], $options->filter('.i-ddopt-n')->each(static fn (Crawler $c): string => $c->text()));
        self::assertSame('/departments?placement=org', $options->eq(1)->attr('href'));
        self::assertSame('org-wide · 2', trim($this->client->request('GET', '/departments?placement=org')->filter('.lfilt details.i-dd[data-axis="placement"] .i-ddval')->text()));
    }

    /** The module, goals and seats dropdowns filter the same way. */
    public function testTheModuleGoalsAndSeatsFiltersReadTheAddress(): void
    {
        $this->screen();

        self::assertSame(['Ecology', 'Protection Service', 'Wetland Management'], $this->named($this->client->request('GET', '/departments?module=none')));
        self::assertSame([], $this->named($this->client->request('GET', '/departments?goals=some')));
        self::assertSame(['Ecology', 'Protection Service', 'Wetland Management'], $this->named($this->client->request('GET', '/departments?goals=none')));
        // Unlimited seats are never vacant.
        self::assertSame([], $this->named($this->client->request('GET', '/departments?seats=vacant')));
    }

    /** And the search reads the name, which is what somebody types. */
    public function testTheSearchReadsTheName(): void
    {
        $this->screen();

        self::assertSame(['Wetland Management'], $this->named($this->client->request('GET', '/departments?q=wetland')));
    }

    /** An empty answer is a row that says so, and it says why. */
    public function testAnEmptyRegisterSaysSo(): void
    {
        $this->screen();

        $empty = $this->client->request('GET', '/departments?q=nothing-is-called-this');
        self::assertCount(0, $empty->filter('tr.drow'));
        self::assertStringContainsString('No department matches', $empty->filter('tr.dempty')->text());
        self::assertStringContainsString('widen a filter', $empty->filter('tr.dempty')->text());
    }

    /**
     * THE FOCUSED ROW IS THE ONE THE SIDEBAR POINTS AT, marked by the one
     * left line a row may wear, and addressable by its anchor.
     */
    public function testTheFocusedDepartmentIsMarked(): void
    {
        $this->screen();
        $uuid = $this->uuidOf('Ecology');
        $crawler = $this->client->request('GET', '/departments?focus='.$uuid);

        $row = $this->row($crawler, 'Ecology');
        self::assertStringContainsString('dcfocus', (string) $row->attr('class'));
        self::assertSame('d-'.$uuid, $row->attr('id'));
        self::assertCount(1, $crawler->filter('tr.dcfocus'));
    }

    /**
     * A ROW CARRIES ONE DOOR. The three operations that change a department
     * live on its configure page, reached from the record — the shape a
     * person and a position already have.
     */
    public function testARowCarriesOneDoorAndNoForm(): void
    {
        $crawler = $this->screen();
        $row = $this->row($crawler, 'Ecology');

        self::assertCount(0, $row->filter('form'));
        self::assertSame('/departments/'.$this->uuidOf('Ecology'), $row->filter('a.open-btn')->attr('href'));
    }

    /** The configure page holds the same three operations, posting where they posted. */
    public function testTheConfigurePageHoldsTheThreeOperations(): void
    {
        $this->screen();
        $page = $this->configureOf('Ecology');

        self::assertCount(1, $page->filter('form[action$="/scope"]'));
        self::assertCount(1, $page->filter('form[action$="/rename"]'));
        self::assertCount(1, $page->filter('form[action$="/deactivate"]'));
        self::assertCount(0, $page->filter('form[action$="/reactivate"]'));
    }

    /** And the record wears the door to it, in the header row. */
    public function testTheRecordWearsTheConfigureDoor(): void
    {
        $this->screen();
        $uuid = $this->uuidOf('Ecology');
        $crawler = $this->client->request('GET', '/departments/'.$uuid);

        self::assertSame('/departments/'.$uuid.'/configure', $crawler->filter('.pgact a.tgl')->attr('href'));

        // AND THE TREE OPENS TO IT: a department's own pages are places in
        // the section, so the sidebar shows the path and lights the department.
        self::assertSame('Ecology', trim($crawler->filter('nav.nav .ntree .nts.on')->text()));
        $configure = $this->client->request('GET', '/departments/'.$uuid.'/configure');
        self::assertSame('Ecology', trim($configure->filter('nav.nav .ntree .nts.on')->text()));
    }

    /**
     * The names in the register, in the order it draws them.
     *
     * @return list<string>
     */
    private function named(Crawler $crawler): array
    {
        return $crawler->filter('[data-dp] tr.drow .ov-nm')->each(static fn (Crawler $c): string => $c->text());
    }

    // ---- every department opens to its lens -------------------------------

    /**
     * EVERY DEPARTMENT IS OPENABLE — the name and the Open door both point at
     * the department's own page, area-level or org-level alike.
     */
    public function testEveryRowOpensItsDepartmentThroughNameAndDoor(): void
    {
        $crawler = $this->screen();

        foreach (['Wetland Management', 'Ecology'] as $name) {
            $row = $this->row($crawler, $name);
            $show = '/departments/'.$this->uuidOf($name);

            self::assertSame($show, $row->filter('.ov-nm')->attr('href'), $name.' name links to its record');
            self::assertSame($show, $row->filter('a.open-btn')->attr('href'), $name.' Open links to its record');
        }
    }

    // ---- create, per-area and per-org -------------------------------------

    /**
     * THE CREATE PICKER ENUMERATES THE INSTALLATION'S AREAS through the contract —
     * every area is an option, by name.
     */
    public function testTheCreatePickerListsEveryAreaByName(): void
    {
        $this->administrator();
        $this->area('Northern Reserve');
        $this->area('Western Reserve');
        $this->em->flush();

        $crawler = $this->client->request('GET', '/departments/configure');

        $options = $crawler->filter('form[data-create-department] select[name="area"] option')
            ->each(static fn (Crawler $c): string => $c->text());

        self::assertContains('Northern Reserve', $options);
        self::assertContains('Western Reserve', $options);
    }

    /** CREATE PER-AREA — the picked area becomes the department's scope. */
    public function testCreatingADepartmentPerAreaConfinesItToThePickedArea(): void
    {
        $this->administrator();
        $north = $this->area('Northern Reserve');
        $this->area('Western Reserve');
        $this->em->flush();

        $crawler = $this->client->request('GET', '/departments/configure');
        $form = $crawler->selectButton('Add department')->form();
        $form['scope'] = 'area';
        $form['area'] = (string) $north->getUuidString();
        $form['name'] = 'Wetland Management';
        $this->client->submit($form);

        self::assertResponseRedirects('/departments');

        $this->em->clear();
        $created = $this->em->getRepository(Department::class)->findOneBy(['name' => 'Wetland Management']);
        self::assertInstanceOf(Department::class, $created);
        self::assertTrue($created->isAreaLevel());
        self::assertSame('Northern Reserve', $created->getArea()?->getName());
    }

    /** CREATE PER-ORG — no area, spans every one. */
    public function testCreatingADepartmentPerOrgLeavesItOrgWide(): void
    {
        $this->administrator();
        $this->area('Northern Reserve');
        $this->em->flush();

        $crawler = $this->client->request('GET', '/departments/configure');
        $form = $crawler->selectButton('Add department')->form();
        $form['scope'] = 'org';
        $form['name'] = 'Administration';
        $this->client->submit($form);

        self::assertResponseRedirects('/departments');

        $this->em->clear();
        $created = $this->em->getRepository(Department::class)->findOneBy(['name' => 'Administration']);
        self::assertInstanceOf(Department::class, $created);
        self::assertTrue($created->isOrgLevel());
        self::assertNull($created->getArea());
    }

    /** A name may repeat FROM ONE AREA TO ANOTHER — two areas may each run one. */
    public function testTheSameNameMayExistInTwoDifferentAreas(): void
    {
        $this->administrator();
        $north = $this->area('Northern Reserve');
        $west = $this->area('Western Reserve');
        $this->areaDepartment('Anti-Poaching', $north);
        $this->em->flush();

        $crawler = $this->client->request('GET', '/departments/configure');
        $form = $crawler->selectButton('Add department')->form();
        $form['scope'] = 'area';
        $form['area'] = (string) $west->getUuidString();
        $form['name'] = 'Anti-Poaching';
        $this->client->submit($form);

        $this->em->clear();
        self::assertCount(2, $this->em->getRepository(Department::class)->findBy(['name' => 'Anti-Poaching']));
    }

    /** But two ORG-WIDE departments of one name are the same one entered twice. */
    public function testASecondOrgWideDepartmentWithTheSameNameIsRefused(): void
    {
        $this->screen();
        $crawler = $this->client->request('GET', '/departments/configure');

        $form = $crawler->selectButton('Add department')->form();
        $form['scope'] = 'org';
        $form['name'] = 'Ecology';
        $this->client->submit($form);

        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('already', $crawler->filter('[data-shell-flash]')->text());

        $this->em->clear();
        self::assertCount(1, $this->em->getRepository(Department::class)->findBy(['name' => 'Ecology']));
    }

    public function testADepartmentWithNoNameIsRefused(): void
    {
        $this->administrator();
        $this->area('Northern Reserve');
        $this->em->flush();

        $crawler = $this->client->request('GET', '/departments/configure');
        $this->client->submit($crawler->selectButton('Add department')->form());

        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('needs a name', $crawler->filter('[data-shell-flash]')->text());
        self::assertCount(0, $this->em->getRepository(Department::class)->findAll());
    }

    // ---- the audited scope change -----------------------------------------

    /**
     * CONFINE (org → area) — the department narrows to a picked area, and the
     * reason is recorded to the audit trail on the transition.
     */
    public function testConfiningAnOrgDepartmentToAnAreaRecordsAnAuditedReason(): void
    {
        $crawler = $this->screen();

        $form = $this->configureOf('Ecology')->filter('form[action$="/scope"]')->selectButton('Save scope and visibility')->form();
        $form['area'] = (string) $this->uuidOf('__area:Northern Reserve');
        $form['reason'] = 'Ecology now works only in the north.';
        $this->client->submit($form);

        self::assertResponseRedirects('/departments/'.$this->uuidOf('Ecology').'/configure');

        $this->em->clear();
        $ecology = $this->em->getRepository(Department::class)->findOneBy(['name' => 'Ecology']);
        self::assertInstanceOf(Department::class, $ecology);
        self::assertTrue($ecology->isAreaLevel());
        self::assertSame('Northern Reserve', $ecology->getArea()?->getName());

        $trail = $this->scopeChanges()->findForDepartment($ecology);
        self::assertCount(1, $trail);
        self::assertSame('Ecology now works only in the north.', $trail[0]->getReason());
        self::assertSame('Naomi', $trail[0]->getChangedBy()?->getFirstName(), 'the audit line records who');
    }

    /** PROMOTE (area → org) — the department widens, still audited with a reason. */
    public function testPromotingAnAreaDepartmentToOrgWideRecordsAnAuditedReason(): void
    {
        $crawler = $this->screen();

        $form = $this->configureOf('Wetland Management')->filter('form[action$="/scope"]')->selectButton('Save scope and visibility')->form();
        $form['area'] = '';
        $form['reason'] = 'Its remit is now the whole park.';
        $this->client->submit($form);

        self::assertResponseRedirects('/departments/'.$this->uuidOf('Wetland Management').'/configure');

        $this->em->clear();
        $wetland = $this->em->getRepository(Department::class)->findOneBy(['name' => 'Wetland Management']);
        self::assertInstanceOf(Department::class, $wetland);
        self::assertTrue($wetland->isOrgLevel());

        $trail = $this->scopeChanges()->findForDepartment($wetland);
        self::assertCount(1, $trail);
        self::assertSame('Its remit is now the whole park.', $trail[0]->getReason());
    }

    /** A BLANK REASON IS REFUSED, and the scope does not move. */
    public function testAScopeChangeWithNoReasonIsRefusedAndNothingMoves(): void
    {
        $crawler = $this->screen();

        $form = $this->configureOf('Wetland Management')->filter('form[action$="/scope"]')->selectButton('Save scope and visibility')->form();
        $form['area'] = '';
        $form['reason'] = '   ';
        $this->client->submit($form);

        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('needs a reason', $crawler->filter('[data-shell-flash]')->text());

        $this->em->clear();
        $wetland = $this->em->getRepository(Department::class)->findOneBy(['name' => 'Wetland Management']);
        self::assertInstanceOf(Department::class, $wetland);
        self::assertTrue($wetland->isAreaLevel(), 'the refusal happens before the scope moves');
        self::assertCount(0, $this->scopeChanges()->findForDepartment($wetland));
    }

    // ---- rename and filing still work -------------------------------------

    public function testARowRenamesItsDepartmentFromThePanel(): void
    {
        $crawler = $this->screen();

        $uuid = $this->uuidOf('Ecology');
        $form = $this->configureOf('Ecology')->filter('form[action$="/rename"]')->selectButton('Rename')->form();
        $form['name'] = 'Ecology & Research';
        $this->client->submit($form);

        self::assertResponseRedirects('/departments/'.$uuid.'/configure');

        $this->em->clear();
        self::assertNull($this->em->getRepository(Department::class)->findOneBy(['name' => 'Ecology']));
        self::assertInstanceOf(Department::class, $this->em->getRepository(Department::class)->findOneBy(['name' => 'Ecology & Research']));
    }

    // ---- deactivate; delete is a Super Admin's ------------------------------

    /**
     * DEACTIVATE is the action; DELETE is drawn for a Super Admin alone (ruled
     * 28 Sep, #48, superseding "never delete" for the one tier that tidies what
     * was made by mistake or in tests).
     */
    public function testTheScreenDeactivatesAndOnlyASuperAdminAlsoDeletes(): void
    {
        $this->screen();

        $page = $this->configureOf('Ecology')->filter('[data-dp-configure]')->text();
        self::assertStringContainsString('Deactivate', $page);
        self::assertStringContainsString('Delete the department', $page);

        $admin = $this->person('Desta', 'Haile', TeamRoleEnum::Admin);
        $this->em->flush();
        $this->client->loginUser($admin);
        $page = $this->configureOf('Ecology')->filter('[data-dp-configure]')->text();
        self::assertStringContainsString('Deactivate', $page);
        self::assertStringNotContainsString('Delete', $page);
    }

    /**
     * DEACTIVATING flips the flag without deleting: the row stays (greyed), the
     * people placed in it and the positions they hold are untouched, and the
     * footprint informs in the flash.
     */
    public function testDeactivatingADepartmentGreysItAndDeletesNothing(): void
    {
        $crawler = $this->screen();

        $form = $this->configureOf('Ecology')->filter('form[action$="/deactivate"]')->selectButton('Deactivate anyway')->form();
        $this->client->submit($form);

        self::assertResponseRedirects('/departments/'.$this->uuidOf('Ecology').'/configure');
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('deactivated', $crawler->filter('[data-shell-flash]')->text());
        self::assertCount(1, $crawler->filter('form[action$="/reactivate"]'), 'the configure page now offers the way back');
        $crawler = $this->client->request('GET', '/departments');

        $this->em->clear();
        $ecology = $this->em->getRepository(Department::class)->findOneBy(['name' => 'Ecology']);
        self::assertInstanceOf(Department::class, $ecology);
        self::assertFalse($ecology->isActive());
        self::assertNotNull($ecology->getDeactivatedAt());

        // The person placed in it, and the position they hold, survive the
        // wind-down — deactivate, never delete.
        $analyst = $this->em->getRepository(Position::class)->findOneBy(['name' => 'Analyst']);
        self::assertInstanceOf(Position::class, $analyst);
        $tumaini = $this->em->getRepository(\Uhifadhi\Bundle\TeamBundle\Entity\User::class)
            ->findOneBy(['email' => 't.njau@example.test']);
        self::assertInstanceOf(\Uhifadhi\Bundle\TeamBundle\Entity\User::class, $tumaini);
        self::assertSame('Analyst', $tumaini->getPosition()?->getName());
        self::assertTrue($tumaini->getPlacement()?->belongsTo($ecology));

        // Greyed in the register.
        self::assertStringContainsString('dcinactive', (string) $this->row($crawler, 'Ecology')->attr('class'));
    }

    /** REACTIVATE brings it back into the register and the pickers. */
    public function testReactivatingADepartmentBringsItBack(): void
    {
        $crawler = $this->screen();
        $this->client->submit($this->configureOf('Ecology')->filter('form[action$="/deactivate"]')->selectButton('Deactivate anyway')->form());

        $this->client->submit($this->configureOf('Ecology')->filter('form[action$="/reactivate"]')->selectButton('Reactivate')->form());

        self::assertResponseRedirects('/departments/'.$this->uuidOf('Ecology').'/configure');

        $this->em->clear();
        $ecology = $this->em->getRepository(Department::class)->findOneBy(['name' => 'Ecology']);
        self::assertInstanceOf(Department::class, $ecology);
        self::assertTrue($ecology->isActive());
        self::assertNull($ecology->getDeactivatedAt());
    }

    /** The deactivate write is gated like every other. */
    public function testTheDeactivateWriteIsGated(): void
    {
        $department = $this->department('Ecology');
        $this->em->flush();

        $this->client->request('POST', '/departments/'.$department->getUuidString().'/deactivate');
        self::assertResponseRedirects('http://localhost/login');
    }

    // ---- §5.7: a scope change is a privilege change -----------------------

    /** PROMOTION informs that everyone filed under it gains authority everywhere. */
    public function testPromotingNoticesThatAuthorityWidensToEveryArea(): void
    {
        $this->administrator();
        $ng = $this->area('Northern Reserve');
        $wetland = $this->areaDepartment('Wetland Management', $ng);
        $position = $this->position('Wetland Ecologist', ['surveys.read']);
        $zawadi = $this->person('Zawadi', 'Kimaro', TeamRoleEnum::Staff);
        $zawadi->setPosition($position);
        // THE FOOTPRINT IS THE PEOPLE PLACED IN IT, so the notice only has
        // somebody to widen if somebody is placed there.
        $this->place($zawadi, [$ng], [$wetland]);
        $this->em->flush();

        $crawler = $this->client->request('GET', '/departments');
        $form = $this->configureOf('Wetland Management')->filter('form[action$="/scope"]')->selectButton('Save scope and visibility')->form();
        $form['area'] = '';
        $form['reason'] = 'Its remit is now the whole park.';
        $this->client->submit($form);

        $crawler = $this->client->followRedirect();
        self::assertStringContainsStringIgnoringCase('every area', $crawler->filter('[data-shell-flash]')->text());
    }

    /** DEMOTION (confine) informs that authority elsewhere is lost. */
    public function testConfiningNoticesThatAuthorityElsewhereIsLost(): void
    {
        $this->administrator();
        $ng = $this->area('Northern Reserve');
        $ecology = $this->department('Ecology');
        $position = $this->position('Analyst', ['surveys.read']);
        $zawadi = $this->person('Zawadi', 'Kimaro', TeamRoleEnum::Staff);
        $zawadi->setPosition($position);
        $this->place($zawadi, null, [$ecology]);
        $this->em->flush();

        $crawler = $this->client->request('GET', '/departments');
        $form = $this->configureOf('Ecology')->filter('form[action$="/scope"]')->selectButton('Save scope and visibility')->form();
        $form['area'] = (string) $ng->getUuidString();
        $form['reason'] = 'Ecology now works only in the north.';
        $this->client->submit($form);

        $crawler = $this->client->followRedirect();
        self::assertStringContainsStringIgnoringCase('lost', $crawler->filter('[data-shell-flash]')->text());
    }

    /** The promote form carries the §5.7 privilege-gain notice (informs, never guards). */
    public function testThePromoteFormStatesThePrivilegeGain(): void
    {
        $crawler = $this->screen();

        $form = $this->configureOf('Wetland Management')->filter('form[action$="/scope"]');
        self::assertStringContainsStringIgnoringCase('every area', $form->text());
    }

    // ---- §5.6: what an area administrator may touch -----------------------

    /** An area-X admin CREATES an area-level department in their own area. */
    public function testAnAreaAdminCreatesAnAreaDepartmentInTheirOwnArea(): void
    {
        $north = $this->area('Northern Reserve');
        $this->areaAdminIn($north);

        $crawler = $this->client->request('GET', '/departments/configure');
        $form = $crawler->selectButton('Add department')->form();
        $form['scope'] = 'area';
        $form['area'] = (string) $north->getUuidString();
        $form['name'] = 'Wetland Ecology';
        $this->client->submit($form);

        self::assertResponseRedirects('/departments');
        $this->em->clear();
        $created = $this->em->getRepository(Department::class)->findOneBy(['name' => 'Wetland Ecology']);
        self::assertInstanceOf(Department::class, $created);
        self::assertTrue($created->isAreaLevel());
    }

    /** But NOT an org-level one — minting an org department is escalation. */
    public function testAnAreaAdminCannotCreateAnOrgDepartment(): void
    {
        $north = $this->area('Northern Reserve');
        $this->areaAdminIn($north);

        $crawler = $this->client->request('GET', '/departments/configure');
        $form = $crawler->selectButton('Add department')->form();
        $form['scope'] = 'org';
        $form['name'] = 'Administration';
        $this->client->submit($form);

        self::assertResponseStatusCodeSame(403);
        $this->em->clear();
        self::assertNull($this->em->getRepository(Department::class)->findOneBy(['name' => 'Administration']));
    }

    /** And NOT a department in another area. */
    public function testAnAreaAdminCannotCreateADepartmentInAnotherArea(): void
    {
        $north = $this->area('Northern Reserve');
        $west = $this->area('Western Reserve');
        $this->areaAdminIn($north);

        $crawler = $this->client->request('GET', '/departments/configure');
        $form = $crawler->selectButton('Add department')->form();
        $form['scope'] = 'area';
        $form['area'] = (string) $west->getUuidString();
        $form['name'] = 'Anti-Poaching';
        $this->client->submit($form);

        self::assertResponseStatusCodeSame(403);
    }

    /** An area admin may NOT change any department's scope. */
    public function testAnAreaAdminCannotChangeScope(): void
    {
        $north = $this->area('Northern Reserve');
        $this->areaAdminIn($north);

        // Their own area-level department — even so, scope change is unbounded.
        $crawler = $this->client->request('GET', '/departments');
        $form = $this->configureOf('Warden Office')->filter('form[action$="/scope"]')->selectButton('Save scope and visibility')->form();
        $form['area'] = '';
        $form['reason'] = 'trying to widen';
        $this->client->submit($form);

        self::assertResponseStatusCodeSame(403);
    }

    /** An area admin may deactivate their OWN area department, not an org one. */
    public function testAnAreaAdminDeactivatesTheirOwnAreaDepartmentButNotAnOrgOne(): void
    {
        $north = $this->area('Northern Reserve');
        $this->areaAdminIn($north);
        $ecology = $this->department('Ecology'); // org-level
        $this->em->flush();

        // Own area department: allowed.
        $crawler = $this->client->request('GET', '/departments');
        $this->client->submit($this->configureOf('Warden Office')->filter('form[action$="/deactivate"]')->selectButton('Deactivate anyway')->form());
        self::assertResponseRedirects('/departments/'.$this->uuidOf('Warden Office').'/configure');

        // Org department: refused.
        $this->client->request('POST', '/departments/'.$ecology->getUuidString().'/deactivate', [
            '_token' => $this->tokenFrom('/departments/configure'),
        ]);
        self::assertResponseStatusCodeSame(403);
    }

    // ---- who may reach it -------------------------------------------------

    public function testAColleagueWithoutTeamManageIsRefused(): void
    {
        $ranger = $this->person('Juma', 'Mwakalinga', TeamRoleEnum::Staff);
        $ranger->setPosition($this->position('Ranger', ['surveys.read']));
        $this->em->flush();
        $this->client->loginUser($ranger);

        $this->client->request('GET', '/departments');
        self::assertResponseStatusCodeSame(403);
    }

    public function testAnAnonymousVisitorIsSentToSignIn(): void
    {
        $this->client->request('GET', '/departments');
        self::assertResponseRedirects('http://localhost/login');
    }

    public function testTheScopeChangeWriteIsGated(): void
    {
        // No login: the client from setUp is anonymous until loginUser() is
        // called, and the audited scope-change route must refuse it at the door.
        $department = $this->department('Ecology');
        $this->em->flush();

        $this->client->request('POST', '/departments/'.$department->getUuidString().'/scope');
        self::assertResponseRedirects('http://localhost/login');
    }

    // ---- the cast ---------------------------------------------------------

    /**
     * The register's cast: one area (Northern Reserve), an area-level department
     * in it (Wetland Management), two org-level (Ecology, Protection Service),
     * and a person placed in each of the three holding a position of their own.
     *
     * THE PEOPLE ARE WHAT MAKES A DEPARTMENT HAVE POSITIONS. A position is filed
     * under nobody and its name is unique across the organization, so the only
     * way a department comes to see one is for somebody placed in that
     * department to hold it.
     */
    private function screen(): Crawler
    {
        $this->administrator();

        $this->north = $this->area('Northern Reserve');

        $wetland = $this->areaDepartment('Wetland Management', $this->north);
        $ecology = $this->department('Ecology');
        $protection = $this->department('Protection Service');

        $zawadi = $this->person('Zawadi', 'Kimaro');
        $zawadi->setPosition($this->position('Wetland Ecologist'));
        $this->place($zawadi, [$this->north], [$wetland]);

        $tumaini = $this->person('Tumaini', 'Njau');
        $tumaini->setPosition($this->position('Analyst'));
        $this->place($tumaini, null, [$ecology]);

        $baraka = $this->person('Baraka', 'Msuya');
        $baraka->setPosition($this->position('Ranger'));
        $this->place($baraka, null, [$protection]);

        $this->em->flush();

        return $this->client->request('GET', '/departments');
    }

    /**
     * Sign in as an AREA-X administrator — a Staff member whose position carries
     * team.manage and whose PLACEMENT is $area alone, so their authority-area is
     * $area. They own a "Warden Office" department there to act on.
     *
     * REACH IS READ OFF THE PLACEMENT NOW. It used to be derived from the
     * department of the administrator's position, which made somebody's
     * authority a property of their job title; the ground is recorded against
     * the person, so that is what this seeds.
     */
    private function areaAdminIn(\Uhifadhi\Bundle\TeamBundle\Tests\Integration\Fixtures\Area\HostArea $area): \Uhifadhi\Bundle\TeamBundle\Entity\User
    {
        $this->areaDepartment('Warden Office', $area);
        $admin = $this->person('Amina', 'Salehe', TeamRoleEnum::Staff);
        $admin->setPosition($this->administratorPosition('Warden'));
        $this->place($admin, [$area]);
        $this->em->flush();
        $this->client->loginUser($admin);

        return $admin;
    }

    private ?\Uhifadhi\Bundle\TeamBundle\Tests\Integration\Fixtures\Area\HostArea $north = null;

    private function row(Crawler $crawler, string $name): Crawler
    {
        return $crawler->filter('[data-dp] tr.drow')
            ->reduce(static fn (Crawler $c): bool => $name === $c->filter('.ov-nm')->text())
            ->first();
    }

    /** The department's configure page, where it is changed. */
    private function configureOf(string $name): Crawler
    {
        $crawler = $this->client->request('GET', '/departments/'.$this->uuidOf($name).'/configure');
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    /** The uuid of a department by name — or, for "__area:Name", of an area. */
    private function uuidOf(string $name): string
    {
        if (str_starts_with($name, '__area:')) {
            $area = $this->em->getRepository(\Uhifadhi\Bundle\TeamBundle\Tests\Integration\Fixtures\Area\HostArea::class)
                ->findOneBy(['name' => substr($name, 7)]);
            self::assertNotNull($area);

            return (string) $area->getUuidString();
        }

        $department = $this->em->getRepository(Department::class)->findOneBy(['name' => $name]);
        self::assertInstanceOf(Department::class, $department);

        return (string) $department->getUuidString();
    }

    private function scopeChanges(): DepartmentScopeChangeRepository
    {
        /** @var DepartmentScopeChangeRepository $repo */
        $repo = static::getContainer()->get('test_public.'.DepartmentScopeChangeRepository::class);

        return $repo;
    }

    /**
     * WHAT ADMINISTERING THE TEAM IS, WRITTEN AS PAIRS. `team.manage` was one
     * flat value; it is eight (concern, verb) pairs now, and these are the
     * eight the upgrade backfills it into, so a fixture that used to say
     * "this person administers the team" still says exactly that.
     */
    private function administratorPosition(string $name): Position
    {
        return $this->position($name, [
            'directory.read',
            'directory.manage',
            'personal-details.read',
            'personal-details.manage',
            'positions.read',
            'positions.configure',
            'departments.read',
            'departments.configure',
        ]);
    }
}
