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
use Uhifadhi\Bundle\TeamBundle\Entity\RankScale;

/**
 * @extends ServiceEntityRepository<Rank>
 */
class RankRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Rank::class);
    }

    /**
     * EVERY RANK IN USE, scale by scale and the highest rank first — the order of
     * the register, the facet and the configure list.
     *
     * @return list<Rank>
     */
    public function findActiveOrdered(): array
    {
        /** @var list<Rank> $ranks */
        $ranks = $this->createQueryBuilder('r')
            ->join('r.scale', 's')
            ->addSelect('s')
            ->andWhere('r.retiredAt IS NULL')
            ->orderBy('s.sortOrder', 'ASC')
            ->addOrderBy('s.id', 'ASC')
            ->addOrderBy('r.seniority', 'ASC')
            ->addOrderBy('r.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $ranks;
    }

    /** @return list<Rank> the ranks in use on one scale, the highest rank first */
    public function findActiveByScale(RankScale $scale): array
    {
        return $this->findBy(['scale' => $scale, 'retiredAt' => null], ['seniority' => 'ASC', 'id' => 'ASC']);
    }

    /** The highest seniority on a scale, retired ranks included, so a new rank never shares a place. */
    public function getMaxSeniority(RankScale $scale): int
    {
        $max = $this->createQueryBuilder('r')
            ->select('MAX(r.seniority)')
            ->andWhere('r.scale = :scale')
            ->setParameter('scale', $scale)
            ->getQuery()
            ->getSingleScalarResult();

        return is_numeric($max) ? (int) $max : 0;
    }
}
