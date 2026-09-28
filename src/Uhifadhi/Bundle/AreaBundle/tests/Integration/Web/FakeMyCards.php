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

namespace Uhifadhi\Bundle\AreaBundle\Tests\Integration\Web;

use Uhifadhi\Contracts\Me\MyCard;
use Uhifadhi\Contracts\Me\MyCardProviderInterface;

/**
 * A PACKAGE'S CARDS ON A PERSON'S OWN DASHBOARD, stood in: a figure, a door
 * to a page, and a card in each band, each naming the person it was asked
 * about so a test can see the frame asked for the right one.
 */
final class FakeMyCards implements MyCardProviderInterface
{
    public function cardsFor(string $personUuid, \DateTimeImmutable $now): array
    {
        return [
            new MyCard(MyCard::LEFT, 20, '<div class="c" data-fake="left-second">second</div>'),
            new MyCard(MyCard::FIGURE, 10, '<div class="c kpi" data-fake="figure">for '.$personUuid.'</div>'),
            new MyCard(MyCard::DOOR, 10, '<a data-fake="door" href="/me/roster">My roster</a>'),
            new MyCard(MyCard::LEFT, 10, '<div class="c" data-fake="left-first">first</div>'),
            new MyCard(MyCard::ROW, 10, '<div class="c" data-fake="row">row</div>'),
        ];
    }
}
