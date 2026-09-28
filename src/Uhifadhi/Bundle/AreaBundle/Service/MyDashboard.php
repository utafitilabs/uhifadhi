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

namespace Uhifadhi\Bundle\AreaBundle\Service;

use Uhifadhi\Contracts\Me\MyCard;
use Uhifadhi\Contracts\Me\MyCardProviderInterface;

/**
 * A PERSON'S OWN DASHBOARD, composed (open item #19, option A ruled 28 Sep
 * 2026): every package's cards about this one person, sorted into the
 * approved layout's slots and, within a slot, by each card's order.
 *
 * The frame knows no package: a card arrives through
 * {@see MyCardProviderInterface} already rendered, which is why an
 * installation without a module simply has fewer cards.
 */
final readonly class MyDashboard
{
    public const array SLOTS = [MyCard::FIGURE, MyCard::DOOR, MyCard::PLATE, MyCard::BESIDE_PLATE, MyCard::LEFT, MyCard::RIGHT, MyCard::ROW];

    /** @param iterable<MyCardProviderInterface> $providers */
    public function __construct(
        private iterable $providers = [],
    ) {
    }

    /**
     * The rendered cards for this person, slot by slot.
     *
     * @return array<string, list<string>>
     */
    public function for(string $personUuid, \DateTimeImmutable $now): array
    {
        $cards = [];
        foreach ($this->providers as $provider) {
            foreach ($provider->cardsFor($personUuid, $now) as $card) {
                if (\in_array($card->slot, self::SLOTS, true)) {
                    $cards[] = $card;
                }
            }
        }
        usort($cards, static fn (MyCard $a, MyCard $b): int => $a->order <=> $b->order);

        $slots = array_fill_keys(self::SLOTS, []);
        foreach ($cards as $card) {
            $slots[$card->slot][] = $card->html;
        }

        return $slots;
    }
}
