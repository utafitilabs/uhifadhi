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
use Uhifadhi\Bundle\TeamBundle\Controller\AreaDepartmentController;

/**
 * AN AREA'S DEPARTMENTS, READ.
 *
 * THE TAB IS THE REGISTER, NARROWED TO ONE AREA. Its cards are the
 * organization register's cards — the same partial, not a second one that
 * would drift — and the only difference is which departments are on it and
 * in what order: the area's OWN first, then the org-wide ones it inherits,
 * under a heading that says so.
 *
 * IT ONLY READS. Adding, renaming and scoping are the configure section's,
 * which is where the header's Configure action goes; the one other action
 * is the way out to the organization's whole register.
 *
 * THE ROUTE IS TEAM'S, and that is the reason there is no new seam here: a
 * department already knows which area it belongs to — `Department::$area`
 * is the published `AreaInterface` — so the bundle that owns departments
 * can answer "this area's" without the area bundle learning what a
 * department is.
 */
#[CoversClass(AreaDepartmentController::class)]
final class AreaDepartmentsTest extends WebTestCaseWithSchema
{
    public function testTheTabGroupsTheAreasOwnFirstThenTheOrgWideOnesItInherits(): void
    {
        $crawler = $this->tab();

        $headings = $crawler->filter('.deptgroup .gh')->each(static fn (Crawler $c): string => $c->text());

        self::assertSame(['Northern Reserve’s own', 'Org-wide · read this area too'], $headings);
    }

    /** Only this area's own and the org-wide ones — never another area's. */
    public function testAnotherAreasDepartmentIsNotOnThisAreasTab(): void
    {
        $crawler = $this->tab();

        self::assertSame(
            ['Wetland Management', 'Ecology'],
            $crawler->filter('.dcard .ov-nm')->each(static fn (Crawler $c): string => $c->text()),
        );
    }

    /** The cards are the register's own, down to the labelled disclosure. */
    public function testTheCardsAreTheRegistersCards(): void
    {
        $crawler = $this->tab();
        $card = $crawler->filter('.dcard')->first();

        self::assertCount(1, $card->filter('.dc-act .ovx.xdisc'));
        self::assertCount(1, $card->filter('.dc-act .ov-open'));
        self::assertSame('Northern Reserve', $card->filter('.ov-sc')->text());
    }

    /** The band says how many read this area, and how they divide. */
    /**
     * A LIST TAB CARRIES NO STRIP: the area's identity lives on its
     * Overview. The list wears the house filter bar above it and the
     * register foot under it, which says how many are listed.
     */
    public function testTheTabHasNoStripAndWearsTheBarAndTheFoot(): void
    {
        $crawler = $this->tab();

        self::assertCount(0, $crawler->filter('.factband'));
        self::assertCount(0, $crawler->filter('.dpfind .cnt'));
        self::assertSame(['kind'], $crawler->filter('.lfilt details.i-dd')->each(static fn (Crawler $c): string => (string) $c->attr('data-axis')));
        self::assertSame('all · 2', trim($crawler->filter('.lfilt details.i-dd .i-ddval')->text()));
        self::assertCount(1, $crawler->filter('.lfilt form.lsearch input[name="q"]'));
        self::assertStringStartsWith('2 departments', trim(preg_replace('/\s+/', ' ', $crawler->filter('.rdf-foot > span')->first()->text()) ?? ''));
    }

    /** The kind chip narrows the tab to the area's own or to the org-wide ones. */
    public function testTheKindChipNarrowsTheTab(): void
    {
        $this->tab();
        $north = $this->em->getRepository(\Uhifadhi\Bundle\TeamBundle\Tests\Integration\Fixtures\Area\HostArea::class)->findOneBy(['name' => 'Northern Reserve']);
        self::assertNotNull($north);

        $crawler = $this->client->request('GET', '/areas/'.$north->getUuidString().'/departments?kind=org');

        self::assertSame('org-wide · 1', trim($crawler->filter('.lfilt details.i-dd .i-ddval')->text()));
        self::assertStringContainsString('Ecology', $crawler->filter('.dcreg')->text());
        self::assertStringNotContainsString('Wetland Management', $crawler->filter('.dcreg')->text());
    }

    /** A DEPARTMENT BEHIND ITS GOAL is the banner above the list, with its door. */
    public function testADepartmentBehindItsGoalIsTheBanner(): void
    {
        $this->administrator();
        $north = $this->area('Northern Reserve');
        $south = $this->area('Southern Reserve');
        $wetland = $this->areaDepartment('Wetland Management', $north);
        $this->em->persist((new \Uhifadhi\Bundle\RegistryBundle\Entity\Module())->setSlug('surveys')->setName('Surveys'));
        $this->em->flush();
        $uuid = (string) $wetland->getUuidString();
        $token = $this->tokenFrom('/departments/'.$uuid.'/configure');
        $this->client->request('POST', '/departments/'.$uuid.'/modules/surveys/toggle', ['_token' => $token]);
        $this->client->request('POST', '/departments/'.$uuid.'/goals', [
            'statement' => 'Log 100 surveys a month', 'kpiRef' => 'surveys.surveys', 'target' => '100', 'unit' => '',
            'direction' => 'at_least', 'opensAt' => '2026-01-01', 'closesAt' => '2036-09-30', '_token' => $token,
        ]);

        $crawler = $this->client->request('GET', '/areas/'.$north->getUuidString().'/departments');
        $banner = $crawler->filter('.banner');

        self::assertCount(1, $banner);
        self::assertSame('1 department is behind its goal', $banner->filter('.banner-t b')->text());
        self::assertStringContainsString('Wetland Management, Log 100 surveys a month at 88 against 100', $banner->filter('.banner-c')->text());
        self::assertSame('/departments/'.$uuid, $banner->filter('.banner-acts a')->attr('href'));
        self::assertCount(0, $this->client->request('GET', '/areas/'.$south->getUuidString().'/departments')->filter('.banner'), 'none when nobody is behind');
    }

    /**
     * THE TAB WRITES NOTHING. Every control that changes a department is on
     * the configure section or the organization's register, and the tab
     * points at both: the header's way out to the whole register, and the
     * Configure door beside it to this area's section.
     */
    public function testTheTabOffersNoWriteAndPointsAtTheTwoPlacesThatDo(): void
    {
        $crawler = $this->tab();

        self::assertCount(0, $crawler->filter('.dcard form'));
        self::assertSame('/departments', $crawler->filter('.pgact a.cta')->attr('href'));
        self::assertStringEndsWith('/configure/departments', (string) $crawler->filter('.pgact a.tgl')->attr('href'));
    }

    private function tab(): Crawler
    {
        $this->administrator();
        $north = $this->area('Northern Reserve');
        $south = $this->area('Southern Reserve');

        $this->areaDepartment('Wetland Management', $north);
        $this->areaDepartment('Coastal Watch', $south);
        $this->department('Ecology');
        $this->em->flush();

        return $this->client->request('GET', '/areas/'.$north->getUuidString().'/departments');
    }
}
