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
use Uhifadhi\Bundle\TeamBundle\Entity\GrantJustification;
use Uhifadhi\Bundle\TeamBundle\Entity\Position;

/**
 * @extends ServiceEntityRepository<GrantJustification>
 */
class GrantJustificationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GrantJustification::class);
    }

    /** The reason in force for one seat and pair, or null. */
    public function findOneCurrent(Position $position, string $pair): ?GrantJustification
    {
        return $this->findOneBy(['position' => $position, 'pair' => $pair, 'revokedAt' => null]);
    }

    /**
     * The reasons in force for one seat, keyed by pair.
     *
     * @return array<string, GrantJustification>
     */
    public function findCurrentByPosition(Position $position): array
    {
        $byPair = [];
        foreach ($this->findBy(['position' => $position, 'revokedAt' => null]) as $justification) {
            $byPair[$justification->getPair()] = $justification;
        }

        return $byPair;
    }

    /**
     * Every row a seat has had, in force or taken away, the newest given first.
     *
     * @return list<GrantJustification>
     */
    public function findByPositionNewestFirst(Position $position): array
    {
        /** @var list<GrantJustification> $rows */
        $rows = $this->findBy(['position' => $position], ['grantedAt' => 'DESC', 'id' => 'DESC']);

        return $rows;
    }

    /**
     * EVERY EXCEPTION IN FORCE ACROSS THE ORGANIZATION, for the review list —
     * by position name, then pair.
     *
     * @return list<GrantJustification>
     */
    public function findAllCurrent(): array
    {
        /** @var list<GrantJustification> $rows */
        $rows = $this->createQueryBuilder('j')
            ->join('j.position', 'p')
            ->addSelect('p')
            ->andWhere('j.revokedAt IS NULL')
            ->orderBy('p.name', 'ASC')
            ->addOrderBy('j.pair', 'ASC')
            ->getQuery()
            ->getResult();

        return $rows;
    }
}
