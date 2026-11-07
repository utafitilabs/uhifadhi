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
use Uhifadhi\Bundle\RegistryBundle\Service\FactRebuildService;
use Uhifadhi\Bundle\TeamBundle\Entity\Department;
use Uhifadhi\Bundle\TeamBundle\Enum\TeamRoleEnum;
use Uhifadhi\Bundle\TeamBundle\Facts\TeamFactProvider;
use Uhifadhi\Bundle\TeamBundle\Performance\MatrixPlacing;
use Uhifadhi\Bundle\TeamBundle\Performance\OrganizationBand;
use Uhifadhi\Bundle\TeamBundle\Service\PerformanceHistory;
use Uhifadhi\Bundle\TeamBundle\Service\StaffingFigures;
use Uhifadhi\Bundle\TeamBundle\Tests\Integration\Fixtures\Area\HostArea;
use Uhifadhi\Contracts\Facts\FactPeriod;

/**
 * `/departments/performance` — the organization's own page.
 *
 * IT WEARS THE AREA IDIOM, which is the whole of the ruling it ports: a
 * header with the scope and the period in it, a strip of sibling
 * screens, and a body. A reader who has learnt an area has learnt this.
 *
 * AND IT COMPUTES NOTHING. Every figure on it comes from a topic
 * through the performance seam; what is asserted here is that the page
 * ASKS for the right scope and period and draws what it is given in the
 * one grammar every matrix is drawn in.
 */
final class PerformanceOverviewTest extends WebTestCaseWithSchema
{
    private HostArea $north;

    /** The page is the area idiom: a head, a tab strip, a body. */
    public function testThePageWearsTheAreaIdiomWithItsThreeSiblingScreens(): void
    {
        $crawler = $this->page();

        self::assertResponseIsSuccessful();
        self::assertSame('Performance', $crawler->filter('h1.pg')->text());
        self::assertSame(
            ['Overview', 'Topics', 'Briefing'],
            $crawler->filter('.atabs a')->each(static fn (Crawler $c): string => $c->text()),
        );
        self::assertSame('Overview', $crawler->filter('.atabs a.on')->text());
    }

    /**
     * THE SUBLINE NAMES THE SCOPE, THE PERIOD AND WHAT IT IS COMPARED
     * WITH — the three things every figure on the page depends on, said
     * once, where the design says them.
     */
    public function testTheSublineNamesTheScopeThePeriodAndTheComparison(): void
    {
        $subline = $this->page()->filter('p.pgsub')->text();

        self::assertStringContainsString('Organization', $subline);
        self::assertStringContainsString(new \DateTimeImmutable()->format('F Y'), $subline);
        self::assertStringContainsString(new \DateTimeImmutable('first day of last month')->format('F Y'), $subline);
    }

    /**
     * THE HEAD'S THREE CONTROLS ARE THE HOUSE DROPDOWN — scope, period and
     * what the period is read against — and every option is an address, so
     * the page a reader is looking at can be sent to somebody.
     */
    public function testTheHeadCarriesScopePeriodAndComparisonOnTheHouseDropdown(): void
    {
        $crawler = $this->page();

        self::assertSame(
            ['scope', 'period', 'compare'],
            $crawler->filter('.pgact .dp-link .dd')->each(static fn (Crawler $c): string => (string) $c->attr('data-dd')),
        );
        self::assertCount(0, $crawler->filter('.pgact .ov-ctl'));
        self::assertCount(0, $crawler->filter('.pgact .periodpick'));

        $period = $crawler->filter('.pgact .dd[data-dd="period"]');
        $month = new \DateTimeImmutable()->format('F Y');
        self::assertSame($month, $period->filter('.dd-v')->text());
        self::assertSame(['period', 'Spans'], $period->filter('.dd-h > span:first-child')->each(static fn (Crawler $c): string => $c->text()));
        self::assertSame(['month', 'quarter', 'year'], $period->filter('.dd-o')->each(static fn (Crawler $c): string => (string) $c->attr('data-value')));

        $compare = $crawler->filter('.pgact .dd[data-dd="compare"]');
        self::assertSame('Against '.new \DateTimeImmutable('first day of last month')->format('F Y'), $compare->filter('.dd-v')->text());

        $urls = json_decode((string) $period->closest('.dp-link')?->attr('data-uhifadhi--team-bundle--choice-link-urls-value'), true);
        self::assertSame('/departments/performance?period=quarter&compare=previous', ((array) $urls)['quarter']);

        $quarter = $this->client->request('GET', '/departments/performance?period=quarter');

        self::assertCount(1, $quarter->filter('.pgact .dd.dd-set[data-dd="period"]'));
        self::assertSame('quarter', $quarter->filter('.pgact .dd[data-dd="period"]')->attr('data-dd-value'));
        self::assertStringContainsString('Quarter', $quarter->filter('p.pgsub')->text());
    }

    /** An address naming a window nobody has is the month, not a wall. */
    public function testAnAddressNamingNoKnownWindowReadsAsTheMonth(): void
    {
        $this->page();

        $crawler = $this->client->request('GET', '/departments/performance?period=fortnight');

        self::assertResponseIsSuccessful();
        self::assertSame('month', $crawler->filter('.pgact .dd[data-dd="period"]')->attr('data-dd-value'));
    }

    /** The scope is a place too: the organization, or one area, by address. */
    public function testTheScopeIsAnAddressAndTheAreasAreItsOptions(): void
    {
        $crawler = $this->page();

        $options = $crawler->filter('.pgact .dd[data-dd="scope"] .dd-o')->each(static fn (Crawler $c): string => (string) $c->attr('data-label'));

        self::assertSame('Organization — all areas', $options[0]);
        self::assertContains('Northern Reserve', $options);

        $area = $this->client->request('GET', '/departments/performance?area='.$this->north->getUuidString());

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Northern Reserve', $area->filter('p.pgsub')->text());
        self::assertSame('Northern Reserve', $area->filter('.pgact .dd[data-dd="scope"] .dd-v')->text());
    }

    /**
     * ONE CARD A TOPIC, FOUR TO A ROW — and a LEDGER takes no slot.
     *
     * The strip asks every topic for one moving number. Goals declared is a
     * list of commitments with a target and a date each, and the number of
     * rows in it is not a performance reading — so the topic says so itself
     * (TopicLedgerInterface) and keeps its card on the Topics register, its
     * row in the sidebar and its own record instead. That is what makes the
     * row four and not five with an orphan wrapped under it — and the
     * register keeping every topic is pinned by
     * testTheTopicsRegisterIsOneCardATopic below.
     */
    public function testEveryTopicWithAMovingFigureIsACardAndALedgerIsNot(): void
    {
        $titles = $this->page()->filter('.tpk .c.kpi > .tab')->each(static fn (Crawler $c): string => $c->text());

        self::assertContains('Staffing', $titles);
        self::assertContains('Attention & output', $titles);
        self::assertNotContains('Goals', $titles, 'A ledger has no single moving number to put on the strip.');
        // How many cards there are depends on how many modules publish a
        // topic; what never varies is that the row does not exceed four.
        self::assertLessThanOrEqual(4, \count($titles), 'A figure row is four to a row, and never five.');
    }

    /**
     * AND THE MATRIX WHOSE COLUMNS ARE THE TOPICS — drawn by the one
     * renderer every matrix in the product is drawn by, with the legend
     * that says what a shade is not.
     */
    public function testTheBoardIsTheOneMatrixGrammar(): void
    {
        $crawler = $this->page();

        self::assertCount(1, $crawler->filter('#tp-heat.pfc'));
        self::assertSame('Departments across the topics', $crawler->filter('#tp-heat .pfc-hd .t')->text());
        self::assertGreaterThan(0, $crawler->filter('#tp-heat .legend')->count());

        $columns = $crawler->filter('#tp-heat thead th.sortable')->each(static fn (Crawler $c): string => $c->text());

        self::assertSame('Department', $columns[0]);
        self::assertContains('Staffing', array_map(static fn (string $c): string => trim(explode("\n", $c)[0]), $columns));
    }

    /**
     * THE TABLE AND ITS LEGEND ARE THE ATLAS'S HEAT TABLE, served inside the
     * card's scroll and sort: four swatches, the dashed absence last, the
     * sentence that says what a tint is not, and the way into a row wearing
     * its chevron.
     */
    public function testTheBoardsTableAndLegendAreTheAtlasHeatTable(): void
    {
        $crawler = $this->page();

        self::assertCount(1, $crawler->filter('#tp-heat .hscroll[data-controller="uhifadhi--team-bundle--matrix"] > table.tbl.heat'));
        self::assertSame(['h5', 'h3', 'h1', 'h0'], $crawler->filter('#tp-heat .pfc-ft .legend .sw')->each(static fn (Crawler $c): string => trim(str_replace('sw', '', (string) $c->attr('class')))));
        self::assertStringContainsString('not drawn where fewer than', $crawler->filter('#tp-heat .pfc-ft .legend .d')->text());
        self::assertGreaterThan(0, $crawler->filter('#tp-heat td.perf-open a.open-btn svg')->count());
    }

    /** Every department is a row, in its own band, with the way into it. */
    public function testEveryDepartmentIsARowWithTheWayIntoIt(): void
    {
        $crawler = $this->page();

        $names = $crawler->filter('#tp-heat .dept b')->each(static fn (Crawler $c): string => $c->text());

        self::assertContains('Ecology', $names);
        self::assertContains('Wetland Management', $names);
        self::assertGreaterThan(0, $crawler->filter('#tp-heat .pfscope')->count());
        self::assertGreaterThan(0, $crawler->filter('#tp-heat a.open-btn')->count());
    }

    /**
     * THE TOPICS REGISTER IS AN INDEX, one card a topic — ruled 09-20:
     * not tabs and not an accordion, because five topics stacked on one
     * page are five pages nobody scrolls to the bottom of.
     */
    public function testTheTopicsRegisterIsOneCardATopic(): void
    {
        $this->seed();

        $crawler = $this->client->request('GET', '/departments/performance/topics');

        self::assertResponseIsSuccessful();
        self::assertSame('Topics', $crawler->filter('.atabs a.on')->text());

        $titles = $crawler->filter('.tpgrid .tpcard .hd .t')->each(static fn (Crawler $c): string => $c->text());
        self::assertContains('Staffing', $titles);
        self::assertContains('Goals', $titles);

        // AND THE KEY THAT SAYS WHOSE FIGURES THESE ARE, once, above the grid.
        self::assertCount(1, $crawler->filter('.tpkey'));
    }

    /** And a card carries the topic's own sentence about what moved. */
    public function testACardCarriesTheTopicsOwnSentenceAboutWhatMoved(): void
    {
        $this->seed();

        $crawler = $this->client->request('GET', '/departments/performance/topics');

        self::assertGreaterThan(0, $crawler->filter('.tpcard .mv')->count());
        self::assertMatchesRegularExpression(
            '/^i (good|bad|attention|quiet)$/',
            (string) $crawler->filter('.tpcard .mv .i')->first()->attr('class'),
        );
    }

    /**
     * A MATRIX CELL'S LINE IS THE ATLAS'S: a department with a written
     * history carries the sparkline `atlas_sparkline()` draws at the cell's
     * size, beside its movement — one polyline a run, the tone a class.
     */
    public function testAMatrixCellsHistoryIsTheAtlasSparklineAtTheCellsSize(): void
    {
        $this->seed();
        $this->history();

        $line = $this->client->request('GET', '/departments/performance')->filter('#tp-heat .hcell svg.spark');

        self::assertGreaterThan(0, $line->count(), 'A cell with a history draws no sparkline.');
        self::assertSame('0 0 70 18', $line->first()->attr('viewBox'));
        self::assertSame('70', $line->first()->attr('width'));
        self::assertMatchesRegularExpression('/^(up|dn|fl)$/', (string) $line->first()->filter('polyline')->attr('class'));
        self::assertNull($line->first()->filter('polyline')->attr('stroke'), 'A line is a class the sheet paints, never a stroke.');
    }

    /**
     * THE CARD'S LINE IS THE ATLAS'S: a figure with a written history carries
     * the sparkline `atlas_sparkline()` draws — the card's box, one polyline a
     * run, the tone a class — on the overview's strip and on the register.
     */
    public function testATopicCardsHistoryIsTheAtlasSparklineOnBothPages(): void
    {
        $this->seed();
        $this->history();

        foreach (['/departments/performance' => '.tpk .c.kpi', '/departments/performance/topics' => '.tpcard'] as $url => $card) {
            $crawler = $this->client->request('GET', $url);
            $line = $crawler->filter($card.' svg.sk');

            self::assertGreaterThan(0, $line->count(), $url.' draws no sparkline under a figure with a history.');
            self::assertSame('0 0 100 26', $line->first()->attr('viewBox'));
            self::assertSame('none', $line->first()->attr('preserveAspectRatio'));
            self::assertMatchesRegularExpression('/^(up|dn|fl)$/', (string) $line->first()->filter('polyline')->attr('class'));
            self::assertNull($line->first()->filter('polyline')->attr('stroke'), 'A line is a class the sheet paints, never a stroke.');
        }
    }

    /** Every card opens its own record, carrying the scope and the period. */
    public function testEveryCardOpensItsOwnRecord(): void
    {
        $this->seed();

        $crawler = $this->client->request('GET', '/departments/performance/topics?period=quarter');
        $href = (string) $crawler->filter('.tpgrid .tpcard')->first()->attr('href');

        self::assertStringContainsString('/departments/performance/topics/', $href);
        self::assertStringContainsString('period=quarter', $href);

        $record = $this->client->request('GET', $href);

        self::assertResponseIsSuccessful();
        self::assertSame('quarter', $record->filter('.pgact .dd[data-dd="period"]')->attr('data-dd-value'));
    }

    /**
     * A RECORD IS THE SAME PAGE FOR EVERY TOPIC: the five figures, the
     * charts, and the matrix of the departments the topic applies to.
     */
    public function testATopicsRecordDrawsItsFiguresItsChartsAndItsMatrix(): void
    {
        $this->seed();

        $crawler = $this->client->request('GET', '/departments/performance/topics/staffing');

        self::assertResponseIsSuccessful();
        self::assertSame('Staffing', trim($crawler->filter('h1.pg')->text()), 'the page head names the topic');
        self::assertCount(0, $crawler->filter('.tphead .t'), 'and the line under it names who publishes it');
        self::assertSame('by', $crawler->filter('.tphead > span')->first()->attr('class'));
        self::assertSame('Built in', $crawler->filter('.tphead .by')->text());
        self::assertCount(0, $crawler->filter('.factband'), 'a topic page carries its own figures, not the hub\'s strip');
        self::assertCount(1, $crawler->filter('.tpk'));
        self::assertCount(4, $crawler->filter('.tpk .c.kpi'), 'exactly four, or a reader cannot tell a short row from a quiet month');
        self::assertCount(1, $crawler->filter('#tp-matrix.pfc'));
        self::assertGreaterThan(0, $crawler->filter('#tp-matrix .legend')->count());
        self::assertCount(1, $crawler->filter('#tp-matrix .lfilt details.i-dd[data-axis="scope"]'));
        self::assertCount(1, $crawler->filter('#tp-matrix .rdf-foot'));
        self::assertSame(['scope', 'period', 'compare'], $crawler->filter('.pgact .dd.dd-sm')->each(static fn (Crawler $c): string => (string) $c->attr('data-dd')), 'the head dropdowns take the compact trigger');
    }

    /**
     * A FIGURE THE WORKER FILED WHILE ITS PERIOD IS OPEN SAYS WHEN IT WAS
     * TRUE, in the house fragment the dashboard prints — the page computed
     * none of it.
     */
    public function testARecordsStoredFiguresSayWhenTheyWereTrue(): void
    {
        $this->seed();

        $figures = $this->client->request('GET', '/departments/performance/topics/staffing')->filter('.tpk .c.kpi');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('as of', $figures->first()->text());
        self::assertCount(1, $figures->first()->filter('time[data-localtime-format]'));
    }

    /** A topic this installation does not carry is not an empty page. */
    public function testATopicNobodyPublishesIsNotAPage(): void
    {
        $this->seed();

        $this->client->request('GET', '/departments/performance/topics/whale-counts');

        self::assertResponseStatusCodeSame(404);
    }

    /** Standing on the register, the sidebar unfolds Topics to the topics. */
    public function testTheSidebarUnfoldsTopicsToItsTopics(): void
    {
        $this->seed();

        $crawler = $this->client->request('GET', '/departments/performance/topics');

        $rows = $crawler->filter('.nav .ntree .ntgroup a.ntm')->each(static fn (Crawler $c): string => $c->text());

        self::assertContains('Staffing', $rows);
        self::assertContains('Goals', $rows);
    }

    /**
     * THE BRIEFING ADDS UP NOTHING OF ITS OWN, AND CARRIES NO STRIP: what
     * needs a decision is the banner above the page, then the index and its
     * two cards, the goals and what changed, one column at full width.
     */
    public function testTheBriefingIsTheBannerTheIndexAndTwoCards(): void
    {
        $this->seed();

        $crawler = $this->client->request('GET', '/departments/performance/briefing');

        self::assertResponseIsSuccessful();
        self::assertSame('Briefing', $crawler->filter('.atabs a.on')->text());
        self::assertCount(0, $crawler->filter('.factband'));
        self::assertCount(0, $crawler->filter('h2.zone'));
        self::assertCount(0, $crawler->filter('.grid.spinerow-eq'));

        self::assertSame(['Goals', 'What changed'], $crawler->filter('.onpage-nav .onpage-l')->each(static fn (Crawler $c): string => $c->text()));
        self::assertSame(['#tp-ledger', '#changed'], $crawler->filter('.onpage-nav a.onpage-row')->each(static fn (Crawler $c): string => (string) $c->attr('href')));
        self::assertSame(['tp-ledger', 'changed'], $crawler->filter('.onpage-main > .pfc')->each(static fn (Crawler $c): string => (string) $c->attr('id')));
        self::assertSame('Goals', $crawler->filter('#tp-ledger .pfc-hd .t')->text());
        self::assertCount(1, $crawler->filter('#tp-ledger .lfilt details.i-dd[data-axis="scope"]'), 'the Goals table wears the house bar');
        self::assertCount(1, $crawler->filter('#tp-ledger .lfilt .lsearch'));
        self::assertCount(1, $crawler->filter('#tp-ledger .rdf-foot'), 'and the foot');
        self::assertSame('What changed', $crawler->filter('#changed .pfc-hd .t')->text());
    }

    /**
     * WHAT NEEDS A DECISION IS THE BANNER ABOVE THE PAGE, never a card — and a
     * period with nothing to decide draws no banner at all. (The banner's
     * lines: AttentionBannerTest.).
     */
    public function testWhatNeedsADecisionIsTheBannerAndNeverACard(): void
    {
        $this->seed();

        $crawler = $this->client->request('GET', '/departments/performance/briefing');

        self::assertCount(0, $crawler->filter('.pfc .mvrow.dcn'), 'no decisions card');
        self::assertCount(0, $crawler->filter('.banner'), 'nothing in the seed waits on a decision');
    }

    /**
     * A MOVEMENT IS THE TOPIC'S SENTENCE AND THE PLATFORM'S TONE, and
     * the row is the door into that topic's record.
     */
    public function testEveryMovementIsATopicsOwnSentenceAndOpensItsRecord(): void
    {
        $this->seed();

        $crawler = $this->client->request('GET', '/departments/performance/briefing');
        $rows = $crawler->filter('.mvlist .mvrow');

        self::assertGreaterThan(0, $rows->count(), 'the host topics report their own movements');

        $first = $rows->first();
        self::assertMatchesRegularExpression(
            '/^i (good|bad|attention|quiet)$/',
            (string) $first->filter('.i')->attr('class'),
            'the tone is one of the four the platform names, never a colour',
        );
        self::assertStringContainsString('/departments/performance/topics/', (string) $first->attr('href'));
    }

    /**
     * THE ORGANIZATION'S SIX ARE THE OVERVIEW'S STRIP, picked by key from the
     * host's own three topics — a band assembled from whatever a module
     * happened to publish would be a different band per installation. A
     * topic page carries its own figures and no strip (ruled 7 Nov).
     */
    public function testTheOrganizationsBandIsTheOverviewsAlone(): void
    {
        $this->seed();

        $band = $this->client->request('GET', '/departments/performance')
            ->filter('.factband .f .k')
            ->each(static fn (Crawler $c): string => $c->text());

        self::assertSame(array_values(OrganizationBand::FIGURES), $band);
        self::assertCount(0, $this->client->request('GET', '/departments/performance/topics/staffing')->filter('.factband'));
    }

    /**
     * WHAT A PERIOD IS READ AGAINST IS A CONTROL AND AN ADDRESS, and
     * the subline names what the figures were ACTUALLY compared with —
     * the two cannot disagree, because they are the same object.
     */
    public function testTheComparisonIsAnAddressAndTheSublineNamesIt(): void
    {
        $this->seed();

        $lastYear = $this->client->request('GET', '/departments/performance?compare=last-year');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString(
            new \DateTimeImmutable('first day of this month -1 year')->format('F Y'),
            $lastYear->filter('p.pgsub')->text(),
        );

        // AND IT SURVIVES A CHANGE OF SCREEN: moving from the Overview to
        // Topics is a change of screen and never of subject.
        self::assertStringContainsString(
            'compare=last-year',
            (string) $lastYear->filter('.atabs a')->eq(1)->attr('href'),
        );
    }

    /** The section's one Configure action opens its settings, and the frame draws it. */
    public function testConfigureOpensTheSectionsSettings(): void
    {
        $this->seed();

        $crawler = $this->client->request('GET', '/departments/performance');
        $configure = $crawler->filter('.pgact a.tgl');

        self::assertCount(1, $configure, 'one configure entry, written by the frame');
        self::assertSame('/departments/performance/settings', $configure->attr('href'));

        $settings = $this->client->request('GET', '/departments/performance/settings');

        self::assertResponseIsSuccessful();
        // THE RULE'S OWN NUMBER, read from the one place that holds it.
        self::assertStringContainsString((string) MatrixPlacing::FEWEST, $settings->filter('.grid .c')->eq(1)->text());
    }

    /** Performance is in the sidebar, under Observatory, with its screens. */
    public function testTheSidebarCarriesPerformanceAndItsThreeScreens(): void
    {
        $crawler = $this->page();

        $row = $crawler->filter('.nav .nav-item')
            ->reduce(static fn (Crawler $c): bool => str_contains($c->text(), 'Performance'));

        self::assertCount(1, $row);
    }

    /** The page is the org chart's, so it is gated exactly as the register is. */
    public function testSomebodyWithoutTeamManageDoesNotGetThePage(): void
    {
        $this->seed();
        $ranger = $this->person('Juma', 'Ranger', TeamRoleEnum::Staff);
        $this->em->flush();
        $this->client->loginUser($ranger);

        $this->client->request('GET', '/departments/performance');

        self::assertResponseStatusCodeSame(403);
    }

    /**
     * TWO CLOSED MONTHS BEHIND THIS ONE, written for every department the
     * seed places somebody in — enough readings for a line.
     */
    private function history(): void
    {
        /** @var PerformanceHistory $history */
        $history = static::getContainer()->get(PerformanceHistory::class);
        $months = PerformanceHistory::monthsEndingAt(new \DateTimeImmutable('first day of this month'), 3);

        foreach ($this->em->getRepository(Department::class)->findAll() as $department) {
            foreach ($months as $position => $month) {
                foreach ([StaffingFigures::POSITIONS, StaffingFigures::FILLED, StaffingFigures::PEOPLE] as $figure) {
                    $history->record($department, $month, $figure, 1.0 + $position);
                }
                $history->record($department, $month, StaffingFigures::VACANT, 2.0 - $position);
            }
        }
        $this->em->flush();
    }

    private function page(): Crawler
    {
        $this->seed();

        return $this->client->request('GET', '/departments/performance');
    }

    private function seed(): void
    {
        $this->north = $this->area('Northern Reserve');

        $wetland = $this->areaDepartment('Wetland Management', $this->north);
        $ecology = $this->department('Ecology');
        $protection = $this->department('Protection Service');

        // A DEPARTMENT'S STAFFING IS THE PEOPLE PLACED IN IT, so the topics
        // only have a figure to report where somebody stands.
        $this->place($this->person('Zawadi', 'Kimaro')->setPosition($this->position('Wetland Ecologist')), [$this->north], [$wetland]);
        $this->place($this->person('Tumaini', 'Njau')->setPosition($this->position('Analyst')), null, [$ecology]);
        $this->place($this->person('Baraka', 'Msuya')->setPosition($this->position('Ranger')), null, [$protection]);

        $this->em->flush();

        // AND WHAT THE SCHEDULED RUN FILES FOR THEM: the page reads the
        // ledger and counts nothing itself.
        /** @var FactRebuildService $rebuild */
        $rebuild = static::getContainer()->get('test_public.'.FactRebuildService::class);
        $rebuild->rebuild([FactPeriod::month(new \DateTimeImmutable())], TeamFactProvider::SLUG);

        $this->administrator();
    }
}
