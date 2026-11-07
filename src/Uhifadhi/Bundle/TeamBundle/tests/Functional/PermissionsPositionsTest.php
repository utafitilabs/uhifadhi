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
use Uhifadhi\Bundle\TeamBundle\Entity\Position;
use Uhifadhi\Bundle\TeamBundle\Permissions\PositionsReportReader;
use Uhifadhi\Bundle\TeamBundle\Tests\Integration\Permission\AnOrganization;
use Uhifadhi\Contracts\Access\ScopeKind;

/**
 * TEAM › PERMISSIONS › POSITIONS — what holding each position grants, read as
 * written from the positions table. A Super Admin's, by tier. The tiers above
 * the matrix, then one row per position: its marks, holders, placement and a
 * summary, opening to the pairs under their packages and the notes. The
 * filter bar's chips and search work over the rows in the browser; every count
 * the server writes is the rows it wrote.
 */
final class PermissionsPositionsTest extends WebTestCaseWithSchema
{
    private const string PATH = '/team/permissions/positions';

    private AnOrganization $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = new AnOrganization($this->em);
    }

    public function testOnlyASuperAdminOpensIt(): void
    {
        $this->client->loginUser($this->org->personNamed('Naomi Kileo'));
        $this->client->request('GET', self::PATH);
        self::assertResponseIsSuccessful();

        $this->client->loginUser($this->org->personNamed('Mariam Temba'));
        $this->client->request('GET', self::PATH);
        self::assertResponseStatusCodeSame(403, 'Staff, even one whose position stores positions.configure');

        $this->client->loginUser($this->org->personNamed('Desta Haile'));
        $this->client->request('GET', self::PATH);
        self::assertResponseStatusCodeSame(403, 'an Admin, by tier');
    }

    public function testItIsHeadedAsALookupWithTheLookupsStrip(): void
    {
        $page = $this->page();

        self::assertSame('Permissions — Positions', $page->filter('.pghead h1.pg')->text());
        self::assertSame('What does holding each position grant? Read as written — nothing here is asked of a voter.', $page->filter('.pghead .pgsub')->text());
        self::assertSame('Super Admin only', $page->filter('.pghead .pgact .chip.acc')->text());
        self::assertSame('uhifadhi / team / permissions / positions', self::squash($page->filter('.crumb')->text()));
        self::assertSame(['Positions'], $page->filter('.atabs a.on')->each(static fn (Crawler $c): string => $c->text()));
        self::assertSame(self::PATH, $page->filter('.atabs a.on')->attr('href'));
        self::assertSame('Positions', $page->filter('.atabs a')->first()->text());
        self::assertGreaterThan(0, $page->filter('a[href="'.self::PATH.'"]')->reduce(static fn (Crawler $c): bool => null === $c->closest('.atabs'))->count(), 'the sidebar carries Positions');
    }

    public function testTheIndexHasOneRowPerCardAndCountsThePositionsShown(): void
    {
        $nav = $this->page()->filter('.onpage-nav');

        self::assertSame(['Above the matrix', 'Positions'], $nav->filter('.onpage-row .onpage-l')->each(static fn (Crawler $c): string => $c->text()));
        self::assertSame(['#tiers', '#positions'], $nav->filter('.onpage-row')->each(static fn (Crawler $c): ?string => $c->attr('href')));
        self::assertCount(0, $nav->filter('.onpage-row')->eq(0)->filter('.onpage-n'));
        self::assertSame('7', $nav->filter('.onpage-row')->eq(1)->filter('.onpage-n')->text());
    }

    public function testAboveTheMatrixIsATableOfThePeopleAboveIt(): void
    {
        $card = $this->page()->filter('.onpage-main #tiers');

        self::assertSame('Above the matrix', $card->filter('.tab')->getNode(0)?->firstChild?->textContent);
        self::assertSame('· 2 tiers · 2 people · not rows in the list', $card->filter('.tab .src')->text());
        self::assertCount(0, $card->filter('.pq-tiers, .pq-trow'), 'the tier rows are gone');
        self::assertSame('uhifadhi--team-bundle--permission-analysis', $card->attr('data-controller'), 'the house register over the rows');

        $bar = $card->filter('[data-pa-reg="above"] > .lfilt');
        self::assertCount(1, $bar);
        self::assertSame(['details', 'label'], $bar->children()->each(static fn (Crawler $c): string => $c->nodeName()), 'the tier chip, then the search');
        $chip = $bar->filter('details.i-dd[data-pa-axis="tier"]');
        self::assertSame('all · 2', $chip->filter('summary.mchip.i-ddt .i-ddval')->text());
        self::assertSame(['', 'super_admin', 'admin'], $chip->filter('button.i-ddopt')->each(static fn (Crawler $c): ?string => $c->attr('data-pa-opt')));
        self::assertSame(['All', 'Super Admin', 'Admin'], $chip->filter('.i-ddopt-l')->each(static fn (Crawler $c): string => $c->text()));
        self::assertSame(['2', '1', '1'], $chip->filter('.i-ddopt-n')->each(static fn (Crawler $c): string => $c->text()));
        self::assertSame('Find a person', $bar->filter('.lsearch input.fld[type="search"]')->attr('placeholder'));

        $table = $card->filter('table.tbl.pq-above');
        self::assertSame(['Person', 'Tier', ''], $table->filter('thead th')->each(static fn (Crawler $c): string => $c->text()));
        $rows = $table->filter('tbody tr[data-pa-r]');
        self::assertSame(['Naomi Kileo', 'Desta Haile'], $rows->each(static fn (Crawler $c): string => $c->filter('td')->eq(0)->filter('a')->text()), 'Super Admins first, then by name');
        $naomi = $this->org->personNamed('Naomi Kileo');
        self::assertSame('/team/'.$naomi->getUuidString(), $rows->eq(0)->filter('td')->eq(0)->filter('a')->attr('href'));
        self::assertSame(['placed: the whole organization', 'placed: the whole organization'], $rows->each(static fn (Crawler $c): string => $c->filter('td .pp-sub')->text()));
        self::assertSame(['tier t-super: Super Admin', 'tier t-admin: Admin'], $rows->each(static fn (Crawler $c): string => $c->filter('td span.tier')->attr('class').': '.$c->filter('td span.tier')->text()));
        self::assertSame(['Open →', 'Open →'], $rows->each(static fn (Crawler $c): string => $c->filter('td.pp-door a.more')->text()));
        self::assertSame('/team/'.$naomi->getUuidString(), $rows->eq(0)->filter('td.pp-door a.more')->attr('href'));
        self::assertSame(['{"tier":["super_admin"]}', '{"tier":["admin"]}'], $rows->each(static fn (Crawler $c): ?string => $c->attr('data-pa-f')));
        self::assertSame('naomi kileo', $rows->eq(0)->attr('data-pa-q'));

        $foot = $card->filter('.rdf-foot[data-pa-foot]');
        self::assertSame('2 people', $foot->filter('[data-pa-count]')->text());
        self::assertSame('20 per page▾', self::squash($foot->filter('details.i-dd.rdf-size > summary')->text()));
        self::assertSame('page 1 of 1', self::squash($foot->filter('.rdf-page')->text()));
        self::assertCount(1, $card->filter('[data-pa-nomatch][hidden]'), 'the empty line waits');

        $line = $card->filter('.pq-tline');
        self::assertStringStartsWith('Every permission is theirs without being granted; alone they hold ', $line->text());
        self::assertStringEndsWith(', and the powers.', $line->text());
        $tierOnly = array_map(static fn (string $p): string => str_replace('.', ' · ', $p), $this->report()['tierOnlyPairs']);
        self::assertNotSame([], $tierOnly);
        self::assertSame($tierOnly, $line->filter('.pq-code')->each(static fn (Crawler $c): string => $c->text()));
    }

    public function testTheFilterBarIsTheStatusTheConcernTheSortAndTheSearch(): void
    {
        $card = $this->page()->filter('#positions');

        self::assertSame('Positions', $card->filter('.tab')->getNode(0)?->firstChild?->textContent);
        self::assertSame('· 7 · read as written', $card->filter('.tab .src')->text());
        $bar = $card->filter('.lfilt');
        self::assertCount(1, $bar, 'one bar');
        self::assertSame(['details', 'details', 'details', 'div'], $bar->children()->each(static fn (Crawler $c): string => $c->nodeName()), 'three chips, then the search at the right end');
        $chips = $bar->filter('details.i-dd');
        self::assertSame(['only', 'concern', 'sort'], $chips->each(static fn (Crawler $c): ?string => $c->attr('data-axis')));
        self::assertSame(['all · 7', 'resource', 'most holders first'], $chips->each(static fn (Crawler $c): string => $c->filter('summary.mchip.i-ddt .i-ddval')->text()));
        self::assertSame(['▾', '▾', '▾'], $chips->each(static fn (Crawler $c): string => $c->filter('summary .i-ddcaret')->text()));
        self::assertCount(0, $bar->filter('summary.on'), 'nothing narrows yet');
        self::assertCount(0, $chips->filter('[open]'), 'every chip starts closed');
        self::assertSame('Find a position', $bar->filter('.lsearch input.fld[type="search"]')->attr('placeholder'));
        self::assertCount(1, $bar->filter('.lsearch svg'), 'the magnifier inside the field');

        self::assertCount(0, $card->filter('.pq-filters, .pq-frow, .pq-pills, .pq-pill, .pq-q'), 'the old filter rows are gone');
        self::assertCount(0, $card->filter('.dd-t, [data-dd]'), 'no shell dropdown heads');
        self::assertCount(0, $card->filter('.pq-lhead, .pq-list, div.pq-row'), 'the row list is a table now');
    }

    public function testTheStatusChipCountsThePositions(): void
    {
        $menu = $this->page()->filter('#positions details.i-dd[data-axis="only"] .i-ddmenu');

        self::assertSame('Show', $menu->attr('aria-label'));
        self::assertCount(0, $menu->filter('.i-ddhead'), 'one group, no head');
        $options = $menu->filter('button.i-ddopt');
        self::assertSame(['all', 'vacant', 'exception', 'locked', 'honoured', 'organization', 'area'], $options->each(static fn (Crawler $c): ?string => $c->attr('data-opt')));
        self::assertSame(['All', 'Vacant', 'With an exception', 'Locked', 'Not honoured', 'Organization-wide', 'In areas'], $options->each(static fn (Crawler $c): string => $c->filter('.i-ddopt-l')->text()));
        self::assertSame(['7', '1', '2', '0', '1', '3', '4'], $options->each(static fn (Crawler $c): string => $c->filter('.i-ddopt-n')->text()));
        self::assertSame(['true', 'false', 'false', 'false', 'false', 'false', 'false'], $options->each(static fn (Crawler $c): ?string => $c->attr('aria-pressed')));
        self::assertStringContainsString(' on', (string) $options->eq(0)->attr('class'));
        self::assertStringContainsString('pq-zero', (string) $options->eq(3)->attr('class'), 'a count of nothing is dimmed');
        self::assertCount(1, $menu->filter('.i-ddsep'), 'All stands apart from the rest');
    }

    public function testTheConcernChipIsGroupedByTheDeclaringPackage(): void
    {
        $menu = $this->page()->filter('#positions details.i-dd[data-axis="concern"] .i-ddmenu');

        self::assertSame('Filter by resource', $menu->attr('aria-label'));
        self::assertStringContainsString('pp-ddscroll', (string) $menu->attr('class'), 'it scrolls inside its panel');
        self::assertSame(['Surveys', 'Ground', 'Modules', 'Settings', 'Team'], $menu->filter('.i-ddhead')->each(static fn (Crawler $c): string => $c->text()), 'grouped by the declaring package');
        $first = $menu->children()->first();
        self::assertSame('', $first->attr('data-opt'), 'Any resource leads, under no head');
        self::assertSame('Any resource', $first->filter('.i-ddopt-l')->text());
        self::assertSame('7', $first->filter('.i-ddopt-n')->text());
        $surveys = $menu->filter('button.i-ddopt[data-opt="surveys"]');
        self::assertSame('Surveys', $surveys->filter('.i-ddopt-l')->text());
        self::assertSame('4', $surveys->filter('.i-ddopt-n')->text(), 'positions holding the concern');
        self::assertContains('0', $menu->filter('.i-ddopt-n')->each(static fn (Crawler $c): string => $c->text()), 'a concern nobody holds is listed, with its nought');
    }

    public function testTheSortChipIsMostHoldersFirstOrByName(): void
    {
        $menu = $this->page()->filter('#positions details.i-dd[data-axis="sort"] .i-ddmenu');

        self::assertSame('Sort', $menu->attr('aria-label'));
        self::assertCount(0, $menu->filter('.i-ddhead'), 'one group, no head');
        self::assertSame(['holders', 'name'], $menu->filter('button.i-ddopt')->each(static fn (Crawler $c): ?string => $c->attr('data-opt')));
        self::assertSame(['Most holders first', 'By name'], $menu->filter('.i-ddopt-l')->each(static fn (Crawler $c): string => $c->text()));
        self::assertCount(0, $menu->filter('.i-ddopt-n'), 'a sort counts nothing');
        self::assertSame(['true', 'false'], $menu->filter('button.i-ddopt')->each(static fn (Crawler $c): ?string => $c->attr('aria-pressed')));
    }

    public function testOnePageHasNoArrowsAndStatesThePageSize(): void
    {
        $card = $this->page()->filter('#positions');

        $foot = $card->filter('.rdf-foot');
        self::assertCount(1, $foot, 'the house register foot');
        self::assertNull($foot->attr('hidden'));
        self::assertSame('20', $card->attr('data-uhifadhi--team-bundle--permission-positions-per-page-value'));
        self::assertSame('7 positions', $foot->filter('[data-uhifadhi--team-bundle--permission-positions-target="count"]')->text());
        $size = $foot->children()->eq(0)->filter('details.i-dd.rdf-size');
        self::assertCount(1, $size, 'the page size is a chooser in the foot');
        self::assertSame('20 per page▾', self::squash($size->filter('summary')->text()));
        self::assertSame('20', $size->filter('summary .pg')->text());
        self::assertSame('Rows per page', $size->filter('.i-ddmenu')->attr('aria-label'));
        self::assertCount(0, $size->filter('.i-ddhead'), 'one group, no head');
        self::assertSame(['20', '50', '100', 'all'], $size->filter('button.i-ddopt')->each(static fn (Crawler $c): ?string => $c->attr('data-opt')));
        self::assertSame(['20', '50', '100', 'All'], $size->filter('button.i-ddopt .i-ddopt-l')->each(static fn (Crawler $c): string => $c->text()));
        self::assertSame(['true', 'false', 'false', 'false'], $size->filter('button.i-ddopt')->each(static fn (Crawler $c): ?string => $c->attr('aria-pressed')));
        self::assertSame('size', $size->attr('data-axis'));
        self::assertSame('uhifadhi-pp-pagesize:positions', $card->attr('data-uhifadhi--team-bundle--permission-positions-size-key-value'), 'remembered per table');
        self::assertSame('page 1 of 1', self::squash($foot->filter('.rdf-page')->text()));
        self::assertSame('1', $foot->filter('.rdf-page .pg')->text());
        self::assertCount(0, $foot->filter('.rdf-page button'), 'nowhere to go');
        self::assertCount(0, $card->filter('tr.pq-r[hidden]'), 'every row on the one page');
    }

    public function testPageOneShowsTwentyAndTheRestWaitHidden(): void
    {
        foreach (range(1, 14) as $n) {
            $this->em->persist(new Position()->setName(\sprintf('Spare post %02d', $n))->setAllowedKinds([ScopeKind::Organization])->setGrantValues([], []));
        }
        $this->em->flush();
        $card = $this->page()->filter('#positions');

        $rows = $card->filter('table.pq-tbl tbody tr.pq-r');
        self::assertCount(21, $rows);
        self::assertCount(20, $rows->reduce(static fn (Crawler $c): bool => null === $c->attr('hidden')), 'page one holds twenty');
        self::assertSame(['Tourism warden'], $rows->reduce(static fn (Crawler $c): bool => null !== $c->attr('hidden'))->each(static fn (Crawler $c): string => $c->filter('.pq-name')->text()), 'the last by the default sort waits for page two');

        $foot = $card->filter('.rdf-foot');
        self::assertSame('21 positions', $foot->filter('[data-uhifadhi--team-bundle--permission-positions-target="count"]')->text());
        self::assertSame('page 1 of 2', trim(str_replace('›', '', self::squash($foot->filter('.rdf-page')->text()))), 'page one of two');
        self::assertSame('›', $foot->filter('.rdf-page button')->text());
        self::assertSame(['1'], $foot->filter('.rdf-page button')->each(static fn (Crawler $c): ?string => $c->attr('data-go')), 'a next arrow only');
        self::assertSame('Next page', $foot->filter('.rdf-page button')->attr('aria-label'));
        self::assertSame('21', $this->page()->filter('.onpage-nav .onpage-row')->eq(1)->filter('.onpage-n')->text(), 'the index counts the filtered rows, not the page');
    }

    public function testThePositionsAreAHouseTableWithTheDoorColumnsToggle(): void
    {
        $table = $this->page()->filter('#positions table.tbl.pq-tbl');

        self::assertCount(1, $table);
        $heads = $table->filter('thead th');
        self::assertSame(['Position', 'People', 'Where', 'Expand all'], $heads->each(static fn (Crawler $c): string => $c->text()));
        self::assertStringContainsString('num', (string) $heads->eq(1)->attr('class'));
        self::assertStringContainsString('pq-dh', (string) $heads->eq(3)->attr('class'));
        self::assertSame('uhifadhi--team-bundle--permission-positions#all', $heads->eq(3)->filter('button.pq-more')->attr('data-action'), 'one toggle, in the door column\'s head');
        $children = $table->filter('tbody')->children();
        self::assertCount(14, $children, 'a row and its body, per position');
        self::assertSame(['pq-r', 'pq-body'], [$children->eq(0)->attr('class'), $children->eq(1)->attr('class')]);
    }

    public function testOneRowPerPositionMostHoldersFirst(): void
    {
        $rows = $this->page()->filter('#positions table.pq-tbl tbody tr.pq-r');

        self::assertSame(
            ['Patrol, security and inspection', 'Driver', 'Head of Operations', 'People officer', 'Rhino coordinator', 'Station head', 'Tourism warden'],
            $rows->each(static fn (Crawler $c): string => $c->filter('.pq-name')->text()),
        );
        self::assertCount(7, $rows->filter('td.pp-door .pq-chev[aria-expanded="false"]'));
        self::assertCount(0, $rows->filter('.pq-open'), 'every row starts closed');

        $ranger = $this->org->positionNamed('Patrol, security and inspection');
        $row = $rows->eq(0);
        $cells = $row->children();
        self::assertCount(4, $cells);
        self::assertSame('/team/positions/'.$ranger->getUuidString(), $row->filter('td')->eq(0)->filter('.pq-nm a.pq-name')->attr('href'));
        self::assertSame('pp-sub pq-sum', $cells->eq(0)->filter('span.pp-sub')->attr('class'));
        self::assertSame('3 permissions across 2 resources: Surveys, Stations', $row->filter('.pq-sum')->text());
        self::assertSame('num', $cells->eq(1)->attr('class'));
        self::assertSame('5', $cells->eq(1)->filter('a')->text());
        self::assertSame('/team?position='.$ranger->getUuidString(), $cells->eq(1)->filter('a')->attr('href'));
        self::assertSame('5 people in Patrol, security and inspection', $cells->eq(1)->filter('a')->attr('aria-label'));
        self::assertCount(0, $cells->eq(1)->filter('.pp-sub'), 'no seats, no sub-line');
        self::assertSame('in named areas', $cells->eq(2)->text());
        self::assertSame('pp-door', $cells->eq(3)->attr('class'));
        self::assertCount(0, $row->filter('.pq-nm .chip'), 'nothing to raise');

        $body = $row->nextAll()->first();
        self::assertSame('pq-body', $body->attr('class'));
        self::assertSame($body->attr('id'), $row->filter('.pq-chev')->attr('aria-controls'));
        self::assertNotNull($body->attr('hidden'), 'closed until opened');
        self::assertSame('4', $body->filter('td')->attr('colspan'));
        self::assertCount(1, $body->filter('td > .pq-in'));

        $warden = $rows->eq(6);
        self::assertSame('0', $warden->filter('td.num')->text());
        self::assertCount(0, $warden->filter('td.num a'), 'nobody to list');
    }

    public function testASeatedPositionSaysHowManySeats(): void
    {
        $this->org->positionNamed('Station head')->setSeatCount(3);
        $this->em->flush();
        $rows = $this->page()->filter('#positions table.pq-tbl tbody tr.pq-r');
        $head = $rows->reduce(static fn (Crawler $c): bool => 'Station head' === $c->filter('.pq-name')->text());

        self::assertSame('of 3 seats', $head->filter('td.num .pp-sub')->text());
    }

    public function testAClosedRowCarriesItsAlarms(): void
    {
        $rows = $this->page()->filter('#positions tr.pq-r');
        $chips = static fn (string $name): array => $rows->reduce(static fn (Crawler $c): bool => $name === $c->filter('.pq-name')->text())
            ->filter('.pq-nm .chip')->each(static fn (Crawler $c): string => $c->attr('class').': '.$c->text());

        self::assertSame(['chip warn: vacant since 14 Aug'], $chips('Tourism warden'));
        self::assertSame(['chip warn: exception not in force'], $chips('Rhino coordinator'));
        self::assertSame(['chip warn: a permission not honoured'], $chips('People officer'));
        self::assertSame([], $chips('Head of Operations'), 'its exception is in force');
    }

    public function testLockedIsAMarkAndAStatus(): void
    {
        $this->org->positionNamed('Driver')->setLocked(true);
        $this->em->flush();
        $page = $this->page();

        $driver = $page->filter('#positions tr.pq-r')->eq(1);
        self::assertSame('chip', $driver->filter('.pq-nm .chip')->attr('class'));
        self::assertSame('locked', $driver->filter('.pq-nm .chip')->text());
        self::assertSame('1', $page->filter('#positions details.i-dd[data-axis="only"] button.i-ddopt[data-opt="locked"] .i-ddopt-n')->text());
    }

    public function testTheOpenRowIsThePairsUnderTheirPackagesAndTheNotes(): void
    {
        $rows = $this->page()->filter('#positions tr.pq-r');
        $row = static fn (string $name): Crawler => $rows->reduce(static fn (Crawler $c): bool => $name === $c->filter('.pq-name')->text())->nextAll()->first();

        $ranger = $row('Patrol, security and inspection')->filter('td .pq-in');
        self::assertSame(['Surveys', 'Ground'], $ranger->filter('.pq-grp .pq-gh')->each(static fn (Crawler $c): string => $c->text()));
        self::assertSame(['Surveys — read, record', 'Stations — read'], $ranger->filter('.pq-cv')->each(static fn (Crawler $c): string => self::squash($c->text())));
        self::assertSame(['/team/permissions/analysis?q=surveys.read', '/team/permissions/analysis?q=stations.read'], $ranger->filter('.pq-cv a.pq-cl')->each(static fn (Crawler $c): ?string => $c->attr('href')), 'each concern opens on Analysis');
        self::assertCount(0, $ranger->filter('.pq-notes'));

        $head = $row('Head of Operations')->filter('td .pq-in');
        self::assertCount(1, $head->filter('.pq-cv .chip')->reduce(static fn (Crawler $c): bool => 'sensitive' === $c->text()), 'the locations concern is sensitive');
        $note = $head->filter('.pq-notes .pq-note');
        self::assertCount(1, $note);
        self::assertStringNotContainsString('pq-alarm', (string) $note->attr('class'));
        self::assertStringContainsString('— exception in force · Naomi Kileo,', self::squash($note->text()));
        self::assertStringEndsWith(': “Runs every operation across the four areas.”', self::squash($note->text()));
        self::assertCount(1, $note->filter('.pq-t time[datetime]'));

        $rhino = $row('Rhino coordinator')->filter('.pq-notes .pq-note.pq-alarm');
        self::assertCount(1, $rhino);
        self::assertStringEndsWith(' · read — held, NOT in force: no written reason, so the voter refuses it', self::squash($rhino->text()));

        $officer = $row('People officer')->filter('.pq-notes .pq-note.pq-alarm');
        self::assertCount(1, $officer);
        self::assertStringEndsWith(' · configure — stored, not honoured: only the tiers hold it', self::squash($officer->text()));
    }

    public function testEveryRowCarriesWhatTheFiltersReadAndThePageItsController(): void
    {
        $page = $this->page();
        $card = $page->filter('#positions');

        self::assertSame('uhifadhi--team-bundle--permission-positions', $card->attr('data-controller'));
        $warden = $card->filter('tr.pq-r')->eq(6);
        self::assertSame('tourism warden', $warden->attr('data-find'));
        self::assertSame('0', $warden->attr('data-holders'));
        self::assertSame('vacant area', $warden->attr('data-flags'));
        self::assertSame('stations', $warden->attr('data-concerns'));
        self::assertSame('exception organization', $card->filter('tr.pq-r')->eq(4)->attr('data-flags'));
        self::assertNotNull($card->filter('.pq-empty')->attr('hidden'), 'the empty line waits for a filter that leaves nothing');
    }

    private function page(): Crawler
    {
        $this->client->loginUser($this->org->personNamed('Naomi Kileo'));
        $page = $this->client->request('GET', self::PATH);
        self::assertResponseIsSuccessful();

        return $page;
    }

    /** @return array{tierOnlyPairs: list<string>} */
    private function report(): array
    {
        $reader = $this->client->getContainer()->get('test_public.'.PositionsReportReader::class);
        self::assertInstanceOf(PositionsReportReader::class, $reader);

        return $reader->report();
    }

    private static function squash(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
