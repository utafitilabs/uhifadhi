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

use Uhifadhi\Bundle\TeamBundle\Access\ConcernCatalogue;
use Uhifadhi\Bundle\TeamBundle\Entity\PermissionAnswer;
use Uhifadhi\Bundle\TeamBundle\Entity\PermissionFinding;
use Uhifadhi\Bundle\TeamBundle\Entity\PermissionRun;
use Uhifadhi\Bundle\TeamBundle\Entity\Position;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Bundle\TeamBundle\Enum\FindingListEnum;
use Uhifadhi\Bundle\TeamBundle\Enum\QuestionKindEnum;
use Uhifadhi\Bundle\TeamBundle\Enum\TeamRoleEnum;
use Uhifadhi\Bundle\TeamBundle\Repository\GrantJustificationRepository;
use Uhifadhi\Bundle\TeamBundle\Repository\PermissionChangeRepository;
use Uhifadhi\Bundle\TeamBundle\Repository\PermissionRunRepository;
use Uhifadhi\Bundle\TeamBundle\Repository\PositionRepository;
use Uhifadhi\Bundle\TeamBundle\Repository\UserRepository;
use Uhifadhi\Contracts\Access\Grant;
use Uhifadhi\Contracts\Access\PowerGroup;
use Uhifadhi\Contracts\Access\PowerTarget;

/**
 * THE SIX STANDING LISTS OF A RUN, read from the people, the positions and
 * the exceptions as they stand, and from the run's own power answers:
 *
 *   1. people who reach nothing — no position, no placement, or a placement
 *      with no ground or no department;
 *   2. sensitive pairs held but not in force — a pair that lifts a rule,
 *      stored on a position with no written reason;
 *   3. tier-only pairs a position still stores, not honoured;
 *   4. gaining-power answers that are not nobody — a Super Admin's are
 *      expected (ruled 7 Nov), so only another actor's yes is filed;
 *   5. positions nobody holds;
 *   6. exceptions in force;
 *   7. self-changes, informational: a person who changed their own tier,
 *      rank or position since the previous run (the last thirty days when
 *      there is none), read from the history.
 *
 * A retired position is on none of them: nobody can be seated in it.
 */
final readonly class PermissionFindingService
{
    public function __construct(
        private UserRepository $users,
        private PositionRepository $positions,
        private GrantJustificationRepository $justifications,
        private ConcernCatalogue $catalogue,
        private PowerCatalogue $powers,
        private PermissionRunRepository $runs,
        private PermissionChangeRepository $changes,
    ) {
    }

    /** The history kinds a self-change is: a tier, a seat, a rank. */
    public const array SELF_CHANGES = ['tier-changed' => 'tier', 'seated' => 'position', 'unseated' => 'position', 'rank-changed' => 'rank'];

    /**
     * @param list<PermissionAnswer> $answers the run's own answers, for the gaining-power list
     *
     * @return list<PermissionFinding>
     */
    public function compute(PermissionRun $run, array $answers): array
    {
        $people = array_values(array_filter($this->users->findAllByName(), static fn (User $u): bool => $u->isActive()));
        $positions = array_values(array_filter($this->positions->findAllOrdered(), static fn (Position $p): bool => !$p->isRetired()));

        return [
            ...$this->reachNothing($run, $people),
            ...$this->positionPairs($run, $positions),
            ...$this->gaining($run, $answers, $positions),
            ...$this->vacant($run, $positions, $people),
            ...$this->exceptions($run),
            ...$this->selfChanges($run),
        ];
    }

    /**
     * @param list<User> $people
     *
     * @return list<PermissionFinding>
     */
    private function reachNothing(PermissionRun $run, array $people): array
    {
        $findings = [];
        foreach ($people as $person) {
            if (TeamRoleEnum::Staff !== $person->getTeamRole()) {
                continue;
            }
            $placement = $person->getPlacement();
            $why = [];
            if (null === $person->getPosition()) {
                $why[] = 'no position';
            }
            if (null === $placement) {
                $why[] = 'no placement';
            } elseif (null === $placement->getDepartment()) {
                $why[] = 'no department';
            } elseif ($placement->reachesNothing()) {
                $why[] = 'no ground';
            }
            if ([] !== $why) {
                $findings[] = new PermissionFinding($run, FindingListEnum::ReachNothing, PermissionFinding::PERSON, $person->getId(), self::nameOf($person),
                    positionName: $person->getPosition()?->getName(), detail: implode(' · ', $why));
            }
        }

        return $findings;
    }

    /**
     * @param list<Position> $positions
     *
     * @return list<PermissionFinding>
     */
    private function positionPairs(PermissionRun $run, array $positions): array
    {
        $findings = [];
        foreach ($positions as $position) {
            $name = (string) $position->getName();
            foreach ($position->getGrantValues() as $pair) {
                $grant = Grant::tryParse($pair);
                if (null === $grant) {
                    continue;
                }
                if ($this->catalogue->isTierOnly($pair)) {
                    $findings[] = new PermissionFinding($run, FindingListEnum::NotHonoured, PermissionFinding::POSITION, $position->getId(), $name,
                        positionName: $name, pair: $pair, detail: 'only the tiers hold it');
                }
                $lifts = $this->catalogue->lifts($grant->concern);
                if (null !== $lifts && null === $this->justifications->findOneCurrent($position, $pair)) {
                    $findings[] = new PermissionFinding($run, FindingListEnum::NotInForce, PermissionFinding::POSITION, $position->getId(), $name,
                        positionName: $name, pair: $pair, detail: \sprintf('lifts %s, and no written reason is in force', $lifts));
                }
            }
        }

        return $findings;
    }

    /**
     * @param list<PermissionAnswer> $answers
     * @param list<Position>         $positions
     *
     * @return list<PermissionFinding>
     */
    private function gaining(PermissionRun $run, array $answers, array $positions): array
    {
        $gaining = [];
        foreach ($this->powers->all() as $power) {
            if (PowerGroup::GainingPower === $power->group) {
                $gaining[$power->key] = $power;
            }
        }
        $names = [];
        foreach ($positions as $position) {
            $id = $position->getId();
            if (null !== $id) {
                $names[$id] = (string) $position->getName();
            }
        }

        $findings = [];
        foreach ($answers as $answer) {
            if (QuestionKindEnum::Power !== $answer->getQuestionKind() || CellAnswer::Allowed->value !== $answer->getVerdict()
                || !isset($gaining[$answer->getQuestionKey()])) {
                continue;
            }
            $tier = null === $answer->getTier() ? null : TeamRoleEnum::tryFrom($answer->getTier());
            if (TeamRoleEnum::SuperAdmin === $tier) {
                continue;
            }
            $position = $answer->getPositionId();
            $subject = null !== $tier ? $tier->label() : (null === $position ? 'no position' : ($names[$position] ?? 'a position'));
            $findings[] = new PermissionFinding($run, FindingListEnum::Gaining, null === $tier ? PermissionFinding::POSITION : PermissionFinding::PERSON,
                $answer->getPositionId(), $subject,
                positionName: null === $tier ? $subject : null,
                pair: $answer->getQuestionKey(),
                detail: $gaining[$answer->getQuestionKey()]->label.' — '.(PowerTarget::tryFrom((string) $answer->getTarget())?->label() ?? (string) $answer->getTarget()));
        }

        return $findings;
    }

    /**
     * @param list<Position> $positions
     * @param list<User>     $people
     *
     * @return list<PermissionFinding>
     */
    private function vacant(PermissionRun $run, array $positions, array $people): array
    {
        $held = [];
        foreach ($people as $person) {
            $id = $person->getPosition()?->getId();
            if (null !== $id) {
                $held[$id] = true;
            }
        }

        $findings = [];
        foreach ($positions as $position) {
            $id = $position->getId();
            if (null !== $id && !isset($held[$id])) {
                $findings[] = new PermissionFinding($run, FindingListEnum::Vacant, PermissionFinding::POSITION, $position->getId(), (string) $position->getName(),
                    positionName: (string) $position->getName(), at: $position->getVacantSince());
            }
        }

        return $findings;
    }

    /** @return list<PermissionFinding> */
    private function exceptions(PermissionRun $run): array
    {
        $findings = [];
        foreach ($this->justifications->findAllCurrent() as $justification) {
            $position = $justification->getPosition();
            if ($position->isRetired()) {
                continue;
            }
            $findings[] = new PermissionFinding($run, FindingListEnum::Exceptions, PermissionFinding::POSITION, $position->getId(), (string) $position->getName(),
                positionName: (string) $position->getName(), pair: $justification->getPair(), detail: $justification->getReason(),
                byName: $justification->getGrantedByName(), at: $justification->getGrantedAt());
        }

        return $findings;
    }

    /** @return list<PermissionFinding> */
    private function selfChanges(PermissionRun $run): array
    {
        $to = $run->getStartedAt() ?? new \DateTimeImmutable();
        $from = $this->runs->findOnePreviousFinished($run)?->getStartedAt() ?? $to->modify('-30 days');

        $findings = [];
        foreach ($this->changes->findSelfChangesBetween($from, $to, array_keys(self::SELF_CHANGES)) as $line) {
            $findings[] = new PermissionFinding($run, FindingListEnum::SelfChanges, PermissionFinding::PERSON, $line->getSubjectId(), $line->getSubjectName(),
                byName: $line->getByName(), changeKind: $line->getAction()->value, what: self::SELF_CHANGES[$line->getAction()->value] ?? $line->getAction()->value,
                beforeValue: $line->getBefore(), afterValue: $line->getAfter(), changedAt: $line->getRecordedAt());
        }

        return $findings;
    }

    private static function nameOf(User $person): string
    {
        $name = trim($person->getFullName());

        return '' === $name ? (string) $person->getEmail() : $name;
    }
}
