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
use Uhifadhi\Bundle\TeamBundle\Entity\PermissionRun;
use Uhifadhi\Bundle\TeamBundle\Enum\PermissionRunStatusEnum;

/**
 * THE PERMISSIONS WORKER'S RUNS: the request waiting, the run going, and the
 * finished runs newest first — the latest is what every page reads.
 *
 * @extends ServiceEntityRepository<PermissionRun>
 */
class PermissionRunRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PermissionRun::class);
    }

    public function findOneLatestFinished(): ?PermissionRun
    {
        /** @var PermissionRun|null $run */
        $run = $this->createQueryBuilder('r')
            ->andWhere('r.status = :finished')
            ->setParameter('finished', PermissionRunStatusEnum::Finished)
            ->orderBy('r.startedAt', 'DESC')
            ->addOrderBy('r.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $run;
    }

    /** The finished run before this one, which its drift is measured against. */
    public function findOnePreviousFinished(PermissionRun $run): ?PermissionRun
    {
        /** @var PermissionRun|null $previous */
        $previous = $this->createQueryBuilder('r')
            ->andWhere('r.status = :finished')
            ->andWhere('r.id < :run')
            ->andWhere('r.startedAt <= :started')
            ->setParameter('finished', PermissionRunStatusEnum::Finished)
            ->setParameter('run', $run->getId())
            ->setParameter('started', $run->getStartedAt() ?? $run->getRequestedAt())
            ->orderBy('r.startedAt', 'DESC')
            ->addOrderBy('r.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $previous;
    }

    /** The newest run that failed, for the freshness line's warning. */
    public function findOneLatestFailed(): ?PermissionRun
    {
        return $this->findOneBy(['status' => PermissionRunStatusEnum::Failed], ['requestedAt' => 'DESC', 'id' => 'DESC']);
    }

    /** The oldest request still waiting for the worker. */
    public function findOnePending(): ?PermissionRun
    {
        return $this->findOneBy(['status' => PermissionRunStatusEnum::Pending], ['requestedAt' => 'ASC', 'id' => 'ASC']);
    }

    public function findOneRunning(): ?PermissionRun
    {
        return $this->findOneBy(['status' => PermissionRunStatusEnum::Running], ['startedAt' => 'DESC']);
    }

    /** @return list<PermissionRun> every request still waiting */
    public function findPending(): array
    {
        return $this->findBy(['status' => PermissionRunStatusEnum::Pending], ['requestedAt' => 'ASC', 'id' => 'ASC']);
    }

    /**
     * The finished runs asked for before this one — the ones whose answers
     * and findings may be pruned once it is the previous run. By id, since
     * the worker takes requests oldest first and two runs may start in the
     * same second.
     *
     * @return list<PermissionRun>
     */
    public function findFinishedOlderThan(PermissionRun $run): array
    {
        /** @var list<PermissionRun> $runs */
        $runs = $this->createQueryBuilder('r')
            ->andWhere('r.status = :finished')
            ->andWhere('r.id < :run')
            ->setParameter('finished', PermissionRunStatusEnum::Finished)
            ->setParameter('run', $run->getId())
            ->orderBy('r.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $runs;
    }
}
