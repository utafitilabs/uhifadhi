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

namespace Uhifadhi\Bundle\AreaBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\Entity\CheckIn;
use Uhifadhi\Bundle\AreaBundle\Entity\PersonPosition;

/**
 * THE PINGS, KEPT. Nothing a page reads comes from here: what a board needs
 * is folded into each watch's check-in row as the pings arrive
 * ({@see CheckInRepository::foldPings()}), and recomputed from these rows
 * only by `area:presence:rebuild`.
 *
 * @extends ServiceEntityRepository<PersonPosition>
 */
class PersonPositionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PersonPosition::class);
    }

    /**
     * WHICH OF THESE REFERENCES THE AREA ALREADY HOLDS — one query, so a
     * batch of two hundred pings is one round trip rather than two
     * hundred.
     *
     * @param list<string> $clientRefs
     *
     * @return list<string>
     */
    public function knownRefs(AreaOfInterest $area, array $clientRefs): array
    {
        if ([] === $clientRefs) {
            return [];
        }

        /** @var list<array{clientRef: string}> $rows */
        $rows = $this->createQueryBuilder('p')
            ->select('p.clientRef AS clientRef')
            ->andWhere('p.area = :area')
            ->andWhere('p.clientRef IN (:refs)')
            ->setParameter('area', $area)
            ->setParameter('refs', $clientRefs)
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $row): string => $row['clientRef'], $rows);
    }

    /**
     * THE PINGS OF ONE WATCH, oldest first — the watch's own trail.
     *
     * @return list<PersonPosition>
     */
    public function forCheckIn(CheckIn $checkIn): array
    {
        /** @var list<PersonPosition> $rows */
        $rows = $this->createQueryBuilder('p')
            ->andWhere('p.checkIn = :checkin')
            ->setParameter('checkin', $checkIn)
            ->orderBy('p.recordedAt', 'ASC')
            ->getQuery()
            ->getResult();

        return $rows;
    }
}
