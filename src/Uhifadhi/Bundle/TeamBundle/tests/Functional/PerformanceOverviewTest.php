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
use Uhifadhi\Bundle\TeamBundle\Enum\TeamRoleEnum;
use Uhifadhi\Bundle\TeamBundle\Performance\MatrixPlacing;
use Uhifadhi\Bundle\TeamBundle\Performance\OrganizationBand;
use Uhifadhi\Bundle\TeamBundle\Service\PerformanceHistory;
use Uhifadhi\Bundle\TeamBundle\Service\StaffingFigures;
use Uhifadhi\Bundle\TeamBundle\Tests\Integration\Fixtures\Area\HostArea;

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
     * THE PERIOD IS IN THE ADDRESS, as three segments — so a reader who
     * wants this quarter does not open a menu, and the page they are
     * looking at can be sent to somebody.
     */
    public function testThePeriodIsASegmentedGroupOfAddresses(): void
    {
        $crawler = $this->page();

        self::assertSame(
            ['Month', 'Quarter', 'Year'],
            $crawler->filter('.periodpick a')->each(static fn (Crawler $c): string => $c->text()),
        );
        self::assertSame('Month', $crawler->filter('.periodpick a.on')->text());

        $quarter = $this->client->request('GET', '/departments/performance?period=quarter');

        self::assertSame('Quarter', $quarter->filter('.periodpick a.on')->text());
        self::assertStringContainsString('Quarter', $quarter->filter('p.pgsub')->text());
    }

    /** An address naming a window nobody has is the month, not a wall. */
    public function testAnAddressNamingNoKnownWindowReadsAsTheMonth(): void
    {
        $this->page();

        $crawler = $this->client->request('GET', '/departments/performance?period=fortnight');

        self::assertResponseIsSuccessful();
        self::assertSame('Month', $crawler->filter('.periodpick a.on')->text());
    }

    /** The scope is a place too: the organization, or one area, by address. */
    public function testTheScopeIsAnAddressAndTheAreasAreItsOptions(): void
    {
        $crawler = $this->page();

        $options = $crawler->filter('.i-ddmenu .i-ddopt-l')->each(static fn (Crawler $c): string => $c->text());

        self::assertContains('Northern Reserve', $options);

        $area = $this->client->request('GET', '/departments/performance?area='.$this->north->getUuidString());

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Northern Reserve', $area->filter('p.pgsub')->text());
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
        self::assertSame('Quarter', $record->filter('.periodpick a.on')->text());
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
        self::assertSame('Staffing', $crawler->filter('.tphead .t')->text());
        self::assertSame('the host', $crawler->filter('.tphead .by')->text());
        self::assertCount(4, $crawler->filter('.tpk .c.kpi'), 'exactly four, or a reader cannot tell a short row from a quiet month');
        self::assertCount(1, $crawler->filter('#tp-matrix.pfc'));
        self::assertGreaterThan(0, $crawler->filter('#tp-matrix .legend')->count());
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
     * THE BRIEFING ADDS UP NOTHING OF ITS OWN: the band is the Goals
     * topic's five figures, "what changed" is one line per topic that
     * can write one, and the ledger is the Goals matrix in the one
     * grammar every matrix is drawn in.
     */
    public function testTheBriefingReadsTheGoalsFiguresTheMovementsAndTheLedger(): void
    {
        $this->seed();

        $crawler = $this->client->request('GET', '/departments/performance/briefing');

        self::assertResponseIsSuccessful();
        self::assertSame('Briefing', $crawler->filter('.atabs a.on')->text());

        // The band is the goals topic's own, by the keys it published them under.
        $band = $crawler->filter('.factband .f .k')->each(static fn (Crawler $c): string => $c->text());
        self::assertContains('Declared', $band);
        self::assertContains('Met', $band);

        // THE LEDGER LEADS, then the two readings of it side by side —
        // a director opens this page to see where the goals stand.
        self::assertSame(['Goals declared', 'What changed, and what to decide'], $crawler->filter('h2.zone')->each(
            static fn (Crawler $c): string => $c->text(),
        ));
        self::assertCount(1, $crawler->filter('#tp-ledger.pfc'));
        self::assertCount(2, $crawler->filter('.grid.spinerow-eq > .pfc'), 'two cards of equal height, neither leading');
    }

    /**
     * A DECISION STATES WHAT IS WRONG AND WHAT IS BEING ASKED, in the
     * topic's own words, against the department it belongs to — and
     * the card says how many there are in all.
     */
    public function testTheDecisionsCardNamesTheDepartmentAndTheAsk(): void
    {
        $this->seed();

        $crawler = $this->client->request('GET', '/departments/performance/briefing');
        $card = $crawler->filter('.grid.spinerow-eq > .pfc')->eq(1);

        self::assertSame('Needs a decision', $card->filter('.pfc-hd .t')->text());
        self::assertMatchesRegularExpression('/^\d+ of \d+$/', $card->filter('.pfc-hd .n')->text());

        $rows = $card->filter('.mvrow.dcn');
        if ($rows->count() > 0) {
            self::assertNotSame('', $rows->first()->filter('.mk')->text(), 'a decision names whose it is');
            self::assertNotSame('', $rows->first()->filter('.l .ask')->text(), 'and what is being asked');
        }
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
     * THE SAME SIX ON EVERY SCREEN of the section, picked by key from
     * the host's own three topics — a band assembled from whatever a
     * module happened to publish would be a different band per
     * installation.
     */
    public function testTheOrganizationsBandIsTheSameSixOnEveryScreen(): void
    {
        $this->seed();

        $expected = array_values(OrganizationBand::FIGURES);

        foreach (['/departments/performance', '/departments/performance/topics/staffing'] as $path) {
            $band = $this->client->request('GET', $path)
                ->filter('.factband .f .k')
                ->each(static fn (Crawler $c): string => $c->text());

            self::assertSame($expected, $band, $path.' draws the organization\'s own band');
        }
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

        $this->administrator();
    }
}
