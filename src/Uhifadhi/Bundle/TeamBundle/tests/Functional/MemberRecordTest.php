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
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Bundle\TeamBundle\Enum\TeamRoleEnum;
use Uhifadhi\Bundle\TeamBundle\Tests\Integration\Fixtures\FakePersonPostings;

/**
 * ONE PERSON'S RECORD, and the four things it can be asked to change.
 *
 * The states worth asserting are the ones the design argues about: the REFUSAL
 * on the last active Super Admin — printed with its reason where the control
 * would have been, rather than greyed out — the deliberately ABSENT delete, the
 * SA-grant warning at the grant, and the invitation facts line, which appears
 * only beside "never signed in" and reads differently for an account nobody
 * invited.
 */
final class MemberRecordTest extends WebTestCaseWithSchema
{
    /** A second Super Admin, so the invariant is not in the way of ordinary tests. */
    private function withSuccessor(): User
    {
        $naomi = $this->person('Naomi', 'Kileo', TeamRoleEnum::SuperAdmin);
        $this->person('Asha', 'Mollel', TeamRoleEnum::SuperAdmin);
        $this->em->flush();
        $this->client->loginUser($naomi);

        return $naomi;
    }

    public function testTheRecordRenders(): void
    {
        $this->withSuccessor();
        $grace = $this->person('Grace', 'Ndosi');
        $this->em->flush();

        $crawler = $this->client->request('GET', '/team/'.$grace->getUuidString());

        self::assertResponseIsSuccessful();
        self::assertSame('Grace Ndosi', $crawler->filter('h1.pg')->text());
    }

    /**
     * EVERY CARD SITS IN A ROW OF THE PAGE GRID — the same rule the department
     * lens is held to. `.c` is a plate that declares no margin, `.grid` is the
     * composition that declares the 20px between two of them, and a card written
     * straight into the page body has to invent a spacing of its own.
     */
    /** EVERY CARD ON THE RECORD SITS IN ONE OF THE TWO COLUMNS of the record grid, and each wears its kicker. */
    public function testEveryCardOnTheRecordSitsInTheColumnOrTheRail(): void
    {
        $this->withSuccessor();
        $grace = $this->person('Grace', 'Ndosi');
        $this->em->flush();

        $crawler = $this->client->request('GET', '/team/'.$grace->getUuidString());
        $cards = $crawler->filter('.recgrid .col > .c');
        self::assertGreaterThanOrEqual(3, $cards->count(), 'the record draws a card per section');
        self::assertSame($cards->count(), $crawler->filter('.pgbody .c')->count(), 'and none outside the grid');
        foreach ($cards as $node) {
            self::assertNotSame('', trim((new Crawler($node))->filter('.tab')->text('')), 'every card wears its kicker');
        }
    }

    /** THE SIDE COLUMN CARRIES WHERE THEY ARE STATIONED, THEN THE HISTORY; the account actions live on the configure page. */
    public function testTheRailCarriesTheHistoryAndTheAccountActions(): void
    {
        $this->withSuccessor();
        $grace = $this->person('Grace', 'Ndosi');
        $this->em->flush();

        $crawler = $this->client->request('GET', '/team/'.$grace->getUuidString());
        self::assertSame(
            ['Stationed at', 'History'],
            $crawler->filter('.recgrid .col')->eq(1)->filter('.c > .tab')->each(
                static fn (Crawler $c): string => trim(str_replace($c->filter('.src')->text(''), '', $c->text())),
            ),
        );
        self::assertCount(0, $crawler->filter('.mb-danger'), 'No account action on the record.');

        $crawler = $this->client->request('GET', '/team/'.$grace->getUuidString().'/configure');
        self::assertCount(1, $crawler->filter('.recgrid .col')->eq(1)->filter('.mb-danger'));
    }

    /**
     * THE HISTORY IS DERIVED FROM STORED FACTS, newest first — there is no
     * audit trail in this release, so the card states what the model can date
     * and nothing it cannot.
     */
    public function testTheHistoryStatesWhatTheModelCanDate(): void
    {
        $naomi = $this->withSuccessor();
        $joseph = $this->person('Joseph', 'Mrema')->setVerified(false);
        $joseph->markInvitedBy($naomi);
        $this->em->flush();

        $crawler = $this->client->request('GET', '/team/'.$joseph->getUuidString());
        $lines = $crawler->filter('.hlist .hrow .t')->each(static fn (Crawler $c): string => $c->text());

        self::assertContains('Invited', $lines);
        self::assertContains('Account created', $lines);
    }

    /**
     * AN INVITATION NOBODY OPENED IS CHASED FROM THE RECORD, and the control
     * is OFFERED AND REFUSED where there is no transport — visible, inert,
     * with the reason on it. Hiding it would leave an administrator hunting
     * for a feature the product has; swallowing the click would leave a
     * colleague waiting for an email nobody sent.
     */
    public function testAnInvitationCanBeChasedFromTheRecordAndSaysWhenItCannotBeSent(): void
    {
        $naomi = $this->withSuccessor();
        $joseph = $this->person('Joseph', 'Mrema')->setVerified(false);
        $joseph->markInvitedBy($naomi);
        $this->em->flush();

        $crawler = $this->client->request('GET', '/team/'.$joseph->getUuidString().'/configure');
        $button = $crawler->filter('.mb-drow form[action$="/invite-again"] button[type="submit"]')->first();

        self::assertStringContainsString('Send the link again', $button->text());
        // This kernel configures no mailer, which is the state a fresh
        // installation is in until somebody sets MAILER_DSN.
        self::assertNotNull($button->attr('disabled'));
        self::assertStringContainsString('MAILER_DSN', (string) $button->attr('title'));
    }

    /** And with no transport the write refuses rather than discarding silently. */
    public function testChasingAnInvitationWithNoMailerRefusesInsteadOfDiscarding(): void
    {
        $naomi = $this->withSuccessor();
        $joseph = $this->person('Joseph', 'Mrema')->setVerified(false);
        $joseph->markInvitedBy($naomi);
        $this->em->flush();
        $first = $joseph->getVerificationToken();

        $this->client->request('POST', '/team/'.$joseph->getUuidString().'/invite-again', [
            '_token' => $this->tokenFrom('/team/'.$joseph->getUuidString().'/configure'),
        ]);

        self::assertResponseRedirects();
        self::assertStringContainsString('No invitation was sent.', $this->client->followRedirect()->text());
        $this->em->clear();
        $again = $this->em->getRepository(User::class)->find($joseph->getId());
        self::assertInstanceOf(User::class, $again);
        self::assertSame($first, $again->getVerificationToken(), 'a refused resend rotated the token anyway');
    }

    /**
     * NOT OFFERED ONCE THEY HAVE SIGNED IN: the token is spent, the account is
     * theirs, and the way back in is a password reset.
     */
    public function testSomebodyWhoHasSignedInIsOfferedAResetAndNotAnInvitation(): void
    {
        $this->withSuccessor();
        $grace = $this->person('Grace', 'Ndosi');
        $this->em->flush();

        $crawler = $this->client->request('GET', '/team/'.$grace->getUuidString().'/configure');

        self::assertStringNotContainsString('Send the link again', $crawler->filter('.pgbody')->text());
        self::assertStringContainsString('Send a password-reset link', $crawler->filter('.mb-danger')->text());
        self::assertCount(0, $crawler->filter('.mb-drow form[action$="/invite-again"]'));
    }

    /**
     * A SCREEN DOES NOT NAME AN ACTION THAT DOES NOT EXIST. The record once
     * drew a "delete this person" row as deliberately absent; naming the
     * absent action only teaches a reader to look for it. Accounts are
     * deactivated, kept and listed, and that is the only thing the card says.
     */
    public function testTheRecordNamesNoDeleteAtAll(): void
    {
        $this->withSuccessor();
        $grace = $this->person('Grace', 'Ndosi');
        $this->em->flush();

        $crawler = $this->client->request('GET', '/team/'.$grace->getUuidString());

        self::assertCount(0, $crawler->filter('.mb-drow.absent'));
        self::assertStringNotContainsString('Delete this person', $crawler->filter('.pgbody')->text());
        self::assertStringNotContainsString('Recycle bin', $crawler->filter('.pgbody')->text());
    }

    public function testThereIsNoDeleteRouteEither(): void
    {
        $this->withSuccessor();
        $grace = $this->person('Grace', 'Ndosi');
        $this->em->flush();

        $this->client->request('POST', '/team/'.$grace->getUuidString().'/delete');

        self::assertResponseStatusCodeSame(404);
    }

    public function testDeactivatingKeepsTheRowAndStampsTheMoment(): void
    {
        $this->withSuccessor();
        $grace = $this->person('Grace', 'Ndosi');
        $this->em->flush();

        $token = $this->tokenFrom('/team/'.$grace->getUuidString().'/configure');
        $this->client->request('POST', '/team/'.$grace->getUuidString().'/deactivate', ['_token' => $token]);

        self::assertResponseRedirects();
        $this->em->clear();
        $stored = $this->em->getRepository(User::class)->findOneBy(['email' => 'g.ndosi@example.test']);
        self::assertInstanceOf(User::class, $stored, 'The row is not deleted.');
        self::assertFalse($stored->isActive());
        self::assertNotNull($stored->getDisabledAt());

        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('Nothing has been deleted', $crawler->html());
    }

    public function testReactivatingClearsTheStamp(): void
    {
        $this->withSuccessor();
        $grace = $this->person('Grace', 'Ndosi');
        $grace->deactivate();
        $this->em->flush();

        $token = $this->tokenFrom('/team/'.$grace->getUuidString().'/configure');
        $this->client->request('POST', '/team/'.$grace->getUuidString().'/reactivate', ['_token' => $token]);

        $this->em->clear();
        $stored = $this->em->getRepository(User::class)->findOneBy(['email' => 'g.ndosi@example.test']);
        self::assertInstanceOf(User::class, $stored);
        self::assertTrue($stored->isActive());
        self::assertNull($stored->getDisabledAt());
    }

    /**
     * THE REFUSAL, PRINTED WITH ITS REASON — not a greyed-out control that says
     * "not now" and leaves the reader guessing.
     */
    public function testTheLastActiveSuperAdminSeesTheRefusalWithItsReason(): void
    {
        $naomi = $this->person('Naomi', 'Kileo', TeamRoleEnum::SuperAdmin);
        $this->em->flush();
        $this->client->loginUser($naomi);

        $crawler = $this->client->request('GET', '/team/'.$naomi->getUuidString().'/configure');

        self::assertCount(1, $crawler->filter('.mb-refuse'));
        self::assertStringContainsString('Refused — last active Super Admin', $crawler->filter('.mb-refuse')->text());
        self::assertStringContainsString('Naomi Kileo is the only one', $crawler->filter('.mb-refuse')->text());
        // And the deactivate control is replaced by the refusal, not disabled.
        self::assertStringContainsString('refused — the last active Super Admin', $crawler->html());
    }

    public function testDemotingTheLastActiveSuperAdminIsRefusedOnSubmitToo(): void
    {
        $naomi = $this->person('Naomi', 'Kileo', TeamRoleEnum::SuperAdmin);
        $this->em->flush();
        $this->client->loginUser($naomi);

        $token = $this->tokenFrom('/team/'.$naomi->getUuidString().'/configure');
        $this->client->request('POST', '/team/'.$naomi->getUuidString().'/tier', [
            '_token' => $token, 'tier' => 'staff',
        ]);

        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('only active Super Admin', $crawler->html());

        $this->em->clear();
        $stored = $this->em->getRepository(User::class)->findOneBy(['email' => 'n.kileo@example.test']);
        self::assertInstanceOf(User::class, $stored);
        self::assertSame(TeamRoleEnum::SuperAdmin, $stored->getTeamRole(), 'Nothing was written.');
    }

    public function testDeactivatingTheLastActiveSuperAdminIsRefusedOnSubmitToo(): void
    {
        $naomi = $this->person('Naomi', 'Kileo', TeamRoleEnum::SuperAdmin);
        $this->em->flush();
        $this->client->loginUser($naomi);

        $token = $this->tokenFrom('/team/'.$naomi->getUuidString().'/configure');
        $this->client->request('POST', '/team/'.$naomi->getUuidString().'/deactivate', ['_token' => $token]);

        $this->client->followRedirect();
        $this->em->clear();
        $stored = $this->em->getRepository(User::class)->findOneBy(['email' => 'n.kileo@example.test']);
        self::assertInstanceOf(User::class, $stored);
        self::assertTrue($stored->isActive(), 'Nothing was written.');
    }

    /** TRANSFER, THEN LEAVE — and the refusal lifts in the same moment. */
    public function testGrantingItToASuccessorLiftsTheRefusal(): void
    {
        $naomi = $this->withSuccessor();

        $crawler = $this->client->request('GET', '/team/'.$naomi->getUuidString().'/configure');

        self::assertCount(0, $crawler->filter('.mb-refuse'));
    }

    /** THE WARNING LIVES AT THE GRANT — beside somebody who is not yet one. */
    public function testTheSuperAdminGrantCarriesItsWarning(): void
    {
        $this->withSuccessor();
        $grace = $this->person('Grace', 'Ndosi');
        $this->em->flush();

        $crawler = $this->client->request('GET', '/team/'.$grace->getUuidString().'/configure');

        self::assertCount(1, $crawler->filter('.mb-grant'));
        self::assertStringContainsString('every permission you hold', $crawler->filter('.mb-grant')->text());
        self::assertStringContainsString('including this one', strtolower($crawler->filter('.mb-grant')->text()));
    }

    /** And it is absent where there is nothing to warn about. */
    public function testSomebodyAlreadySuperAdminGetsNoGrantWarning(): void
    {
        $naomi = $this->withSuccessor();

        $crawler = $this->client->request('GET', '/team/'.$naomi->getUuidString().'/configure');

        self::assertCount(0, $crawler->filter('.mb-grant'));
    }

    /**
     * THE INVITATION FACTS LINE reads differently for the two ways an account
     * comes to exist, and appears only beside "never signed in".
     */
    public function testAnInvitedAccountNamesWhoInvitedThem(): void
    {
        $naomi = $this->withSuccessor();
        $joseph = $this->person('Joseph', 'Mrema')->setVerified(false);
        $joseph->markInvitedBy($naomi);
        $this->em->flush();

        $crawler = $this->client->request('GET', '/team/'.$joseph->getUuidString().'/configure');

        self::assertStringContainsString('Invited by Naomi Kileo', $crawler->filter('[data-signin-state]')->text());
    }

    public function testAnAccountCreatedDirectlySaysNobodyInvitedThem(): void
    {
        $this->withSuccessor();
        $hawa = $this->person('Hawa', 'Rajabu')->setVerified(false);
        $this->em->flush();

        $crawler = $this->client->request('GET', '/team/'.$hawa->getUuidString().'/configure');

        self::assertStringContainsString('Created with a password, handed over', $crawler->filter('[data-signin-state]')->text());
        self::assertStringContainsString('no invitation outstanding', $crawler->filter('[data-signin-state]')->text());
    }

    public function testSomebodyWhoHasArrivedGetsNoInvitationLine(): void
    {
        $this->withSuccessor();
        $grace = $this->person('Grace', 'Ndosi');
        $this->em->flush();

        $crawler = $this->client->request('GET', '/team/'.$grace->getUuidString().'/configure');

        self::assertStringContainsString('Signed in and verified', $crawler->filter('[data-signin-state]')->text());
        self::assertStringNotContainsString('invited by', $crawler->filter('[data-signin-state]')->text());
    }

    /** THE LEDGER LISTS WHAT IS GRANTED, THROUGH WHICH POSITION — and nothing that is not. */
    public function testTheEffectiveLedgerSaysWhyOnEveryRow(): void
    {
        $this->withSuccessor();
        $ranger = $this->position('Ranger', ['surveys.read']);
        $grace = $this->person('Grace', 'Ndosi');
        $grace->setPosition($ranger);
        $this->em->flush();

        $crawler = $this->client->request('GET', '/team/'.$grace->getUuidString());
        $ledger = $crawler->filter('.recgrid .col')->first()->filter('.c')->eq(1);

        self::assertStringContainsString('through', $ledger->filter('.pml-thru')->text());
        self::assertStringContainsString('Ranger', $ledger->filter('.pml-thru')->text());
        self::assertCount(1, $ledger->filter('.pmk-row'), 'Only what is granted is listed.');
        self::assertStringNotContainsString('not held', $ledger->text());
    }

    /** A TIER ABOVE THE MATRIX SAYS SO: every permission, by tier, whatever the position. */
    public function testATierAboveTheMatrixReadsByTierOnEveryRow(): void
    {
        $naomi = $this->withSuccessor();

        $crawler = $this->client->request('GET', '/team/'.$naomi->getUuidString());
        self::assertStringContainsString('by tier', $crawler->filter('.factband')->text());
        self::assertStringContainsString('Above the matrix', $crawler->filter('.recgrid')->text());
    }

    public function testAssigningAPositionWritesIt(): void
    {
        $this->withSuccessor();
        $ranger = $this->position('Ranger', ['surveys.read']);
        $frank = $this->person('Frank', 'Massawe');
        $this->em->flush();

        $token = $this->tokenFrom('/team/'.$frank->getUuidString().'/configure');
        $this->client->request('POST', '/team/'.$frank->getUuidString().'/position', [
            '_token' => $token, 'position' => $ranger->getUuidString(),
        ]);

        $this->em->clear();
        $stored = $this->em->getRepository(User::class)->findOneBy(['email' => 'f.massawe@example.test']);
        self::assertInstanceOf(User::class, $stored);
        self::assertSame('Ranger', $stored->getPosition()?->getName());
    }

    /**
     * THE PICKER IS ONE FLAT LIST. It used to be grouped into optgroups, one
     * per department, because a position was filed under one; the ruling took
     * the department off the position, so there is one Analyst in the
     * organization and a reader choosing from this list never has to ask
     * which.
     */
    public function testThePositionPickerIsOneFlatListWithNoDepartmentGroups(): void
    {
        $this->withSuccessor();
        $this->position('Ranger', ['surveys.read']);
        $this->position('Analyst');
        $frank = $this->person('Frank', 'Massawe');
        $this->em->flush();

        $crawler = $this->client->request('GET', '/team/'.$frank->getUuidString().'/configure');
        $picker = $crawler->filter('.mb-assignrow select[name="position"]');

        self::assertCount(0, $picker->filter('optgroup'), 'A position belongs to no department, so there is nothing to group by.');
        self::assertSame(
            ['— no position —', 'Analyst', 'Ranger'],
            $picker->filter('option')->each(static fn (Crawler $c): string => trim(explode('—', $c->text(), 2)[0]) ?: trim($c->text())),
        );
    }

    /**
     * THE RECORD READS AND NEVER WRITES. Ruled 21 Sep: changes happen on the
     * configure page; the record states the position, where it applies, the
     * departments, where the person is stationed, what that grants, and the
     * account's history — with no control at all.
     */
    public function testTheRecordCarriesNoControl(): void
    {
        $this->withSuccessor();
        $sergeant = $this->position('Sergeant', ['surveys.read']);
        $kilimani = $this->area('Kilimani Crater');
        $ecology = $this->department('Ecology');
        $frank = $this->person('Frank', 'Massawe');
        $frank->setPosition($sergeant);
        $this->place($frank, [$kilimani], [$ecology]);
        $this->em->flush();

        $crawler = $this->client->request('GET', '/team/'.$frank->getUuidString());
        self::assertResponseIsSuccessful();

        self::assertCount(0, $crawler->filter('.recgrid form'));
        self::assertCount(0, $crawler->filter('.recgrid input, .recgrid select, .recgrid button'));

        $sub = preg_replace('/\s+/', ' ', $crawler->filter('p.pgsub')->text()) ?? '';
        self::assertStringContainsString('Sergeant', $sub);
        self::assertStringContainsString('Ecology', $sub);

        $band = $crawler->filter('.factband .f .k')->each(static fn (Crawler $k): string => trim($k->text()));
        self::assertSame(['Reads', 'Records', 'Manages', 'Exports'], $band);
        self::assertStringContainsString('What Sergeant grants', $crawler->filter('.factband a.more')->text());

        $position = $crawler->filter('#position');
        self::assertCount(1, $position);
        self::assertStringContainsString('Sergeant', $position->filter('.pcard b')->first()->text());
        $pills = $position->filter('.pmx-sk.on')->each(static fn (Crawler $c): string => trim($c->text()));
        self::assertContains('Kilimani Crater', $pills);
        self::assertContains('Ecology', $pills);
        self::assertStringContainsString('Change the position', $position->filter('.pcard-foot a.ov-open')->text());
        self::assertStringEndsWith('/configure', (string) $position->filter('.pcard-foot a.ov-open')->attr('href'));

        // THE LEDGER: verbs, where, and through which position — per concern.
        $ledger = $crawler->filter('.recgrid .col')->first()->filter('.c')->eq(1);
        self::assertStringStartsWith('What that actually grants, right now', trim($ledger->filter('.tab')->text()));
        $row = $ledger->filter('.pmk-row.thru')->first();
        self::assertCount(1, $row);
        self::assertStringContainsString('read', $row->filter('.pmc.on')->text());
        self::assertStringContainsString('Kilimani Crater', $row->filter('.pmx-sc')->text());
        self::assertStringContainsString('Sergeant', $row->filter('.pml-thru')->text());
        self::assertStringContainsString('See it on the position', $ledger->filter('.pcard-foot a.ov-open')->text());

        // THE SIDE COLUMN: stationed at, then history.
        $side = $crawler->filter('.recgrid .col')->eq(1)->filter('.c > .tab')->each(static fn (Crawler $t): string => trim(explode('·', $t->text())[0]));
        self::assertSame(['Stationed at', 'History'], $side);
        self::assertCount(1, $crawler->filter('.recgrid .col')->eq(1)->filter('.c.stcard'));

        // AND THE DOOR OUT IS CONFIGURE, in the head.
        self::assertStringContainsString('Configure', $crawler->filter('.pgact')->text());
    }

    /**
     * WHERE THEY ARE STATIONED IS DRAWN, by whoever owns the ground: the card
     * carries the station's plate from the seam, the station's facts under
     * it, and the door to the station. Somebody stationed nowhere gets the
     * empty state, with no plate at all.
     */
    public function testTheStationedAtCardDrawsTheGroundFromWhoeverOwnsIt(): void
    {
        $this->withSuccessor();
        $grace = $this->person('Grace', 'Ndosi');
        $this->em->flush();
        FakePersonPostings::$stationed = [(string) $grace->getUuidString()];

        try {
            $crawler = $this->client->request('GET', '/team/'.$grace->getUuidString());
            self::assertResponseIsSuccessful();
            $card = $crawler->filter('.c.stcard');
            self::assertCount(1, $card->filter('[data-fixture-plate="'.FakePersonPostings::STATION.'"]'), 'The plate the ground drew is on the card.');
            self::assertStringContainsString('Eastgate Post', $card->text());
            self::assertStringContainsString('ST-01', $card->text());
            self::assertStringContainsString('The station', $card->filter('.stcard-foot a.ov-open')->text());
            self::assertStringContainsString('stationed at Eastgate Post', $crawler->filter('p.pgsub')->text());
        } finally {
            FakePersonPostings::$stationed = [];
        }
    }

    /** THE EMPTY STATE: holds no position, nothing granted, a door to give one — and no ledger card at all. */
    public function testAPersonWithNoPositionReadsTheEmptyState(): void
    {
        $this->withSuccessor();
        $grace = $this->person('Grace', 'Ndosi');
        $this->em->flush();

        $crawler = $this->client->request('GET', '/team/'.$grace->getUuidString());

        self::assertStringContainsString('Holds no position', $crawler->filter('p.pgsub')->text());
        self::assertStringStartsWith('—', trim($crawler->filter('.factband .f .v')->first()->text()), 'Read is granted, never assumed: a dash.');
        self::assertStringContainsString('The positions register', $crawler->filter('.factband a.more')->text());

        $position = $crawler->filter('#position');
        self::assertStringContainsString('Holds no position.', $position->filter('.pmx-zero')->text());
        self::assertStringContainsString('Give a position', $position->filter('.pcard-foot a.ov-open')->text());

        self::assertCount(1, $crawler->filter('.recgrid .col')->first()->filter('.c'), 'No ledger card for a person who holds nothing.');
    }

    /**
     * THE FLASH NAMES THE BARE POSITION — the whole name there is. It used to
     * print "Protection Service / Ranger", and half of that is a fact about a
     * post that no longer exists.
     */
    public function testTheFlashNamesTheBarePosition(): void
    {
        $this->withSuccessor();
        $ranger = $this->position('Ranger', ['surveys.read']);
        $frank = $this->person('Frank', 'Massawe');
        $this->em->flush();

        $token = $this->tokenFrom('/team/'.$frank->getUuidString().'/configure');
        $this->client->request('POST', '/team/'.$frank->getUuidString().'/position', [
            '_token' => $token, 'position' => $ranger->getUuidString(),
        ]);

        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('Frank Massawe now holds Ranger.', $crawler->html());
    }

    /**
     * A FULL POST REFUSES AT THE DOOR, AND THE REFUSAL NAMES THE HOLDER.
     *
     * The seat count is enforced in the service so that no door can forget to
     * ask; what THIS holds is that the door reads the refusal back as a
     * sentence rather than letting it out as a 500. The message is the whole
     * point of the exception — "that position is full" is not actionable and
     * "Joseph Mollel holds it" is, because the administrator's next move is to
     * end that holding or pick another position and they cannot choose without
     * the name.
     */
    public function testAFullPositionIsRefusedAndTheRefusalNamesWhoHoldsIt(): void
    {
        $this->withSuccessor();
        $head = $this->position('Head of Protection', ['surveys.read']);
        $head->setSeatCount(1);
        $joseph = $this->person('Joseph', 'Mollel');
        $joseph->setPosition($head);
        $frank = $this->person('Frank', 'Massawe');
        $this->em->flush();

        $token = $this->tokenFrom('/team/'.$frank->getUuidString().'/configure');
        $this->client->request('POST', '/team/'.$frank->getUuidString().'/position', [
            '_token' => $token, 'position' => $head->getUuidString(),
        ]);

        // A REDIRECT AND NOT A CRASH. Asserting the flash alone would pass on
        // a page that had already fallen over on the way to rendering it.
        self::assertTrue($this->client->getResponse()->isRedirect());
        $crawler = $this->client->followRedirect();
        self::assertResponseIsSuccessful();

        $html = $crawler->html();
        self::assertStringContainsString('Head of Protection', $html);
        self::assertStringContainsString('Joseph Mollel', $html);
        self::assertStringContainsString('one seat', $html);

        // AND NOBODY WAS SEATED. A refusal that still wrote would be worse
        // than no refusal, because the page would say it had not.
        $this->em->clear();
        $stored = $this->em->getRepository(User::class)->findOneBy(['email' => 'f.massawe@example.test']);
        self::assertInstanceOf(User::class, $stored);
        self::assertNull($stored->getPosition(), 'the refused assignment left Frank holding nothing.');
    }

    /**
     * A DEACTIVATED HOLDER DOES NOT OCCUPY A SEAT. Somebody who has left is
     * kept on the roster rather than deleted, and a singular post whose only
     * holder left is a post that stands empty — a seat count that counted
     * former holders would make every one-seat position unfillable the first
     * time somebody moved on.
     */
    public function testSomebodyWhoHasLeftFreesTheSeatTheyHeld(): void
    {
        $this->withSuccessor();
        $head = $this->position('Head of Protection', ['surveys.read']);
        $head->setSeatCount(1);
        $joseph = $this->person('Joseph', 'Mollel');
        $joseph->setPosition($head);
        $joseph->deactivate();
        $frank = $this->person('Frank', 'Massawe');
        $this->em->flush();

        $token = $this->tokenFrom('/team/'.$frank->getUuidString().'/configure');
        $this->client->request('POST', '/team/'.$frank->getUuidString().'/position', [
            '_token' => $token, 'position' => $head->getUuidString(),
        ]);

        $this->em->clear();
        $stored = $this->em->getRepository(User::class)->findOneBy(['email' => 'f.massawe@example.test']);
        self::assertInstanceOf(User::class, $stored);
        self::assertSame('Head of Protection', $stored->getPosition()?->getName());
    }

    /** "No position" is a real choice, and the flash says what it costs. */
    public function testTakingThePositionAwayIsARealChoice(): void
    {
        $this->withSuccessor();
        $ranger = $this->position('Ranger', ['surveys.read']);
        $grace = $this->person('Grace', 'Ndosi');
        $grace->setPosition($ranger);
        $this->em->flush();

        $token = $this->tokenFrom('/team/'.$grace->getUuidString().'/configure');
        $this->client->request('POST', '/team/'.$grace->getUuidString().'/position', [
            '_token' => $token, 'position' => '',
        ]);

        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('no permissions at all', $crawler->html());
    }

    public function testTheRecordFieldsSave(): void
    {
        $this->withSuccessor();
        $grace = $this->person('Grace', 'Ndosi');
        $this->em->flush();

        $token = $this->tokenFrom('/team/'.$grace->getUuidString().'/configure');
        $this->client->request('POST', '/team/'.$grace->getUuidString(), [
            '_token' => $token,
            'firstName' => 'Grace',
            'lastName' => 'Ndosi-Mwangi',
            'email' => 'G.Ndosi@Example.TEST',
            'rangerCode' => 'R-104',
        ]);

        $this->em->clear();
        $stored = $this->em->getRepository(User::class)->findOneBy(['rangerCode' => 'r-104']);
        self::assertInstanceOf(User::class, $stored);
        self::assertSame('Grace Ndosi-Mwangi', $stored->getFullName());
        self::assertSame('g.ndosi@example.test', $stored->getEmail(), 'The entity folds the email itself.');
    }

    /**
     * PERSONAL DETAILS ARE THEIR OWN CONCERN, AND THE PAGE PROVES IT. Somebody
     * holding `directory.read` and NOT `personal-details.read` may know that
     * Grace is on the team, what she is called and what position she holds,
     * and may not read how to reach her or what her account is doing. The
     * page stays open and the half of it that is about the person shuts,
     * which is the whole reason the two were declared separately.
     */
    public function testAReaderWithoutPersonalDetailsSeesThePersonAndNotTheirContactDetails(): void
    {
        $this->person('Naomi', 'Kileo', TeamRoleEnum::SuperAdmin);
        $colleague = $this->person('Asha', 'Mollel');
        $colleague->setPosition($this->position('Duty Officer', ['directory.read']));
        // A grant is only held somewhere, so the reader is placed across the
        // organization: what shuts the contact block is the missing pair and
        // nothing about where they stand.
        $this->place($colleague);

        $grace = $this->person('Grace', 'Ndosi');
        $grace->setPosition($this->position('Ranger', ['surveys.read']));
        $this->em->flush();
        $address = (string) $grace->getEmail();
        $this->client->loginUser($colleague);

        $crawler = $this->client->request('GET', '/team/'.$grace->getUuidString());

        self::assertResponseIsSuccessful();
        self::assertSame('Grace Ndosi', $crawler->filter('h1.pg')->text());
        self::assertStringContainsString('Ranger', $crawler->filter('p.pgsub')->text(), 'Who they are and what they do is the directory.');

        self::assertStringNotContainsString($address, $crawler->html(), 'The address is a contact detail and this reader may not read one.');
        self::assertStringNotContainsString('sign-in state', $crawler->html(), 'And neither is what their account is doing.');
        self::assertCount(0, $crawler->filter('input[name="email"]'), 'Nor written, which would print it just the same.');
    }

    /**
     * AND THE SAME PAGE, READ BY SOMEBODY WHO HOLDS BOTH, carries all of it —
     * so the assertions above are about the missing pair and not about a card
     * that stopped rendering for everybody.
     */
    public function testAReaderHoldingPersonalDetailsSeesTheContactDetails(): void
    {
        $this->withSuccessor();
        $grace = $this->person('Grace', 'Ndosi');
        $this->em->flush();
        $address = (string) $grace->getEmail();

        $crawler = $this->client->request('GET', '/team/'.$grace->getUuidString().'/configure');

        self::assertStringContainsString($address, $crawler->html());
        self::assertStringContainsString('data-signin-state', $crawler->html());
        self::assertCount(1, $crawler->filter('input[name="email"]'));
    }

    /** Impersonation is offered to a Super Admin only; for anybody else, absent. */
    public function testSwitchUserIsAbsentForAnAdministratorWhoIsNotASuperAdmin(): void
    {
        $this->person('Naomi', 'Kileo', TeamRoleEnum::SuperAdmin);
        $senior = $this->position('Senior Ranger', ['directory.read']);
        $grace = $this->person('Grace', 'Ndosi');
        $grace->setPosition($senior);
        // THE POSITION GRANTS AND THE PLACEMENT REACHES, and the model fails
        // closed: somebody holding `directory.read` with nowhere to exercise
        // it reaches no ground at all, so the administrator in this scene is
        // placed across the organization before they read anything.
        $this->place($grace);
        $target = $this->person('Zawadi', 'Naisenya');
        $this->em->flush();
        $this->client->loginUser($grace);

        $crawler = $this->client->request('GET', '/team/'.$target->getUuidString());

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('_switch_user', $crawler->html());
    }
}
