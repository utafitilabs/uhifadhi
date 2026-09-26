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

use Uhifadhi\Bundle\TeamBundle\Entity\GrantJustification;
use Uhifadhi\Bundle\TeamBundle\Entity\Position;
use Uhifadhi\Bundle\TeamBundle\Enum\TeamRoleEnum;
use Uhifadhi\Bundle\TeamBundle\Service\PositionService;

/**
 * THE EXCEPTIONS CARD, ON EVERY SCREEN THAT SHOWS IT (ruled 2026-09-26).
 *
 * "Live locations" lifts the rank rule, so it is not a box in the matrix: it
 * has its own card on the position's configure page, folded shut, where only
 * a Super Admin gives or takes it with a written reason. Wherever it is in
 * force it is visible: a chip on the register, a line on the holder's record,
 * the reason and its history on the position's record, and one review list
 * on the Team overview that also names the two tiers seeing everybody.
 */
final class PositionExceptionTest extends WebTestCaseWithSchema
{
    private const string PAIR = 'locations.read';
    private const string REASON = 'The radio room at headquarters coordinates every rescue and must see every ranger.';

    public function testTheExceptionIsItsOwnCardAndNeverABoxInTheMatrix(): void
    {
        $this->administrator();
        $room = $this->position('Radio Operator');
        $this->em->flush();

        $crawler = $this->client->request('GET', $this->url($room, '/configure'));

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('input[name="grants[]"][value="locations.read"]'), 'the matrix draws no box for an exception');
        $card = $crawler->filter('.c')->reduce(static fn ($c): bool => str_starts_with(trim($c->filter('.tab')->count() ? $c->filter('.tab')->text() : ''), 'Exceptions to the rank rule'));
        self::assertCount(1, $card);
        self::assertStringContainsString('Live locations', $card->text());
        self::assertStringContainsString('lifts the rank rule', $card->text());
        self::assertSame('not given', $card->filter('.xst')->text());
        self::assertCount(1, $card->filter('details.xrule:not([open])'), 'folded shut');
        self::assertStringEndsWith('/exceptions/locations.read', (string) $card->filter('form')->attr('action'));
        self::assertCount(1, $card->filter('textarea[name="reason"][required]'));
    }

    public function testASuperAdminGivesItWithAReason(): void
    {
        $naomi = $this->administrator();
        $room = $this->position('Radio Operator');
        $this->em->flush();

        $this->client->request('POST', $this->url($room, '/exceptions/locations.read'), ['_token' => $this->token($room), 'reason' => self::REASON]);
        $crawler = $this->client->followRedirect();

        self::assertStringContainsString('Live locations', $crawler->filter('.flashes')->text());
        $this->em->clear();
        $held = $this->reload($room);
        self::assertContains(self::PAIR, $held->getGrantValues());
        $row = $this->em->getRepository(GrantJustification::class)->findOneBy(['position' => $held]);
        self::assertInstanceOf(GrantJustification::class, $row);
        self::assertSame(self::REASON, $row->getReason());
        self::assertSame($naomi->getId(), $row->getGrantedBy()?->getId());

        $card = $crawler->filter('details.xrule');
        self::assertSame('given', $card->filter('.xst')->text());
        self::assertStringContainsString(self::REASON, $card->filter('.xwhy')->text());
        self::assertStringContainsString('Take it away', $card->text());
    }

    public function testWithoutAReasonNothingIsGiven(): void
    {
        $this->administrator();
        $room = $this->position('Radio Operator');
        $this->em->flush();

        $this->client->request('POST', $this->url($room, '/exceptions/locations.read'), ['_token' => $this->token($room), 'reason' => '   ']);
        $crawler = $this->client->followRedirect();

        self::assertStringContainsString('reason', $crawler->filter('.flashes')->text());
        $this->em->clear();
        self::assertNotContains(self::PAIR, $this->reload($room)->getGrantValues());
    }

    /** An Admin composes every other grant, and reads this card without controls. */
    public function testAnAdminSeesTheCardReadOnlyAndIsRefusedAPost(): void
    {
        $amani = $this->person('Amani', 'Lwila', TeamRoleEnum::Admin);
        $room = $this->position('Radio Operator');
        $this->em->flush();
        $this->client->loginUser($amani);

        $crawler = $this->client->request('GET', $this->url($room, '/configure'));
        $card = $crawler->filter('details.xrule');
        self::assertCount(0, $card->filter('textarea, button'));
        self::assertStringContainsString('only a Super Admin', $card->text());

        $this->client->request('POST', $this->url($room, '/exceptions/locations.read'), ['_token' => $this->token($room), 'reason' => self::REASON]);
        self::assertResponseStatusCodeSame(403);
        $this->em->clear();
        self::assertNotContains(self::PAIR, $this->reload($room)->getGrantValues());
    }

    public function testTheMatrixSaveCannotSmuggleItIn(): void
    {
        $this->administrator();
        $room = $this->position('Radio Operator');
        $this->em->flush();

        $this->client->request('POST', $this->url($room, '/permissions'), ['_token' => $this->token($room), 'grants' => ['directory.read', self::PAIR]]);
        $crawler = $this->client->followRedirect();

        self::assertStringContainsString('its own card', $crawler->filter('.flashes')->text());
        $this->em->clear();
        self::assertNotContains(self::PAIR, $this->reload($room)->getGrantValues());
    }

    public function testASuperAdminTakesItAwayAndTheRecordKeepsTheHistory(): void
    {
        $naomi = $this->administrator();
        $room = $this->position('Radio Operator');
        $this->em->flush();
        $this->positions()->grantException($room, self::PAIR, self::REASON, $naomi);

        $this->client->request('POST', $this->url($room, '/exceptions/locations.read/revoke'), ['_token' => $this->token($room)]);
        $this->client->followRedirect();

        $this->em->clear();
        self::assertNotContains(self::PAIR, $this->reload($room)->getGrantValues());

        $record = $this->client->request('GET', $this->url($room, ''));
        $card = $record->filter('.c')->reduce(static fn ($c): bool => str_starts_with(trim($c->filter('.tab')->count() ? $c->filter('.tab')->text() : ''), 'Exceptions to the rank rule'));
        self::assertCount(1, $card);
        self::assertStringContainsString('not given', $card->text());
        self::assertStringContainsString('Taken away', $card->filter('.xhist')->text());
        self::assertStringContainsString('Naomi Kileo', $card->filter('.xhist')->text());
    }

    public function testTheRecordShowsTheReasonWhoAndWhen(): void
    {
        $naomi = $this->administrator();
        $room = $this->position('Radio Operator');
        $this->em->flush();
        $this->positions()->grantException($room, self::PAIR, self::REASON, $naomi);

        $record = $this->client->request('GET', $this->url($room, ''));

        self::assertStringContainsString(self::REASON, $record->filter('.xwhy')->text());
        self::assertStringContainsString('given by Naomi Kileo', $record->filter('.xby')->text());
    }

    public function testTheRegisterAndTheHoldersRecordCarryTheChip(): void
    {
        $naomi = $this->administrator();
        $room = $this->position('Radio Operator');
        $operator = $this->person('Rehema', 'Saning’o');
        $operator->setPosition($room);
        $this->position('Ranger');
        $this->em->flush();
        $this->positions()->grantException($room, self::PAIR, self::REASON, $naomi);

        $register = $this->client->request('GET', '/team/positions');
        $rows = $register->filter('table.preg tr.prow')->each(static fn ($r): array => [$r->filter('.ov-nm')->text(), $r->filter('.chip.warn')->each(static fn ($c): string => $c->text())]);
        $byName = array_column($rows, 1, 0);
        self::assertContains('lifts the rank rule', $byName['Radio Operator']);
        self::assertNotContains('lifts the rank rule', $byName['Ranger']);

        $member = $this->client->request('GET', '/team/'.$operator->getUuidString());
        self::assertStringContainsString('lifts the rank rule', $member->filter('#position')->text());
    }

    /** ONE PLACE TO REVIEW: every seat holding it, and the two tiers seeing everybody by tier. */
    public function testTheOverviewListsEverySeatHoldingItAndTheTiers(): void
    {
        $naomi = $this->administrator();
        $this->person('Amani', 'Lwila', TeamRoleEnum::Admin);
        $room = $this->position('Radio Operator');
        $this->person('Rehema', 'Sanare')->setPosition($room);
        $this->position('Ranger');
        $this->em->flush();
        $this->positions()->grantException($room, self::PAIR, self::REASON, $naomi);

        $crawler = $this->client->request('GET', '/team/overview');
        $card = $crawler->filter('.c')->reduce(static fn ($c): bool => str_starts_with(trim($c->filter('.tab')->count() ? $c->filter('.tab')->text() : ''), 'Sees every live position'));

        self::assertCount(1, $card);
        $lines = $card->filter('.rln')->each(static fn ($l): string => $l->text());
        self::assertCount(3, $lines);
        self::assertStringContainsString('Radio Operator', $lines[0]);
        self::assertStringContainsString('1 holds it', $lines[0]);
        self::assertStringContainsString('Naomi Kileo', $lines[0]);
        self::assertStringContainsString('Super Admin', $lines[1]);
        self::assertStringContainsString('Naomi Kileo', $lines[1]);
        self::assertStringContainsString('Admin', $lines[2]);
        self::assertStringContainsString('Amani Lwila', $lines[2]);
        self::assertStringNotContainsString('Ranger', $card->text());
    }

    private function url(Position $position, string $suffix): string
    {
        return '/team/positions/'.$position->getUuidString().$suffix;
    }

    private function token(Position $position): string
    {
        $crawler = $this->client->request('GET', $this->url($position, '/configure'));

        return (string) $crawler->filter('input[name="_token"]')->first()->attr('value');
    }

    private function reload(Position $position): Position
    {
        $fresh = $this->em->getRepository(Position::class)->find((int) $position->getId());
        \assert($fresh instanceof Position);

        return $fresh;
    }

    private function positions(): PositionService
    {
        $service = static::getContainer()->get('test_public.'.PositionService::class);
        \assert($service instanceof PositionService);

        return $service;
    }
}
