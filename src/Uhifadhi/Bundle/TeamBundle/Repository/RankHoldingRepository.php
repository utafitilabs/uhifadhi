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

namespace Uhifadhi\Bundle\TeamBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Uhifadhi\Bundle\TeamBundle\Entity\Rank;
use Uhifadhi\Bundle\TeamBundle\Entity\RankHolding;
use Uhifadhi\Bundle\TeamBundle\Entity\User;

/**
 * @extends ServiceEntityRepository<RankHolding>
 */
class RankHoldingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RankHolding::class);
    }

    /** The rank a person holds now, or null. */
    public function findCurrentByPerson(User $person): ?RankHolding
    {
        return $this->findOneBy(['person' => $person, 'until' => null]);
    }

    /**
     * THE RANK EACH OF THESE PEOPLE HOLDS NOW, keyed by the person's id — one
     * query for a page of the register, never one per row.
     *
     * @param list<User> $people
     *
     * @return array<int, RankHolding>
     */
    public function findCurrentByPeople(array $people): array
    {
        if ([] === $people) {
            return [];
        }

        /** @var list<RankHolding> $holdings */
        $holdings = $this->createQueryBuilder('h')
            ->join('h.rank', 'r')
            ->addSelect('r')
            ->join('r.scale', 's')
            ->addSelect('s')
            ->andWhere('h.person IN (:people)')
            ->andWhere('h.until IS NULL')
            ->setParameter('people', $people)
            ->getQuery()
            ->getResult();

        $byPerson = [];
        foreach ($holdings as $holding) {
            $byPerson[(int) $holding->getPerson()->getId()] = $holding;
        }

        return $byPerson;
    }

    /** @return list<RankHolding> a person's ranks, the one held now first */
    public function findByPersonNewestFirst(User $person): array
    {
        /** @var list<RankHolding> $holdings */
        $holdings = $this->createQueryBuilder('h')
            ->join('h.rank', 'r')
            ->addSelect('r')
            ->leftJoin('h.recordedBy', 'b')
            ->addSelect('b')
            ->andWhere('h.person = :person')
            ->setParameter('person', $person)
            ->orderBy('h.since', 'DESC')
            ->addOrderBy('h.id', 'DESC')
            ->getQuery()
            ->getResult();

        return $holdings;
    }

    /**
     * HOW MANY HOLD EACH RANK NOW, keyed by the rank's id. A rank nobody holds
     * is absent, and reads as nought.
     *
     * @return array<int, int>
     */
    public function countCurrentByRank(): array
    {
        /** @var list<array{rank: int|string, holders: int|string}> $rows */
        $rows = $this->createQueryBuilder('h')
            ->select('IDENTITY(h.rank) AS rank', 'COUNT(h.id) AS holders')
            ->andWhere('h.until IS NULL')
            ->groupBy('h.rank')
            ->getQuery()
            ->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['rank']] = (int) $row['holders'];
        }

        return $counts;
    }

    /** Whether anybody ever held this rank — the difference between retiring it and deleting it. */
    public function isEverHeld(Rank $rank): bool
    {
        return null !== $this->findOneBy(['rank' => $rank]);
    }
}
