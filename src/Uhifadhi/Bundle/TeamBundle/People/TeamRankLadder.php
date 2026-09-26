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

namespace Uhifadhi\Bundle\TeamBundle\People;

use Uhifadhi\Bundle\TeamBundle\Repository\RankHoldingRepository;
use Uhifadhi\Bundle\TeamBundle\Repository\RankRepository;
use Uhifadhi\Bundle\TeamBundle\Repository\UserRepository;
use Uhifadhi\Contracts\People\RankLadderInterface;

/**
 * THE LADDER, READ FROM THE RANKS PAGE'S OWN ORDER: every active rank of
 * every active scale, the scales as that page lists them and the ranks of a
 * scale from most senior down — so place 1 is the most senior rank the
 * organization has, and a person's place is the place of the rank they hold
 * now. Three queries for any number of people: the ladder, the people, their
 * current holdings.
 */
final readonly class TeamRankLadder implements RankLadderInterface
{
    public function __construct(
        private RankRepository $ranks,
        private UserRepository $users,
        private RankHoldingRepository $holdings,
    ) {
    }

    public function placesOf(array $personUuids): array
    {
        if ([] === $personUuids) {
            return [];
        }

        $placeOfRank = $this->placeOfRank();
        if ([] === $placeOfRank) {
            return [];
        }

        $people = $this->users->findByUuids(array_values(array_unique($personUuids)));
        $held = $this->holdings->findCurrentByPeople($people);

        $places = [];
        foreach ($people as $person) {
            $uuid = $person->getUuidString();
            $holding = $held[(int) $person->getId()] ?? null;
            if (null === $uuid || null === $holding) {
                continue;
            }
            $place = $placeOfRank[(int) $holding->getRank()->getId()] ?? null;
            if (null !== $place) {
                $places[$uuid] = $place;
            }
        }

        return $places;
    }

    public function length(): int
    {
        return \count($this->placeOfRank());
    }

    /** @return array<int, int> each active rank's place on the ladder, keyed by rank id */
    private function placeOfRank(): array
    {
        $place = 0;
        $map = [];
        foreach ($this->ranks->findActiveOrdered() as $rank) {
            if ($rank->getScale()->isRetired()) {
                continue;
            }
            $map[(int) $rank->getId()] = ++$place;
        }

        return $map;
    }
}
