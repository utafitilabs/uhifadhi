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

namespace Uhifadhi\Bundle\TeamBundle\Deletion;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Uhifadhi\Bundle\TeamBundle\Controller\DepartmentController;
use Uhifadhi\Bundle\TeamBundle\Entity\Department;
use Uhifadhi\Bundle\TeamBundle\Entity\DepartmentGoal;
use Uhifadhi\Bundle\TeamBundle\Entity\DepartmentPeriodFigure;
use Uhifadhi\Bundle\TeamBundle\Entity\Placement;
use Uhifadhi\Contracts\Deletion\DeletionContributorInterface;
use Uhifadhi\Contracts\Deletion\DeletionLine;
use Uhifadhi\Contracts\Deletion\DeletionSubject;

/**
 * A DEPARTMENT, DELETED BY A SUPER ADMIN (ruled 28 Sep, #48). Its goals, the
 * modules it runs and its stored figures go with it; the people placed in it
 * stay, placed in the departments they have left.
 */
final readonly class DepartmentDeletion implements DeletionContributorInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UrlGeneratorInterface $router,
    ) {
    }

    public function supports(object $record): bool
    {
        return $record instanceof Department;
    }

    public function describe(object $record): DeletionSubject
    {
        \assert($record instanceof Department);

        return new DeletionSubject(
            kind: 'department',
            reference: (string) $record->getName(),
            title: 'Department '.$record->getName(),
            summary: ($record->getArea()?->getName() ?? 'the whole organization').' · '.\count($record->getModules()).' modules',
            recordUrl: $this->router->generate(DepartmentController::RECORD, ['uuid' => $record->getUuidString()]),
            afterUrl: $this->router->generate(DepartmentController::REGISTER),
            register: 'departments',
        );
    }

    public function whatGoes(object $record): array
    {
        \assert($record instanceof Department);

        return array_values(array_filter([
            new DeletionLine('departments', 1, singular: 'department'),
            new DeletionLine('goals', $this->count(DepartmentGoal::class, $record), singular: 'goal'),
            new DeletionLine('modules it runs', \count($record->getModules()), detail: 'the modules stay installed', singular: 'module it runs'),
            new DeletionLine('stored figures', $this->count(DepartmentPeriodFigure::class, $record), singular: 'stored figure'),
        ], static fn (DeletionLine $line): bool => $line->count > 0));
    }

    public function whatStays(object $record): array
    {
        $placed = (int) $this->entityManager->createQuery(\sprintf('SELECT COUNT(p) FROM %s p WHERE p.department = :department', Placement::class))
            ->setParameter('department', $record)->getSingleScalarResult();

        // THEY STAY, WITHOUT A DEPARTMENT: an unfinished record that reaches
        // nothing until somebody files them in another.
        return $placed > 0 ? [new DeletionLine('people who belong to it', $placed, detail: 'stay, without a department until they are filed in another', singular: 'person who belongs to it')] : [];
    }

    public function delete(object $record): void
    {
        $this->entityManager->remove($record);
        $this->entityManager->flush();
    }

    /** @param class-string $entity */
    private function count(string $entity, Department $department): int
    {
        return (int) $this->entityManager->createQuery(\sprintf('SELECT COUNT(x) FROM %s x WHERE x.department = :department', $entity))
            ->setParameter('department', $department)->getSingleScalarResult();
    }
}
