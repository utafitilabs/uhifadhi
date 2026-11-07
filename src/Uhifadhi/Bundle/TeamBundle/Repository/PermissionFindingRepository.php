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
use Uhifadhi\Bundle\TeamBundle\Entity\PermissionFinding;
use Uhifadhi\Bundle\TeamBundle\Entity\PermissionRun;
use Uhifadhi\Bundle\TeamBundle\Enum\FindingListEnum;

/**
 * A RUN'S STANDING LISTS, one list at a time, and their counts for the hub.
 *
 * @extends ServiceEntityRepository<PermissionFinding>
 */
class PermissionFindingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PermissionFinding::class);
    }

    /** @return list<PermissionFinding> in the order the list reads: by name */
    public function findByRunAndList(PermissionRun $run, FindingListEnum $list): array
    {
        return $this->findBy(['run' => $run, 'list' => $list], ['subjectName' => 'ASC', 'pair' => 'ASC', 'id' => 'ASC']);
    }

    /** @return array<string, int> list value to its count; a list with nothing on it is absent */
    public function countByRunAndList(PermissionRun $run): array
    {
        /** @var list<array{list: FindingListEnum, n: int|string}> $rows */
        $rows = $this->createQueryBuilder('f')
            ->select('f.list AS list', 'COUNT(f.id) AS n')
            ->andWhere('f.run = :run')
            ->setParameter('run', $run)
            ->groupBy('f.list')
            ->getQuery()
            ->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[$row['list']->value] = (int) $row['n'];
        }

        return $counts;
    }

    public function deleteByRun(PermissionRun $run): int
    {
        $deleted = $this->createQueryBuilder('f')
            ->delete()
            ->andWhere('f.run = :run')
            ->setParameter('run', $run)
            ->getQuery()
            ->execute();

        return \is_int($deleted) ? $deleted : 0;
    }
}
