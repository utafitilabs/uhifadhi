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
use Symfony\Component\Uid\Uuid;
use Uhifadhi\Bundle\RegistryBundle\Entity\AreaModule;
use Uhifadhi\Contracts\Entity\AreaInterface;

/**
 * Per-area install state, read in the area's own order.
 *
 * EVERY READING IS A QUERY. Nothing here is memoised, and that is the design,
 * not an omission: the promise the platform makes to an area manager is that
 * switching a module off takes its contributions off the page the same day, and
 * a cache in front of these two methods is precisely how that promise stops
 * being true.
 *
 * NOT FINAL. This bundle is installed by other packages, and a Doctrine
 * repository is the documented place to add a query — or to stand in for one
 * in somebody else's test. Sealing it would make the registry's query surface the
 * only one its consumers may ever have.
 *
 * @extends ServiceEntityRepository<AreaModule>
 */
class AreaModuleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AreaModule::class);
    }

    /**
     * Every module assigned to an area, active and parked alike, in sub-nav order.
     *
     * @return list<AreaModule>
     */
    public function forArea(AreaInterface $area): array
    {
        return $this->orderedFor($area, onlyActive: false);
    }

    /**
     * Only the area's active modules, in sub-nav order — what a sub-nav renders.
     *
     * @return list<AreaModule>
     */
    public function activeForArea(AreaInterface $area): array
    {
        return $this->orderedFor($area, onlyActive: true);
    }

    /**
     * IS THIS MODULE SWITCHED ON FOR THE AREA AT THIS UUID — one row, one query,
     * and no entity hydrated.
     *
     * Three answers, and the third is the one worth naming: `true` active,
     * `false` parked, `null` **no row at all** — an area that has never taken
     * the module, or a uuid that is no area. The caller is the route gate, and
     * for it the last two are the same sentence: the module is not part of this
     * area.
     *
     * ADDRESSED BY UUID, because that is what a URL carries. The join reaches
     * into the resolved area entity's own `uuid` field, which every area in this
     * fleet has and no line of {@see AreaInterface} requires — see
     * {@see areaIsAddressableByUuid()} for what happens where it is missing.
     */
    public function activeStateForAreaUuid(string $areaUuid, string $slug): ?bool
    {
        if (!Uuid::isValid($areaUuid)) {
            return null;
        }

        /** @var array{active: bool}|null $row */
        $row = $this->createQueryBuilder('am')
            ->select('am.active')
            ->join('am.module', 'm')
            ->join('am.area', 'a')
            ->andWhere('a.uuid = :uuid')
            ->andWhere('m.slug = :slug')
            ->setParameter('uuid', Uuid::fromString($areaUuid), 'uuid')
            ->setParameter('slug', $slug)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $row['active'] ?? null;
    }

    /**
     * CAN AN AREA BE FOUND BY UUID HERE AT ALL — asked once, before the query
     * above is ever built.
     *
     * The registry's area contract asks for identity and nothing else: an
     * installation may resolve {@see AreaInterface} to a class with no uuid, and
     * that installation is not misconfigured, it simply addresses areas some
     * other way. The honest thing there is to say so and stand aside — a gate
     * that cannot read the URL must not take the site down guessing.
     */
    public function areaIsAddressableByUuid(): bool
    {
        $areaClass = $this->getEntityManager()
            ->getClassMetadata(AreaModule::class)
            ->getAssociationMapping('area')['targetEntity'] ?? null;

        if (!\is_string($areaClass) || AreaInterface::class === $areaClass || !class_exists($areaClass)) {
            return false;
        }

        return $this->getEntityManager()->getClassMetadata($areaClass)->hasField('uuid');
    }

    /**
     * @return list<AreaModule>
     */
    private function orderedFor(AreaInterface $area, bool $onlyActive): array
    {
        $qb = $this->createQueryBuilder('am')
            ->join('am.module', 'm')
            ->addSelect('m')
            ->andWhere('am.area = :area')
            ->setParameter('area', $area)
            ->orderBy('am.position', 'ASC')
            ->addOrderBy('am.id', 'ASC');

        if ($onlyActive) {
            $qb->andWhere('am.active = true');
        }

        /** @var list<AreaModule> $result */
        $result = $qb->getQuery()->getResult();

        return $result;
    }

    /**
     * WHEN EACH AREA SWITCHED EACH MODULE ON, for every active assignment
     * in the installation — the one read a performance topic needs to
     * date its holes and to know which departments it may be asked about.
     *
     * A ROW WITH NO DATE is one written before the day was recorded; it
     * comes back as null, and every surface reads that as "running, since
     * nobody knows when" rather than dating it at the upgrade.
     *
     * @return array<string, array<string, \DateTimeImmutable|null>> area uuid to slug to when it was switched on
     */
    public function runningSince(): array
    {
        /** @var list<array{area: Uuid|string, slug: string, installedAt: \DateTimeImmutable|null}> $rows */
        $rows = $this->createQueryBuilder('am')
            ->select('a.uuid AS area, m.slug AS slug, am.installedAt AS installedAt')
            ->join('am.module', 'm')
            ->join('am.area', 'a')
            ->andWhere('am.active = true')
            ->getQuery()
            ->getArrayResult();

        $running = [];
        foreach ($rows as $row) {
            $running[(string) $row['area']][(string) $row['slug']] = $row['installedAt'];
        }

        return $running;
    }

    /**
     * EVERY AREA THE INSTALLATION HOLDS, whatever class it maps them to: the
     * target the `area` association names, read in id order. A kernel that maps
     * no concrete area (the association left at the interface) has none.
     *
     * @return iterable<AreaInterface>
     */
    public function everyArea(): iterable
    {
        $em = $this->getEntityManager();
        $areaClass = $em->getClassMetadata(AreaModule::class)->getAssociationMapping('area')->targetEntity;

        if (AreaInterface::class === $areaClass || !class_exists($areaClass)) {
            return [];
        }

        $identifier = $em->getClassMetadata($areaClass)->getSingleIdentifierFieldName();

        /** @var iterable<AreaInterface> $areas */
        $areas = $em->getRepository($areaClass)->findBy([], [$identifier => 'ASC']);

        return $areas;
    }
}
