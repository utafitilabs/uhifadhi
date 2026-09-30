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
use Uhifadhi\Bundle\TeamBundle\Entity\Department;
use Uhifadhi\Bundle\TeamBundle\Entity\Placement;
use Uhifadhi\Contracts\Deletion\DeletionContributorInterface;
use Uhifadhi\Contracts\Deletion\DeletionLine;
use Uhifadhi\Contracts\Deletion\DeletionSubject;
use Uhifadhi\Contracts\Entity\AreaInterface;

/**
 * WHAT OF THE TEAM'S AN AREA'S DELETE REACHES (ruled 28 Sep, #48): the
 * departments confined to it go with it, and the people placed in it stay,
 * placed in whatever ground they have left. The database does both.
 */
final readonly class AreaTeamDeletion implements DeletionContributorInterface
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function supports(object $record): bool
    {
        return $record instanceof AreaInterface;
    }

    public function describe(object $record): ?DeletionSubject
    {
        return null;
    }

    public function whatGoes(object $record): array
    {
        $departments = (int) $this->entityManager->createQuery(\sprintf('SELECT COUNT(d) FROM %s d WHERE d.area = :area', Department::class))
            ->setParameter('area', $record)->getSingleScalarResult();

        return $departments > 0 ? [new DeletionLine('departments confined to it', $departments, singular: 'department confined to it')] : [];
    }

    public function whatStays(object $record): array
    {
        $placed = (int) $this->entityManager->createQuery(\sprintf('SELECT COUNT(p) FROM %s p JOIN p.areas a WHERE a = :area', Placement::class))
            ->setParameter('area', $record)->getSingleScalarResult();

        return $placed > 0 ? [new DeletionLine('people placed in it', $placed, detail: 'stay, placed in whatever ground they have left', singular: 'person placed in it')] : [];
    }

    /** The database's cascade removes the departments and the placement lines. */
    public function delete(object $record): void
    {
    }
}
