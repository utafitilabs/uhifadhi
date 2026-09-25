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
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\DomCrawler\Crawler;
use Uhifadhi\Bundle\TeamBundle\Controller\TeamConfigureController;
use Uhifadhi\Bundle\TeamBundle\Entity\Position;
use Uhifadhi\Bundle\TeamBundle\Entity\TeamSettings;
use Uhifadhi\Bundle\TeamBundle\Enum\InvitationUnitEnum;
use Uhifadhi\Bundle\TeamBundle\Shell\TeamSectionConfiguration;
use Uhifadhi\Contracts\Access\ScopeKind;

/**
 * THE TEAM SECTION'S CONFIGURE PAGE — three sections at
 * /team/configure/{people,positions,assignments}, each a form of the rules
 * the whole team is run under, and the one-screen page they replace gone.
 *
 * THE DOM IS THE PROOF. A configure page is a form: the current value sits
 * in its control and the primary action is in the save row, so the counts
 * of controls per page are the design's counts and not a description of it.
 */
#[CoversClass(TeamConfigureController::class)]
#[CoversClass(TeamSectionConfiguration::class)]
final class TeamConfigureTest extends WebTestCaseWithSchema
{
    /** The one screen these three replace is gone, with no redirect. */
    public function testTheOneScreenConfigurePageIsGone(): void
    {
        $this->administrator();
        $this->client->request('GET', '/team/configure');

        self::assertResponseStatusCodeSame(404);
    }

    /** @return iterable<string, array{string, string}> */
    public static function sections(): iterable
    {
        yield 'people' => ['/team/configure/people', 'People'];
        yield 'positions' => ['/team/configure/positions', 'Positions'];
        yield 'assignments' => ['/team/configure/assignments', 'Assignments'];
    }

    /**
     * EVERY SECTION WEARS THE SAME FRAME: the section strip where a data tab
     * shows its strip, the register's door before the lit Configure action,
     * and the crumb naming the section.
     */
    #[DataProvider('sections')]
    public function testEachSectionStandsInTheStripWithTheRegistersDoor(string $path, string $lit): void
    {
        $this->administrator();
        $crawler = $this->client->request('GET', $path);

        self::assertResponseIsSuccessful();
        self::assertSame(['People', 'Positions', 'Assignments', 'Ranks'], $crawler->filter('.atabs a')->each(static fn (Crawler $a): string => $a->text()));
        self::assertSame([$lit], $crawler->filter('.atabs a.on')->each(static fn (Crawler $a): string => $a->text()));
        self::assertSame('Team', trim($crawler->filter('h1.pg')->text()));

        $actions = $crawler->filter('.pgact > *');
        self::assertCount(2, $actions);
        self::assertSame('‹ The register', trim($actions->first()->text()));
        self::assertSame('/team', $actions->first()->attr('href'));
        self::assertSame('Configure', trim($actions->last()->text()));
        self::assertStringContainsString('on', (string) $actions->last()->attr('class'));
        self::assertSame('/team/overview', $actions->last()->attr('href'));

        self::assertStringEndsWith('/ configure / '.strtolower($lit), preg_replace('/\s+/', ' ', trim($crawler->filter('.crumb')->text())) ?? '');
    }

    /** @return iterable<string, array{string}> */
    public static function tabs(): iterable
    {
        yield 'overview' => ['/team/overview'];
        yield 'people' => ['/team'];
        yield 'positions' => ['/team/positions'];
        yield 'assignments' => ['/team/assignments'];
        yield 'roles' => ['/team/roles'];
    }

    /** Configure on every Team tab opens the first section, People. */
    #[DataProvider('tabs')]
    public function testConfigureOnEveryTabOpensPeople(string $path): void
    {
        $this->administrator();
        $action = $this->client->request('GET', $path)->filter('.pgact > *')->last();

        self::assertSame('Configure', trim($action->text()));
        self::assertSame('/team/configure/people', $action->attr('href'));
    }

    // ---- people ----------------------------------------------------------

    /** ONE CARD, THE INVITATION RULES, AS A FORM with the current values in it. */
    public function testPeopleStatesTheInvitationRulesInTheirControls(): void
    {
        $this->administrator();
        $crawler = $this->client->request('GET', '/team/configure/people');

        $form = $crawler->filter('.pgbody form');
        self::assertCount(1, $form);
        self::assertStringStartsWith('Invitation rules', trim($form->filter('.tab')->text(null, true)));
        self::assertCount(2, $form->filter('input:not([type="hidden"])'));
        self::assertCount(1, $form->filter('select'));
        self::assertCount(4, $form->filter('button'));

        self::assertSame('7', $form->filter('input[name="validAmount"]')->attr('value'));
        self::assertSame('days', $form->filter('select[name="validUnit"] option[selected]')->attr('value'));
        self::assertSame('1', $form->filter('input[name="uses"]')->attr('value'));
        self::assertSame('Allowed', trim($form->filter('.fyn button.on')->text()));
        self::assertSame('Save invitation rules', trim($form->filter('.save-row .cta')->text()));
        self::assertCount(1, $form->filter('.save-row button[type="reset"]'));
    }

    public function testSavingTheInvitationRulesWritesTheRowAndReadsItBack(): void
    {
        $this->administrator();
        $token = $this->tokenFrom('/team/configure/people');

        $this->client->request('POST', '/team/configure/people', [
            '_token' => $token, 'validAmount' => '48', 'validUnit' => 'hours', 'uses' => '2', 'withPassword' => 'invitation',
        ]);

        self::assertResponseRedirects('/team/configure/people');
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('Saved', $crawler->filter('.flashes')->text());
        self::assertSame('48', $crawler->filter('input[name="validAmount"]')->attr('value'));
        self::assertSame('hours', $crawler->filter('select[name="validUnit"] option[selected]')->attr('value'));
        self::assertSame('2', $crawler->filter('input[name="uses"]')->attr('value'));
        self::assertSame('Invitation only', trim($crawler->filter('.fyn button.on')->text()));

        $row = $this->em->getRepository(TeamSettings::class)->find(TeamSettings::ONE);
        self::assertInstanceOf(TeamSettings::class, $row);
        self::assertSame(InvitationUnitEnum::Hours, $row->getInvitationValidUnit());
        self::assertFalse($row->isInvitationWithPassword());
    }

    public function testAValidityOfNothingIsRefusedInWriting(): void
    {
        $this->administrator();
        $token = $this->tokenFrom('/team/configure/people');

        $this->client->request('POST', '/team/configure/people', [
            '_token' => $token, 'validAmount' => '0', 'validUnit' => 'days', 'uses' => '1', 'withPassword' => 'allowed',
        ]);

        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('at least one', $crawler->filter('.flashes')->text());
        self::assertNull($this->em->getRepository(TeamSettings::class)->find(TeamSettings::ONE));
    }

    // ---- positions -------------------------------------------------------

    /** THE HOUSE CREATE CARD, and nothing else: name, seats, placement, one action. */
    public function testPositionsCarriesTheCreateCardAndNoOther(): void
    {
        $this->administrator();
        $crawler = $this->client->request('GET', '/team/configure/positions');

        $forms = $crawler->filter('.pgbody form');
        self::assertCount(1, $forms);
        $card = $crawler->filter('#add form.crcard');
        self::assertCount(1, $card);
        self::assertStringEndsWith('/team/positions', (string) $card->attr('action'));
        self::assertCount(2, $card->filter('input:not([type="hidden"])'));
        self::assertCount(1, $card->filter('select'));
        self::assertCount(1, $card->filter('button'));
        self::assertSame('Add position', trim($card->filter('button.cta')->text()));
        self::assertSame(['name', 'seats', 'placement'], $card->filter('.crlab')->each(static fn (Crawler $l): string => strtolower(trim($l->text()))));
        self::assertSame(['organization', 'area'], $card->filter('select[name="allows[]"] option')->each(static fn (Crawler $o): string => (string) $o->attr('value')));
        self::assertSame('Add a position', trim($crawler->filter('#add .dcaddhd b')->text()));
        self::assertStringContainsString('ticked on the position', $card->filter('.crhint')->text());
    }

    public function testAddingAPositionWritesItsNameSeatsAndPlacement(): void
    {
        $this->administrator();
        $crawler = $this->client->request('GET', '/team/configure/positions');

        $form = $crawler->filter('#add form')->form();
        $this->client->submit($form, ['name' => 'Sergeant', 'seats' => '8', 'allows' => ['organization']]);

        self::assertResponseRedirects('/team/positions');
        $stored = $this->em->getRepository(Position::class)->findOneBy(['name' => 'Sergeant']);
        self::assertInstanceOf(Position::class, $stored);
        self::assertSame(8, $stored->getSeatCount());
        self::assertSame([ScopeKind::Organization], $stored->getAllowedKinds());
    }

    /** A blank seat count is unlimited, as the placeholder says. */
    public function testABlankSeatCountIsUnlimited(): void
    {
        $this->administrator();
        $form = $this->client->request('GET', '/team/configure/positions')->filter('#add form')->form();
        $this->client->submit($form, ['name' => 'Analyst', 'allows' => ['area']]);

        $stored = $this->em->getRepository(Position::class)->findOneBy(['name' => 'Analyst']);
        self::assertInstanceOf(Position::class, $stored);
        self::assertNull($stored->getSeatCount());
        self::assertSame([ScopeKind::Area], $stored->getAllowedKinds());
    }

    // ---- assignments -----------------------------------------------------

    public function testAssignmentsStatesTheStationingRulesInTheirControls(): void
    {
        $this->administrator();
        $form = $this->client->request('GET', '/team/configure/assignments')->filter('.pgbody form');

        self::assertCount(1, $form);
        self::assertStringStartsWith('Stationing rules', trim($form->filter('.tab')->text(null, true)));
        self::assertCount(1, $form->filter('input:not([type="hidden"])'));
        self::assertCount(6, $form->filter('button'));
        self::assertSame('1', $form->filter('input[name="leaders"]')->attr('value'));
        self::assertSame(['Allowed', 'Allowed'], $form->filter('.fyn button.on')->each(static fn (Crawler $b): string => trim($b->text())));
        self::assertSame('Save stationing rules', trim($form->filter('.save-row .cta')->text()));
    }

    public function testSavingTheStationingRulesWritesTheRowAndReadsItBack(): void
    {
        $this->administrator();
        $token = $this->tokenFrom('/team/configure/assignments');

        $this->client->request('POST', '/team/configure/assignments', [
            '_token' => $token, 'twoStations' => 'one', 'leaders' => '2', 'emptyStation' => 'not-allowed',
        ]);

        self::assertResponseRedirects('/team/configure/assignments');
        $crawler = $this->client->followRedirect();
        self::assertSame('2', $crawler->filter('input[name="leaders"]')->attr('value'));
        self::assertSame(['One only', 'Not allowed'], $crawler->filter('.fyn button.on')->each(static fn (Crawler $b): string => trim($b->text())));

        $row = $this->em->getRepository(TeamSettings::class)->find(TeamSettings::ONE);
        self::assertInstanceOf(TeamSettings::class, $row);
        self::assertFalse($row->isTwoStationsAllowed());
        self::assertSame(2, $row->getLeadersPerStation());
        self::assertFalse($row->isEmptyStationAllowed());
    }

    // ---- gates -----------------------------------------------------------

    /**
     * A SECTION THE VIEWER MAY NOT OPEN IS WITHHELD, and Configure opens the
     * first one they may: somebody who composes positions but does not manage
     * the directory sees Positions alone.
     */
    public function testASectionTheViewerMayNotOpenIsWithheldFromTheStrip(): void
    {
        $composer = $this->person('Wera', 'Mwita');
        $composer->setPosition($this->position('Registrar', ['directory.read', 'positions.read', 'positions.configure']));
        $this->place($composer);
        $this->em->flush();
        $this->client->loginUser($composer);

        self::assertSame('/team/configure/positions', $this->client->request('GET', '/team/positions')->filter('.pgact > *')->last()->attr('href'));

        $crawler = $this->client->request('GET', '/team/configure/positions');
        self::assertResponseIsSuccessful();
        self::assertSame(['Positions'], $crawler->filter('.atabs a')->each(static fn (Crawler $a): string => $a->text()));

        $this->client->request('GET', '/team/configure/people');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/team/configure/assignments');
        self::assertResponseStatusCodeSame(403);
    }

    /** Reading the team does not open any of it. */
    public function testAReaderIsRefusedEverySection(): void
    {
        $reader = $this->person('Wera', 'Mwita');
        $reader->setPosition($this->position('Reader', ['directory.read', 'positions.read']));
        $this->place($reader);
        $this->em->flush();
        $this->client->loginUser($reader);

        foreach (['/team/configure/people', '/team/configure/positions', '/team/configure/assignments'] as $path) {
            $this->client->request('GET', $path);
            self::assertResponseStatusCodeSame(403, $path);
        }
        self::assertCount(0, $this->client->request('GET', '/team/positions')->filter('.pgact a.tgl'), 'no configure action for a reader');
    }

    // ---- doors -----------------------------------------------------------

    public function testTheOverviewAndTheRegisterOpenTheirSections(): void
    {
        $this->administrator();

        self::assertSame('/team/configure/people', $this->client->request('GET', '/team/overview')->filter('.factband a.more')->attr('href'));
        self::assertSame('/team/configure/positions#add', $this->client->request('GET', '/team/positions')->filter('.pgact a.cta')->attr('href'));
    }

    /** The section strip lights the sidebar's own row for the section. */
    public function testTheSidebarOpensThePathToTheSection(): void
    {
        $this->administrator();
        $crawler = $this->client->request('GET', '/team/configure/positions');

        $lit = $crawler->filter('.nav .ntt.on, .nav .ntree a.on')->each(static fn (Crawler $a): string => trim($a->text()));
        self::assertContains('Positions', $lit);
    }
}
