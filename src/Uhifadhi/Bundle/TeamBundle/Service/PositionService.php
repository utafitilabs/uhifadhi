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

namespace Uhifadhi\Bundle\TeamBundle\Service;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Uhifadhi\Bundle\TeamBundle\Access\ConcernCatalogue;
use Uhifadhi\Bundle\TeamBundle\Entity\GrantJustification;
use Uhifadhi\Bundle\TeamBundle\Entity\Position;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Bundle\TeamBundle\Enum\TeamRoleEnum;
use Uhifadhi\Bundle\TeamBundle\Exception\NameNotUniqueException;
use Uhifadhi\Bundle\TeamBundle\Exception\PositionHeldException;
use Uhifadhi\Bundle\TeamBundle\Exception\RuleExceptionRefusedException;
use Uhifadhi\Bundle\TeamBundle\Exception\SeatsBelowHoldersException;
use Uhifadhi\Bundle\TeamBundle\Exception\UnknownGrantException;
use Uhifadhi\Bundle\TeamBundle\Repository\GrantJustificationRepository;
use Uhifadhi\Bundle\TeamBundle\Repository\UserRepository;
use Uhifadhi\Contracts\Access\Grant;
use Uhifadhi\Contracts\Access\ScopeKind;

/**
 * WHAT A POSITION IS, AND WHAT IT GRANTS — the only writes that shape either.
 *
 * A position is the only thing that grants a staff member any capability at
 * all, so the two facts about it are its NAME — unique across the whole
 * organization, because a position belongs to no department — and its GRANT.
 * Both are written here.
 *
 * A GRANT IS A (CONCERN, VERB) PAIR. It used to be a flat permission value
 * validated against the flat permission catalogue; the ruled model replaced
 * that with `<concern>.<verb>` pairs declared by whoever enforces them, so
 * this writes {@see Position::setGrantValues()} against
 * {@see ConcernCatalogue::pairs()} and the flat write is gone from here. The
 * flat catalogue is still shipped for the gates that have not moved yet, and
 * nothing on this class reads it.
 *
 * THE GRANT IS VALIDATED AGAINST THE CATALOGUE, ALWAYS. What there is to have
 * a permission about is not fixed — the team's four concerns are this
 * bundle's and the rest arrive and leave with the modules that declare them —
 * so what may be written is asked of the catalogue rather than assumed. A
 * pair nothing declares is refused rather than stored, and a pair a position
 * ALREADY holds that nothing declares any more is left exactly where it is:
 * pruned, not purged. Removing it on the module's way out would silently
 * rewrite what an administrator granted.
 *
 * IT DECIDES NOTHING ABOUT WHO IS ASKING — with one exception, on purpose.
 * Whether the administrator on the other end may confer an ordinary
 * permission is an area-scope question about the signed-in session
 * ({@see \Uhifadhi\Bundle\TeamBundle\Security\AreaAuthority}), settled by the
 * screen before it calls in here.
 *
 * AN EXCEPTION TO A RULE IS THE ONE WRITE THAT ASKS WHO. A concern that lifts
 * a rule (the control room's "Live locations" lifts the rank rule) is given
 * and taken only by a Super Admin, only with a written reason, and never
 * through the matrix — ruled 2026-09-26. That is checked HERE, not on a
 * screen, so no screen, command or import written later can forget it.
 */
final readonly class PositionService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ConcernCatalogue $catalogue,
        private UserRepository $users,
        private ?GrantJustificationRepository $justifications = null,
    ) {
    }

    /**
     * ONE FIELD, AND IT IS THE NAME — unique across the organization, because
     * a position belongs to nobody. There is one Sergeant, not one per
     * department, so a reader of a person's record never has to ask which.
     *
     * A NEW POSITION IS BORN EMPTY, and the day it was born is the day it
     * fell vacant: a position written in March and never filled has stood
     * empty since March, which is exactly what a director needs to see.
     *
     * @throws NameNotUniqueException when the organization already has the name
     */
    public function create(string $name): Position
    {
        $position = new Position()->setName($name)
            ->setVacantSince(new \DateTimeImmutable());

        $this->entityManager->persist($position);
        $this->flush($name);

        return $position;
    }

    /** @throws NameNotUniqueException when the organization already has the name */
    public function rename(Position $position, string $name): void
    {
        $position->setName($name);

        $this->flush($name);
    }

    /**
     * THE WHOLE GRANT, REPLACED. What is absent from the list is what was
     * revoked — the matrix posts only what is ticked — so this is a set, never
     * an addition.
     *
     * @param list<string> $pairs each written `<concern>.<verb>`
     *
     * @throws UnknownGrantException         when a pair nothing installed declares is granted
     * @throws RuleExceptionRefusedException when the save names an exception the seat does not hold
     */
    public function setGrants(Position $position, array $pairs): void
    {
        // AN EXCEPTION IS NEVER THE MATRIX'S TO WRITE. The matrix does not
        // draw one, so a save that names one it does not already hold is a
        // forged form, and a save that leaves one out is merely a save of the
        // boxes it draws: what the seat holds as an exception stays.
        $exceptions = $this->catalogue->exceptionPairs();
        $heldExceptions = array_values(array_intersect($position->getGrantValues(), $exceptions));
        foreach ($pairs as $pair) {
            if (\in_array($pair, $exceptions, true) && !\in_array($pair, $heldExceptions, true)) {
                throw RuleExceptionRefusedException::onItsOwnCard($this->labelOf($pair));
            }
        }

        $ordinary = array_values(array_filter($pairs, static fn (string $pair): bool => !\in_array($pair, $exceptions, true)));
        $position->setGrantValues(array_values(array_unique([...$ordinary, ...$heldExceptions])), $this->catalogue->pairs());

        $this->entityManager->flush();
    }

    /** The shortest reason a Super Admin may write: long enough to be a sentence, not a shrug. */
    public const int REASON_MIN_LENGTH = 12;

    /**
     * GIVING A SEAT AN EXCEPTION TO A RULE: a Super Admin, a written reason,
     * and a pair that is an exception — or nothing is written.
     *
     * @throws RuleExceptionRefusedException when any of the three is missing, or the seat already holds it
     */
    public function grantException(Position $position, string $pair, string $reason, User $actor, ?\DateTimeImmutable $now = null): GrantJustification
    {
        $label = $this->exceptionLabel($pair);
        $this->assertSuperAdmin($actor, $label);

        $reason = trim($reason);
        if (mb_strlen($reason) < self::REASON_MIN_LENGTH) {
            throw RuleExceptionRefusedException::noReason($label);
        }

        if (\in_array($pair, $position->getGrantValues(), true) || null !== $this->justifications?->findOneCurrent($position, $pair)) {
            throw RuleExceptionRefusedException::alreadyHeld($label, (string) $position->getName());
        }

        $justification = new GrantJustification($position, $pair, $reason, $actor, $now ?? new \DateTimeImmutable());
        $this->entityManager->persist($justification);
        $position->setGrantValues([...$position->getGrantValues(), $pair], $this->catalogue->pairs());

        $this->entityManager->flush();

        return $justification;
    }

    /**
     * TAKING IT AWAY: the pair leaves the seat, and its row is stamped with
     * who and when rather than deleted, so the history still reads true.
     *
     * @throws RuleExceptionRefusedException when the actor is not a Super Admin or the seat does not hold it
     */
    public function revokeException(Position $position, string $pair, User $actor, ?\DateTimeImmutable $now = null): void
    {
        $label = $this->exceptionLabel($pair);
        $this->assertSuperAdmin($actor, $label);

        $held = \in_array($pair, $position->getGrantValues(), true);
        $current = $this->justifications?->findOneCurrent($position, $pair);
        if (!$held && null === $current) {
            throw RuleExceptionRefusedException::notHeld($label, (string) $position->getName());
        }

        $current?->revoke($actor, $now ?? new \DateTimeImmutable());
        $position->setGrantValues(
            array_values(array_filter($position->getGrantValues(), static fn (string $value): bool => $value !== $pair)),
            $this->catalogue->pairs(),
        );

        $this->entityManager->flush();
    }

    /** @throws RuleExceptionRefusedException when the pair is not an exception */
    private function exceptionLabel(string $pair): string
    {
        if (!\in_array($pair, $this->catalogue->exceptionPairs(), true)) {
            throw RuleExceptionRefusedException::notAnException($pair);
        }

        return $this->labelOf($pair);
    }

    private function labelOf(string $pair): string
    {
        $grant = Grant::tryParse($pair);

        return null === $grant ? $pair : ($this->catalogue->concern($grant->concern)?->label() ?? $pair);
    }

    /** @throws RuleExceptionRefusedException */
    private function assertSuperAdmin(User $actor, string $label): void
    {
        if (TeamRoleEnum::SuperAdmin !== $actor->getTeamRole()) {
            throw RuleExceptionRefusedException::notASuperAdmin($label);
        }
    }

    /**
     * WHAT A POSITION IS, IN ONE WRITE — the three facts the identity card
     * states, saved together because they are refused together.
     *
     * THE SEAT COUNT CANNOT FALL BELOW THE PEOPLE ALREADY IN IT. Choosing
     * which two of six holders lose their seat is not a decision a product
     * may make, so the write is refused and the floor is named.
     *
     * @param list<ScopeKind> $allowedKinds
     *
     * @throws NameNotUniqueException     when the organization already has the name
     * @throws SeatsBelowHoldersException when the count is below the holders
     * @throws \InvalidArgumentException  when the kinds are not a placement's kinds
     */
    public function setIdentity(Position $position, string $name, ?int $seatCount, array $allowedKinds): void
    {
        $holders = \count($this->users->findActiveHolders($position));
        if (null !== $seatCount && $seatCount < $holders) {
            throw new SeatsBelowHoldersException($position, $seatCount, $holders);
        }

        $position->setName($name)->setSeatCount($seatCount)->setAllowedKinds($allowedKinds);

        $this->flush($name);
    }

    /**
     * CLOSING A POSITION. We do not delete things: the row stays, everything
     * it granted keeps its history, and it can come back.
     *
     * REFUSED WHILE ANYBODY HOLDS IT, and the refusal names the count.
     *
     * @throws PositionHeldException when somebody still holds it
     */
    public function retire(Position $position, ?\DateTimeImmutable $now = null): void
    {
        $holders = \count($this->users->findActiveHolders($position));
        if ($holders > 0) {
            throw new PositionHeldException($position, $holders);
        }

        $position->retire($now ?? new \DateTimeImmutable());

        $this->entityManager->flush();
    }

    /** Reopening a retired position; it is assignable again the moment the stamp is cleared. */
    public function reinstate(Position $position): void
    {
        $position->reinstate();

        $this->entityManager->flush();
    }

    /**
     * The unique index on the name is what actually refuses a repeated one.
     * Carried out of the storage layer here so a caller catches a fact about
     * the org chart rather than a driver exception.
     *
     * @throws NameNotUniqueException
     */
    private function flush(string $name): void
    {
        try {
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException $clash) {
            throw new NameNotUniqueException($name, $clash);
        }
    }
}
