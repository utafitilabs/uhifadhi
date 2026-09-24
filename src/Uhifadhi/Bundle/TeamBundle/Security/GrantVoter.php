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

namespace Uhifadhi\Bundle\TeamBundle\Security;

use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Uhifadhi\Bundle\TeamBundle\Access\ConcernCatalogue;
use Uhifadhi\Bundle\TeamBundle\Entity\Department;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Bundle\TeamBundle\Repository\DepartmentRepository;
use Uhifadhi\Contracts\Access\Grant;
use Uhifadhi\Contracts\Entity\AreaInterface;

/**
 * A CHECK ASKS THREE QUESTIONS, AND IT FAILS CLOSED.
 *
 *   1. DOES THE POSITION GRANT IT? The (concern, verb) pair must be on the
 *      position the person holds.
 *   2. DOES THE PLACEMENT COVER THE AREA? The record's area must lie in the
 *      ground the person is placed against; an organization-wide placement
 *      covers every area, including ones gazetted afterwards.
 *   3. DOES THE PLACEMENT COVER THE DEPARTMENT? When the concern belongs to a
 *      department, that department must be one the person is placed against.
 *      When it belongs to none, the question does not arise.
 *
 * EVERY QUESTION THAT APPLIES MUST ANSWER YES, and one that cannot be
 * answered refuses. Nothing is held unless a position says so, reading
 * included: a person with no position holds nothing, a person with no
 * placement reaches no ground, and a pair nothing declares is refused rather
 * than waved through.
 *
 * The reasoning is a preference between two kinds of mistake. A missing
 * permission is discovered immediately, by the person who needed it, and
 * fixed in a minute. A permission that exists without anybody knowing it does
 * is discovered by its consequences, if at all.
 *
 * THE SUBJECT IS PASSED EXPLICITLY - `is_granted('patrols.record', $area)` -
 * which is what makes this usable off a route, in a command or behind the
 * API, and testable without a request.
 *
 * WHAT "BELONGS TO A DEPARTMENT" MEANS. A department runs a set of modules,
 * so a concern belongs to the departments that run the module which declared
 * it. A core bundle's concern names no module and therefore belongs to none:
 * the ground, the directory and the catalogue are the installation's, not any
 * one department's. Where a concern's module IS run by some department, the
 * person must be placed in at least one of those departments.
 *
 * @extends Voter<string, ?AreaInterface>
 *
 * @see https://symfony.com/doc/current/security/voters.html — a voter extends Voter, answers supports() and voteOnAttribute()
 * @see vendor/symfony/security-core/Authorization/Voter/Voter.php — the two abstract signatures this class implements, the fourth ?Vote argument included
 * @see vendor/symfony/security-bundle/DependencyInjection/SecurityExtension.php — the 'security.voter' tag; a reusable bundle is not autoconfigured, so config/services.php writes it by hand
 */
final class GrantVoter extends Voter
{
    public function __construct(
        private readonly ConcernCatalogue $catalogue,
        private readonly DepartmentRepository $departments,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        $grant = Grant::tryParse($attribute);

        return null !== $grant && $this->catalogue->has($grant);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        $grant = Grant::tryParse($attribute);
        if (null === $grant) {
            return false;
        }

        /*
         * ABOVE THE MATRIX. Super admin and Admin are tiers rather than
         * grants, and they are asked first so that the person who WRITES the
         * positions is not themselves described by one - otherwise an
         * administrator could lock themselves out of the page that would let
         * them back in.
         */
        if ($user->getTeamRole()->canManageContent()) {
            return true;
        }

        // 1. Does the position grant it?
        $position = $user->getPosition();
        if (null === $position || !$position->hasGrant($grant)) {
            return false;
        }

        // 2. Does the placement cover the area? No placement reaches no
        //    ground, which is the model failing closed rather than a gap.
        $placement = $user->getPlacement();
        if (null === $placement) {
            return false;
        }

        if (!$placement->coversArea($subject instanceof AreaInterface ? $subject : null)) {
            return false;
        }

        // 3. Does the placement cover the department, where the concern
        //    belongs to one at all?
        return $this->coversTheDepartment($user, $grant->concern);
    }

    /**
     * THE THIRD QUESTION, AND IT IS CONDITIONAL. A concern with no module
     * belongs to no department, and a question that does not arise is not a
     * refusal. A concern whose module NO department runs is the same case:
     * there is no department for the placement to have to cover.
     */
    private function coversTheDepartment(User $user, string $concern): bool
    {
        $module = $this->catalogue->moduleOf($concern);
        if (null === $module) {
            return true;
        }

        $running = $this->departmentsRunning($module);
        if ([] === $running) {
            return true;
        }

        $placement = $user->getPlacement();
        foreach ($running as $department) {
            if ($placement?->coversDepartment($department) ?? false) {
                return true;
            }
        }

        return false;
    }

    /**
     * The departments that run a module, by its slug.
     *
     * @return list<Department>
     */
    private function departmentsRunning(string $module): array
    {
        $running = [];
        foreach ($this->departments->findAllActiveOrdered() as $department) {
            foreach ($department->getModules() as $attached) {
                if ($module === $attached->getSlug()) {
                    $running[] = $department;
                    break;
                }
            }
        }

        return $running;
    }
}
