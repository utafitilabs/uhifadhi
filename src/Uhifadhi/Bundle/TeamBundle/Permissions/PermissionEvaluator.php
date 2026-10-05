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

namespace Uhifadhi\Bundle\TeamBundle\Permissions;

use Symfony\Component\Security\Core\Authorization\AccessDecision;
use Symfony\Component\Security\Core\Authorization\UserAuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;
use Uhifadhi\Bundle\TeamBundle\Entity\Position;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Bundle\TeamBundle\Enum\TeamRoleEnum;
use Uhifadhi\Bundle\TeamBundle\Repository\PositionRepository;
use Uhifadhi\Bundle\TeamBundle\Repository\UserRepository;
use Uhifadhi\Bundle\TeamBundle\Security\Reach;
use Uhifadhi\Contracts\Access\Power;
use Uhifadhi\Contracts\Access\PowerTarget;

/**
 * THE PERMISSIONS PAGE'S ANSWERS. Every power is asked as a real account about
 * a real target on this installation, through the authorization checker's
 * isGrantedForUser() — the same voters the routes ask, for an account that is
 * not the one signed in, changing nothing.
 *
 * ONE HOLDER SPEAKS FOR A POSITION: people who hold the same position answer
 * alike, so the ledger asks one holder of each, and one Admin and one Super
 * Admin; any single person is asked in full by forPerson().
 *
 * @see https://symfony.com/doc/current/security.html#securing-other-services — "you can use the isGrantedForUser() method to explicitly set the target user"
 */
final readonly class PermissionEvaluator
{
    public function __construct(
        private PowerCatalogue $powers,
        private UserRepository $users,
        private PositionRepository $positions,
        private UserAuthorizationCheckerInterface $checker,
    ) {
    }

    /** @return list<User> one active Staff holder of each position, then an Admin, then a Super Admin */
    public function actors(): array
    {
        $actors = [];
        foreach ($this->positions->findAllOrdered() as $position) {
            foreach ($this->users->findActiveHolders($position) as $holder) {
                if (TeamRoleEnum::Staff === $holder->getTeamRole()) {
                    $actors[] = $holder;
                    break;
                }
            }
        }
        foreach ([TeamRoleEnum::Admin, TeamRoleEnum::SuperAdmin] as $tier) {
            $first = $this->users->findActiveInTier($tier)[0] ?? null;
            if (null !== $first) {
                $actors[] = $first;
            }
        }

        return $actors;
    }

    /** @return list<Cell> every actor's cells */
    public function ledger(): array
    {
        $cells = [];
        foreach ($this->actors() as $actor) {
            $cells = [...$cells, ...$this->forPerson($actor)];
        }

        return $cells;
    }

    /** @return list<Cell> every power asked as this person, about each of its targets */
    public function forPerson(User $actor): array
    {
        $people = array_values(array_filter($this->users->findAllByName(), static fn (User $u): bool => $u->isActive()));
        $positions = $this->positions->findAllOrdered();

        $cells = [];
        foreach ($this->powers->all() as $power) {
            foreach ($power->targets as $kind) {
                $cells[] = $this->ask($actor, $power, $kind, $people, $positions);
            }
        }

        return $cells;
    }

    /**
     * WHO MAY ACT ON THIS PERSON: every actor asked every power that is asked
     * about somebody else, with this person as the somebody.
     *
     * @return list<Cell> the allowed cells only
     */
    public function actingOn(User $person): array
    {
        $cells = [];
        foreach ($this->actors() as $actor) {
            if ($actor->getId() === $person->getId()) {
                continue;
            }
            foreach ($this->powers->all() as $power) {
                $kind = self::first($power->targets, static fn (PowerTarget $k): bool => $k->isPerson() && PowerTarget::Themselves !== $k);
                if (null === $kind) {
                    continue;
                }
                $cell = $this->askAbout($actor, $power, $kind, $person);
                if (CellAnswer::Allowed === $cell->answer) {
                    $cells[] = $cell;
                }
            }
        }

        return $cells;
    }

    /**
     * @param list<User>     $people
     * @param list<Position> $positions
     */
    private function ask(User $actor, Power $power, PowerTarget $kind, array $people, array $positions): Cell
    {
        [$found, $target] = $this->target($actor, $kind, $people, $positions);
        if (!$found) {
            return new Cell($actor, $power, $kind, $kind->label(), CellAnswer::NoTarget);
        }

        return $this->askAbout($actor, $power, $kind, $target);
    }

    private function askAbout(User $actor, Power $power, PowerTarget $kind, User|Position|null $target): Cell
    {
        $label = $target instanceof User ? $target->getFullName() : ($target instanceof Position ? (string) $target->getName() : $kind->label());
        $source = $this->powers->sourceOf($power);
        foreach ($power->questions as $question) {
            $decision = new AccessDecision();
            if (!$this->checker->isGrantedForUser($actor, $question, $source->subject($power, $question, $kind, $target), $decision)) {
                return new Cell($actor, $power, $kind, $label, CellAnswer::Refused, $question, self::reasons($decision));
            }
        }

        return new Cell($actor, $power, $kind, $label, CellAnswer::Allowed);
    }

    /** @return list<string> */
    private static function reasons(AccessDecision $decision): array
    {
        $reasons = [];
        foreach ($decision->votes as $vote) {
            if (VoterInterface::ACCESS_DENIED === $vote->result) {
                $reasons = [...$reasons, ...$vote->reasons];
            }
        }

        return array_values(array_unique($reasons));
    }

    /**
     * A REAL TARGET OF THIS KIND, found relative to the actor — or none, when
     * the installation has nobody or nothing of that kind to ask about.
     *
     * @param list<User>     $people
     * @param list<Position> $positions
     *
     * @return array{bool, User|Position|null}
     */
    private function target(User $actor, PowerTarget $kind, array $people, array $positions): array
    {
        $reach = new Reach($actor);
        $others = array_values(array_filter($people, static fn (User $u): bool => $u->getId() !== $actor->getId()));
        $staff = array_values(array_filter($others, static fn (User $u): bool => TeamRoleEnum::Staff === $u->getTeamRole()));
        $own = $actor->getPosition();

        $found = match ($kind) {
            PowerTarget::Themselves => $actor,
            PowerTarget::TheirOwnPosition => $own,
            PowerTarget::ANewAccount, PowerTarget::TheRankScale => null,
            PowerTarget::AColleague => self::first($staff, static fn (User $u): bool => $reach->reachesPerson($u)),
            PowerTarget::SomebodyBeyondTheirArea => self::first($staff, static fn (User $u): bool => !$reach->reachesPerson($u)),
            PowerTarget::AnAdmin => self::first($others, static fn (User $u): bool => TeamRoleEnum::Admin === $u->getTeamRole()),
            PowerTarget::ASuperAdmin => self::first($others, static fn (User $u): bool => TeamRoleEnum::SuperAdmin === $u->getTeamRole()),
            PowerTarget::AStrongerPosition => self::first($positions, static fn (Position $p): bool => $p !== $own
                && [] !== array_diff($p->getGrantValues(), $own?->getGrantValues() ?? [])),
            PowerTarget::APositionHeldElsewhere => self::first($positions, fn (Position $p): bool => $p !== $own
                && [] !== array_filter($this->users->findActiveHolders($p), static fn (User $h): bool => !$reach->reachesPerson($h))),
        };

        return [null !== $found || \in_array($kind, [PowerTarget::ANewAccount, PowerTarget::TheRankScale], true), $found];
    }

    /**
     * @template T of object
     *
     * @param list<T>           $items
     * @param callable(T): bool $test
     *
     * @return T|null
     */
    private static function first(array $items, callable $test): ?object
    {
        foreach ($items as $item) {
            if ($test($item)) {
                return $item;
            }
        }

        return null;
    }
}
