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
use Uhifadhi\Bundle\TeamBundle\Controller\MemberController;
use Uhifadhi\Bundle\TeamBundle\Tests\Integration\Fixtures\FakeRecordCells;

/**
 * A MODULE'S CARD ON A PERSON'S RECORD — collected from the seam, drawn as
 * the last card of the main column after the grants ledger, and absent when
 * the provider answers null.
 */
#[CoversClass(MemberController::class)]
final class PersonRecordCellTest extends WebTestCaseWithSchema
{
    protected function tearDown(): void
    {
        FakeRecordCells::speakAbout(null);
        parent::tearDown();
    }

    public function testAContributedCellIsTheLastCardOfTheMainColumnAfterTheLedger(): void
    {
        $this->administrator();
        $holder = $this->person('Wera', 'Mwita');
        $holder->setPosition($this->position('Ranger', ['directory.read']));
        $this->em->flush();
        FakeRecordCells::speakAbout($holder->getUuidString());

        $crawler = $this->client->request('GET', '/team/'.$holder->getUuidString());

        self::assertResponseIsSuccessful();
        $cards = $crawler->filter('.recgrid > .col:first-child > .c');
        self::assertGreaterThanOrEqual(3, $cards->count(), 'position, ledger, then the contributed card');
        self::assertSame($holder->getUuidString(), $cards->last()->attr('data-fixture-cell'));
        self::assertStringStartsWith('What that actually grants', trim($cards->eq($cards->count() - 2)->filter('.tab')->text(null, true)));
        self::assertStringStartsWith('Handsets', trim($cards->last()->filter('.tab')->text(null, true)));
    }

    public function testAProviderAnsweringNullDrawsNothing(): void
    {
        $this->administrator();
        $holder = $this->person('Wera', 'Mwita');
        $this->em->flush();

        $crawler = $this->client->request('GET', '/team/'.$holder->getUuidString());

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-fixture-cell]'));
    }
}
