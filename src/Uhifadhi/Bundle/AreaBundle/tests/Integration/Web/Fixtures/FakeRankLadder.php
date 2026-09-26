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

namespace Uhifadhi\Bundle\AreaBundle\Tests\Integration\Web\Fixtures;

use Uhifadhi\Contracts\People\RankLadderInterface;

/**
 * THE TEAM'S LADDER, STOOD IN FOR — this kernel has no team bundle, and a
 * test says who holds which place: `place($uuid, 5)`. A person never placed
 * holds no rank.
 */
final class FakeRankLadder implements RankLadderInterface
{
    /** @var array<string, int> */
    private array $places = [];

    public function place(string $personUuid, int $place): void
    {
        $this->places[$personUuid] = $place;
    }

    public function placesOf(array $personUuids): array
    {
        return array_intersect_key($this->places, array_flip($personUuids));
    }

    public function length(): int
    {
        return [] === $this->places ? 0 : max($this->places);
    }
}
