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

use Uhifadhi\Bundle\TeamBundle\Me\TeamMyCards;
use Uhifadhi\Contracts\Me\MyCard;

/**
 * THE TEAM'S CARDS ON A PERSON'S OWN DASHBOARD (#19, option A ruled 28 Sep
 * 2026): my record and my phone, about the one person asked for, and no door
 * the person may not open.
 */
final class TeamMyCardsTest extends WebTestCaseWithSchema
{
    public function testMyRecordStatesMyPositionAndOffersNoDoorIMayNotOpen(): void
    {
        $this->person('Naomi', 'Kileo', \Uhifadhi\Bundle\TeamBundle\Enum\TeamRoleEnum::SuperAdmin);
        $me = $this->person('Naserian', 'Lekishon');
        $me->setPosition($this->position('Ranger', ['personal-details.read']));
        $this->place($me);
        $this->em->flush();
        $this->client->loginUser($me);
        $this->client->request('GET', '/login');

        $cards = $this->cards((string) $me->getUuidString());

        self::assertArrayHasKey(MyCard::RIGHT, $cards);
        self::assertStringContainsString('Ranger', $cards[MyCard::RIGHT]);
        self::assertStringNotContainsString('My record &rarr;', $cards[MyCard::RIGHT], 'a ranger may not open the record page');
        self::assertStringContainsString('Not signed in on a phone', $cards[MyCard::ROW]);
    }

    public function testNobodyByThatUuidHasNoCards(): void
    {
        self::assertSame([], $this->service()->cardsFor('00000000-0000-4000-8000-000000000000', new \DateTimeImmutable()));
    }

    /** @return array<string, string> */
    private function cards(string $uuid): array
    {
        $cards = [];
        foreach ($this->service()->cardsFor($uuid, new \DateTimeImmutable()) as $card) {
            $cards[$card->slot] = $card->html;
        }

        return $cards;
    }

    private function service(): TeamMyCards
    {
        $service = static::getContainer()->get('test_public.'.TeamMyCards::class);
        self::assertInstanceOf(TeamMyCards::class, $service);

        return $service;
    }
}
