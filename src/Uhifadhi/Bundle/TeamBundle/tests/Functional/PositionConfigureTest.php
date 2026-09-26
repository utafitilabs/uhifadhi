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
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\DomCrawler\Form;
use Uhifadhi\Bundle\TeamBundle\Entity\Position;
use Uhifadhi\Contracts\Access\ScopeKind;

/**
 * CONFIGURING A POSITION — ONE PAGE, NO TABS.
 *
 * IT MIRRORS THE RECORD: the same skeleton, the same column split, and
 * everything a position can be changed to on it, with the holders beside the
 * editor because changing what this position grants changes what those
 * people may do.
 *
 * THE TWO REFUSALS ARE THE POINT OF THE SCREEN. A seat count cannot fall
 * below the people already sitting in it, and a position somebody holds
 * cannot be retired — both refused in place, naming the count, rather than
 * failing on submit.
 */
final class PositionConfigureTest extends WebTestCaseWithSchema
{
    public function testThePageMirrorsTheRecordAndHasNoTabs(): void
    {
        $this->administrator();
        $sergeant = $this->position('Sergeant');
        $this->em->flush();

        $crawler = $this->client->request('GET', $this->configure($sergeant));

        self::assertResponseIsSuccessful();
        self::assertSame('Sergeant', $crawler->filter('.dpthead h1.pg')->text());
        self::assertCount(0, $crawler->filter('.atabs'));
        self::assertSame(
            ['Identity', 'What it grants', 'Exceptions to the rank rule', 'Holders', 'Actions'],
            $crawler->filter('.c > .tab')->each(
                static fn (Crawler $c): string => trim(str_replace($c->filter('.src')->count() ? $c->filter('.src')->text() : '', '', $c->text())),
            ),
        );
    }

    /** COMPOSING WHAT A POSITION GRANTS IS ADMINISTERING THE TEAM. */
    public function testItIsGatedOnConfiguringPositionsAndNotMerelyOnReadingThem(): void
    {
        $sergeant = $this->position('Sergeant');
        $reader = $this->person('Rita', 'Massawe');
        $reader->setPosition($this->position('Reader', ['positions.read']));
        $this->em->flush();
        $this->client->loginUser($reader);

        $this->client->request('GET', $this->configure($sergeant));

        self::assertResponseStatusCodeSame(403);
    }

    /** THE THREE FACTS A POSITION IS, written together. */
    public function testTheIdentityCardWritesTheNameTheSeatsAndTheKinds(): void
    {
        $this->administrator();
        $sergeant = $this->position('Sergeant', [], [ScopeKind::Area])->setSeatCount(4);
        $this->em->flush();

        $crawler = $this->client->request('GET', $this->configure($sergeant));
        $form = $crawler->filter('form[action$="/identity"]')->form();
        $form['name'] = 'Senior Sergeant';
        $form['seats'] = '9';
        self::tick($form, 'allows', ['organization', 'area']);
        $this->client->submit($form);

        $this->client->followRedirect();
        $this->em->clear();
        $saved = $this->em->getRepository(Position::class)->findOneBy(['name' => 'Senior Sergeant']);

        self::assertInstanceOf(Position::class, $saved);
        self::assertSame(9, $saved->getSeatCount());
        self::assertSame([ScopeKind::Organization, ScopeKind::Area], $saved->getAllowedKinds());
    }

    /** UNLIMITED IS NULL, and the form says so with its own word. */
    public function testChoosingUnlimitedSeatsStoresNoCount(): void
    {
        $this->administrator();
        $sergeant = $this->position('Sergeant')->setSeatCount(4);
        $this->em->flush();

        $this->client->request('POST', $this->configure($sergeant, '/identity'), [
            '_token' => $this->token($sergeant),
            'name' => 'Sergeant',
            'seatMode' => 'unlimited',
            'seats' => '4',
            'allows' => ['area'],
        ]);

        $this->em->clear();
        self::assertNull($this->reload($sergeant)->getSeatCount());
    }

    /**
     * THE SEAT COUNT CANNOT FALL BELOW THE PEOPLE ALREADY IN IT. Choosing
     * which two of six holders lose their seat is not a decision a product
     * may make, so the write is refused and the floor is named.
     */
    public function testLoweringTheSeatsPastTheHoldersIsRefusedAndNamesTheFloor(): void
    {
        $this->administrator();
        $sergeant = $this->position('Sergeant')->setSeatCount(4);
        $this->person('Joseph', 'Mollel')->setPosition($sergeant);
        $this->person('Sara', 'Mwanga')->setPosition($sergeant);
        $this->em->flush();

        $this->client->request('POST', $this->configure($sergeant, '/identity'), [
            '_token' => $this->token($sergeant),
            'name' => 'Sergeant',
            'seatMode' => 'number',
            'seats' => '1',
            'allows' => ['area'],
        ]);
        $crawler = $this->client->followRedirect();

        self::assertStringContainsString('cannot go below 2', $crawler->filter('.flashes')->text());
        $this->em->clear();
        self::assertSame(4, $this->reload($sergeant)->getSeatCount());
    }

    /** The floor is stated on the control too, not only after a refusal. */
    public function testTheSeatFieldStatesItsFloorBeforeAnybodyTriesIt(): void
    {
        $this->administrator();
        $sergeant = $this->position('Sergeant')->setSeatCount(4);
        $this->person('Joseph', 'Mollel')->setPosition($sergeant);
        $this->em->flush();

        $crawler = $this->client->request('GET', $this->configure($sergeant));

        self::assertSame('1', $crawler->filter('input[name="seats"]')->attr('min'));
        self::assertStringContainsString('it cannot go below 1', $crawler->filter('.pms-row .zfrag')->text());
    }

    /** A BOX ONLY WHERE THE CONCERN DECLARES THE VERB. */
    public function testTheMatrixDrawsABoxOnlyWhereTheConcernDeclaresTheVerb(): void
    {
        $this->administrator();
        $sergeant = $this->position('Sergeant', ['surveys.read']);
        $this->em->flush();

        $crawler = $this->client->request('GET', $this->configure($sergeant));
        $row = $crawler->filter('.pmk-row')->reduce(
            static fn (Crawler $c): bool => 'Surveys' === $c->filter('.pmx-cn b')->text(),
        );

        self::assertSame(
            ['surveys.read', 'surveys.record'],
            $row->filter('input[name="grants[]"]')->each(static fn (Crawler $c): string => (string) $c->attr('value')),
        );
    }

    /** Ticking a cell on the rendered page and pressing Save grants it. */
    public function testTickingACellAndPressingSaveGrantsIt(): void
    {
        $this->administrator();
        $sergeant = $this->position('Sergeant');
        $this->em->flush();

        $crawler = $this->client->request('GET', $this->configure($sergeant));
        $form = $crawler->filter('form[action$="/permissions"]')->form();
        self::tick($form, 'grants', ['surveys.read']);
        $this->client->submit($form);

        $this->em->clear();
        self::assertSame(['surveys.read'], $this->reload($sergeant)->getGrantValues());
    }

    /**
     * AN ORPHANED GRANT SURVIVES A SAVE THAT DOES NOT TOUCH IT. Editing a
     * position is not a migration.
     */
    public function testAnOrphanedGrantSurvivesASaveThatDoesNotTouchIt(): void
    {
        $this->administrator();
        $sergeant = $this->position('Sergeant');
        $this->em->flush();
        $this->em->getConnection()->executeStatement(
            "UPDATE team_position SET grants = '[\"telemetry.read\"]' WHERE id = ?",
            [$sergeant->getId()],
        );
        $this->em->clear();

        $crawler = $this->client->request('GET', $this->configure($sergeant));
        $form = $crawler->filter('form[action$="/permissions"]')->form();
        $this->client->submit($form);

        $this->em->clear();
        self::assertContains('telemetry.read', $this->reload($sergeant)->getGrantValues());
    }

    /**
     * THE SAVE BAR IS ONE BAR, and its LEFT TEXT is the change preview —
     * server-rendered as the zero state, rewritten by the controller as
     * boxes are ticked.
     */
    public function testThereIsOneSaveBarAndItsLeftTextIsThePreview(): void
    {
        $this->administrator();
        $sergeant = $this->position('Sergeant');
        $this->person('Joseph', 'Mollel')->setPosition($sergeant);
        $this->em->flush();

        $crawler = $this->client->request('GET', $this->configure($sergeant));
        $bar = $crawler->filter('form[action$="/permissions"] .staddrow');

        self::assertCount(1, $bar);
        self::assertStringContainsString('no changes', $bar->filter('.chg')->text());
        self::assertStringContainsString('reaches 1 holder', $bar->filter('.chg .to')->text());
        self::assertCount(1, $bar->filter('button.cta'));
    }

    /** Each box carries what it WAS, so the preview compares with the saved state. */
    public function testEveryBoxCarriesWhatItWasSoThePreviewIsAComparison(): void
    {
        $this->administrator();
        $sergeant = $this->position('Sergeant', ['surveys.read']);
        $this->em->flush();

        $crawler = $this->client->request('GET', $this->configure($sergeant));
        $box = $crawler->filter('input[value="surveys.read"]');

        self::assertSame('1', $box->attr('data-was'));
        self::assertSame('read', $box->attr('data-verb'));
        self::assertSame('Surveys', $box->attr('data-concern'));
    }

    /** The bulk controls are the shipped controller's, named on both sides. */
    public function testTheBulkControlsNameTheShippedController(): void
    {
        $this->administrator();
        $sergeant = $this->position('Sergeant');
        $this->em->flush();

        $crawler = $this->client->request('GET', $this->configure($sergeant));

        self::assertGreaterThan(0, $crawler->filter('[data-action="uhifadhi--team-bundle--grants#all"]')->count());
        self::assertGreaterThan(0, $crawler->filter('[data-action="uhifadhi--team-bundle--grants#none"]')->count());
        self::assertFileExists(\dirname(__DIR__, 2).'/assets/controllers/grants_controller.js');
    }

    /**
     * THERE IS NO DELETE, AND THERE IS NO DEAD CONTROL WHERE ONE WOULD BE.
     * We do not delete things: a position is retired, which is the one
     * action the card offers.
     */
    public function testDeleteIsNotDrawnBecausePositionsAreRetiredAndNeverDeleted(): void
    {
        $this->administrator();
        $sergeant = $this->position('Sergeant');
        $this->em->flush();

        $crawler = $this->client->request('GET', $this->configure($sergeant));

        self::assertStringNotContainsString('Delete', $crawler->filter('.mb-danger')->text());
        self::assertCount(1, $crawler->filter('.mb-danger .mb-drow'), 'Actions: one.');
    }

    /**
     * THE MATRIX SAVES WITH NO JAVASCRIPT AT ALL. The bulk controls are an
     * enhancement; the form is a form.
     */
    public function testTheMatrixStillSavesByHandWithNoJavaScriptAtAll(): void
    {
        $this->administrator();
        $sergeant = $this->position('Sergeant');
        $this->em->flush();

        $this->client->request('POST', $this->configure($sergeant, '/permissions'), [
            '_token' => $this->token($sergeant),
            'grants' => ['surveys.read', 'surveys.record'],
        ]);
        $this->client->followRedirect();

        $this->em->clear();
        self::assertSame(['surveys.read', 'surveys.record'], $this->reload($sergeant)->getGrantValues());
    }

    /**
     * RENAMING DOES NOT TOUCH THE GRANT, and saving the grant does not touch
     * the name: the identity and the matrix are two forms on one page for
     * exactly that reason.
     */
    public function testTheIdentityAndTheGrantAreWrittenIndependently(): void
    {
        $this->administrator();
        $sergeant = $this->position('Sergeant', ['surveys.read'])->setSeatCount(4);
        $this->em->flush();

        $this->client->request('POST', $this->configure($sergeant, '/identity'), [
            '_token' => $this->token($sergeant),
            'name' => 'Senior Sergeant',
            'seatMode' => 'number',
            'seats' => '4',
            'allows' => ['area'],
        ]);
        $this->client->followRedirect();
        $this->em->clear();

        self::assertSame(['surveys.read'], $this->reload($sergeant)->getGrantValues());
        self::assertSame('Senior Sergeant', $this->reload($sergeant)->getName());
    }

    /**
     * THE ACTIONS CARD IS ALWAYS DRAWN, and what it offers is the only thing
     * that changes. A sweep of the demo ground found no button named Retire
     * and read that as a missing card; every demo position was held, so every
     * one of them was correctly showing the refusal instead. Both states are
     * asserted here so the next reading of that page needs no guessing.
     */
    public function testTheActionsCardIsPresentWhoeverHoldsThePosition(): void
    {
        $this->administrator();
        $held = $this->position('Sergeant');
        $this->person('Joseph', 'Mollel')->setPosition($held);
        $free = $this->position('Ranger');
        $this->em->flush();

        $onHeld = $this->client->request('GET', $this->configure($held));
        self::assertSame('Retire this position', $onHeld->filter('.mb-danger .dt b')->text());
        self::assertCount(0, $onHeld->filter('.mb-danger button'), 'Held: refused in place, no button.');

        $onFree = $this->client->request('GET', $this->configure($free));
        self::assertSame('Retire this position', $onFree->filter('.mb-danger .dt b')->text());
        self::assertSame('Retire', trim($onFree->filter('.mb-danger button')->text()));
    }

    /** The two fold shortcuts the design draws over the matrix, wired. */
    public function testTheMatrixCarriesTheTwoFoldShortcuts(): void
    {
        $this->administrator();
        $sergeant = $this->position('Sergeant');
        $this->em->flush();

        $crawler = $this->client->request('GET', $this->configure($sergeant));

        self::assertSame(
            ['Fold all', 'Open all'],
            $crawler->filter('.pmbar-acts button')->each(static fn (Crawler $c): string => $c->text()),
        );
        self::assertCount(
            $crawler->filter('details.pmf.pmfg')->count(),
            $crawler->filter('details[data-uhifadhi--team-bundle--folds-target="fold"]'),
        );
    }

    /**
     * RETIRING IS REFUSED WHILE ANYBODY HOLDS IT, in place and naming the
     * count — not a control that looks operable and then fails.
     */
    public function testRetiringIsRefusedInPlaceWhileSomebodyHoldsIt(): void
    {
        $this->administrator();
        $sergeant = $this->position('Sergeant');
        $this->person('Joseph', 'Mollel')->setPosition($sergeant);
        $this->em->flush();

        $crawler = $this->client->request('GET', $this->configure($sergeant));

        self::assertStringContainsString('1 person holds it', $crawler->filter('.mb-danger .mb-absent')->text());
        self::assertCount(0, $crawler->filter('form[action$="/retire"]'));
    }

    /** And the route refuses it too — the control is not the enforcement. */
    public function testTheRouteRefusesRetiringAHeldPositionEvenWithoutTheControl(): void
    {
        $this->administrator();
        $sergeant = $this->position('Sergeant');
        $this->person('Joseph', 'Mollel')->setPosition($sergeant);
        $this->em->flush();

        $this->client->request('POST', $this->configure($sergeant, '/retire'), ['_token' => $this->token($sergeant)]);
        $crawler = $this->client->followRedirect();

        self::assertStringContainsString('1 person hold', $crawler->filter('.flashes')->text());
        $this->em->clear();
        self::assertFalse($this->reload($sergeant)->isRetired());
    }

    /** A POSITION IS RETIRED, NEVER DELETED — and it comes back. */
    public function testAnEmptyPositionIsRetiredAndCanBeReinstated(): void
    {
        $this->administrator();
        $sergeant = $this->position('Sergeant');
        $this->em->flush();

        $crawler = $this->client->request('GET', $this->configure($sergeant));
        $this->client->submit($crawler->filter('form[action$="/retire"]')->form());
        $this->client->followRedirect();

        $this->em->clear();
        self::assertTrue($this->reload($sergeant)->isRetired());

        $crawler = $this->client->request('GET', $this->configure($sergeant));
        self::assertStringContainsString('Reinstate this position', $crawler->text());
        $this->client->submit($crawler->filter('form[action$="/retire"]')->form());
        $this->client->followRedirect();

        $this->em->clear();
        self::assertFalse($this->reload($sergeant)->isRetired());
    }

    /** A retired position is absent from the picker a person is given one on. */
    public function testARetiredPositionIsNotOfferedToAnybody(): void
    {
        $this->administrator();
        $joseph = $this->person('Joseph', 'Mollel');
        $this->position('Sergeant')->retire(new \DateTimeImmutable());
        $this->position('Ranger');
        $this->em->flush();

        $crawler = $this->client->request('GET', '/team/'.$joseph->getUuidString().'/configure');
        $offered = $crawler->filter('select[name="position"] option')->each(static fn (Crawler $c): string => trim(explode('—', $c->text(), 2)[0]));

        self::assertNotContains('Sergeant', $offered);
        self::assertContains('Ranger', $offered);
    }

    /**
     * TICK EXACTLY THESE, AND UNTICK THE REST — a group of same-named
     * checkboxes is a list of fields to DomCrawler, not one field with a
     * list of values, so a suite that assigned to it would silently set
     * nothing.
     *
     * @param list<string> $values
     */
    private static function tick(Form $form, string $name, array $values): void
    {
        /** @var list<ChoiceFormField> $boxes */
        $boxes = $form[$name];
        foreach ($boxes as $box) {
            \in_array($box->availableOptionValues()[0] ?? '', $values, true) ? $box->tick() : $box->untick();
        }
    }

    private function configure(Position $position, string $suffix = '/configure'): string
    {
        return '/team/positions/'.$position->getUuidString().$suffix;
    }

    private function token(Position $position): string
    {
        $crawler = $this->client->request('GET', $this->configure($position));

        return (string) $crawler->filter('input[name="_token"]')->attr('value');
    }

    private function reload(Position $position): Position
    {
        $fresh = $this->em->getRepository(Position::class)->find((int) $position->getId());
        \assert($fresh instanceof Position);

        return $fresh;
    }

    /**
     * THE IDENTITY CARD IS THE DESIGN'S: the kinds it allows are pressed
     * pills over real boxes, and its footer states "no changes · reaches N
     * holders", with Discard beside Save.
     */
    public function testTheIdentityCardWearsPillsAndTheDesignsFooter(): void
    {
        $this->administrator();
        $position = $this->position('Sergeant');
        $this->em->flush();
        $crawler = $this->client->request('GET', $this->configure($position));

        $pills = $crawler->filter('.pms-row label.pmw');
        self::assertCount(2, $pills, 'organization and area, nothing else');
        self::assertCount(2, $pills->filter('input[type="checkbox"][name="allows[]"]'));
        self::assertCount(0, $crawler->filter('.pms-row label.pmb'));

        $foot = $crawler->filter('.pms-row ~ .staddrow, .staddrow')->first();
        self::assertStringStartsWith('no changes', trim($foot->filter('.chg')->text()));
        self::assertStringContainsString('reaches', $foot->filter('.chg .to')->text());
        self::assertSame(['Discard', 'Save the identity'], $foot->filter('button')->each(static fn (Crawler $b): string => trim($b->text())));
    }
}
