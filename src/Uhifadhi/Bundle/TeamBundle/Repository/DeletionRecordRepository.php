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
     * Newest first, one page of them.
     *
     * @return list<DeletionRecord>
     */
    public function findPage(int $offset, int $limit): array
    {
        /** @var list<DeletionRecord> $rows */
        $rows = $this->createQueryBuilder('d')
            ->orderBy('d.deletedAt', 'DESC')->addOrderBy('d.id', 'DESC')
            ->setFirstResult($offset)->setMaxResults($limit)
            ->getQuery()->getResult();

        return $rows;
    }
}
