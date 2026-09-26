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
use Uhifadhi\Bundle\TeamBundle\Access\TeamConcerns;
use Uhifadhi\Bundle\TeamBundle\Entity\Position;
use Uhifadhi\Contracts\Access\ScopeKind;

/**
 * THE POSITION RECORD — the matrix, read-only, on the station record's
 * skeleton.
 *
 * NO TABS, AND NO UUID ON THE PAGE. A record carries no section tabs, and an
 * identifier nobody types is not a fact a reader needs.
 *
 * WHAT IS ASSERTED IS WHAT A TEMPLATE CAN GET WRONG: that the band's four
 * figures agree with the matrix under them, that a cell exists only where
 * the concern declares the verb, that an orphaned grant is drawn rather than
 * vanishing with the module that declared it, and that the holders are doors
 * to the person's record and nothing more.
 */
final class PositionRecordTest extends WebTestCaseWithSchema
{
    public function testTheRecordRendersOnTheRecordSkeletonWithNoTabStrip(): void
    {
        $this->administrator();
        $sergeant = $this->position('Sergeant');
        $this->em->flush();

        $crawler = $this->client->request('GET', '/team/positions/'.$sergeant->getUuidString());

        self::assertResponseIsSuccessful();
        self::assertSame('Sergeant', $crawler->filter('.dpthead h1.pg')->text());
        self::assertSame('SE', $crawler->filter('.dpthead .mark')->text());
        self::assertCount(0, $crawler->filter('.atabs'), 'A record carries no section tabs.');
        // NO UUID ON THE PAGE: an identifier nobody types is not a fact a
        // reader needs. It is still in the hrefs, because that is how a door
        // addresses a row — what the rule forbids is PRINTING it.
        self::assertStringNotContainsString((string) $sergeant->getUuidString(), $crawler->filter('.pgbody')->text());
        self::assertCount(1, $crawler->filter('.recgrid > .col + .col'), 'Two columns, the station record’s split.');
    }

    /** THE FOUR FIGURES, four to a row, and they agree with the matrix. */
    public function testTheBandIsTheFourFiguresAndTheyAgreeWithTheMatrix(): void
    {
        $this->administrator();
        $sergeant = $this->position('Sergeant', ['surveys.read', TeamConcerns::PERSONAL_DETAILS.'.read'])->setSeatCount(8);
        $this->person('Joseph', 'Mollel')->setPosition($sergeant);
        $this->em->flush();

        $crawler = $this->client->request('GET', '/team/positions/'.$sergeant->getUuidString());
        $band = $crawler->filter('.factband .f');

        self::assertCount(4, $band, 'A figure row is four to a row, and never five.');
        self::assertSame(
            ['Seats filled', 'Seats free', 'Holders', 'Concerns granted'],
            $band->filter('.k')->each(static fn (Crawler $c): string => $c->text()),
        );
        self::assertStringContainsString('1of 8', $band->eq(0)->filter('.v')->text());
        self::assertStringContainsString('7', $band->eq(1)->filter('.v')->text());
        self::assertStringContainsString('2of 10', $band->eq(3)->filter('.v')->text());
        self::assertStringContainsString('1 sensitive', $crawler->filter('.factband')->text());
    }

    /** ONE FOLD PER DECLARER, each naming who declared it. */
    public function testEachDeclarerIsAFoldNamingWhoDeclaredIt(): void
    {
        $this->administrator();
        $sergeant = $this->position('Sergeant', ['surveys.read']);
        $this->em->flush();

        $crawler = $this->client->request('GET', '/team/positions/'.$sergeant->getUuidString());
        $groups = $crawler->filter('.pmf.pmfg');

        self::assertGreaterThanOrEqual(2, $groups->count());
        self::assertStringContainsString('declared by the surveys module', $crawler->html());
        self::assertStringContainsString('declared by the core', $crawler->html());

        // THE CORE'S FOLDS COME FIRST, widest first, and a module's after them.
        $folds = $groups->each(static fn (Crawler $g): array => [
            trim($g->filter('summary b')->first()->text()),
            $g->filter('summary .pmx-by.core')->count() > 0,
            $g->filter('.pmk-row')->count(),
        ]);
        $lastCore = -1;
        $firstModule = \count($folds);
        $coreWidths = [];
        foreach ($folds as $i => [$name, $core, $width]) {
            if ($core) {
                $lastCore = max($lastCore, $i);
                $coreWidths[] = $width;
            } else {
                $firstModule = min($firstModule, $i);
            }
        }
        self::assertLessThan($firstModule, $lastCore, implode(' · ', array_column($folds, 0)));
        $sorted = $coreWidths;
        rsort($sorted);
        self::assertSame($sorted, $coreWidths, 'The widest core group leads.');
    }

    /**
     * NO CELL WHERE A CONCERN DECLARES NO VERB. Not a disabled box: a greyed
     * box says "you could have this and do not", which is a different and
     * false sentence.
     */
    public function testThereIsNoCellWhereAConcernDeclaresNoVerb(): void
    {
        $this->administrator();
        $sergeant = $this->position('Sergeant', ['surveys.read']);
        $this->em->flush();

        $crawler = $this->client->request('GET', '/team/positions/'.$sergeant->getUuidString());
        $row = $crawler->filter('.pmk-row')->reduce(
            static fn (Crawler $c): bool => 'Surveys' === $c->filter('.pmx-cn b')->text(),
        );

        // The surveys concern declares read and record, and nothing else.
        self::assertSame(
            ['read', 'record'],
            $row->filter('.pmc')->each(static fn (Crawler $c): string => $c->text()),
        );
        self::assertSame(['read'], $row->filter('.pmc.on')->each(static fn (Crawler $c): string => $c->text()));
    }

    /** A sensitive concern is marked, never hidden. */
    public function testASensitiveConcernIsMarkedAsOne(): void
    {
        $this->administrator();
        $sergeant = $this->position('Sergeant');
        $this->em->flush();

        $crawler = $this->client->request('GET', '/team/positions/'.$sergeant->getUuidString());

        self::assertStringContainsString('sensitive', $crawler->filter('.pmx-sens')->text());
    }

    /**
     * THE SCOPE CHIPS SAY WHAT THE POSITION ALLOWS, and they are read-only:
     * a position is defined once for the organization and where it applies
     * is the holder's.
     */
    public function testTheScopeChipsStateTheKindsThePositionAllowsAndAreNotControls(): void
    {
        $this->administrator();
        $sergeant = $this->position('Sergeant', ['surveys.read']);
        $this->em->flush();

        $crawler = $this->client->request('GET', '/team/positions/'.$sergeant->getUuidString());
        $row = $crawler->filter('.pmk-row')->reduce(
            static fn (Crawler $c): bool => 'Surveys' === $c->filter('.pmx-cn b')->text(),
        );

        // Surveys offers organization, area and department; the position is
        // placed at the organization or at an area, so department is unlit.
        self::assertSame(
            ['organization', 'area', 'department'],
            $row->filter('.pmx-sk')->each(static fn (Crawler $c): string => $c->text()),
        );
        self::assertSame(
            ['organization', 'area'],
            $row->filter('.pmx-sk.on')->each(static fn (Crawler $c): string => $c->text()),
        );
        self::assertCount(0, $crawler->filter('.pmk-row input'), 'The record reads; the configure page edits.');
    }

    /** A row the position holds nothing on has no scope to state, and says so. */
    public function testARowThatGrantsNothingStatesADashRatherThanAnEmptyBox(): void
    {
        $this->administrator();
        $sergeant = $this->position('Sergeant');
        $this->em->flush();

        $crawler = $this->client->request('GET', '/team/positions/'.$sergeant->getUuidString());

        self::assertGreaterThan(0, $crawler->filter('.pmk-row[data-ungranted] .pmx-sk.none')->count());
    }

    /**
     * AN ORPHANED GRANT IS DRAWN. Pruned, not purged: removing it on the
     * module's way out would silently rewrite what an administrator granted,
     * and the difference between "you no longer have this" and "you cannot
     * see that you still have this" is the whole ruling.
     */
    public function testAnOrphanedGrantIsDrawn(): void
    {
        $this->administrator();
        $sergeant = $this->position('Sergeant');
        $this->em->flush();
        // Written past the validated path on purpose: this is the state a
        // module leaves behind when it is uninstalled.
        $this->em->getConnection()->executeStatement(
            "UPDATE team_position SET grants = '[\"telemetry.read\"]' WHERE id = ?",
            [$sergeant->getId()],
        );
        $this->em->clear();

        $crawler = $this->client->request('GET', '/team/positions/'.$sergeant->getUuidString());

        self::assertStringContainsString('Declared by nothing installed', $crawler->html());
        self::assertStringContainsString('telemetry.read', $crawler->html());
    }

    /**
     * HOLDERS ARE READ-ONLY HERE, and every name is a door to the person's
     * record — which is where a position is given.
     */
    public function testTheHoldersAreDoorsToThePersonsRecordAndNothingElse(): void
    {
        $this->administrator();
        $sergeant = $this->position('Sergeant')->setSeatCount(8);
        $joseph = $this->person('Joseph', 'Mollel')->setPosition($sergeant);
        $this->place($joseph, null, null);
        $this->em->flush();

        $crawler = $this->client->request('GET', '/team/positions/'.$sergeant->getUuidString());
        $holder = $crawler->filter('.pshold .pshold-r');

        self::assertCount(1, $holder);
        self::assertSame('Joseph Mollel', $holder->filter('.nm')->text());
        self::assertSame('the whole organization', $holder->filter('.wh')->text());
        self::assertStringContainsString(
            '/team/'.$joseph->getUuidString(),
            (string) $holder->filter('.nm')->attr('href'),
        );
        self::assertCount(0, $crawler->filter('.pshold form'), 'Nothing on this page changes who holds it.');
    }

    /** Nobody holds it, and the card says that rather than drawing nothing. */
    public function testAPositionNobodyHoldsSaysSo(): void
    {
        $this->administrator();
        $sergeant = $this->position('Sergeant');
        $this->em->flush();

        $crawler = $this->client->request('GET', '/team/positions/'.$sergeant->getUuidString());

        self::assertStringContainsString('Nobody holds this position', $crawler->text());
    }

    /**
     * THE HISTORY IS WHAT THE MODEL CAN TRUTHFULLY DATE, newest first and
     * bounded. Every grant that was ticked and unticked is a real event with
     * no stored date, and a line that cannot be placed in a list ordered by
     * when is not put in one with a guess.
     */
    public function testTheHistoryIsBoundedAndDerivedFromDatedFacts(): void
    {
        $this->administrator();
        $sergeant = $this->position('Sergeant');
        $this->person('Joseph', 'Mollel')->setPosition($sergeant)->setPositionSince(new \DateTimeImmutable('2026-06-12'));
        $this->em->flush();

        $crawler = $this->client->request('GET', '/team/positions/'.$sergeant->getUuidString());
        $rows = $crawler->filter('.hlist .hrow');

        self::assertLessThanOrEqual(8, $rows->count());
        // Newest first, so the day the position was written leads and the
        // holding it gained in June sits under it.
        self::assertStringContainsString('Position created', $rows->eq(0)->text());
        self::assertStringContainsString('Joseph Mollel given this position', $crawler->filter('.hlist')->text());
    }

    /**
     * THE SUBLINE SAYS WHERE A HOLDER MAY STAND, and it once said what a
     * scope reaches: an organization-only position read "placed Everything.",
     * which is a sentence about grants printed where the placement belongs.
     */
    public function testTheSublineNamesThePlacementAndNotAScopesReach(): void
    {
        $this->administrator();
        $sergeant = $this->position('Sergeant', [], [ScopeKind::Organization]);
        $this->em->flush();

        $crawler = $this->client->request('GET', '/team/positions/'.$sergeant->getUuidString());
        $subline = $crawler->filter('.dpthead .pgsub')->text();

        self::assertStringContainsString('placed across the organization', $subline);
        self::assertStringNotContainsString('Everything', $subline);
    }

    /**
     * THE BROWSER TAB IS TEXT, NOT MARKUP. The title block is captured into a
     * string and escaped by the shell, so an HTML entity written in it reaches
     * the tab as the letters somebody typed.
     */
    public function testTheTabTitleCarriesNoUnrenderedEntity(): void
    {
        $this->administrator();
        $sergeant = $this->position('Sergeant');
        $this->em->flush();

        $crawler = $this->client->request('GET', '/team/positions/'.$sergeant->getUuidString());
        $title = $crawler->filter('title')->text();

        self::assertSame('Sergeant · positions — Uhifadhi', $title);
        self::assertStringNotContainsString('middot', $title);
    }

    /**
     * FOLD ALL AND OPEN ALL ARE DRAWN AND WIRED. They were left out of the
     * first round as "a browser behaviour we cannot draw"; they are two
     * buttons over native folds and the design draws them.
     */
    public function testTheMatrixCarriesTheTwoFoldShortcuts(): void
    {
        $this->administrator();
        $sergeant = $this->position('Sergeant', ['surveys.read']);
        $this->em->flush();

        $crawler = $this->client->request('GET', '/team/positions/'.$sergeant->getUuidString());

        self::assertSame(
            ['Fold all', 'Open all'],
            $crawler->filter('.pmbar-acts button')->each(static fn (Crawler $c): string => $c->text()),
        );
        self::assertCount(
            $crawler->filter('details.pmf.pmfg')->count(),
            $crawler->filter('details[data-uhifadhi--team-bundle--folds-target="fold"]'),
        );
    }

    public function testItIsGatedOnReadingThePositionsRegister(): void
    {
        $sergeant = $this->position('Sergeant');
        $frank = $this->person('Frank', 'Massawe');
        $this->em->flush();
        $this->client->loginUser($frank);

        $this->client->request('GET', '/team/positions/'.$sergeant->getUuidString());

        self::assertResponseStatusCodeSame(403);
    }

    public function testAPositionThisInstallationDoesNotHaveIsNotFound(): void
    {
        $this->administrator();
        $this->client->request('GET', '/team/positions/00000000-0000-4000-8000-000000000000');

        self::assertResponseStatusCodeSame(404);
    }

    /** The band and the card foot both lead to the one place grants are edited. */
    public function testBothDoorsLeadToTheConfigurePage(): void
    {
        $this->administrator();
        $sergeant = $this->position('Sergeant');
        $this->em->flush();

        $crawler = $this->client->request('GET', '/team/positions/'.$sergeant->getUuidString());
        $configure = '/team/positions/'.$sergeant->getUuidString().'/configure';

        self::assertSame($configure, $crawler->filter('.factband .more')->attr('href'));
        self::assertSame($configure, $crawler->filter('.pcard-foot .ov-open')->attr('href'));
        self::assertInstanceOf(Position::class, $sergeant);
    }
}
