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
use Uhifadhi\Bundle\TeamBundle\Entity\OneTimePassword;
use Uhifadhi\Bundle\TeamBundle\Entity\User;

/**
 * @extends ServiceEntityRepository<OneTimePassword>
 */
class OneTimePasswordRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OneTimePassword::class);
    }

    /**
     * Every one-time password issued for a person, newest first.
     *
     * @return list<OneTimePassword>
     */
    public function findByPerson(User $person): array
    {
        return $this->findBy(['person' => $person], ['issuedAt' => 'DESC', 'id' => 'DESC']);
    }

    /** The latest issued for a person and not yet used, or null. */
    public function findOneUnusedByPerson(User $person): ?OneTimePassword
    {
        return $this->findOneBy(['person' => $person, 'usedAt' => null], ['issuedAt' => 'DESC', 'id' => 'DESC']);
    }
}
