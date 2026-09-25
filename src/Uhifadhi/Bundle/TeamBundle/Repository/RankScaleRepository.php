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
use Uhifadhi\Bundle\TeamBundle\Entity\RankScale;

/**
 * @extends ServiceEntityRepository<RankScale>
 */
class RankScaleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RankScale::class);
    }

    /** @return list<RankScale> the scales still read, in the order the organization reads them; a retired scale is nowhere */
    public function findAllOrdered(): array
    {
        return $this->findBy(['retiredAt' => null], ['sortOrder' => 'ASC', 'id' => 'ASC']);
    }
}
