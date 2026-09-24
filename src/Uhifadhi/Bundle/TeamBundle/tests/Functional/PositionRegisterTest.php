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
 * THE POSITIONS REGISTER — one collapsible card per position, and nothing
 * that edits.
 *
 * IT USED TO BE A WIDGET CANVAS: thirteen widgets and seven presets, five of
 * which were five renderings of one matrix. The ruled register is the
 * department card carried across — head, preview body, summary foot — and
 * the matrix lives once, on the position record. What is asserted here is
 * what a template can get wrong about that:
 *
 *   · the card's head states the SEATS and whether the next person is
 *     refused, which is the one thing a reader of a register is looking for;
 *   · the body is a PREVIEW — chips grouped by whoever declared the concern,
 *     sensitive ones marked — and carries no control that writes;
 *   · the foot counts by VERB and names the holders;
 *   · "grants nothing" is said in those words, because fail-closed is the
 *     model and an empty body would read as a rendering bug;
 *   · a RETIRED position is in the register, greyed, rather than hidden: an
 *     administrator told a name is taken has to be able to find the row
 *     holding it;
 *   · the scope filter is in the ADDRESS, so it is shareable.
 */
final class PositionRegisterTest extends WebTestCaseWithSchema
{
    /** @return list<string> */
    private function named(Crawler $crawler): array
    {
        return $crawler->filter('table.preg tr.prow .ov-nm')->each(static fn (Crawler $c): string => $c->text());
    }

    /**
     * THE HEADER IS THE SECTION'S, NOT THE SCREEN'S. A section wears the area
     * idiom: every tab is headed "Team" and the strip says which one you are
     * on.
     */
    public function testTheRegisterRenders(): void
    {
        $this->administrator();
        $crawler = $this->client->request('GET', '/team/positions');

        self::assertResponseIsSuccessful();
        self::assertSame('Team', $crawler->filter('h1.pg')->text());
        self::assertSame('Positions', $crawler->filter('.atabs a.on')->text());
    }

    /**
     * EXACTLY ONE CONFIGURE ENTRY, AND THE FRAME WRITES IT. This page typed
     * its own as well, so the header drew the word twice — which is the whole
     * reason the frame owns the entry: "exactly one per surface" cannot be
     * true if every page may add one.
     */
    public function testTheHeaderDrawsOneConfigureEntryAndTheFrameOwnsIt(): void
    {
        $this->administrator();
        $crawler = $this->client->request('GET', '/team/positions');
        $actions = $crawler->filter('.pghead .pgact a');

        self::assertSame(
            ['Add a position', 'Configure'],
            $actions->each(static fn (Crawler $c): string => trim($c->text())),
        );
        self::assertSame('Configure', trim($actions->last()->text()), 'Configure is last in the row, always.');
    }

    /**
     * GATED ON READING THE REGISTER — `positions.read`. A Staff member with
     * no position holds nothing at all and is refused.
     */
    public function testItIsGatedOnReadingThePositionsRegister(): void
    {
        $frank = $this->person('Frank', 'Massawe');
        $this->em->flush();
        $this->client->loginUser($frank);

        $this->client->request('GET', '/team/positions');

        self::assertResponseStatusCodeSame(403);
    }

    public function testWithNoPositionsTheRegisterSaysSoRatherThanDrawingNothing(): void
    {
        $this->administrator();
        $crawler = $this->client->request('GET', '/team/positions');

        self::assertCount(0, $crawler->filter('table.preg tr.prow'));
        self::assertStringContainsString('No positions yet', $crawler->filter('table.preg tr.dempty')->text());
    }

    /** ONE ROW PER POSITION, by name, on the People table's idiom (ruled 2026-09-22, option B). */
    public function testThereIsOneRowPerPosition(): void
    {
        $this->administrator();
        $this->position('Sergeant');
        $this->position('Ranger');
        $this->em->flush();

        $crawler = $this->client->request('GET', '/team/positions');

        self::assertSame(['Ranger', 'Sergeant'], $this->named($crawler));
        self::assertCount(0, $crawler->filter('.dcstack, .dcard'), 'the card board is gone');
        self::assertSame(['Position', 'Seats', 'Grants', 'Sensitive', 'Holders'], $crawler->filter('thead th a')->each(static fn (Crawler $c): string => $c->text()));
    }

    /** Every header sorts its one column through the address. */
    public function testEveryHeaderSortsItsColumn(): void
    {
        $this->administrator();
        $sergeant = $this->position('Sergeant')->setSeatCount(4);
        $this->position('Ranger')->setSeatCount(2);
        $this->person('Joseph', 'Mollel')->setPosition($sergeant);
        $this->em->flush();

        self::assertSame(['Sergeant', 'Ranger'], $this->named($this->client->request('GET', '/team/positions?sort=seats&dir=desc')));
        self::assertSame(['Sergeant', 'Ranger'], $this->named($this->client->request('GET', '/team/positions?sort=holders&dir=desc')));
        $crawler = $this->client->request('GET', '/team/positions?sort=seats');
        self::assertSame('Seats', $crawler->filter('th.sorted a')->text());
        self::assertStringContainsString('dir=desc', (string) $crawler->filter('th.sorted a')->attr('href'));
    }

    /**
     * THE SEATS COLUMN states filled over count, and a full one is marked as
     * refusing the next person — the whole reason the count is there.
     */
    public function testTheSeatsColumnStatesTheSeatsAndWhetherTheNextPersonIsRefused(): void
    {
        $this->administrator();
        $sergeant = $this->position('Sergeant')->setSeatCount(1);
        $this->person('Joseph', 'Mollel')->setPosition($sergeant);
        $this->position('Ranger');
        $this->em->flush();

        $crawler = $this->client->request('GET', '/team/positions');
        $seats = $crawler->filter('tr.prow td:nth-child(3)')->each(static fn (Crawler $c): string => preg_replace('/\s+/', ' ', trim($c->text())) ?? '');

        self::assertStringContainsString('unlimited', $seats[0], 'Ranger seats anybody.');
        self::assertStringContainsString('1 / 1', $seats[1]);
        self::assertStringContainsString('full', $seats[1]);
    }

    /** A free seat is counted as a vacancy, not merely implied by the two numbers. */
    public function testAnOpenPositionNamesHowManySeatsAreVacant(): void
    {
        $this->administrator();
        $sergeant = $this->position('Sergeant')->setSeatCount(8);
        $this->person('Joseph', 'Mollel')->setPosition($sergeant);
        $this->em->flush();

        $crawler = $this->client->request('GET', '/team/positions');

        self::assertSame('7 vacant', $crawler->filter('tr.prow .chip.warn')->text());
        self::assertSame(['Sergeant'], $this->named($this->client->request('GET', '/team/positions?seats=vacant')));
        self::assertSame([], $this->named($this->client->request('GET', '/team/positions?seats=full')));
    }

    /**
     * THE GRANTS COLUMN is a summary by verb and the sensitive count sits
     * beside it — and the register carries nothing that writes. The matrix
     * lives once, on the record.
     */
    public function testTheGrantsColumnSummarisesByVerbAndCountsTheSensitiveOnes(): void
    {
        $this->administrator();
        $this->position('Sergeant', ['surveys.read', TeamConcerns::PERSONAL_DETAILS.'.read']);
        $this->em->flush();

        $crawler = $this->client->request('GET', '/team/positions');

        self::assertStringContainsString('reads 2', $crawler->filter('tr.prow td:nth-child(4)')->text());
        self::assertSame('1 sensitive', $crawler->filter('tr.prow td:nth-child(5) .chip')->text());
        self::assertCount(0, $crawler->filter('table.preg input'), 'The register previews; it never edits.');
        self::assertSame(['Sergeant'], $this->named($this->client->request('GET', '/team/positions?grants=sensitive')));
    }

    /** The holders column stacks the holders; the fold opens to them as cards. */
    public function testTheHoldersColumnStacksTheHoldersAndTheFoldNamesThem(): void
    {
        $this->administrator();
        $sergeant = $this->position('Sergeant', ['surveys.read', 'surveys.record']);
        $this->person('Joseph', 'Mollel')->setPosition($sergeant);
        $this->em->flush();

        $crawler = $this->client->request('GET', '/team/positions');

        self::assertSame('JM', $crawler->filter('tr.prow .avs .av')->text());
        $fold = $crawler->filter('tr.prow')->first()->nextAll()->first();
        self::assertSame('foldrow', $fold->attr('class'));
        self::assertStringContainsString('Joseph Mollel', $fold->filter('.poscard b')->text());
        self::assertStringContainsString('Seat somebody', $fold->filter('.poscard.add')->text());
    }

    /** Which rows are open lives in the address; a shut fold row is still in the document for the fold to animate. */
    public function testWhichRowsAreOpenLivesInTheAddress(): void
    {
        $this->administrator();
        $sergeant = $this->position('Sergeant');
        $this->em->flush();
        $uuid = (string) $sergeant->getUuidString();

        $shut = $this->client->request('GET', '/team/positions');
        self::assertSame('false', $shut->filter('tr.prow .fchev')->attr('aria-expanded'));
        self::assertCount(1, $shut->filter('tr.foldrow:not(.open)'));
        self::assertStringContainsString('open='.$uuid, (string) $shut->filter('tr.prow .fchev')->attr('href'));

        $open = $this->client->request('GET', '/team/positions?open='.$uuid);
        self::assertSame('true', $open->filter('tr.prow .fchev')->attr('aria-expanded'));
        self::assertCount(1, $open->filter('tr.prow.open'));
        self::assertCount(1, $open->filter('tr.foldrow.open'));
        self::assertSame('uhifadhi--shell-bundle--register-fold', $open->filter('table.preg')->attr('data-controller'));
    }

    /**
     * FAIL CLOSED, AND SAID OUT LOUD. Nothing is granted by default, read
     * included, so a position may perfectly well grant nothing — and an
     * empty cell would read as a rendering fault instead of as the model.
     */
    public function testAPositionThatGrantsNothingSaysSo(): void
    {
        $this->administrator();
        $this->position('Community Liaison');
        $this->em->flush();

        $crawler = $this->client->request('GET', '/team/positions');

        self::assertSame('Grants nothing', trim($crawler->filter('tr.prow td:nth-child(4)')->text()));
        self::assertSame(['Community Liaison'], $this->named($this->client->request('GET', '/team/positions?grants=none')));
    }

    /**
     * THE PLACEMENT FILTER IS IN THE ADDRESS, so the choice is shareable and
     * it survives a save's redirect; the placement reads under the name.
     */
    public function testThePlacementFilterNarrowsTheRegisterAndLivesInTheAddress(): void
    {
        $this->administrator();
        $this->position('Sergeant', [], [ScopeKind::Organization]);
        $this->position('Ranger', [], [ScopeKind::Area]);
        $this->em->flush();

        $crawler = $this->client->request('GET', '/team/positions?placement=area');

        self::assertSame(['Ranger'], $this->named($crawler));
        self::assertSame('area', trim($crawler->filter('tr.prow .sub')->text()));
        self::assertSame('Area', $crawler->filter('.lfilt .i-ddval')->first()->text());
    }

    /**
     * A RETIRED POSITION IS IN THE REGISTER, MARKED — not hidden. We do not
     * delete things, and an administrator told a name is taken has to be
     * able to find the row that is holding it.
     */
    public function testARetiredPositionIsDrawnMarkedRatherThanHidden(): void
    {
        $this->administrator();
        $this->position('Sergeant')->retire(new \DateTimeImmutable());
        $this->em->flush();

        $crawler = $this->client->request('GET', '/team/positions');

        self::assertCount(1, $crawler->filter('tr.prow'));
        self::assertSame('retired', $crawler->filter('tr.prow .chip.stoff')->text());
    }

    /**
     * THE CREATE CARD IS A POSITION'S TWO FACTS — a name and a seat count, as
     * the design draws it. No department, because a position belongs to none;
     * no allowed kinds either, those are set on the record's configure page,
     * so a new position keeps the entity's default until somebody goes there.
     */
    public function testTheCreateCardWritesTheNameAndTheSeats(): void
    {
        $this->administrator();
        $crawler = $this->client->request('GET', '/team/configure');

        self::assertCount(0, $crawler->filter('#add select[name="department"]'));
        self::assertCount(0, $crawler->filter('#add input[name="allows[]"]'), 'The kinds a position allows are not on the add row.');
        self::assertSame(['name', 'seats'], $crawler->filter('#add .crlab')->each(static fn ($l): string => strtolower(trim($l->text()))));

        $form = $crawler->filter('#add form')->form();
        $form['name'] = 'Sergeant';
        $form['seats'] = '8';
        $this->client->submit($form);

        $this->client->followRedirect();
        $crawler = $this->client->request('GET', '/team/positions');

        $seats = preg_replace('/\s+/', ' ', trim($crawler->filter('tr.prow td:nth-child(3)')->text())) ?? '';
        self::assertStringContainsString('0 / 8', $seats);
        self::assertStringContainsString('8 vacant', $seats);
        // It grants nothing yet, so the grants column says that; the kinds it
        // allows read under the name.
        self::assertSame('Grants nothing', trim($crawler->filter('tr.prow td:nth-child(4)')->text()));
        self::assertSame(
            [ScopeKind::Area],
            $this->em->getRepository(Position::class)->findOneBy(['name' => 'Sergeant'])?->getAllowedKinds(),
        );
    }

    /** The organization holds one of each name, and the refusal says so. */
    public function testASecondPositionOfTheSameNameIsRefusedInTheOrganizationsWords(): void
    {
        $this->administrator();
        $this->position('Sergeant');
        $this->em->flush();

        $crawler = $this->client->request('GET', '/team/configure');
        $form = $crawler->filter('#add form')->form();
        $form['name'] = 'Sergeant';
        $this->client->submit($form);

        self::assertStringContainsString(
            'already has a position called',
            (string) $this->client->followRedirect()->filter('.flashes')->text(),
        );
    }
}
