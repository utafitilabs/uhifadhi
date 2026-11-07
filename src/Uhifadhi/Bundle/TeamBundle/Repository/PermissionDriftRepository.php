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
use Uhifadhi\Bundle\TeamBundle\Entity\PermissionDrift;
use Uhifadhi\Bundle\TeamBundle\Entity\PermissionRun;

/**
 * THE DRIFT A RUN FOUND, and how much of it nobody has looked at yet.
 *
 * @extends ServiceEntityRepository<PermissionDrift>
 */
class PermissionDriftRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PermissionDrift::class);
    }

    /** @return list<PermissionDrift> by subject, question and place */
    public function findByRun(PermissionRun $run): array
    {
        return $this->findBy(['run' => $run], ['subjectName' => 'ASC', 'questionKey' => 'ASC', 'placeName' => 'ASC', 'id' => 'ASC']);
    }

    public function countUnreviewedByRun(PermissionRun $run): int
    {
        return $this->count(['run' => $run, 'reviewedAt' => null]);
    }
}
