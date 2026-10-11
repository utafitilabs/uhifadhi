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
use Uhifadhi\Bundle\TeamBundle\Entity\Department;
use Uhifadhi\Bundle\TeamBundle\Entity\DepartmentPeriodFigure;

/**
 * @extends ServiceEntityRepository<DepartmentPeriodFigure>
 */
final class DepartmentPeriodFigureRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DepartmentPeriodFigure::class);
    }

    public function findOne(Department $department, string $periodKey, string $figureKey): ?DepartmentPeriodFigure
    {
        return $this->findOneBy([
            'department' => $department,
            'periodKey' => $periodKey,
            'figureKey' => $figureKey,
        ]);
    }

    /**
     * ONE FIGURE OVER SEVERAL PERIODS, in one query — a sparkline is six
     * cells and a page draws one per row, which is a page of round trips
     * if they are read one at a time.
     *
     * @param list<string> $periodKeys
     *
     * @return array<string, float|null> period key to what was written
     */
    public function run(Department $department, string $figureKey, array $periodKeys): array
    {
        if ([] === $periodKeys) {
            return [];
        }

        /** @var list<array{periodKey: string, value: float|null}> $rows */
        $rows = $this->createQueryBuilder('f')
            ->select('f.periodKey AS periodKey, f.value AS value')
            ->andWhere('f.department = :department')
            ->andWhere('f.figureKey = :figure')
            ->andWhere('f.periodKey IN (:periods)')
            ->setParameter('department', $department)
            ->setParameter('figure', $figureKey)
            ->setParameter('periods', $periodKeys)
            ->getQuery()
            ->getArrayResult();

        $written = [];
        foreach ($rows as $row) {
            $written[$row['periodKey']] = $row['value'];
        }

        return $written;
    }

    /**
     * SEVERAL FIGURES OF SEVERAL DEPARTMENTS OVER SEVERAL PERIODS, in one
     * read — what a page drawing a run for every row asks.
     *
     * @param list<Department> $departments
     * @param list<string>     $figureKeys
     * @param list<string>     $periodKeys
     *
     * @return array<string, array<string, array<string, float|null>>> period key, then department uuid, then figure key, to what was written
     */
    public function findByDepartmentsAndPeriods(array $departments, array $figureKeys, array $periodKeys): array
    {
        if ([] === $departments || [] === $figureKeys || [] === $periodKeys) {
            return [];
        }

        /** @var list<array{uuid: \Symfony\Component\Uid\Uuid|string, periodKey: string, figureKey: string, value: float|null}> $rows */
        $rows = $this->createQueryBuilder('f')
            ->select('d.uuid AS uuid, f.periodKey AS periodKey, f.figureKey AS figureKey, f.value AS value')
            ->join('f.department', 'd')
            ->andWhere('f.department IN (:departments)')
            ->andWhere('f.figureKey IN (:figures)')
            ->andWhere('f.periodKey IN (:periods)')
            ->setParameter('departments', $departments)
            ->setParameter('figures', $figureKeys)
            ->setParameter('periods', $periodKeys)
            ->getQuery()
            ->getArrayResult();

        $written = [];
        foreach ($rows as $row) {
            $written[$row['periodKey']][(string) $row['uuid']][$row['figureKey']] = $row['value'];
        }

        return $written;
    }

    /**
     * ONE FIGURE, ONE PERIOD, EVERY DEPARTMENT — what a whole matrix column
     * needs in a single read.
     *
     * @return array<string, float|null> department uuid to what was written
     */
    public function acrossDepartments(string $figureKey, string $periodKey): array
    {
        /** @var list<array{uuid: \Symfony\Component\Uid\Uuid|string, value: float|null}> $rows */
        $rows = $this->createQueryBuilder('f')
            ->select('d.uuid AS uuid, f.value AS value')
            ->join('f.department', 'd')
            ->andWhere('f.figureKey = :figure')
            ->andWhere('f.periodKey = :period')
            ->setParameter('figure', $figureKey)
            ->setParameter('period', $periodKey)
            ->getQuery()
            ->getArrayResult();

        $written = [];
        foreach ($rows as $row) {
            $written[(string) $row['uuid']] = $row['value'];
        }

        return $written;
    }
}
