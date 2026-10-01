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

namespace Uhifadhi\Bundle\RegistryBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Uhifadhi\Bundle\RegistryBundle\Entity\SettingValue;
use Uhifadhi\Contracts\Settings\SettingDepth;

/**
 * @extends ServiceEntityRepository<SettingValue>
 */
class SettingValueRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SettingValue::class);
    }

    /**
     * @return list<SettingValue>
     */
    public function forKey(string $key): array
    {
        /** @var list<SettingValue> */
        return $this->findBy(['key' => $key]);
    }

    public function at(string $key, SettingDepth $level, ?string $placeUuid): ?SettingValue
    {
        return $this->findOneBy(['key' => $key, 'level' => $level, 'placeUuid' => $placeUuid]);
    }
}
