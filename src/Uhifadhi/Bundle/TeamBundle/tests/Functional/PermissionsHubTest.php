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
use Uhifadhi\Bundle\RegistryBundle\Enum\ModuleCategory;
use Uhifadhi\Bundle\RegistryBundle\Enum\ModuleStatus;
use Uhifadhi\Bundle\TeamBundle\Entity\Department;
use Uhifadhi\Bundle\TeamBundle\Entity\PermissionRun;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Bundle\TeamBundle\Enum\PermissionRunStatusEnum;
use Uhifadhi\Bundle\TeamBundle\Enum\PermissionRunTriggerEnum;
use Uhifadhi\Bundle\TeamBundle\Enum\TeamRoleEnum;
use Uhifadhi\Bundle\TeamBundle\Permissions\PermissionFindingsReader;
use Uhifadhi\Bundle\TeamBundle\Permissions\PermissionOverviewReader;
use Uhifadhi\Bundle\TeamBundle\Permissions\PermissionRunService;
use Uhifadhi\Bundle\TeamBundle\Service\UserService;
use Uhifadhi\Contracts\Access\ScopeKind;

/**
 * TEAM › PERMISSIONS, THE HUB — Overview and Reports, a Super Admin's, by
 * tier. The Overview reads the latest run the worker filed: the drift banner,
 * the band, four figures, four plots, the lists for review and the last
 * history lines; Reports is the three lookups. Recompute now queues a run and
 * Mark reviewed marks the drift.
 */
final class PermissionsHubTest extends WebTestCaseWithSchema
{
    private User $chief;
    private User $ranger;
    private Department $ecology;
    private Module $surveys;
    private ?User $clerk = null;

    protected function setUp(): void
    {
        parent::setUp();

        $kilimani = $this->area('Kilimani Game Reserve');
        $ziwa = $this->area('Ziwa Kavu');
        $this->surveys = new Module()->setSlug('surveys')->setName('Surveys')->setCategory(ModuleCategory::Operations)
            ->setStatus(ModuleStatus::Live)->setDataSource('a fixture')->setPinned(false)->setPosition(1);
        $this->em->persist($this->surveys);
        $protection = $this->department('Protection Service');
        $protection->attachModule($this->surveys);
        $this->ecology = $this->department('Ecology and Monitoring');

        $area = [ScopeKind::Area];
        $ranger = $this->position('Patrol ranger', ['stations.read', 'surveys.read', 'surveys.record'], $area);
        $head = $this->position('Station head', ['stations.read', 'surveys.read', 'surveys.record', 'assignments.manage'], $area);
        $this->position('Tourism warden', ['stations.read'], $area)->setVacantSince(new \DateTimeImmutable('2026-08-14'));
        $officer = $this->position('People officer', ['directory.read', 'positions.configure'], [ScopeKind::Organization]);

        $this->chief = $this->person('Amani', 'Kweka', TeamRoleEnum::SuperAdmin);
        $this->place($this->chief);
        $this->place($this->person('Zawadi', 'Mtei', TeamRoleEnum::Admin));
        foreach ([['Baraka', 'Shirima'], ['Tumaini', 'Lyimo']] as [$first, $last]) {
            $this->place($this->person($first, $last)->setPosition($ranger), [$kilimani], [$protection]);
        }
        $this->ranger = $this->person('Pendo', 'Urio')->setPosition($ranger);
        $this->place($this->ranger, [$kilimani], [$this->ecology]);
        $this->place($this->person('Hamisi', 'Kimaro')->setPosition($head), [$ziwa], [$protection]);
        $this->place($this->person('Subira', 'Mosha')->setPosition($officer));
        $this->person('Jabari', 'Tarimo');
        $this->em->flush();
    }

    public function testOnlyASuperAdminOpensEitherPage(): void
    {
        foreach (['/team/permissions', '/team/permissions/reports'] as $path) {
            $this->client->loginUser($this->chief);
            $this->client->request('GET', $path);
            self::assertResponseIsSuccessful($path);

            $this->client->loginUser($this->staffWhoReadsTheDirectory());
            $this->client->request('GET', $path);
            self::assertResponseStatusCodeSame(403, $path.' for Staff');

            $this->client->loginUser($this->admin());
            $this->client->request('GET', $path);
            self::assertResponseStatusCodeSame(403, $path.' for an Admin, by tier');
        }
    }

    public function testTheOverviewIsHeadedWithItsOwnTwoTabsAndNotTheTeamStrip(): void
    {
        $page = $this->overview();

        self::assertSame('Permissions', $page->filter('.pghead h1.pg')->text());
        self::assertSame('What every kind of person may do to every other, asked of the rules the app enforces — for real accounts, changing nothing.', $page->filter('.pghead .pgsub')->text());
        self::assertSame('uhifadhi / team / permissions', self::squash($page->filter('.crumb')->text()));
        self::assertSame(['Overview', 'Reports'], $page->filter('.atabs a')->each(static fn (Crawler $c): string => $c->text()));
        self::assertSame(['Overview'], $page->filter('.atabs a.on')->each(static fn (Crawler $c): string => $c->text()));
        self::assertSame(['/team/permissions', '/team/permissions/reports'], $page->filter('.atabs a')->each(static fn (Crawler $c): ?string => $c->attr('href')));
    }

    public function testTheHeadCarriesTheChipAndRecomputeNowAndNoConfigure(): void
    {
        $actions = $this->overview()->filter('.pghead .pgact');

        self::assertSame('Super Admin only', $actions->filter('.chip.acc')->text());
        $form = $actions->filter('.pp-fresh form[method="post"][action="/team/permissions/recompute"]');
        self::assertCount(1, $form);
        self::assertSame('Recompute now', trim($form->filter('button.tgl')->text()));
        self::assertNotSame('', (string) $form->filter('input[name="_token"]')->attr('value'));
        self::assertCount(0, $actions->filter('a')->reduce(static fn (Crawler $c): bool => 'Configure' === trim($c->text())));
    }

    public function testTheBannerSaysHowManyAnswersChangedWithNoRecordedChange(): void
    {
        $this->runNow();
        self::assertCount(0, $this->overview()->filter('.banner'), 'no drift, no banner');

        $this->ecology->attachModule($this->surveyModule());
        $this->em->flush();
        $this->runNow();

        $drift = $this->reader(PermissionFindingsReader::class)->drift();
        $answers = array_sum(array_map(static fn (array $l): int => \count($l['places']), $drift['lines']));
        self::assertGreaterThan(0, $answers);

        $banner = $this->overview()->filter('.banner');
        self::assertCount(1, $banner);
        self::assertSame($answers.' answers changed with no recorded change', $banner->filter('.banner-t b')->text());
        self::assertSame('nothing in the history explains it', $banner->filter('.banner-c')->text());
        self::assertCount(1, $banner->filter('form[method="post"][action="/team/permissions/drift/reviewed"] button.btn.sm'));
    }

    public function testMarkReviewedMarksTheDriftAndTheBannerSaysSo(): void
    {
        $this->runNow();
        $this->ecology->attachModule($this->surveyModule());
        $this->em->flush();
        $this->runNow();
        $page = $this->overview();
        $token = (string) $page->filter('.banner form input[name="_token"]')->attr('value');

        $this->client->request('POST', '/team/permissions/drift/reviewed', ['_token' => $token]);
        self::assertResponseRedirects('/team/permissions');
        self::assertSame(0, $this->reader(PermissionFindingsReader::class)->drift()['unreviewed']);

        $banner = $this->client->followRedirect()->filter('.banner');
        self::assertStringContainsString('banner-ok', (string) $banner->attr('class'));
        self::assertStringEndsWith('unrecorded changes · marked reviewed', $banner->filter('.banner-t b')->text());
        self::assertCount(0, $banner->filter('form'), 'nothing left to mark');
    }

    public function testRecomputeNowQueuesARunForTheWorker(): void
    {
        $token = (string) $this->overview()->filter('.pp-fresh input[name="_token"]')->attr('value');

        $this->client->request('POST', '/team/permissions/recompute', ['_token' => $token]);
        self::assertResponseRedirects('/team/permissions');

        $pending = $this->em->getRepository(PermissionRun::class)->findBy(['status' => PermissionRunStatusEnum::Pending]);
        self::assertCount(1, $pending);
        self::assertSame(PermissionRunTriggerEnum::Manual, $pending[0]->getTrigger());

        $fresh = $this->client->followRedirect()->filter('.pp-fresh');
        self::assertStringContainsString('pp-busy', (string) $fresh->attr('class'));
        self::assertSame('Queued for the worker', trim($fresh->filter('button')->text()));
        self::assertNotNull($fresh->filter('button')->attr('disabled'));
    }

    public function testThePostsRefuseAStaleFormAndAnybodyButASuperAdmin(): void
    {
        $this->client->loginUser($this->chief);
        foreach (['/team/permissions/recompute', '/team/permissions/drift/reviewed'] as $path) {
            $this->client->request('POST', $path, ['_token' => 'stale']);
            self::assertResponseStatusCodeSame(404, $path);
        }

        $this->client->loginUser($this->staffWhoReadsTheDirectory());
        foreach (['/team/permissions/recompute', '/team/permissions/drift/reviewed'] as $path) {
            $this->client->request('POST', $path, ['_token' => 'any']);
            self::assertResponseStatusCodeSame(403, $path);
        }
        self::assertSame([], $this->em->getRepository(PermissionRun::class)->findAll());
    }

    public function testTheBandAndTheFourFiguresAreTheRunsAndTheTeams(): void
    {
        $this->runNow();
        $kpis = $this->reader(PermissionOverviewReader::class)->overview()['kpis'];
        self::assertSame(4, $kpis['positions']);
        self::assertSame(8, $kpis['people']);
        self::assertSame(2, $kpis['above']);

        $page = $this->overview();
        $band = $page->filter('.factband .f')->each(static fn (Crawler $c): string => self::squash($c->filter('.k')->text()));
        self::assertSame(['As of', 'Positions', 'People', 'Questions'], $band);
        self::assertSame('4', $page->filter('.factband .f')->eq(1)->filter('.v')->text());
        self::assertSame('8', $page->filter('.factband .f')->eq(2)->filter('.v')->text());
        self::assertSame($kpis['questionsFiled'].'filed', $page->filter('.factband .f')->eq(3)->filter('.v')->text());
        self::assertCount(2, $page->filter('.factband .f')->eq(0)->filter('time[data-localtime-format="stamp"]'), 'the run, and the next one');

        $cards = $page->filter('.kstrip .c.kpi');
        self::assertSame(['Positions', 'People', 'Exceptions', 'Needs attention'], $cards->each(static fn (Crawler $c): string => $c->filter('.tab')->text()));
        self::assertSame(['4', '8', (string) $kpis['exceptionsInForce'], (string) $kpis['needsAttention']], $cards->each(static fn (Crawler $c): string => $c->filter('b')->text()));
        self::assertSame([
            $kpis['vacant'].' vacant · '.$kpis['locked'].' locked',
            '6 staff · 2 above the matrix',
            'in force · '.$kpis['notInForce'].' held, not in force',
            $kpis['reachNothing'].' reach nothing · '.$kpis['notHonoured'].(1 === $kpis['notHonoured'] ? ' permission' : ' permissions').' not honoured',
        ], $cards->each(static fn (Crawler $c): string => self::squash($c->filter('.sub')->text())));
        self::assertGreaterThan(0, $kpis['needsAttention']);
        self::assertCount(0, $cards->filter('.hot'), 'attention lives in the banner only');
    }

    public function testTheFourPlotsAreTheTeamOverviewsCards(): void
    {
        $this->runNow();
        $overview = $this->reader(PermissionOverviewReader::class)->overview();
        $page = $this->overview();

        $titles = $page->filter('.grid.g2 > .c > .tab')->each(static fn (Crawler $c): string => self::squash(str_replace($c->filter('.src')->text(), '', $c->text())));
        self::assertSame(['Resources by positions holding them', 'Positions by permissions held', 'Who can act, by place', 'Permission changes by month'], $titles);

        $concerns = $page->filter('.grid.g2 > .c')->eq(0);
        $shown = min(12, \count($overview['concernsByPositions']));
        self::assertCount($shown, $concerns->filter('.sxbars .sxbar'));
        $top = $overview['concernsByPositions'][0];
        self::assertSame($top['label'], $concerns->filter('.sxbar .l')->first()->text());
        self::assertSame($top['positions'].' · '.$top['share'].' %', self::squash($concerns->filter('.sxbar .n')->first()->text()));
        self::assertSame('· '.\count($overview['concernsByPositions']), self::squash($concerns->filter('.tab .src')->text()));
        self::assertSame('/team/permissions/positions', $concerns->filter('.sxmore a')->attr('href'));

        $positions = $page->filter('.grid.g2 > .c')->eq(1);
        self::assertCount(4, $positions->filter('.sxbars .sxbar'));
        self::assertSame('Station head', $positions->filter('.sxbar .l')->first()->text(), 'most pairs first');
        self::assertSame('/team/permissions/positions', $positions->filter('.sxmore a')->attr('href'));
        self::assertStringContainsString('1 not honoured', $positions->filter('.sxbar')->reduce(static fn (Crawler $c): bool => 'People officer' === $c->filter('.l')->text())->filter('.n')->text());

        $places = $page->filter('.grid.g2 > .c')->eq(2);
        self::assertSame('· '.\count($overview['whoCanActByPlace']).' places', self::squash($places->filter('.tab .src')->text()));
        self::assertCount(1, $places->filter('canvas'));
        self::assertSame('a person counts in every place their placement covers', $places->filter('.sxmore span')->first()->text());
        self::assertSame('Analysis →', $places->filter('.sxmore a')->text());
        self::assertSame('/team/permissions/analysis', $places->filter('.sxmore a')->attr('href'));

        $months = $page->filter('.grid.g2 > .c')->eq(3);
        self::assertSame('· last 6 months', self::squash($months->filter('.tab .src')->text()));
        self::assertSame('The history →', $months->filter('.sxmore a')->text());
        self::assertSame('#history', $months->filter('.sxmore a')->attr('href'));
    }

    public function testTheListsForReviewAreTheRunsSevenWithTheAlarmsInWarn(): void
    {
        $this->runNow();
        $review = $this->reader(PermissionOverviewReader::class)->overview()['review'];
        $card = $this->overview()->filter('table.tbl.pp-stt')->closest('.c');
        \assert(null !== $card);

        self::assertStringStartsWith('For review', $card->filter('.tab')->text());
        self::assertCount(0, $card->filter('.lfilt'), 'no bar on the lists for review');
        self::assertSame(['Count', 'List', ''], $card->filter('thead th')->each(static fn (Crawler $c): string => $c->text()));
        $rows = $card->filter('tbody tr');
        self::assertCount(7, $rows);
        foreach ($review as $i => $list) {
            $row = $rows->eq($i);
            self::assertCount(3, $row->filter('td'));
            self::assertSame((string) $list['count'], $row->filter('td.num')->text());
            self::assertSame($list['label'], $row->filter('td')->eq(1)->getNode(0)?->firstChild?->textContent);
            self::assertSame($list['summary'], $row->filter('td')->eq(1)->filter('span.sub')->text(), 'the items, one cut-off sub-line');
            self::assertSame($list['summary'], $row->filter('td')->eq(1)->filter('span.sub')->attr('title'));
            self::assertSame('Analysis →', $row->filter('td.door a.more')->text());
            self::assertSame('/team/permissions/analysis#'.$list['id'], $row->filter('td.door a.more')->attr('href'));
            self::assertSame($list['alarm'] && $list['count'] > 0, 1 === $row->filter('td.num.pp-bad')->count(), $list['id']);
        }
    }

    public function testTheHistoryIsAHouseTableOfEveryChangeNewestFirst(): void
    {
        $accounts = $this->client->getContainer()->get('test_public.'.UserService::class);
        self::assertInstanceOf(UserService::class, $accounts);
        $accounts->changeTier($this->ranger, TeamRoleEnum::Admin, $this->chief);

        $history = $this->overview()->filter('#history');
        self::assertStringStartsWith('Permission history', $history->filter('.tab')->text());
        self::assertSame('· newest first', $history->filter('.tab .src')->text());
        self::assertCount(0, $history->filter('.lfilt, .pp-hrow'), 'no bar; the old lines are gone');
        $table = $history->filter('[data-pa-reg="history"] table.tbl.pp-hist');
        self::assertSame(['Change', 'Before → after'], $table->filter('thead th')->each(static fn (Crawler $c): string => $c->text()));
        $rows = $table->filter('tbody tr[data-pa-r]');
        self::assertCount(1, $rows);
        $change = $rows->filter('td.pp-hd');
        self::assertStringContainsString('Pendo Urio', (string) $change->getNode(0)?->firstChild?->textContent);
        self::assertStringStartsWith('Amani Kweka · ', $change->filter('.sub')->text(), 'by · when');
        self::assertCount(1, $change->filter('.sub time[datetime]'));
        self::assertSame(['Staff', 'Admin'], $rows->filter('td .ph-diff s, td .ph-diff i')->each(static fn (Crawler $c): string => $c->text()));

        $foot = $history->filter('.rdf-foot[data-pa-foot]');
        self::assertSame('1 change', $foot->filter('[data-pa-count]')->text());
        self::assertSame('20 per page▾', self::squash($foot->filter('details.i-dd.rdf-size > summary')->text()));
        self::assertSame('page 1 of 1', self::squash($foot->filter('.rdf-page')->text()));
    }

    public function testTheHistoryPagesEveryChangeNotTheLastSix(): void
    {
        $accounts = $this->client->getContainer()->get('test_public.'.UserService::class);
        self::assertInstanceOf(UserService::class, $accounts);
        foreach (range(1, 11) as $n) {
            $accounts->changeTier($this->ranger, 0 === $n % 2 ? TeamRoleEnum::Staff : TeamRoleEnum::Admin, $this->chief);
        }

        $history = $this->overview()->filter('#history');
        $rows = $history->filter('table.pp-hist tbody tr[data-pa-r]');
        self::assertCount(11, $rows, 'the whole history');
        self::assertSame(['Admin', 'Staff'], $rows->eq(1)->filter('td .ph-diff s, td .ph-diff i')->each(static fn (Crawler $c): string => $c->text()), 'newest first');
        self::assertSame(['Staff', 'Admin'], $rows->eq(0)->filter('td .ph-diff s, td .ph-diff i')->each(static fn (Crawler $c): string => $c->text()));
        self::assertSame('11 changes', $history->filter('[data-pa-count]')->text());
        self::assertSame('uhifadhi--team-bundle--permission-analysis', $history->attr('data-controller'), 'the house register pages it');
    }

    public function testReportsIsTheThreeLookups(): void
    {
        $this->runNow();
        $this->client->loginUser($this->chief);
        $page = $this->client->request('GET', '/team/permissions/reports');

        self::assertSame('Permissions — Reports', $page->filter('.pghead h1.pg')->text());
        self::assertSame('uhifadhi / team / permissions / reports', self::squash($page->filter('.crumb')->text()));
        self::assertSame('/team/permissions', $page->filter('.crumb a')->last()->attr('href'));
        self::assertSame(['Reports'], $page->filter('.atabs a.on')->each(static fn (Crawler $c): string => $c->text()));
        self::assertCount(1, $page->filter('.pp-fresh form[action="/team/permissions/recompute"]'));
        self::assertSame('Lookups', $page->filter('h2.zone')->text());

        $tiles = $page->filter('.grid.g4 .c.mtile');
        self::assertSame(['Positions', 'Analysis', 'Check'], $tiles->each(static fn (Crawler $c): string => $c->filter('.mtile-title')->text()));
        self::assertSame(['as written', 'stored', 'live'], $tiles->each(static fn (Crawler $c): string => $c->filter('.chip')->text()));
        self::assertSame('/team/permissions/positions', $tiles->eq(0)->attr('href'));
        self::assertSame('/team/permissions/analysis', $tiles->eq(1)->attr('href'));
        self::assertSame('/team/permissions/check', $tiles->eq(2)->attr('href'), 'Check is live once its route mounts');
        self::assertCount(1, $tiles->eq(2)->filter('.chip.ok'));
        self::assertSame('grants, read from the positions', $tiles->eq(0)->filter('.mtile-source .mono')->text());
        self::assertStringStartsWith("the worker's answers · as of", self::squash($tiles->eq(1)->filter('.mtile-source .mono')->text()));
        self::assertCount(1, $tiles->eq(1)->filter('.mtile-source time'));
        self::assertSame('the real voters, now', $tiles->eq(2)->filter('.mtile-source .mono')->text());
    }

    public function testBeforeAnyRunTheOverviewSaysNotComputedYet(): void
    {
        $page = $this->overview();

        self::assertStringContainsString('not computed yet', $page->filter('.factband .f')->eq(0)->text());
        self::assertCount(0, $page->filter('.banner'));
        self::assertCount(7, $page->filter('table.pp-stt tbody tr'));
    }

    private function overview(): Crawler
    {
        $this->client->loginUser($this->chief);
        $page = $this->client->request('GET', '/team/permissions');
        self::assertResponseIsSuccessful();

        return $page;
    }

    private function runNow(): void
    {
        $service = $this->reader(PermissionRunService::class);
        $run = $service->requestRun(PermissionRunTriggerEnum::Manual, null);
        $service->handle($run->getId());
        $this->em->clear();
        $this->chief = $this->em->find(User::class, $this->chief->getId()) ?? throw new \LogicException('gone');
        $this->ranger = $this->em->find(User::class, $this->ranger->getId()) ?? throw new \LogicException('gone');
        $this->ecology = $this->em->find(Department::class, $this->ecology->getId()) ?? throw new \LogicException('gone');
    }

    private function surveyModule(): Module
    {
        return $this->em->getRepository(Module::class)->findOneBy(['slug' => 'surveys']) ?? throw new \LogicException('no module');
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

    private function staffWhoReadsTheDirectory(): User
    {
        if (null !== $this->clerk) {
            return $this->clerk;
        }
        $staff = $this->person('Rehema', 'Swai');
        $staff->setPosition($this->position('Clerk', ['directory.read', 'positions.read']));
        $this->place($staff);
        $this->em->flush();

        return $this->clerk = $staff;
    }

    private function admin(): User
    {
        $admin = $this->em->getRepository(User::class)->findOneBy(['email' => 'u.mbise@example.test']) ?? $this->person('Upendo', 'Mbise', TeamRoleEnum::Admin);
        $this->em->flush();

        return $admin;
    }

    private static function squash(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
