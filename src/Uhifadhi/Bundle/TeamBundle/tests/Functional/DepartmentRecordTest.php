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

use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\DomCrawler\Crawler;
use Uhifadhi\Bundle\RegistryBundle\Entity\Module;
use Uhifadhi\Bundle\TeamBundle\Controller\DepartmentController;
use Uhifadhi\Bundle\TeamBundle\Entity\Department;

/**
 * A DEPARTMENT'S RECORD — three panels, one job each: Overview (the facts
 * strip, the figures, the goals and where the figures come from), People &
 * access (the index beside who is in it, its members and its history) and
 * Settings (the modules it runs, read). What needs a decision is the banner
 * above the panels; the record reads and Configure writes.
 */
#[CoversClass(DepartmentController::class)]
final class DepartmentRecordTest extends WebTestCaseWithSchema
{
    public function testTheRecordHasThreePanels(): void
    {
        $crawler = $this->record($this->seed());

        self::assertSame(
            ['Overview', 'People & access', 'Settings'],
            $crawler->filter('.atabs button')->each(static fn (Crawler $c): string => trim($c->text())),
        );
        self::assertSame(
            ['overview', 'people', 'settings'],
            $crawler->filter('[data-tab-panel]')->each(static fn (Crawler $c): string => (string) $c->attr('data-tab-panel')),
        );
        self::assertCount(0, $crawler->filter('.scoperule'));
    }

    public function testTheFactsStripSitsInTheOverviewPanelAlone(): void
    {
        $ecology = $this->seed();
        $this->attach($ecology, 'surveys');

        $crawler = $this->record($ecology);
        $strip = $crawler->filter('.factband');

        self::assertCount(1, $strip);
        self::assertSame('overview', $strip->closest('[data-tab-panel]')?->attr('data-tab-panel'));
        self::assertSame(['Belong', 'Support', 'Modules'], $strip->filter('.f .k')->each(static fn (Crawler $c): string => $c->text()));
        self::assertSame('1', $strip->filter('.f')->eq(0)->filter('.v')->text());
        self::assertSame('of 2', $strip->filter('.f')->eq(2)->filter('.v em')->text());
    }

    public function testAnOrgWideDepartmentIsReadThroughTheAreaDropdown(): void
    {
        $crawler = $this->record($this->seed());

        self::assertSame('org-wide department', trim($crawler->filter('.dpthead .pgsub')->text()));
        $reading = $crawler->filter('.pgact [data-dd="reading"]');
        self::assertCount(1, $reading);
        self::assertSame(
            ['All areas', 'Northern Reserve'],
            $reading->filter('.dd-o .dd-ol')->each(static fn (Crawler $c): string => trim($c->text())),
        );
    }

    public function testAnAreaDepartmentReadsItsOneAreaWithoutADropdown(): void
    {
        $this->seed();
        $office = $this->areaDepartment('Warden Office', $this->north());
        $this->em->flush();

        $crawler = $this->record($office);

        self::assertSame('area department · Northern Reserve', trim(preg_replace('/\s+/', ' ', $crawler->filter('.dpthead .pgsub')->text()) ?? ''));
        self::assertCount(0, $crawler->filter('[data-dd="reading"]'));
    }

    public function testTheOverviewDrawsTheAttachedModulesFiguresAndNoOthers(): void
    {
        $ecology = $this->seed();
        $this->attach($ecology, 'surveys');

        $tiles = $this->record($ecology)->filter('[data-tab-panel="overview"] [data-dept-kpis]');

        self::assertStringContainsString('Surveys logged', $tiles->text());
        self::assertStringContainsString('88', $tiles->text());
        self::assertStringNotContainsString('Shifts filled', $tiles->text());
        $coverage = $tiles->filter('.kpi')->reduce(static fn (Crawler $c): bool => str_contains($c->closest('.c')?->text() ?? '', 'Coverage'))->first();
        self::assertStringContainsString("\u{2014}", $coverage->filter('b')->text());
    }

    /**
     * A FIGURE'S LINE IS THE ATLAS'S: a figure with a history carries the
     * sparkline `atlas_sparkline()` draws at the card's size, its tone a
     * class the sheet paints; a figure without one draws no line.
     */
    public function testAFiguresHistoryIsTheAtlasSparkline(): void
    {
        $ecology = $this->seed();
        $this->attach($ecology, 'surveys');

        $tiles = $this->record($ecology)->filter('[data-tab-panel="overview"] [data-dept-kpis] .c');
        $surveys = $tiles->reduce(static fn (Crawler $c): bool => str_contains($c->text(), 'Surveys logged'))->first();
        $line = $surveys->filter('svg.sk');

        self::assertCount(1, $line);
        self::assertSame('0 0 100 26', $line->attr('viewBox'));
        self::assertSame('up', $line->filter('polyline')->attr('class'));
        self::assertNull($line->filter('polyline')->attr('stroke'), 'A line is a class the sheet paints, never a stroke.');

        $coverage = $tiles->reduce(static fn (Crawler $c): bool => str_contains($c->text(), 'Coverage'))->first();
        self::assertCount(0, $coverage->filter('svg'));
    }

    public function testAnUnattachedDepartmentSaysThereIsNothingToRollUp(): void
    {
        $tiles = $this->record($this->seed())->filter('[data-tab-panel="overview"] [data-dept-kpis]');

        self::assertStringContainsString('No module figures this period', $tiles->text());
    }

    public function testAGoalNobodyMeasuresIsAGoalCardAndABannerLine(): void
    {
        $ecology = $this->seed();
        $this->declare($ecology, 'Cover the ground every month', 'nowhere.covered');

        $crawler = $this->record($ecology);

        $goal = $crawler->filter('[data-tab-panel="overview"] .goal');
        self::assertCount(1, $goal);
        self::assertStringContainsString('Cover the ground every month', $goal->filter('.gt')->text());
        self::assertStringContainsString('no figure yet', $goal->filter('.chip')->text());

        $banner = $crawler->filter('.banner[aria-label="Needs a decision"]');
        self::assertCount(1, $banner);
        self::assertNull($banner->closest('[data-tab-panel]'));
        self::assertStringContainsString('1 goal cannot be measured yet', $banner->text());
    }

    public function testNothingToDecideDrawsNoBanner(): void
    {
        self::assertCount(0, $this->record($this->seed())->filter('.banner'));
    }

    public function testWhereTheFiguresComeFromIsAHouseTable(): void
    {
        $ecology = $this->seed();
        $this->attach($ecology, 'surveys');

        $card = $this->record($ecology)->filter('#dp-sources');

        self::assertCount(1, $card->filter('.lfilt .lsearch'));
        self::assertSame(['Module', 'Figures it feeds', 'Areas'], $card->filter('table.tbl th')->each(static fn (Crawler $c): string => trim($c->text())));
        self::assertStringContainsString('Surveys', $card->filter('table.tbl tbody')->text());
        self::assertStringContainsString('1 module', $card->filter('.rdf-foot')->text());
    }

    public function testPeopleAndAccessIsTheIndexBesideItsCards(): void
    {
        $crawler = $this->record($this->seed());
        $panel = $crawler->filter('[data-tab-panel="people"]');

        $rows = $panel->filter('.onpage-nav a.onpage-row');
        self::assertSame(['#dp-who', '#dp-members', '#dp-history'], $rows->each(static fn (Crawler $c): string => (string) $c->attr('href')));
        foreach (['dp-who', 'dp-members', 'dp-history'] as $id) {
            self::assertCount(1, $panel->filter('.onpage-main > .c#'.$id), $id);
        }
        self::assertStringContainsString('Analyst', $panel->filter('#dp-who')->text());
        self::assertStringContainsString('Tumaini Njau', $panel->filter('#dp-members table.tbl')->text());
        self::assertCount(1, $panel->filter('#dp-members .rdf-foot'));
        self::assertStringContainsString('Department created', $panel->filter('#dp-history')->text());
    }

    public function testSettingsReadsTheModulesAndWritesNothing(): void
    {
        $ecology = $this->seed();
        $this->attach($ecology, 'surveys');

        $crawler = $this->record($ecology);
        $panel = $crawler->filter('[data-tab-panel="settings"]');

        $state = [];
        $panel->filter('table.tbl tbody tr')->each(static function (Crawler $row) use (&$state): void {
            $state[trim($row->filter('td')->eq(0)->filter('b')->text())] = trim($row->filter('td')->eq(2)->text());
        });
        self::assertSame(['Roster' => 'off', 'Surveys' => 'on'], $state);
        self::assertCount(1, $panel->filter('.lfilt'));
        self::assertCount(1, $panel->filter('.rdf-foot'));
        self::assertCount(0, $crawler->filter('form'), 'the record writes nothing');
    }

    private ?\Uhifadhi\Bundle\TeamBundle\Tests\Integration\Fixtures\Area\HostArea $northArea = null;

    private function north(): \Uhifadhi\Bundle\TeamBundle\Tests\Integration\Fixtures\Area\HostArea
    {
        return $this->northArea ??= $this->area('Northern Reserve');
    }

    private function seed(): Department
    {
        $this->administrator();
        $this->north();
        foreach (['surveys' => 'Surveys', 'roster' => 'Roster'] as $slug => $name) {
            $this->em->persist((new Module())->setSlug($slug)->setName($name));
        }
        $ecology = $this->department('Ecology');
        $tumaini = $this->person('Tumaini', 'Njau');
        $tumaini->setPosition($this->position('Analyst'));
        $this->place($tumaini, null, [$ecology]);
        $this->em->flush();

        return $ecology;
    }

    private function record(Department $department): Crawler
    {
        $crawler = $this->client->request('GET', '/departments/'.$department->getUuidString());
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    private function attach(Department $department, string $slug): void
    {
        $this->client->request('POST', '/departments/'.$department->getUuidString().'/modules/'.$slug.'/toggle', [
            '_token' => $this->tokenFrom('/departments/'.$department->getUuidString().'/configure'),
        ]);
        self::assertResponseRedirects();
        $this->em->clear();
    }

    private function declare(Department $department, string $statement, string $ref): void
    {
        $this->client->request('POST', '/departments/'.$department->getUuidString().'/goals', [
            'statement' => $statement,
            'kpiRef' => $ref,
            'target' => '60',
            'unit' => '%',
            'direction' => 'at_least',
            'opensAt' => '2026-01-01',
            'closesAt' => '2036-09-30',
            '_token' => $this->tokenFrom('/departments/'.$department->getUuidString().'/configure'),
        ]);
        self::assertResponseRedirects();
        $this->em->clear();
    }
}
