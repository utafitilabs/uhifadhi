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
use Uhifadhi\Bundle\TeamBundle\Enum\TeamRoleEnum;

/**
 * THE SECTION'S TWO READING SCREENS — what they state, and what they refuse to
 * do.
 *
 * NEITHER WRITES. The overview reports figures the register and Team own, and
 * the matrix reports an attachment the department's own card makes; a control
 * on either would be a second write path for one fact. The assertions below
 * are therefore as much about the absence of forms as about the figures.
 */
final class DepartmentSectionScreensTest extends WebTestCaseWithSchema
{
    // ---- the overview ------------------------------------------------------

    /**
     * FOUR KPI CARDS, FOUR OR NONE — a figure row is four to a row (ruled),
     * and no workshop index codes on them. The drawn row labels its plates
     * DP·K1 … so a reviewer can name one; those labels belong in the design
     * file and never in the product.
     *
     * THE COUNT OF DEPARTMENTS IS NOT ONE OF THE FOUR: the band directly
     * above opens with exactly that fact and the register below is the list.
     */
    public function testTheOverviewOpensWithFourKpiCardsAndNoWorkshopLabels(): void
    {
        $crawler = $this->overview();

        self::assertCount(4, $crawler->filter('.kstrip .c.kpi'));
        self::assertCount(0, $crawler->filter('.kstrip .idx'));
        self::assertSame(
            ['Positions filled', 'People', 'Modules attached', 'Goals declared'],
            $crawler->filter('.kstrip .c.kpi .tab')->each(static fn (Crawler $c): string => $c->text()),
        );
    }

    /** And the figures are the installation's, counted rather than typed. */
    public function testTheKpiFiguresAreCounted(): void
    {
        $crawler = $this->overview();
        $figures = $crawler->filter('.kstrip .c.kpi b.disp')->each(static fn (Crawler $c): string => $c->text());

        // Three positions reach a department — two through Ecology's members
        // and one through Protection Service's — and each of them is held,
        // because a position only reaches a department by being held there.
        // (The count of departments is the band's, not the strip's.)
        self::assertStringStartsWith('3', $figures[0]);
        self::assertSame('3', $figures[1]);
    }

    /**
     * THE IDENTITY BAND STATES FACTS AS FRAGMENTS, not sentences, and it is the
     * same band an area's overview opens with.
     */
    public function testTheOverviewCarriesTheIdentityBand(): void
    {
        $crawler = $this->overview();

        self::assertSame(
            ['Departments', 'Org-wide', 'Area-level', 'Positions', 'People'],
            $crawler->filter('.factband .f .k')->each(static fn (Crawler $c): string => $c->text()),
        );
    }

    /**
     * EVERY CARD IS BOUNDED. A list card shows its rows and then says what it
     * is not showing and where the rest is; it never grows to the data.
     */
    public function testEveryListCardEndsOnItsBound(): void
    {
        $crawler = $this->overview();

        self::assertGreaterThan(0, $crawler->filter('.sxmore')->count());
        foreach ($crawler->filter('.sxmore')->each(static fn (Crawler $c): Crawler => $c) as $bound) {
            self::assertSame(1, $bound->filter('a')->count(), 'A bound names one destination.');
        }
    }

    /** THE OVERVIEW WRITES NOTHING, and the proof is that it has no form. */
    public function testTheOverviewCarriesNoForm(): void
    {
        self::assertCount(0, $this->overview()->filter('.pgbody form'));
    }

    /** A ranked bar is scaled to the largest row, not to its own total. */
    public function testTheRankedBarsAreScaledToTheLargestDepartment(): void
    {
        $crawler = $this->overview();
        $bars = $crawler->filter('.sxbars')->first()->filter('.sxbar');

        self::assertGreaterThan(0, $bars->count());
        self::assertSame('Ecology', $bars->first()->filter('.l')->text());
    }

    /**
     * THE THREE DEPARTMENT RANKINGS ARE THE ATLAS'S BARS: the staffing and
     * scope keys wear the bar's own marks, and no key names a colour on the
     * page.
     */
    public function testTheDepartmentRankingsAreTheAtlasBars(): void
    {
        $crawler = $this->overview();

        self::assertCount(3, $crawler->filter('.sxbars'));
        self::assertCount(1, $crawler->filter('.sxmxkey i.sxdot.v'), 'Vacant is the rest of a two-part bar.');
        self::assertCount(1, $crawler->filter('.sxmxkey i.sxdot.b'), 'Area-level is the soft fill.');
        self::assertCount(0, $crawler->filter('.sxmxkey [style]'));
        self::assertCount(0, $crawler->filter('.sxbar .n b b'));
    }

    // ---- the matrix --------------------------------------------------------

    /**
     * ABSENCE IS DRAWN. A department that does not read a module gets the
     * dashed ring, not an empty cell a reader cannot tell from a failure.
     */
    public function testTheMatrixDrawsAbsenceAsWellAsPresence(): void
    {
        $crawler = $this->matrix();

        self::assertSame(
            ['Department', 'Scope', 'Attached'],
            $crawler->filter('.sxmx thead th')->each(static fn (Crawler $c): string => $c->text()),
            'With no module installed the matrix has no module column, and still reads.',
        );
        self::assertCount(3, $crawler->filter('.sxmx tbody tr:not(.sxgrp)'));
    }

    /** THE MATRIX'S KEY IS THE ATLAS'S DOT KEY: present, absent, and the sentence after them. */
    public function testTheMatrixKeyIsTheAtlasDotKey(): void
    {
        $key = $this->matrix()->filter('.c .sxmxkey');

        self::assertCount(1, $key);
        self::assertSame(['attached', 'not attached', 'an org-wide department’s attachment is read in every area'], $key->filter('span')->each(static fn (Crawler $c): string => $c->text()));
        self::assertSame(['sxdot', 'sxdot no'], $key->filter('i')->each(static fn (Crawler $c): string => (string) $c->attr('class')));
    }

    /** EVERY ROW STATES ITS SCOPE, which is how the matrix is read across areas. */
    public function testEveryMatrixRowStatesItsScope(): void
    {
        $scopes = $this->matrix()->filter('.sxmx tbody tr:not(.sxgrp) td:nth-child(2)')
            ->each(static fn (Crawler $c): string => $c->text());

        self::assertSame(['org-wide', 'org-wide', 'Northern Reserve'], $scopes);
    }

    /** THE MATRIX READS AND DOES NOT WRITE: no checkbox, no form. */
    public function testTheMatrixCarriesNoForm(): void
    {
        self::assertCount(0, $this->matrix()->filter('.pgbody form'));
    }

    // ---- the ground both screens stand on ---------------------------------

    // ---- the overview as a widget surface ---------------------------------

    /**
     * THE OVERVIEW IS A COMPOSITION, not a fixed order of cells.
     *
     * What a reader comes to this tab for differs by who they are — somebody
     * staffing the organization, somebody wiring modules up, a director
     * reading goals — and one order cannot be right for all three. So the
     * section ships directions and the reader adopts one, exactly as the team
     * roster and the area overview do.
     */
    public function testTheOverviewDrawsTheCompositionAndNotAFixedOrderOfCells(): void
    {
        $crawler = $this->overview();

        $cells = $crawler->filter('.w-grid .w-cell')->each(
            static fn (Crawler $c): string => (string) $c->attr('data-w'),
        );

        self::assertSame(
            ['kpis', 'staffing', 'scope', 'modules', 'unattached', 'vacancies', 'goals'],
            $cells,
            'The shipped direction is every cell, in the order the organization is read from the outside in.',
        );
    }

    /**
     * AND THE BAND AND THE DOORS ARE NOT PART OF IT. The band is what the
     * section IS, drawn the same on every tab, and the doors are the way out
     * of it — neither is a reading anybody would arrange differently, and a
     * page whose every last element is arrangeable has no shape of its own.
     */
    public function testTheBandAndTheDoorsStayPageChrome(): void
    {
        $crawler = $this->overview();

        self::assertCount(1, $crawler->filter('.factband'));
        self::assertCount(0, $crawler->filter('.w-grid .factband'));
        self::assertCount(3, $crawler->filter('.sxdoors .sxdoor'));
        self::assertCount(0, $crawler->filter('.w-grid .sxdoors'));
    }

    /** The door to the library is on the page it composes. */
    public function testTheOverviewCarriesTheDoorToItsLibrary(): void
    {
        $crawler = $this->overview();

        $door = $crawler->filter('.pgact a[href="/departments/widgets"]');
        self::assertCount(1, $door);
        self::assertStringContainsString('Widget library', $door->text());
    }

    /** And the library opens on the direction this surface ships. */
    public function testTheLibraryOpensOnTheShippedDirection(): void
    {
        $crawler = $this->screen('/departments/widgets');

        self::assertResponseIsSuccessful();
        self::assertSame(1, substr_count($crawler->html(), 'w-presetflag-active'), 'exactly one card wears Active');
        self::assertStringContainsString('data-preset-kind="design" data-preset-id="default"', $crawler->html());
        self::assertStringContainsString('data-preset-kind="design" data-preset-id="staffing"', $crawler->html());
    }

    /**
     * ADOPTING A DIRECTION CHANGES THE OVERVIEW, which is the whole point: the
     * library is not a preferences screen that describes a page somewhere
     * else, it composes the page itself.
     */
    public function testAdoptingADirectionRecomposesTheOverview(): void
    {
        $crawler = $this->screen('/departments/widgets');
        $token = (string) $crawler->filter('[data-widget-root]')->attr('data-widget-csrf-token');

        $this->client->request('POST', '/departments/widgets/preset/asks', ['_token' => $token]);
        self::assertResponseRedirects('/departments/widgets');

        $cells = $this->client->request('GET', '/departments/overview')->filter('.w-grid .w-cell')->each(
            static fn (Crawler $c): string => (string) $c->attr('data-w'),
        );

        self::assertSame(['kpis', 'unattached', 'vacancies', 'goals'], $cells);
    }

    private function overview(): Crawler
    {
        return $this->screen('/departments/overview');
    }

    private function matrix(): Crawler
    {
        return $this->screen('/departments/modules');
    }

    /**
     * THE CAST IS BUILT OUT OF PLACEMENTS. A department's positions are the
     * ones its members hold, so Ecology comes to have two by having two
     * people placed in it, and Wetland Management has none because nobody is
     * placed there. Field Assistant is held by nobody at all, which is how a
     * position reaches the vacancies card without reaching a department.
     */
    private function screen(string $path): Crawler
    {
        $ecology = $this->department('Ecology');
        $protection = $this->department('Protection Service');
        $this->areaDepartment('Wetland Management', $this->area('Northern Reserve'));

        $analyst = $this->position('Analyst');
        $this->position('Field Assistant');

        $admin = $this->person('Naomi', 'Kileo', TeamRoleEnum::Admin);
        $admin->setPosition($this->administratorPosition('Warden'));
        $this->place($admin, null, [$ecology]);

        $ranger = $this->person('Juma', 'Mollel');
        $ranger->setPosition($analyst);
        $this->place($ranger, null, [$ecology]);

        $baraka = $this->person('Baraka', 'Msuya');
        $baraka->setPosition($this->position('Surveyor'));
        $this->place($baraka, null, [$protection]);

        $this->em->flush();
        $this->client->loginUser($admin);

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
