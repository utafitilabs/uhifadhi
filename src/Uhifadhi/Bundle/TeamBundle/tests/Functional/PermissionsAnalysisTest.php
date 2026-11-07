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
use Uhifadhi\Bundle\RegistryBundle\Entity\Module;
use Uhifadhi\Bundle\TeamBundle\Entity\Department;
use Uhifadhi\Bundle\TeamBundle\Entity\Position;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Bundle\TeamBundle\Enum\PermissionRunTriggerEnum;
use Uhifadhi\Bundle\TeamBundle\Enum\TeamRoleEnum;
use Uhifadhi\Bundle\TeamBundle\Permissions\PermissionAnalysisReader;
use Uhifadhi\Bundle\TeamBundle\Permissions\PermissionFindingsReader;
use Uhifadhi\Bundle\TeamBundle\Permissions\PermissionRunService;
use Uhifadhi\Bundle\TeamBundle\Service\UserService;
use Uhifadhi\Bundle\TeamBundle\Tests\Integration\Permission\AnOrganization;
use Uhifadhi\Contracts\Access\ScopeKind;

/**
 * TEAM › PERMISSIONS › ANALYSIS — one question answered from the worker's
 * latest run, then the lists that run filed and its drift. A Super Admin's,
 * by tier. The question card holds the dropdown and the answer to it: for a
 * pair, positions down and places across with the people who pass in each
 * cell; for a power, the actors by target kind. The lists and the drift are
 * cards of their own, each one row of the index, independent of the question.
 */
final class PermissionsAnalysisTest extends WebTestCaseWithSchema
{
    private const string PATH = '/team/permissions/analysis';

    private const string RANGERS = 'Patrol, security and inspection';

    private const array ONE = ['people' => 'person', 'permissions' => 'permission', 'positions' => 'position', 'exceptions' => 'exception', 'answers' => 'answer'];

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

    public function testItIsHeadedAsALookupWithTheRunInItsHead(): void
    {
        $this->runNow();
        $page = $this->page();

        self::assertSame('Permissions — Analysis', $page->filter('.pghead h1.pg')->text());
        self::assertSame('Who can do this, and where? Answered by the worker for the real people, stored, and read with the time it was true.', $page->filter('.pghead .pgsub')->text());
        self::assertSame('uhifadhi / team / permissions / analysis', self::squash($page->filter('.crumb')->text()));
        self::assertSame('/team/permissions', $page->filter('.crumb a')->last()->attr('href'));
        self::assertSame(['Positions', 'Analysis', 'Check'], $page->filter('.atabs a')->each(static fn (Crawler $c): string => $c->text()));
        self::assertSame(['Analysis'], $page->filter('.atabs a.on')->each(static fn (Crawler $c): string => $c->text()));
        self::assertSame(self::PATH, $page->filter('.atabs a.on')->attr('href'));

        $actions = $page->filter('.pghead .pgact');
        self::assertSame('Super Admin only', $actions->filter('.chip.acc')->text());
        $fresh = $actions->filter('.pp-fresh');
        self::assertStringStartsWith('As of ', self::squash($fresh->filter('.pp-fresh > span')->text()));
        self::assertStringContainsString(' · next run ', self::squash($fresh->filter('.pp-fresh > span')->text()));
        self::assertCount(2, $fresh->filter('.pp-fresh > span b.pp-time time[data-localtime-format="stamp"]'), 'the run, and the next one');
        $form = $fresh->filter('form[method="post"][action="/team/permissions/recompute"]');
        self::assertSame('Recompute now', trim($form->filter('button.tgl')->text()));
        self::assertSame('team_permissions_analysis', $form->filter('input[name="back"]')->attr('value'));
    }

    public function testRecomputeNowComesBackHere(): void
    {
        $token = (string) $this->page()->filter('.pp-fresh input[name="_token"]')->attr('value');

        $this->client->request('POST', '/team/permissions/recompute', ['_token' => $token, 'back' => 'team_permissions_analysis']);
        self::assertResponseRedirects(self::PATH);
    }

    public function testTheIndexIsOneRowPerCardInTheDesignsOrderWithTheRunsCounts(): void
    {
        $this->runNow();
        $standing = $this->reader(PermissionFindingsReader::class)->standing();
        $nav = $this->page('?q=surveys.record')->filter('.onpage-nav');

        $rows = $nav->filter('.onpage-row');
        self::assertSame(
            ['The question', 'Reach nothing', 'Not in force', 'Not honoured', 'Gaining power', 'Vacant positions', 'Exceptions in force', 'Self-changes', 'Drift'],
            $rows->each(static fn (Crawler $c): string => $c->filter('.onpage-l')->text()),
        );
        self::assertSame(
            ['#question', '#reach-nothing', '#not-in-force', '#not-honoured', '#gaining', '#vacant', '#exceptions', '#self-changes', '#drift'],
            $rows->each(static fn (Crawler $c): ?string => $c->attr('href')),
        );
        self::assertCount(9, $rows->filter('.onpage-dot'), 'every row carries its dot');
        self::assertCount(0, $nav->filter('.warn, .pa-bad'), 'the index is navigation only');

        self::assertSame('5', $rows->eq(0)->filter('.onpage-n')->text(), 'three rangers in Kilimani, one in Sinde Flats, the station head');
        self::assertSame('5 people below the tiers can, somewhere', $rows->eq(0)->attr('title'));
        $counts = array_map(static fn (array $l): string => (string) $l['count'], $standing['lists']);
        self::assertSame([...$counts, '0'], \array_slice($rows->each(static fn (Crawler $c): string => $c->filter('.onpage-n')->text()), 1));
    }

    public function testTheQuestionIsTheFirstChipOfTheBarGroupedByPackageThenThePowers(): void
    {
        $this->runNow();
        $card = $this->page('?q=surveys.record')->filter('.onpage-main #question');

        self::assertSame('Who can record surveys, and where', $card->filter('.tab')->getNode(0)?->firstChild?->textContent);
        self::assertStringStartsWith('· stored · as of ', self::squash($card->filter('.tab .src')->text()));
        self::assertCount(1, $card->filter('.tab .src time'));
        self::assertCount(0, $card->filter('.pa-ctl, .pp-k, .dd, .dd-t'), 'no Question label, no searchable dropdown');

        $chip = $card->filter('.lfilt > details.i-dd')->first();
        self::assertSame('q', $chip->attr('data-pa-axis'));
        self::assertSame('surveys · record', $chip->filter('summary.mchip.i-ddt .i-ddval')->text());
        self::assertCount($chip->filter('.i-ddhead')->count() - 1, $chip->filter('.i-ddsep'), 'a line between groups');
        self::assertCount(1, $chip->filter('.i-ddmenu.pa-ddscroll'));
        self::assertCount(0, $card->filter('.lfilt .lsearch'), 'no search in this bar');

        $heads = $chip->filter('.i-ddhead')->each(static fn (Crawler $c): string => $c->text());
        self::assertSame('Surveys', $heads[0], 'the declaring package first');
        self::assertSame('Powers', end($heads), 'the powers last, in one group');
        self::assertCount(1, array_keys($heads, 'Powers', true));

        $record = $chip->filter('a.i-ddopt[data-pa-q="surveys.record"]');
        self::assertSame('?q=surveys.record', $record->attr('href'), 'choosing a question loads the page for it');
        self::assertStringContainsString('on', (string) $record->attr('class'));
        self::assertSame('Surveys · record', $record->filter('.i-ddopt-l')->text());
        self::assertSame('2', $record->filter('.i-ddopt-n')->text(), 'the rangers and the station head hold it');
        self::assertSame('2 positions hold it', $record->attr('title'));
        self::assertSame('4', $chip->filter('a.i-ddopt[data-pa-q="surveys.read"] .i-ddopt-n')->text());

        $power = $this->reader(PermissionAnalysisReader::class)->forPower('make-admin');
        $first = $power['targets'][0]['key'];
        $allowed = \count(array_filter($power['rows'], static fn (array $r): bool => 'allowed' === ($r['cells'][$first] ?? null)));
        $option = $chip->filter('a.i-ddopt[data-pa-q="make-admin"]');
        self::assertSame((string) $allowed, $option->filter('.i-ddopt-n')->text());
        self::assertSame($allowed.(1 === $allowed ? ' actor holds it' : ' actors hold it').' over '.$power['targets'][0]['label'], $option->attr('title'));
        self::assertSame('make admin', $this->page('?q=make-admin')->filter('#question .lfilt > details.i-dd[data-pa-axis="q"] .i-ddval')->text());
    }

    public function testAPairIsAnsweredPositionsDownPlacesAcrossWithTheTiersAbove(): void
    {
        $this->runNow();
        $card = $this->page('?q=surveys.record')->filter('#question');

        self::assertCount(1, $card->filter('[data-pa-pair]'));
        self::assertCount(0, $card->filter('[data-pa-power]'));
        self::assertSame('By tier: Super Admin 1 and Admin 1 can, everywhere.', self::squash($card->filter('.pa-lead')->text()));

        $grid = $card->filter('table.tbl.pa-grid');
        self::assertCount(5, $grid->filter('colgroup col.pa-pc'));
        self::assertSame(['Position', 'The whole organization', 'Kilimani Game Reserve', 'Mlima Mkuu', 'Sinde Flats', 'Ziwa Kavu'], $grid->filter('thead th')->each(static fn (Crawler $c): string => $c->text()));
        self::assertSame('people whose placement is the whole organization', $grid->filter('thead th.pa-plh')->first()->attr('title'));
        self::assertSame([self::RANGERS, 'Station head'], $grid->filter('tbody th.pa-rh')->each(static fn (Crawler $c): string => $c->text()));
        self::assertSame(['', '3', '', '1', ''], $grid->filter('tbody tr')->eq(0)->filter('td.pa-c')->each(static fn (Crawler $c): string => $c->text()));
        self::assertSame(['Total', '', '3', '', '1', '1'], $grid->filter('tfoot tr > *')->each(static fn (Crawler $c): string => $c->text()));

        $cell = $grid->filter('tbody tr')->eq(0)->filter('button.pa-cell')->first();
        self::assertSame('3 '.self::RANGERS.' · Kilimani Game Reserve', $cell->attr('aria-label'));
        self::assertSame('false', $cell->attr('aria-expanded'));

        self::assertNotNull($card->filter('.pa-people')->attr('hidden'), 'the people wait for a cell');
        self::assertSame('A person counts in every column their placement covers: somebody placed across the organization appears in every cell of their row.', $card->filter('.au-why.pa-note')->text());
        self::assertSame('uhifadhi--team-bundle--permission-analysis', $card->attr('data-controller'));
    }

    public function testEachCellCarriesThePeopleWhoPassThere(): void
    {
        $this->runNow();
        $card = $this->page('?q=surveys.record')->filter('#question');

        $cell = $card->filter('tbody tr')->eq(0)->filter('button.pa-cell')->first();
        $key = (string) $cell->attr('data-pa-cell');
        $people = $card->filter('template[data-pa-people="'.$key.'"]');
        self::assertCount(1, $people);
        self::assertSame(self::RANGERS.' · Kilimani Game Reserve', $people->attr('data-pa-where'));

        $names = new Crawler((string) $people->html())->filter('li');
        self::assertSame(['Imani Laizer', 'Omari Mollel', 'Thabo Ndlovu'], $names->each(static fn (Crawler $c): string => $c->text()));
        $thabo = $this->org->personNamed('Thabo Ndlovu');
        self::assertSame('/team/permissions/check?person='.$thabo->getId().'&q=surveys.record&target=area-'.$this->org->areaNamed('Kilimani Game Reserve')->getId(), $names->eq(2)->filter('a')->attr('href'), 'each name is the door to their check, here');
        self::assertCount(\count($card->filter('button.pa-cell')), $card->filter('template[data-pa-people]'), 'one list per cell that holds anybody');
    }

    public function testAnOrganizationWideHolderFillsTheRowAndAPairNobodyPassesSaysSo(): void
    {
        $this->runNow();

        $row = $this->page('?q=locations.read')->filter('#question table.pa-grid tbody tr')->first();
        self::assertSame('Head of Operations', $row->filter('th.pa-rh')->text());
        self::assertSame(['1', '1', '1', '1', '1'], $row->filter('td.pa-c')->each(static fn (Crawler $c): string => $c->text()));

        $card = $this->page('?q=locations.read')->filter('#question');
        self::assertCount(0, $card->filter('.au-why.pa-none:not([data-pa-nomatch])'), 'an empty row is still a row');
        $unheld = $card->filter('a.i-ddopt[data-pa-q]')->reduce(static fn (Crawler $c): bool => '0' === $c->filter('.i-ddopt-n')->text() && str_contains((string) $c->attr('data-pa-q'), '.'))->first()->attr('data-pa-q');
        $card = $this->page('?q='.$unheld)->filter('#question');
        self::assertCount(0, $card->filter('table.pa-grid'));
        self::assertSame('No position’s holders pass anywhere; only the tiers can.', $card->filter('.au-why.pa-none:not([data-pa-nomatch])')->text());
    }

    public function testTheQuestionCardCarriesTheHouseFilterBarWithThreeChips(): void
    {
        $this->runNow();
        $card = $this->page('?q=surveys.record')->filter('#question');
        $answer = $card->filter('[data-pa-pair]');

        self::assertSame(['lfilt', 'pa-lead'], \array_slice($answer->children()->each(static fn (Crawler $c): string => (string) $c->attr('class')), 0, 2), 'under the question, above the tiers');
        $bar = $answer->filter('.lfilt');
        self::assertSame(['q', 'rows', 'department', 'sort'], $bar->filter('details.i-dd')->each(static fn (Crawler $c): ?string => $c->attr('data-pa-axis')));
        self::assertCount(4, $bar->filter('details.i-dd > summary.mchip.i-ddt'));
        self::assertCount(0, $card->filter('.dd-t, .dd'), 'the house chips only');

        $rows = $bar->filter('[data-pa-axis="rows"]');
        self::assertSame('all positions · 2', self::squash($rows->filter('.i-ddval')->text()));
        self::assertStringNotContainsString(' on', (string) $rows->filter('summary')->attr('class'));
        self::assertSame([['All positions', '2'], ['With people', '2'], ['Empty', '0'], ['Changed since last run', '0']], self::options($rows));
        self::assertSame(['all', 'with-people', 'empty', 'changed'], $rows->filter('button.i-ddopt')->each(static fn (Crawler $c): ?string => $c->attr('data-pa-opt')));
        self::assertSame('true', $rows->filter('button.i-ddopt.on')->attr('aria-pressed'));
        self::assertSame('All positions', $rows->filter('button.i-ddopt.on .i-ddopt-l')->text());
        self::assertCount(2, $rows->filter('button.i-ddopt.pa-zero'), 'a count of nothing is dimmed');
        self::assertCount(1, $rows->filter('.i-ddsep'));

        $department = $bar->filter('[data-pa-axis="department"]');
        self::assertSame('department', $department->filter('.i-ddval')->text());
        self::assertSame([['Any department', '5'], ['Ecology and Monitoring', '0'], ['ICT', '0'], ['Protection Service', '5']], self::options($department));
        self::assertSame('', $department->filter('button.i-ddopt')->first()->attr('data-pa-opt'));
        self::assertSame((string) $this->org->personNamed('Thabo Ndlovu')->getPlacement()?->getDepartment()?->getId(), $department->filter('button.i-ddopt')->last()->attr('data-pa-opt'));
        self::assertCount(2, $department->filter('button.i-ddopt.pa-zero'));

        $sort = $bar->filter('[data-pa-axis="sort"]');
        self::assertSame('most people first', $sort->filter('.i-ddval')->text());
        self::assertSame([['Most people first', ''], ['By name', '']], self::options($sort));
        self::assertSame(['people', 'name'], $sort->filter('button.i-ddopt')->each(static fn (Crawler $c): ?string => $c->attr('data-pa-opt')));
        self::assertCount(0, $sort->filter('.i-ddopt-n'));

        self::assertCount(0, $this->page('?q=make-admin')->filter('#question .lfilt [data-pa-axis="rows"], #question .lfilt [data-pa-axis="department"], #question .lfilt [data-pa-axis="sort"]'), 'a power has none of the grid chips');
    }

    public function testEveryPositionHoldingThePairIsARowSoAnEmptyRowExists(): void
    {
        $this->runNow();
        $card = $this->page('?q=locations.read')->filter('#question');

        $rows = $card->filter('table.pa-grid tbody tr');
        self::assertSame(['Head of Operations', 'Rhino coordinator'], $rows->filter('th.pa-rh')->each(static fn (Crawler $c): string => $c->text()));
        self::assertSame(['', '', '', '', ''], $rows->eq(1)->filter('td.pa-c')->each(static fn (Crawler $c): string => $c->text()), 'held with no reason in force: nobody passes');
        self::assertSame(['1', '0'], $rows->each(static fn (Crawler $c): ?string => $c->attr('data-pa-people')));
        self::assertSame([['All positions', '2'], ['With people', '1'], ['Empty', '1'], ['Changed since last run', '0']], self::options($card->filter('[data-pa-axis="rows"]')));
    }

    public function testEachPersonInACellCarriesTheDepartmentsTheyServe(): void
    {
        $this->runNow();
        $card = $this->page('?q=locations.read')->filter('#question');
        $grace = $this->org->personNamed('Grace Ndosi');
        $placement = $grace->getPlacement() ?? throw new \LogicException('unplaced');
        $serves = [(int) $placement->getDepartment()?->getId(), ...array_map(static fn (Department $d): int => (int) $d->getId(), $placement->getSupports())];

        $names = new Crawler((string) $card->filter('template[data-pa-people]')->first()->html())->filter('li');
        self::assertSame((string) $grace->getId(), $names->attr('data-pa-id'));
        self::assertSame(implode(' ', $serves), $names->attr('data-pa-deps'), 'their own, then those they support');
        self::assertSame([['Any department', '1'], ['Ecology and Monitoring', '1'], ['ICT', '0'], ['Protection Service', '1']], self::options($card->filter('[data-pa-axis="department"]')), 'a person counts under every department they serve');
    }

    public function testACellTheDriftNamesIsMarkedAndTheNoteLinksToDrift(): void
    {
        $this->runNow();
        $before = $this->page('?q=surveys.record')->filter('#question');
        self::assertCount(0, $before->filter('td.pa-chg'));
        self::assertCount(0, $before->filter('.pa-note[data-pa-chg]'), 'nothing changed, no note');

        $this->driftTheRangers();
        $card = $this->page('?q=surveys.record')->filter('#question');

        $marked = $card->filter('td.pa-c.pa-chg');
        self::assertCount(1, $marked, 'Kilimani on the ranger row');
        self::assertSame('changed since the last run', $marked->attr('title'));
        self::assertSame('4 '.self::RANGERS.' · Kilimani Game Reserve · changed since the last run', $marked->filter('button.pa-cell')->attr('aria-label'));
        self::assertSame(1, $card->filter('table.pa-grid tbody tr')->eq(0)->filter('td.pa-c')->reduce(static fn (Crawler $c): bool => str_contains((string) $c->attr('class'), 'pa-chg'))->count());
        self::assertSame('1', $card->filter('table.pa-grid tbody tr')->eq(0)->attr('data-pa-changed'));

        $note = $card->filter('p.au-why.pa-note[data-pa-chg]');
        self::assertSame('1 cell changed since the last run · see Drift', self::squash($note->text()));
        self::assertSame('#drift', $note->filter('a.pa-dlink')->attr('href'));
        self::assertSame('see Drift', $note->filter('a.pa-dlink')->text());

        self::assertSame([['All positions', '2'], ['With people', '2'], ['Empty', '0'], ['Changed since last run', '1']], self::options($card->filter('[data-pa-axis="rows"]')));
        self::assertSame([['Any department', '6'], ['Ecology and Monitoring', '1'], ['ICT', '0'], ['Protection Service', '5']], self::options($card->filter('[data-pa-axis="department"]')));
    }

    public function testTheGridIsPagedWithTheHouseRegisterFoot(): void
    {
        $this->runNow();
        $card = $this->page('?q=surveys.record')->filter('#question');

        $foot = $card->filter('table.pa-grid + .rdf-foot');
        self::assertCount(1, $foot);
        self::assertStringStartsWith('2 positions · ', self::squash($foot->filter('span')->first()->text()));
        self::assertSame('20 per page▾', self::squash($foot->filter('details.i-dd.rdf-size > summary')->text()));
        self::assertSame('20', $foot->filter('.rdf-size > summary .pg')->text());
        self::assertSame([['20', '20'], ['50', '50'], ['100', '100'], ['All', '0']], $foot->filter('.rdf-size .i-ddmenu button.i-ddopt')->each(static fn (Crawler $c): array => [$c->text(), (string) $c->attr('data-pa-size')]));
        self::assertSame('page 1 of 1', self::squash($foot->filter('.rdf-page')->text()));
        self::assertSame('1', $foot->filter('.rdf-page .pg')->text());
        self::assertCount(0, $foot->filter('.rdf-page button'), 'an arrow only where there is a page');
        self::assertSame('Total', $card->filter('table.pa-grid tfoot th')->text());
    }

    public function testMoreThanTwentyRowsTakeASecondPageAndTheTotalCountsThemAll(): void
    {
        for ($i = 1; $i <= 18; ++$i) {
            $this->em->persist(new Position()->setName(\sprintf('Gate keeper %02d', $i))->setAllowedKinds([ScopeKind::Area])->setGrantValues(['stations.read'], ['stations.read']));
        }
        $this->em->flush();
        $this->runNow();
        $card = $this->page('?q=stations.read')->filter('#question');

        $rows = $card->filter('table.pa-grid tbody tr');
        $all = $rows->count();
        self::assertGreaterThan(20, $all);
        self::assertCount(20, $rows->reduce(static fn (Crawler $c): bool => null === $c->attr('hidden')), 'the first page');
        $foot = $card->filter('table.pa-grid + .rdf-foot');
        self::assertStringStartsWith($all.' positions · ', self::squash($foot->filter('span')->first()->text()));
        self::assertSame('page 1 of 2›', self::squash($foot->filter('.rdf-page')->text()));
        self::assertSame(['Next page'], $foot->filter('.rdf-page button')->each(static fn (Crawler $c): ?string => $c->attr('aria-label')));
        self::assertSame('1', $foot->filter('.rdf-page button')->attr('data-pa-go'));
        self::assertSame('Total · all '.$all, $card->filter('table.pa-grid tfoot th')->text());
    }

    public function testEveryTableOnThePageHasTheBarAndTheFootWhateverItsSize(): void
    {
        $this->runNow();
        $this->driftTheRangers();
        $page = $this->page();

        $tables = [
            'reach-nothing' => [['reason', 'position'], 'people'],
            'not-in-force' => [['position', 'permission'], 'permissions'],
            'not-honoured' => [['position', 'permission'], 'permissions'],
            'vacant' => [['since'], 'positions'],
            'exceptions' => [['position', 'permission', 'by'], 'exceptions'],
            'drift' => [['place', 'position', 'review'], 'answers'],
        ];
        foreach ($tables as $id => [$axes, $noun]) {
            $card = $page->filter('.onpage-main > .c#'.$id);
            $rows = $card->filter('table.pa-st tbody tr');
            self::assertGreaterThan(0, $rows->count(), $id);
            $bar = $card->filter('.lfilt');
            self::assertCount(1, $bar, $id);
            self::assertSame($axes, $bar->filter('details.i-dd')->each(static fn (Crawler $c): ?string => $c->attr('data-pa-axis')), $id);
            self::assertCount(1, $bar->filter('.lsearch input[type="search"]'), $id);
            $foot = $card->filter('table.pa-st + .rdf-foot');
            self::assertCount(1, $foot, $id.' has a foot even at one page');
            self::assertStringStartsWith($rows->count().' '.(1 === $rows->count() ? self::ONE[$noun] : $noun).' · ', self::squash($foot->filter('span')->first()->text()), $id);
            self::assertSame('page 1 of 1', self::squash($foot->filter('.rdf-page')->text()), $id);
            self::assertSame('No '.$noun.' matches these filters.', $card->filter('.au-why.pa-none[data-pa-nomatch]')->text(), $id);
        }

        $reason = $page->filter('#reach-nothing [data-pa-axis="reason"]');
        $all = $page->filter('#reach-nothing table.pa-st tbody tr')->count();
        self::assertSame('all · '.$all, self::squash($reason->filter('.i-ddval')->text()));
        self::assertSame('position', $page->filter('#not-in-force [data-pa-axis="position"] .i-ddval')->text());
        self::assertSame([['All positions', '1'], ['Rhino coordinator', '1']], self::options($page->filter('#not-in-force [data-pa-axis="position"]')));
        self::assertSame([['All', (string) $page->filter('#drift tbody tr')->count()], ['Unreviewed', (string) $page->filter('#drift tbody tr')->count()], ['Reviewed', '0']], self::options($page->filter('#drift [data-pa-axis="review"]')));

        $power = $this->page('?q=make-admin')->filter('#question');
        self::assertSame(['q', 'verdict'], $power->filter('.lfilt details.i-dd')->each(static fn (Crawler $c): ?string => $c->attr('data-pa-axis')));
        self::assertCount(1, $power->filter('.lfilt .lsearch input[type="search"]'));
        self::assertStringStartsWith(\count($power->filter('table.pa-pow tbody tr')).' actors · ', self::squash($power->filter('table.pa-pow + .rdf-foot span')->first()->text()));
    }

    public function testADriftRowIsOneLineWithItsDoorsAtTheRight(): void
    {
        $this->runNow();
        $this->driftTheRangers();
        $row = $this->page()->filter('#drift tbody tr')->reduce(static fn (Crawler $c): bool => str_starts_with($c->filter('th.pa-rh')->text(), self::RANGERS) && 'Surveys · record' === $c->filter('th .pp-sub')->text())->first();

        self::assertCount(0, $row->filter('th form, th a, th button'), 'no action stacked under a name');
        self::assertSame(self::RANGERS, $row->filter('th.pa-rh')->getNode(0)?->firstChild?->textContent, 'the position on the primary line');
        self::assertSame('Surveys · record', $row->filter('th .pp-sub')->text(), 'the permission its mono sub-line');
        $door = $row->filter('td.pp-door');
        self::assertCount(1, $door);
        self::assertSame('pp-door', $row->filter('td')->last()->attr('class'));
        self::assertCount(1, $door->filter('.pa-acts'), 'both doors on one line');
        self::assertSame('Verify live →', $door->filter('.pa-acts a.more')->text());
        self::assertStringStartsWith('/team/permissions/check?person=', (string) $door->filter('a.more')->attr('href'));
        self::assertSame('Mark reviewed', trim($door->filter('form button.pp-b')->text()));
        self::assertSame(['Position · permission', 'The whole organization', 'Kilimani Game Reserve', 'Mlima Mkuu', 'Sinde Flats', 'Ziwa Kavu', ''], $this->page()->filter('#drift table.pa-st-drift thead th')->each(static fn (Crawler $c): string => $c->text()));
    }

    public function testAQuestionIsReadFromTheAddressAndFallsBackToTheFirstPair(): void
    {
        $this->runNow();

        $chosen = static fn (Crawler $page): ?string => $page->filter('[data-pa-axis="q"] a.i-ddopt.on')->attr('data-pa-q');
        self::assertSame('surveys.read', $chosen($this->page('?q=surveys')), 'a concern opens on its first verb');
        $fallback = $this->page('?q=nothing.such');
        self::assertSame($fallback->filter('[data-pa-axis="q"]')->attr('data-default'), $chosen($fallback));
        self::assertSame($chosen($this->page()), $chosen($fallback));
    }

    public function testAPowerIsAnsweredActorsDownTargetKindsAcross(): void
    {
        $this->runNow();
        $power = $this->reader(PermissionAnalysisReader::class)->forPower('make-admin');
        $card = $this->page('?q=make-admin')->filter('#question');

        self::assertSame('Who holds make Admin, over whom', $card->filter('.tab')->getNode(0)?->firstChild?->textContent);
        self::assertCount(0, $card->filter('[data-pa-pair]'));
        self::assertSame('Giving power — to somebody else', self::squash($card->filter('[data-pa-power] .pa-lead')->first()->text()));
        self::assertCount(0, $card->filter('.pa-expect'), 'only a gaining power carries the expectation');

        $table = $card->filter('table.tbl.pa-pow');
        self::assertSame(['Who', ...array_column($power['targets'], 'label'), ''], $table->filter('thead th')->each(static fn (Crawler $c): string => $c->text()));
        self::assertCount(0, $table->filter('tr.pa-grp'), 'no band rows');
        $rows = $table->filter('tbody tr');
        self::assertSame(array_column($power['rows'], 'name'), $rows->each(static fn (Crawler $c): string => (string) $c->filter('th.pa-rh')->getNode(0)?->firstChild?->textContent));
        self::assertSame(array_column($power['rows'], 'kind'), $rows->each(static fn (Crawler $c): string => $c->filter('th.pa-rh .pp-sub')->text()), 'tier or position, under the name');
        $first = $rows->eq(0)->filter('td.pa-tc .au-v.pp-v');
        self::assertSame('allowed', $first->eq(0)->text());
        self::assertStringContainsString('ok', (string) $first->eq(0)->attr('class'));
        $door = $rows->eq(0)->filter('td.pp-door a.more');
        self::assertSame('Check →', $door->text());
        self::assertStringStartsWith('/team/permissions/check?person=', (string) $door->attr('href'));
        self::assertStringContainsString('q=make-admin', (string) $door->attr('href'));
        self::assertSame($door->attr('href'), $rows->eq(0)->filter('th.pa-rh a')->attr('href'), 'the name opens the same check');
    }

    public function testAGainingPowerSaysWhatIsExpectedAndWarnsOnlyOnAnAlarm(): void
    {
        $this->runNow();
        $power = $this->reader(PermissionAnalysisReader::class)->forPower('raise-their-own-tier');
        $expect = $this->page('?q=raise-their-own-tier')->filter('#question .pa-expect');

        self::assertSame('The expected answer is nobody but a Super Admin; any other yes here is an alarm.', $expect->text());
        self::assertSame($power['alarm'], str_contains((string) $expect->attr('class'), 'pa-bad'));
    }

    public function testTheListsAreOneCardEachWithTheRunsEntries(): void
    {
        $this->runNow();
        $lists = array_column($this->reader(PermissionFindingsReader::class)->standing()['lists'], null, 'id');
        $page = $this->page();

        $titles = [
            'reach-nothing' => 'People who reach nothing',
            'not-in-force' => 'Sensitive permissions held but not in force',
            'not-honoured' => 'Tier-only permissions stored on a position and not honoured',
            'gaining' => 'Gaining power — answers below Super Admin',
            'vacant' => 'Positions nobody holds',
            'exceptions' => 'Exceptions in force',
            'self-changes' => 'Self-changes',
        ];
        foreach ($titles as $id => $title) {
            $card = $page->filter('.onpage-main > .c#'.$id);
            self::assertCount(1, $card, $id);
            self::assertSame($title.' · '.$lists[$id]['count'], self::squash($card->filter('.tab > span')->first()->text()), $id);
            self::assertStringStartsWith('· filed in the run of ', self::squash($card->filter('.tab .src')->text()), $id);
            self::assertSame($lists[$id]['alarm'] && $lists[$id]['count'] > 0, 1 === $card->filter('.pa-cnt.pa-bad')->count(), $id);
            self::assertCount($lists[$id]['count'], $card->filter('table.pa-st tbody tr'), $id);
        }

        $reach = $page->filter('#reach-nothing table.pa-st');
        self::assertSame(['Person', 'Why', ''], $reach->filter('thead th')->each(static fn (Crawler $c): string => $c->text()));
        $entry = $lists['reach-nothing']['entries'][0];
        $person = $reach->filter('tbody tr')->eq(0);
        self::assertSame($entry['name'], $person->filter('th.pa-rh')->getNode(0)?->firstChild?->textContent);
        self::assertSame($entry['position'] ?? 'no position', $person->filter('th.pa-rh .pp-sub')->text(), 'the position under the name');
        self::assertSame(ucfirst(\is_string($entry['why']) ? $entry['why'] : ''), $person->filter('td.pa-s')->text());
        self::assertSame('Check →', $person->filter('td.pp-door a.more')->text());
        self::assertStringStartsWith('/team/permissions/check?person=', (string) $person->filter('td.pp-door a.more')->attr('href'));

        $rhino = $this->org->positionNamed('Rhino coordinator')->getUuidString();
        $officer = $this->org->positionNamed('People officer')->getUuidString();
        foreach (['not-in-force' => ['Rhino coordinator', 'no written reason, so the voter refuses it', $rhino], 'not-honoured' => ['People officer', 'only the tiers hold it', $officer]] as $id => [$position, $reason, $uuid]) {
            self::assertSame(['Position · permission', 'Reason', ''], $page->filter('#'.$id.' thead th')->each(static fn (Crawler $c): string => $c->text()), $id);
            $row = $page->filter('#'.$id.' tbody tr')->eq(0);
            self::assertSame($position, $row->filter('th.pa-rh')->getNode(0)?->firstChild?->textContent, $id);
            self::assertSame($lists[$id]['entries'][0]['pairLabel'], $row->filter('th.pa-rh .pp-sub')->text(), $id.': the permission as the sub-line');
            self::assertSame($reason, $row->filter('td.pa-s')->text(), $id);
            self::assertSame('Open →', $row->filter('td.pp-door a.more')->text(), $id);
            self::assertSame('/team/positions/'.$uuid, $row->filter('td.pp-door a.more')->attr('href'), $id);
        }
        self::assertSame('nobody below Super Admin can raise themselves', $page->filter('#gaining p.pa-s.pa-one')->text());
        self::assertSame(['Position', 'Vacant since', ''], $page->filter('#vacant thead th')->each(static fn (Crawler $c): string => $c->text()));
        self::assertSame('Tourism warden', $page->filter('#vacant tbody th')->text());
        self::assertCount(1, $page->filter('#vacant tbody .pp-t time'));
        self::assertSame('/team/positions/'.$this->org->positionNamed('Tourism warden')->getUuidString(), $page->filter('#vacant tbody td.pp-door a.more')->attr('href'));

        $exception = $page->filter('#exceptions tbody tr')->eq(0);
        self::assertSame(['Position · permission', 'By', 'Reason', ''], $page->filter('#exceptions thead th')->each(static fn (Crawler $c): string => $c->text()));
        self::assertSame('Head of Operations', $exception->filter('th.pa-rh')->getNode(0)?->firstChild?->textContent);
        self::assertSame($lists['exceptions']['entries'][0]['pairLabel'], $exception->filter('th.pa-rh .pp-sub')->text());
        self::assertSame('Naomi Kileo', $exception->filter('td.pa-s')->eq(0)->getNode(0)?->firstChild?->textContent);
        self::assertCount(1, $exception->filter('td.pa-s')->eq(0)->filter('.pp-sub time'), 'the time under who gave it');
        self::assertSame('Runs every operation across the four areas.', $exception->filter('td.pa-s')->eq(1)->text());
        self::assertSame('Open →', $exception->filter('td.pp-door a.more')->text());
        self::assertSame('Nobody changed their own standing.', $page->filter('#self-changes .au-why.pa-none')->text());
    }

    public function testASelfChangeReadsAsTheirOwnBeforeAndAfter(): void
    {
        $naomi = $this->org->personNamed('Naomi Kileo');
        $desta = $this->org->personNamed('Desta Haile');
        $accounts = $this->client->getContainer()->get('test_public.'.UserService::class);
        self::assertInstanceOf(UserService::class, $accounts);
        $accounts->changeTier($desta, TeamRoleEnum::SuperAdmin, $naomi);
        $accounts->changeTier($naomi, TeamRoleEnum::Admin, $naomi);
        $this->runNow();

        $this->client->loginUser($desta);
        $card = $this->client->request('GET', self::PATH)->filter('#self-changes');
        self::assertSame(['Who', 'What', ''], $card->filter('thead th')->each(static fn (Crawler $c): string => $c->text()));
        $row = $card->filter('tbody tr');
        self::assertCount(1, $row);
        self::assertSame('Naomi Kileo', $row->filter('th.pa-rh')->getNode(0)?->firstChild?->textContent);
        self::assertCount(1, $row->filter('th.pa-rh .pp-sub time'), 'when, under who');
        self::assertSame('their own tier Super Admin → Admin', self::squash($row->filter('td.pa-s')->text()));
        self::assertSame(['Super Admin', 'Admin'], $row->filter('.ph-diff s, .ph-diff i')->each(static fn (Crawler $c): string => $c->text()));
        self::assertSame('Open →', $row->filter('td.pp-door a.more')->text());
        self::assertSame('/team/'.$naomi->getUuidString(), $row->filter('td.pp-door a.more')->attr('href'));
    }

    public function testDriftIsTheBannerAndTheLastCardAndMarkReviewedComesBackHere(): void
    {
        $this->runNow();
        $page = $this->page();
        self::assertCount(0, $page->filter('.pp-drift'), 'no drift, no banner');
        self::assertSame('No answer changed without a history line.', $page->filter('#drift .au-why.pa-none')->text());
        self::assertStringContainsString('pa-alarm', (string) $page->filter('.onpage-main > .c#drift')->attr('class'));

        $this->driftTheRangers();
        $drift = $this->reader(PermissionFindingsReader::class)->drift();
        $changed = array_sum(array_map(static fn (array $l): int => \count($l['places']), $drift['lines']));
        self::assertGreaterThan(0, $changed);

        $page = $this->page();
        $banner = $page->filter('.pp-drift');
        self::assertSame($changed.' answers changed with no recorded change', $banner->filter('.pp-dtext b')->text());
        self::assertSame('nothing in the history explains it', $banner->filter('.pp-dcause')->text());
        self::assertSame('#drift', $banner->filter('.pp-dacts a.btn.sm')->attr('href'));
        self::assertSame('team_permissions_analysis', $banner->filter('form input[name="back"]')->attr('value'));

        $card = $page->filter('.onpage-main > .c#drift');
        self::assertSame('Drift · answers that changed with no recorded change · '.\count($drift['lines']), self::squash($card->filter('.tab > span')->first()->text()));
        self::assertStringStartsWith('· between the runs of ', self::squash($card->filter('.tab .src')->text()));
        self::assertSame('nothing in the history explains it', $card->filter('p.pa-lead')->text());
        self::assertSame(['Position · permission', 'The whole organization', 'Kilimani Game Reserve', 'Mlima Mkuu', 'Sinde Flats', 'Ziwa Kavu', ''], $card->filter('table.pa-st-drift thead th')->each(static fn (Crawler $c): string => $c->text()));

        $line = $card->filter('tbody tr')->reduce(static fn (Crawler $c): bool => str_starts_with($c->filter('th.pa-rh')->text(), self::RANGERS));
        self::assertGreaterThan(0, $line->count());
        $first = $line->eq(0);
        self::assertCount(1, $first->filter('th .pp-sub'));
        self::assertSame('3→4', str_replace(' ', '', $first->filter('td')->eq(1)->text()), 'Kilimani: the ranger in Ecology passes now');
        self::assertSame((string) \count($drift['lines']), $page->filter('.onpage-row[href="#drift"] .onpage-n')->text());

        $form = $first->filter('form[method="post"][action="/team/permissions/drift/reviewed"]');
        $ids = $form->filter('input[name="ids[]"]')->each(static fn (Crawler $c): int => (int) $c->attr('value'));
        self::assertNotSame([], $ids);
        $this->client->request('POST', '/team/permissions/drift/reviewed', [
            '_token' => (string) $form->filter('input[name="_token"]')->attr('value'),
            'back' => 'team_permissions_analysis',
            'ids' => array_map('strval', $ids),
        ]);
        self::assertResponseRedirects(self::PATH);
        $after = $this->reader(PermissionFindingsReader::class)->drift();
        self::assertSame($changed - \count($ids), $after['unreviewed'], 'only that line');

        $reviewed = $this->client->followRedirect()->filter('#drift tbody tr')->reduce(static fn (Crawler $c): bool => str_contains((string) $c->attr('class'), 'pa-done'));
        self::assertCount(1, $reviewed);
        self::assertSame('Reviewed', trim($reviewed->filter('button.pp-b')->text()));
        self::assertNotNull($reviewed->filter('button.pp-b')->attr('disabled'));
    }

    public function testBeforeAnyRunThePageSaysNotComputedYet(): void
    {
        $page = $this->page();

        self::assertStringContainsString('not computed yet', $page->filter('.pghead .pp-fresh')->text());
        self::assertStringContainsString('not computed yet', $page->filter('#question .pa-none')->text());
        self::assertCount(0, $page->filter('.pp-drift'));
        self::assertCount(9, $page->filter('.onpage-row'));
        self::assertCount(9, $page->filter('.onpage-main > .c'), 'the question, seven lists, the drift');
    }

    private function page(string $query = ''): Crawler
    {
        $this->client->loginUser($this->naomi());
        $page = $this->client->request('GET', self::PATH.$query);
        self::assertResponseIsSuccessful();

        return $page;
    }

    private function naomi(): User
    {
        return $this->em->getRepository(User::class)->findOneBy(['email' => 'naomi.kileo@example.test']) ?? throw new \LogicException('gone');
    }

    private function runNow(): void
    {
        $service = $this->reader(PermissionRunService::class);
        $run = $service->requestRun(PermissionRunTriggerEnum::Manual, null);
        $service->handle($run->getId());
    }

    /** Ecology starts running Surveys behind the services' back: no history line, so the next run files drift. */
    private function driftTheRangers(): void
    {
        $ecology = $this->em->getRepository(Department::class)->findOneBy(['name' => 'Ecology and Monitoring']) ?? throw new \LogicException('no department');
        $surveys = $this->em->getRepository(Module::class)->findOneBy(['slug' => 'surveys']) ?? throw new \LogicException('no module');
        $ecology->attachModule($surveys);
        $this->em->flush();
        $this->runNow();
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function reader(string $class): object
    {
        $service = $this->client->getContainer()->get('test_public.'.$class);
        self::assertInstanceOf($class, $service);

        return $service;
    }

    /** @return list<array{string, string}> each option's label and count */
    private static function options(Crawler $chip): array
    {
        return $chip->filter('.i-ddmenu button.i-ddopt')->each(static fn (Crawler $c): array => [
            $c->filter('.i-ddopt-l')->text(),
            $c->filter('.i-ddopt-n')->count() > 0 ? $c->filter('.i-ddopt-n')->text() : '',
        ]);
    }

    private static function squash(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
