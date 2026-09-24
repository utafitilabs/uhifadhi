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
use Uhifadhi\Bundle\TeamBundle\Entity\TeamSettings;

/**
 * NOT FINAL, as the account repository is not: a unit suite stubs the one
 * read the account service makes of it.
 *
 * @extends ServiceEntityRepository<TeamSettings>
 */
class TeamSettingsRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TeamSettings::class);
    }

    /** The one row, or null on a schema the migration never wrote. */
    public function findOne(): ?TeamSettings
    {
        return $this->find(TeamSettings::ONE);
    }
}
