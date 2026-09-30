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
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Uhifadhi\Bundle\TeamBundle\Entity\DeletionRecord;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Bundle\TeamBundle\Enum\TeamRoleEnum;
use Uhifadhi\Contracts\Deletion\DeletionContributorInterface;

/**
 * A SUPER ADMIN DELETES WHAT WAS MADE BY MISTAKE OR IN TESTS (ruled 28 Sep,
 * #48): a hard delete of the record and everything under it, counted first,
 * confirmed by typing its reference, and one audit line kept.
 *
 * THE CORE NEVER NAMES A MODULE. Whoever holds rows a delete reaches answers
 * for them through {@see DeletionContributorInterface}: one owns the kind of
 * record, every other one clears its own rows first.
 *
 * THE TIER IS READ FROM THE SIGNED-IN ACCOUNT ITSELF, never from a grant: a
 * Super Admin who has switched into somebody else is that somebody, and is
 * refused.
 */
final readonly class DeletionService
{
    /**
     * @param iterable<DeletionContributorInterface> $contributors
     */
    public function __construct(
        private iterable $contributors,
        private EntityManagerInterface $entityManager,
        private TokenStorageInterface $tokens,
    ) {
    }

    public function mayDelete(): bool
    {
        return null !== $this->superAdmin();
    }

    /** What deleting this record would take and leave, before anything happens. */
    public function plan(object $record): DeletionPlan
    {
        $subject = null;
        $goes = [];
        $stays = [];
        foreach ($this->supporting($record) as $contributor) {
            $described = $contributor->describe($record);
            if (null !== $described) {
                if (null !== $subject) {
                    throw new \LogicException(\sprintf('Two contributors own the %s being deleted; one kind of record has one owner.', $described->kind));
                }
                $subject = $described;
            }
            $goes = [...$goes, ...$contributor->whatGoes($record)];
            $stays = [...$stays, ...$contributor->whatStays($record)];
        }

        if (null === $subject) {
            throw new \LogicException(\sprintf('Nothing owns a %s, so nothing can delete it.', $record::class));
        }

        return new DeletionPlan($subject, $goes, $stays);
    }

    /**
     * @throws AccessDeniedException    for anybody but a Super Admin
     * @throws DeletionRefusedException when the typed reference is not the record's
     */
    public function delete(object $record, string $typed, ?\DateTimeImmutable $now = null): DeletionRecord
    {
        $actor = $this->superAdmin() ?? throw new AccessDeniedException('Only a Super Admin deletes.');
        $plan = $this->plan($record);
        if ($typed !== $plan->subject->reference) {
            throw DeletionRefusedException::referenceMismatch($plan->subject->reference);
        }

        $line = new DeletionRecord(
            deletedAt: $now ?? new \DateTimeImmutable(),
            byName: $actor->getFullName(),
            byId: $actor->getId(),
            kind: $plan->subject->kind,
            reference: $plan->subject->reference,
            title: $plan->subject->title,
            whatWent: $plan->whatGoes(),
        );

        $this->entityManager->wrapInTransaction(function () use ($record, $line): void {
            $owner = null;
            foreach ($this->supporting($record) as $contributor) {
                if (null !== $contributor->describe($record)) {
                    $owner = $contributor;
                    continue;
                }
                $contributor->delete($record);
            }
            $owner?->delete($record);
            $this->entityManager->persist($line);
            $this->entityManager->flush();
        });

        return $line;
    }

    /** @return list<DeletionContributorInterface> */
    private function supporting(object $record): array
    {
        $found = [];
        foreach ($this->contributors as $contributor) {
            if ($contributor->supports($record)) {
                $found[] = $contributor;
            }
        }

        return $found;
    }

    private function superAdmin(): ?User
    {
        $user = $this->tokens->getToken()?->getUser();

        return $user instanceof User && TeamRoleEnum::SuperAdmin === $user->getTeamRole() ? $user : null;
    }
}
