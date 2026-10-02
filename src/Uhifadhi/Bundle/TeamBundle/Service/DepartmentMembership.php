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

use Uhifadhi\Bundle\TeamBundle\Entity\Department;
use Uhifadhi\Bundle\TeamBundle\Entity\Position;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Bundle\TeamBundle\Repository\UserRepository;

/**
 * WHO IS IN A DEPARTMENT, AND THEREFORE WHICH POSITIONS IT SEES.
 *
 * A DEPARTMENT IS A PLACEMENT, NOT AN OWNER, so neither of those questions
 * has a column any more. Membership is read off each person's placement, and
 * a department's positions are the distinct positions its members hold -
 * derived, in one place, because a second copy of the derivation would be a
 * second answer and the one that disagreed would be the one nobody noticed.
 *
 * SOMEBODY PLACED ACROSS ALL DEPARTMENTS IS IN EVERY ONE OF THEM. That is
 * what the placement says, and a screen that quietly left them out of each
 * department's list would be answering a different question from the one the
 * voter answers.
 */
final readonly class DepartmentMembership
{
    public function __construct(
        private UserRepository $users,
    ) {
    }

    /**
     * EVERYBODY PLACED IN THIS DEPARTMENT, active and inactive alike - the
     * caller filters, because "who is in Ecology" and "who is in Ecology and
     * still signing in" are different questions and this one must not answer
     * the second by accident.
     *
     * @return list<User>
     */
    public function membersOf(Department $department): array
    {
        return array_values(array_filter(
            $this->users->findAllByName(),
            static fn (User $user): bool => $user->getPlacement()?->belongsTo($department) ?? false,
        ));
    }

    /**
     * THE POSITIONS A DEPARTMENT SEES: the distinct positions held by the
     * people placed in it. A department whose members hold nothing has none,
     * and that is a real state rather than an empty query.
     *
     * @return list<Position>
     */
    public function positionsIn(Department $department): array
    {
        $byName = [];
        foreach ($this->membersOf($department) as $member) {
            $position = $member->getPosition();
            if (null !== $position) {
                $byName[(string) $position->getName()] = $position;
            }
        }

        ksort($byName);

        return array_values($byName);
    }

    /**
     * WHETHER ONE PERSON BELONGS TO ONE DEPARTMENT — the reporting line, not
     * the departments they support.
     */
    public function covers(User $user, Department $department): bool
    {
        return $user->getPlacement()?->belongsTo($department) ?? false;
    }
}
