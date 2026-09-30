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

namespace Uhifadhi\Bundle\AreaBundle\Deletion;

use Doctrine\ORM\EntityManagerInterface;
use Uhifadhi\Bundle\AreaBundle\Entity\CheckIn;
use Uhifadhi\Bundle\AreaBundle\Entity\PersonPosition;
use Uhifadhi\Bundle\AreaBundle\Entity\Posting;
use Uhifadhi\Contracts\Deletion\DeletionContributorInterface;
use Uhifadhi\Contracts\Deletion\DeletionLine;
use Uhifadhi\Contracts\Deletion\DeletionSubject;
use Uhifadhi\Contracts\Entity\UserInterface;

/**
 * WHAT OF THE AREA'S GOES WITH A DELETED PERSON (ruled 28 Sep, #48): their
 * postings, their check-ins and the position reports their phone sent. The
 * database removes all three with the account; this counts them first, so
 * the Super Admin reads what goes before typing the name.
 */
final readonly class PersonDutyDeletion implements DeletionContributorInterface
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function supports(object $record): bool
    {
        return $record instanceof UserInterface;
    }

    public function describe(object $record): ?DeletionSubject
    {
        return null;
    }

    public function whatGoes(object $record): array
    {
        \assert($record instanceof UserInterface);

        return array_values(array_filter([
            new DeletionLine('postings', $this->count(Posting::class, $record), singular: 'posting'),
            new DeletionLine('check-ins', $this->count(CheckIn::class, $record), singular: 'check-in'),
            new DeletionLine('position reports', $this->count(PersonPosition::class, $record), singular: 'position report'),
        ], static fn (DeletionLine $line): bool => $line->count > 0));
    }

    public function whatStays(object $record): array
    {
        return [];
    }

    /** The database's cascade removes them with the account. */
    public function delete(object $record): void
    {
    }

    /** @param class-string $entity */
    private function count(string $entity, UserInterface $person): int
    {
        return (int) $this->entityManager->createQuery(\sprintf('SELECT COUNT(x) FROM %s x WHERE x.person = :person', $entity))
            ->setParameter('person', $person)->getSingleScalarResult();
    }
}
