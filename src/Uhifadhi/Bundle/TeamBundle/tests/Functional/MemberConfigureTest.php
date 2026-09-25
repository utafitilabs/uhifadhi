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
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Bundle\TeamBundle\Enum\TeamRoleEnum;
use Uhifadhi\Contracts\Access\ScopeKind;

/**
 * THE PERSON'S CONFIGURE PAGE — the record mirrored, and everything that
 * writes. Ruled 21 Sep: the record reads, configure writes; one save bar per
 * card; where is the organization OR named areas, departments all OR a chosen
 * few; the account actions live here beside the sign-in they act on.
 */
final class MemberConfigureTest extends WebTestCaseWithSchema
{
    private function signedInAdministrator(): User
    {
        $naomi = $this->person('Naomi', 'Kileo', TeamRoleEnum::SuperAdmin);
        $this->person('Asha', 'Mollel', TeamRoleEnum::SuperAdmin);
        $this->em->flush();
        $this->client->loginUser($naomi);

        return $naomi;
    }

    private function configureUrl(User $person): string
    {
        return '/team/'.$person->getUuidString().'/configure';
    }

    /** THE PAGE IS THE RECORD MIRRORED: the same head, three writing cards, the side column. */
    public function testTheConfigurePageCarriesTheWritingCardsAndTheSideColumn(): void
    {
        $this->signedInAdministrator();
        $grace = $this->person('Grace', 'Ndosi');
        $this->em->flush();

        $crawler = $this->client->request('GET', $this->configureUrl($grace));
        self::assertResponseIsSuccessful();

        self::assertSame('Grace Ndosi', $crawler->filter('h1.pg')->text());
        self::assertStringContainsString('Configure', $crawler->filter('p.pgsub')->text());
        self::assertCount(0, $crawler->filter('.atabs'), 'A record\'s configure page has no tabs.');

        $tabs = $crawler->filter('.recgrid .col')->first()->filter('.c > .tab')->each(static fn (Crawler $t): string => trim(explode('·', $t->text())[0]));
        self::assertSame(['Details', 'Sign-in', 'Position', 'Rank'], $tabs);

        $side = $crawler->filter('.recgrid .col')->eq(1)->filter('.c > .tab')->each(static fn (Crawler $t): string => trim(explode('·', $t->text())[0]));
        self::assertSame(['Stationed at', 'Account', 'History'], $side);

        // ONE SAVE BAR PER CARD, each with Discard beside its save.
        $bars = $crawler->filter('.recgrid .col')->first()->filter('.staddrow');
        self::assertCount(4, $bars);
        self::assertSame(
            ['Save the details', 'Save the sign-in', 'Save the position', 'Save the rank'],
            $bars->each(static fn (Crawler $b): string => trim($b->filter('button.cta')->text())),
        );
        self::assertCount(4, $bars->filter('button.btn:contains("Discard")'));

        self::assertStringContainsString('The record', $crawler->filter('.pgact')->text());
    }

    /** WHERE IS THE ORGANIZATION OR NAMED AREAS; departments all or a chosen few — as chips, only the kinds the position allows. */
    public function testThePositionCardOffersWhereAndDepartmentsAsChips(): void
    {
        $this->signedInAdministrator();
        $sergeant = $this->position('Sergeant', ['surveys.read'], [ScopeKind::Organization, ScopeKind::Area]);
        $this->area('Kilimani Crater');
        $this->area('Olkeju');
        $this->department('Ecology');
        $this->department('Protection Service');
        $frank = $this->person('Frank', 'Massawe');
        $frank->setPosition($sergeant);
        $this->em->flush();

        $crawler = $this->client->request('GET', $this->configureUrl($frank));

        $picker = $crawler->filter('.mb-assignrow select[name="position"]');
        self::assertCount(1, $picker);
        self::assertCount(0, $picker->filter('optgroup'));

        $chips = $crawler->filter('.pwchips label.pmb');
        $labels = $chips->each(static fn (Crawler $c): string => trim(preg_replace('/\s+/', ' ', $c->text()) ?? ''));
        self::assertContains('The whole organization every area at once', $labels);
        self::assertContains('Kilimani Crater', $labels);
        self::assertContains('Olkeju', $labels);
        self::assertContains('All departments', $labels);
        self::assertContains('Ecology', $labels);
        self::assertContains('Protection Service', $labels);
        self::assertCount(1, $crawler->filter('.pwchips input[name="where"][value="organization"]'));
        self::assertCount(2, $crawler->filter('.pwchips input[name="areas[]"]'));
        self::assertCount(2, $crawler->filter('.pwchips input[name="departments[]"]'));
    }

    /** A POSITION THAT ALLOWS ONLY AREAS OFFERS NO ORGANIZATION CHIP — absent, not disabled. */
    public function testAKindThePositionDoesNotAllowIsAbsent(): void
    {
        $this->signedInAdministrator();
        $ranger = $this->position('Ranger', ['surveys.read'], [ScopeKind::Area]);
        $this->area('Kilimani Crater');
        $frank = $this->person('Frank', 'Massawe');
        $frank->setPosition($ranger);
        $this->em->flush();

        $crawler = $this->client->request('GET', $this->configureUrl($frank));

        self::assertCount(0, $crawler->filter('.pwchips input[name="where"][value="organization"]'));
        self::assertCount(1, $crawler->filter('.pwchips input[name="areas[]"]'));
        self::assertCount(0, $crawler->filter('.pwchips input[disabled]'));
    }

    /** SAVING THE POSITION WRITES THE SEAT, WHERE, AND THE DEPARTMENTS in one go. */
    public function testSavingThePositionWritesTheSeatWhereAndTheDepartments(): void
    {
        $this->signedInAdministrator();
        $sergeant = $this->position('Sergeant', ['surveys.read']);
        $kilimani = $this->area('Kilimani Crater');
        $this->area('Olkeju');
        $ecology = $this->department('Ecology');
        $protection = $this->department('Protection Service');
        $this->department('ICT');
        $frank = $this->person('Frank', 'Massawe');
        $this->em->flush();

        $token = $this->tokenFrom($this->configureUrl($frank), 'form[action$="/position"] input[name="_token"]');
        $this->client->request('POST', '/team/'.$frank->getUuidString().'/position', [
            '_token' => $token,
            'position' => $sergeant->getUuidString(),
            'where' => 'areas',
            'areas' => [$kilimani->getUuidString()],
            'departments' => [$ecology->getUuidString(), $protection->getUuidString()],
            'return' => 'configure',
        ]);
        self::assertResponseRedirects($this->configureUrl($frank));

        $this->em->clear();
        $stored = $this->em->getRepository(User::class)->find($frank->getId());
        self::assertInstanceOf(User::class, $stored);
        self::assertSame('Sergeant', $stored->getPosition()?->getName());
        $placement = $stored->getPlacement();
        self::assertNotNull($placement);
        self::assertFalse($placement->isWholeOrganization());
        self::assertSame(['Kilimani Crater'], array_map(static fn ($a): string => (string) $a->getName(), $placement->getAreas() ?? []));
        self::assertSame('Ecology +1', $placement->departmentsLabel());
    }

    /** THE WHOLE ORGANIZATION AND ALL DEPARTMENTS are each one word, and win over the named ones. */
    public function testTheWholeOrganizationAndAllDepartmentsAreEachOneChoice(): void
    {
        $this->signedInAdministrator();
        $sergeant = $this->position('Sergeant', ['surveys.read']);
        $kilimani = $this->area('Kilimani Crater');
        $ecology = $this->department('Ecology');
        $frank = $this->person('Frank', 'Massawe');
        $this->em->flush();

        $token = $this->tokenFrom($this->configureUrl($frank), 'form[action$="/position"] input[name="_token"]');
        $this->client->request('POST', '/team/'.$frank->getUuidString().'/position', [
            '_token' => $token,
            'position' => $sergeant->getUuidString(),
            'where' => 'organization',
            'areas' => [$kilimani->getUuidString()],
            'all_departments' => '1',
            'departments' => [$ecology->getUuidString()],
        ]);

        $this->em->clear();
        $placement = $this->em->getRepository(User::class)->find($frank->getId())?->getPlacement();
        self::assertNotNull($placement);
        self::assertTrue($placement->isWholeOrganization());
        self::assertTrue($placement->isAllDepartments());
    }

    /** A FULL POSITION IS LISTED WITH ITS HOLDER AND REFUSED, naming who holds it. */
    public function testAFullPositionIsListedWithItsHolderAndRefused(): void
    {
        $this->signedInAdministrator();
        $chief = $this->position('Chief Warden', ['surveys.read']);
        $chief->setSeatCount(1);
        $holder = $this->person('Sarah', 'Kimaro');
        $holder->setPosition($chief);
        $frank = $this->person('Frank', 'Massawe');
        $this->em->flush();

        $crawler = $this->client->request('GET', $this->configureUrl($frank));
        $option = $crawler->filter('.mb-assignrow select[name="position"] option:contains("Chief Warden")');
        self::assertCount(1, $option);
        self::assertStringContainsString('held by S. Kimaro', $option->text());
        self::assertStringContainsString('full', $option->text());
        self::assertNotNull($option->attr('disabled'), 'A full position is listed and refused, never hidden.');

        $token = $this->tokenFrom($this->configureUrl($frank), 'form[action$="/position"] input[name="_token"]');
        $this->client->request('POST', '/team/'.$frank->getUuidString().'/position', [
            '_token' => $token,
            'position' => $chief->getUuidString(),
            'where' => 'organization',
            'all_departments' => '1',
            'return' => 'configure',
        ]);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flashes', 'Sarah Kimaro');
    }

    /**
     * WHILE A SESSION IS BORROWED, EVERY PAGE WEARS THE BAND: whose session it
     * is, whose it really is, and the one exit — which lands on the dashboard.
     * Nobody else ever sees it.
     */
    public function testABorrowedSessionWearsTheBandWithItsExitOnEveryPage(): void
    {
        $this->signedInAdministrator();
        $grace = $this->person('Grace', 'Ndosi');
        $grace->setPosition($this->position('Reader', [TeamConcerns::DIRECTORY.'.read']));
        $this->place($grace);
        $this->em->flush();

        self::assertCount(0, $this->client->request('GET', '/team')->filter('.impband'), 'No band on an ordinary session.');

        $this->client->request('GET', '/team?_switch_user='.urlencode((string) $grace->getEmail()));
        $this->client->followRedirect();
        $crawler = $this->client->request('GET', '/team');
        self::assertResponseIsSuccessful();

        $band = $crawler->filter('.impband');
        self::assertCount(1, $band);
        self::assertStringContainsString('Signed in as Grace Ndosi', preg_replace('/\s+/', ' ', $band->text()) ?? '');
        self::assertStringContainsString('you are Naomi Kileo', preg_replace('/\s+/', ' ', $band->text()) ?? '');
        self::assertSame('/?_switch_user=_exit', $band->filter('a.impx')->attr('href'));
    }

    /** THE DETAILS CARD SAVES THE FOUR COLUMNS and comes back to this page. */
    public function testTheDetailsCardSavesAndReturnsHere(): void
    {
        $this->signedInAdministrator();
        $grace = $this->person('Grace', 'Ndosi');
        $this->em->flush();

        $crawler = $this->client->request('GET', $this->configureUrl($grace));
        $form = $crawler->filter('form[action$="/'.$grace->getUuidString().'"]')->form();
        $form['lastName'] = 'Ndosi-Mwangi';
        $form['rangerCode'] = 'R-101';
        $this->client->submit($form);
        self::assertResponseRedirects($this->configureUrl($grace));

        $this->em->clear();
        $stored = $this->em->getRepository(User::class)->find($grace->getId());
        self::assertInstanceOf(User::class, $stored);
        self::assertSame('Grace Ndosi-Mwangi', $stored->getFullName());
        self::assertSame('r-101', $stored->getRangerCode(), 'The entity folds the code itself.');
    }

    /** THE ACCOUNT ACTIONS LIVE HERE, graded: reset quiet, switch warning, deactivate danger. */
    public function testTheAccountActionsLiveHereAndWearTheirGrade(): void
    {
        $this->signedInAdministrator();
        $grace = $this->person('Grace', 'Ndosi');
        $this->em->flush();

        $crawler = $this->client->request('GET', $this->configureUrl($grace));
        $card = $crawler->filter('.recgrid .col')->eq(1)->filter('.c')->eq(1);

        self::assertStringStartsWith('Account', trim($card->filter('.tab')->text()));
        self::assertCount(1, $card->filter('.mb-drow button.softbtn.danger:contains("Deactivate")'));
        self::assertCount(1, $card->filter('.mb-drow a.softbtn.warn:contains("Switch")'));
        // THE SWITCH LANDS ON THE DASHBOARD, a page every account reaches — never on the
        // team page where it was made, which would answer the borrowed account with a refusal.
        $switch = (string) $card->filter('.mb-drow a.softbtn.warn')->attr('href');
        self::assertStringStartsWith('/?_switch_user=', $switch);
        self::assertCount(1, $card->filter('.mb-drow button.softbtn:contains("Send")'));
    }
}
