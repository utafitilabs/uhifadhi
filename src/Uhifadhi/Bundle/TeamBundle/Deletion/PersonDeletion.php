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
use Uhifadhi\Bundle\TeamBundle\Entity\ApiToken;
use Uhifadhi\Bundle\TeamBundle\Entity\OneTimePassword;
use Uhifadhi\Bundle\TeamBundle\Entity\RankHolding;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Contracts\Deletion\DeletionContributorInterface;
use Uhifadhi\Contracts\Deletion\DeletionLine;
use Uhifadhi\Contracts\Deletion\DeletionSubject;

/**
 * A PERSON, DELETED BY A SUPER ADMIN (ruled 28 Sep, #48: people too, and what
 * they recorded goes with them, counted first). The Team owns the account:
 * it names the person, counts what of the Team's goes with them, and removes
 * the account and its placement last. What they recorded elsewhere is counted
 * and removed by whoever holds it.
 */
final readonly class PersonDeletion implements DeletionContributorInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UrlGeneratorInterface $router,
    ) {
    }

    public function supports(object $record): bool
    {
        return $record instanceof User;
    }

    public function describe(object $record): DeletionSubject
    {
        \assert($record instanceof User);
        $position = $record->getPosition()?->getName() ?? 'no position';

        return new DeletionSubject(
            kind: 'person',
            reference: $record->getFullName(),
            title: 'Person '.$record->getFullName(),
            summary: $position.' · '.$record->getEmail(),
            recordUrl: $this->router->generate('team_member', ['uuid' => $record->getUuidString()]),
            afterUrl: $this->router->generate('team_index'),
            register: 'team',
        );
    }

    public function whatGoes(object $record): array
    {
        \assert($record instanceof User);

        return array_values(array_filter([
            new DeletionLine('accounts', 1, detail: $record->getEmail(), singular: 'account'),
            new DeletionLine('rank lines', $this->count(RankHolding::class, 'person', $record), singular: 'rank line'),
            new DeletionLine('signed-in handsets', $this->count(ApiToken::class, 'owner', $record), singular: 'signed-in handset'),
            new DeletionLine('one-time passwords', $this->count(OneTimePassword::class, 'person', $record), singular: 'one-time password'),
        ], static fn (DeletionLine $line): bool => $line->count > 0));
    }

    public function whatStays(object $record): array
    {
        return [];
    }

    public function delete(object $record): void
    {
        \assert($record instanceof User);
        $placement = $record->getPlacement();
        $this->entityManager->remove($record);
        if (null !== $placement) {
            $this->entityManager->remove($placement);
        }
        $this->entityManager->flush();
    }

    /** @param class-string $entity */
    private function count(string $entity, string $field, User $person): int
    {
        return (int) $this->entityManager->createQuery(\sprintf('SELECT COUNT(x) FROM %s x WHERE x.%s = :person', $entity, $field))
            ->setParameter('person', $person)->getSingleScalarResult();
    }
}
