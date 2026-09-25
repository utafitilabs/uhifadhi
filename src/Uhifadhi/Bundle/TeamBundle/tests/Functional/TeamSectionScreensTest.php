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
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Bundle\TeamBundle\Enum\TeamRoleEnum;
use Uhifadhi\Bundle\TeamBundle\Service\PerformanceHistory;
use Uhifadhi\Bundle\TeamBundle\Service\TeamFigures;
use Uhifadhi\Bundle\TeamBundle\Tests\Integration\Fixtures\FakeStationDirectory;
use Uhifadhi\Contracts\Area\DirectoryArea;
use Uhifadhi\Contracts\Area\PostedStation;
use Uhifadhi\Contracts\Area\StationPost;

/**
 * THE TEAM SECTION'S OWN SCREENS — the overview it opens on, and the two
 * configure screens behind its one action.
 *
 * THE OVERVIEW OWNS NO FIGURE. Every one of them belongs to the register, to
 * Positions or to the station in the area, which is what makes it safe to open
 * first — and is why the assertions below are about what it READS, never about
 * what it changes.
 */
final class TeamSectionScreensTest extends WebTestCaseWithSchema
{
    protected function setUp(): void
    {
        parent::setUp();
        FakeStationDirectory::clear();
    }

    // ---- the overview ----------------------------------------------------

    /**
     * FOUR KPI CARDS, FOUR OR NONE — the area overview's own row, and four to
     * a row is the ruled shape of every figure row in the product.
     *
     * ROLES IS NOT ONE OF THEM: the tiers are three and never move, so the
     * card's figure is a constant of the product. It keeps its place in the
     * identity band, where a fact that does not move belongs.
     */
    public function testTheOverviewOpensWithTheFourKpiCards(): void
    {
        $this->installation();

        $cards = $this->visit('/team/overview')->filter('.kstrip .kpi');

        self::assertCount(4, $cards, 'A figure row is four to a row, and never five.');
        self::assertSame(
            ['People', 'Positions', 'Seats filled', 'Assignments'],
            $cards->filter('.tab')->each(static fn (Crawler $c): string => $c->text()),
        );
    }

    /**
     * THE ROW IS THE DESIGN'S FLUSH FIGURE ROW (`grid w-flush dp-kstrip` in
     * team/overview.html; the shell's `.kstrip` is the design's `.dp-kstrip`):
     * no margin of its own under it, and the qualifier read as a sentence the
     * pill sits at the end of, not a row of flex columns.
     */
    public function testTheKpiRowIsTheDesignsFlushFigureRow(): void
    {
        $this->installation();

        $row = $this->visit('/team/overview')->filter('[data-kpi]');

        self::assertCount(1, $row);
        self::assertSame('grid w-flush kstrip', $row->attr('class'));

        $css = (string) file_get_contents(\dirname(__DIR__, 2).'/public/team.css');
        self::assertStringContainsString('.grid.kstrip[data-kpi] .c.kpi { height: auto; }', $css);
        self::assertStringContainsString('.grid.kstrip[data-kpi] .c.kpi .sub { display: block; line-height: 1.55; }', $css);
    }

    /**
     * A FIGURE THAT FELL READS WITH THE DESIGN'S MINUS SIGN (U+2212,
     * `&minus;` in the design), never a hyphen, in the bad pill.
     */
    public function testAFallingFigureWearsTheMinusSign(): void
    {
        $this->installation();

        $closed = PerformanceHistory::monthKey(new \DateTimeImmutable('first day of last month'));
        $this->history()->recordForInstallation($closed, TeamFigures::PEOPLE, 99.0);

        $pill = $this->visit('/team/overview')->filter('.kstrip .c.kpi')->eq(0)->filter('.delta');

        self::assertSame('delta bad', $pill->attr('class'));
        self::assertMatchesRegularExpression('/^\x{2212}\d+$/u', trim($pill->text()));
    }

    /**
     * A PERIOD NOBODY WROTE HAS NO PILL. An installation whose snapshot has
     * never run has no previous figure, and a delta reading zero would say
     * the figure held steady — a claim it cannot make.
     */
    public function testNoKpiClaimsAMovementTheInstallationNeverWroteDown(): void
    {
        $this->installation();

        self::assertCount(0, $this->visit('/team/overview')->filter('.kstrip .delta'));
    }

    /**
     * AND A PERIOD THAT WAS WRITTEN IS COMPARED AGAINST — read from the
     * history, never recomputed, because a closed period cannot be worked out
     * again from tables that hold what is true now.
     *
     * THE MOVEMENT IS ABSOLUTE: two more people is "+2", not a percentage.
     */
    public function testAKpiComparesAgainstTheLastClosedPeriodTheSnapshotWroteDown(): void
    {
        $this->installation();

        $history = $this->history();
        $closed = PerformanceHistory::monthKey(new \DateTimeImmutable('first day of last month'));
        $history->recordForInstallation($closed, TeamFigures::PEOPLE, 2.0);
        // A figure nobody wrote stays without a pill even in a period that
        // has some: the hole is per figure, not per period.
        $history->recordForInstallation($closed, TeamFigures::POSTINGS, 0.0);

        $cards = $this->visit('/team/overview')->filter('.kstrip .c.kpi');

        self::assertStringContainsString('+2', $cards->eq(0)->filter('.delta')->text());
        self::assertSame('delta good', $cards->eq(0)->filter('.delta')->attr('class'));
        self::assertCount(0, $cards->eq(1)->filter('.delta'), 'Positions was never written down');
        self::assertCount(1, $cards->eq(3)->filter('.delta'), 'Postings was written, and has not moved');
    }

    private function history(): PerformanceHistory
    {
        /** @var PerformanceHistory $history */
        $history = static::getContainer()->get(PerformanceHistory::class);

        return $history;
    }

    /** The identity band states the five facts, in the ruled order. */
    public function testTheOverviewBandStatesTheFiveFacts(): void
    {
        $this->installation();

        self::assertSame(
            ['People', 'Positions', 'Held', 'Assignments', 'Roles'],
            $this->visit('/team/overview')->filter('.factband .f .k')->each(static fn (Crawler $c): string => $c->text()),
        );
    }

    /** A person with no position is a row on the bars, not a rounding. */
    public function testPeopleWithNoDepartmentAreTheirOwnRowOnTheBars(): void
    {
        $this->installation();

        $labels = $this->visit('/team/overview')->filter('.c')->eq(4)->filter('.sxbar .l')
            ->each(static fn (Crawler $c): string => $c->text());

        self::assertContains('No department', $labels);
    }

    /**
     * THE TWO RANKINGS ARE THE ATLAS'S BARS, served as the design's rows: a
     * group that is not a department is dimmed, the widths arrive worked out,
     * the positions card counts its positions in its tab, and its key wears
     * the bar's own marks rather than a colour written on the page.
     */
    public function testTheTwoRankingsAreTheAtlasBars(): void
    {
        $this->installation();
        $crawler = $this->visit('/team/overview');

        $people = $crawler->filter('.c')->reduce(static fn (Crawler $c): bool => str_starts_with($c->filter('.tab')->count() > 0 ? $c->filter('.tab')->first()->text() : '', 'People by department'));
        self::assertCount(1, $people);
        $unplaced = $people->filter('.sxbar')->reduce(static fn (Crawler $c): bool => 'No department' === $c->filter('.l')->text());
        self::assertCount(1, $unplaced);
        self::assertStringContainsString('q', (string) $unplaced->attr('class'), 'A group that is not a department is dimmed.');
        self::assertMatchesRegularExpression('/^width:\d+(\.\d)?%$/', (string) $people->filter('.sxbar .t i.f')->first()->attr('style'));
        self::assertCount(1, $people->filter('.sxbar .n b')->first());

        $seats = $crawler->filter('.c')->reduce(static fn (Crawler $c): bool => str_starts_with($c->filter('.tab')->count() > 0 ? $c->filter('.tab')->first()->text() : '', 'Positions held and unheld'));
        self::assertCount(1, $seats);
        self::assertMatchesRegularExpression('/· \d+ · by department/', $seats->filter('.tab .src')->text());
        self::assertSame(['somebody holds it', 'nobody holds it'], $seats->filter('.sxmxkey span')->each(static fn (Crawler $c): string => $c->text()));
        self::assertCount(1, $seats->filter('.sxmxkey i.sxdot.v'));
        self::assertCount(0, $seats->filter('.sxmxkey [style]'), 'The key names no colour of its own.');
    }

    /**
     * ASSIGNMENTS BY AREA IS THE ATLAS'S COLUMN CHART: one accent series,
     * the area names under the columns, the axis a round number above the
     * tallest with its half, and an area with none keeping its column as a
     * faded hairline — drawn by `atlas_chart()`, not by the template.
     */
    public function testAssignmentsByAreaIsTheAtlasColumnChart(): void
    {
        $this->installation();
        $frank = $this->em->getRepository(User::class)->findOneBy(['firstName' => 'Frank']);
        $ibrahim = $this->em->getRepository(User::class)->findOneBy(['firstName' => 'Ibrahim']);
        self::assertNotNull($frank);
        self::assertNotNull($ibrahim);
        FakeStationDirectory::$areas = [new DirectoryArea('area-north', 'Northern Reserve'), new DirectoryArea('area-south', 'Southern Plains')];
        FakeStationDirectory::$stations = [
            new PostedStation(uuid: 'st-01', name: 'Eastgate Post', code: 'ST-01', areaUuid: 'area-north', areaName: 'Northern Reserve', zoneName: 'Crater', posts: [
                new StationPost((string) $frank->getUuidString(), new \DateTimeImmutable('2026-01-04'), true),
                new StationPost((string) $ibrahim->getUuidString(), new \DateTimeImmutable('2026-02-11')),
            ]),
            new PostedStation(uuid: 'st-02', name: 'Ridge Outpost', code: 'ST-02', areaUuid: 'area-south', areaName: 'Southern Plains', zoneName: 'Ridge'),
        ];

        $card = $this->visit('/team/overview')->filter('.c')->reduce(
            static fn (Crawler $c): bool => str_starts_with($c->filter('.tab')->count() > 0 ? $c->filter('.tab')->first()->text() : '', 'Assignments by area'),
        );

        self::assertCount(1, $card);
        self::assertCount(0, $card->filter('svg'), 'The template draws no chart of its own.');
        self::assertCount(1, $card->filter('.chart-plate canvas[data-controller="symfony--ux-chartjs--chart"]'));
        self::assertSame('Assignments by area', $card->filter('canvas')->attr('aria-label'));

        $view = json_decode((string) $card->filter('canvas')->attr('data-symfony--ux-chartjs--chart-view-value'), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($view);
        self::assertSame('bar', self::at($view, 'type'));
        self::assertSame(['northern reserve', 'southern plains'], self::at($view, 'data', 'labels'));
        self::assertSame([2, 0], self::at($view, 'data', 'datasets', '0', 'data'));
        self::assertSame('var(--acc)', self::at($view, 'data', 'datasets', '0', 'backgroundColor'));
        self::assertSame(2, self::at($view, 'data', 'datasets', '0', 'minBarLength'));
        self::assertSame(40, self::at($view, 'data', 'datasets', '0', 'maxBarThickness'));
        // The design's columns are `rx="1.5"`, its nought stubs `rx="1"`.
        self::assertSame([1.5, 1], self::at($view, 'data', 'datasets', '0', 'borderRadius'));
        self::assertFalse(self::at($view, 'data', 'datasets', '0', 'borderSkipped'));
        // The design's 640×140 plot drawn across the half card at the reference width.
        self::assertStringContainsString('--chart-height:114px', (string) $card->filter('.chart-plate')->attr('style'));
        self::assertSame(8, self::at($view, 'options', 'scales', 'y', 'max'));
        self::assertSame(4, self::at($view, 'options', 'scales', 'y', 'ticks', 'stepSize'));
        self::assertFalse(self::at($view, 'options', 'plugins', 'legend', 'display'));
    }

    /** The attention cards name the people they are about, and link to them. */
    public function testTheAttentionCardsNameThePeopleAndLinkToThem(): void
    {
        $this->installation();
        $page = $this->visit('/team/overview');

        self::assertStringContainsString('Frank Massawe', $page->text());
        self::assertStringContainsString('Ibrahim Mrema', $page->text());
    }

    /** THE OVERVIEW WRITES NOTHING: it carries no control that changes anybody. */
    public function testTheOverviewCarriesNoForm(): void
    {
        $this->installation();

        self::assertCount(0, $this->visit('/team/overview')->filter('form[method="post"]'));
    }

    /** Four doors at the foot, and each one opens. */
    public function testTheDoorsAtTheFootAllOpen(): void
    {
        $this->installation();
        $doors = $this->visit('/team/overview')->filter('.sxdoors .sxdoor');

        self::assertCount(4, $doors);
        foreach ($doors->each(static fn (Crawler $c): ?string => $c->attr('href')) as $href) {
            $this->client->request('GET', (string) $href);
            self::assertResponseIsSuccessful();
        }
    }

    /**
     * Four people: a Super Admin, an Admin, a Staff member whose position
     * carries the grant, and one who holds nothing and has never signed in.
     */
    private function installation(): void
    {
        $this->department('Administration');
        $this->department('Ecology');
        $this->administratorPosition('Warden');
        $this->position('Analyst');

        // Two above the matrix by tier, and two Staff holding nothing: the
        // model's zero, and an account that has never signed in.
        $this->person('Salum', 'Mwaipopo', TeamRoleEnum::Admin);
        $this->person('Frank', 'Massawe');
        $this->person('Ibrahim', 'Mrema')->setVerified(false);
        $this->administrator();
    }

    /**
     * A value down a path of keys in the chart's served view, each block
     * asserted to exist rather than cast.
     *
     * @param array<array-key, mixed> $payload
     */
    private static function at(array $payload, string ...$path): mixed
    {
        $value = $payload;
        foreach ($path as $key) {
            self::assertIsArray($value);
            self::assertArrayHasKey($key, $value);
            $value = $value[$key];
        }

        return $value;
    }

    private function visit(string $path): Crawler
    {
        $crawler = $this->client->request('GET', $path);
        self::assertResponseIsSuccessful();

        return $crawler;
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
