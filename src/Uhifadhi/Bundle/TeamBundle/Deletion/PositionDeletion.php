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
use Uhifadhi\Bundle\TeamBundle\Entity\DepartmentGoal;
use Uhifadhi\Bundle\TeamBundle\Entity\GrantJustification;
use Uhifadhi\Bundle\TeamBundle\Entity\Position;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Contracts\Deletion\DeletionContributorInterface;
use Uhifadhi\Contracts\Deletion\DeletionLine;
use Uhifadhi\Contracts\Deletion\DeletionSubject;

/**
 * A POSITION, DELETED BY A SUPER ADMIN (ruled 28 Sep, #48). Its exceptions go
 * with it; whoever holds it stays and holds no position - and so, until they
 * are seated again, no permissions at all.
 */
final readonly class PositionDeletion implements DeletionContributorInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UrlGeneratorInterface $router,
    ) {
    }

    public function supports(object $record): bool
    {
        return $record instanceof Position;
    }

    public function describe(object $record): DeletionSubject
    {
        \assert($record instanceof Position);

        return new DeletionSubject(
            kind: 'position',
            reference: (string) $record->getName(),
            title: 'Position '.$record->getName(),
            summary: \sprintf('%d grants · held by %d', \count($record->getGrantValues()), $this->count(User::class, 'position', $record)),
            recordUrl: $this->router->generate('team_position_show', ['uuid' => $record->getUuidString()]),
            afterUrl: $this->router->generate('team_positions'),
            register: 'positions',
        );
    }

    public function whatGoes(object $record): array
    {
        \assert($record instanceof Position);

        return array_values(array_filter([
            new DeletionLine('positions', 1, detail: \count($record->getGrantValues()).' grants', singular: 'position'),
            new DeletionLine('exceptions to a rule', $this->count(GrantJustification::class, 'position', $record), singular: 'exception to a rule'),
        ], static fn (DeletionLine $line): bool => $line->count > 0));
    }

    public function whatStays(object $record): array
    {
        \assert($record instanceof Position);

        return array_values(array_filter([
            new DeletionLine('people', $this->count(User::class, 'position', $record), detail: 'stay, and hold no position until they are seated again', singular: 'person'),
            new DeletionLine('department goals', $this->count(DepartmentGoal::class, 'owner', $record), detail: 'stay, without an owner', singular: 'department goal'),
        ], static fn (DeletionLine $line): bool => $line->count > 0));
    }

    public function delete(object $record): void
    {
        $this->entityManager->remove($record);
        $this->entityManager->flush();
    }

    /** @param class-string $entity */
    private function count(string $entity, string $field, Position $position): int
    {
        return (int) $this->entityManager->createQuery(\sprintf('SELECT COUNT(x) FROM %s x WHERE x.%s = :position', $entity, $field))
            ->setParameter('position', $position)->getSingleScalarResult();
    }
}
