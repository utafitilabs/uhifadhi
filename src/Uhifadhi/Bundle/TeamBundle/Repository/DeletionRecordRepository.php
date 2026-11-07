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
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Uhifadhi\Bundle\TeamBundle\Entity\DeletionRecord;

/**
 * @extends ServiceEntityRepository<DeletionRecord>
 */
class DeletionRecordRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DeletionRecord::class);
    }

    /**
     * Newest first, the lines the register's filter leaves, one page of them
     * from the first row the shell's pager asked for; a null limit is every
     * row from the offset on.
     *
     * @return list<DeletionRecord>
     */
    public function findMatching(?string $kind, ?string $by, ?string $search, int $offset = 0, ?int $limit = null): array
    {
        /** @var list<DeletionRecord> $rows */
        $rows = $this->filtered($kind, $by, $search)
            ->orderBy('d.deletedAt', 'DESC')->addOrderBy('d.id', 'DESC')
            ->setFirstResult($offset)->setMaxResults($limit)
            ->getQuery()->getResult();

        return $rows;
    }

    /** How many lines the register's filter leaves, on every page. */
    public function countMatching(?string $kind, ?string $by, ?string $search): int
    {
        return (int) $this->filtered($kind, $by, $search)->select('COUNT(d.id)')->getQuery()->getSingleScalarResult();
    }

    /**
     * How many lines carry each value of one column — the kind, or who
     * deleted — over the whole log, in the value's order.
     *
     * @param 'kind'|'byName' $field
     *
     * @return array<string, int>
     */
    public function countBy(string $field): array
    {
        /** @var list<array{value: string, n: int|string}> $rows */
        $rows = $this->createQueryBuilder('d')
            ->select('d.'.$field.' AS value', 'COUNT(d.id) AS n')
            ->groupBy('d.'.$field)->orderBy('d.'.$field, 'ASC')
            ->getQuery()->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(string) $row['value']] = (int) $row['n'];
        }

        return $counts;
    }

    /** THE REGISTER'S ONE FILTER: the kind, who deleted, and the words of the title, the reference or what went. */
    private function filtered(?string $kind, ?string $by, ?string $search): QueryBuilder
    {
        $qb = $this->createQueryBuilder('d');
        if (null !== $kind) {
            $qb->andWhere('d.kind = :kind')->setParameter('kind', $kind);
        }
        if (null !== $by) {
            $qb->andWhere('d.byName = :by')->setParameter('by', $by);
        }
        if (null !== $search) {
            $qb->andWhere('LOWER(d.title) LIKE :q OR LOWER(d.reference) LIKE :q OR LOWER(d.whatWent) LIKE :q')
                ->setParameter('q', '%'.mb_strtolower($search).'%');
        }

        return $qb;
    }
}
