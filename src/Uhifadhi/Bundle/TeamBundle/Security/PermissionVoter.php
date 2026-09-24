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
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Bundle\TeamBundle\Service\PermissionCatalogue;
use Uhifadhi\Contracts\Entity\AreaInterface;

/**
 * Decides a granular permission (e.g. `is_granted('patrols.record', $area)`) from the user's
 * tier, position, and — for an area-scoped permission — the TARGET AREA it is asked about.
 * Super Admin / Admin hold every permission in every area by tier; a Staff user holds exactly
 * the permissions of their assigned {@see \Uhifadhi\Bundle\TeamBundle\Entity\Position}, confined to their
 * authority-area.
 *
 * The catalogue — the app's own PermissionEnum plus what installed modules declare — is the
 * single source of what counts as a permission: core and module-declared values are decided
 * identically. Attributes outside the catalogue are none of this voter's business — it
 * abstains so the role voters can decide them, which also means a permission of an
 * UNINSTALLED module is simply no longer decidable here.
 *
 * AREA-SCOPED. A permission answers "may this person do X *here*?" A Staff member's reach is
 * their PLACEMENT — the ground recorded against them, the whole organization or a named set of
 * areas. It is a fact about the person, not about their job title. The voter compares the
 * passed target area against it:
 *
 *   1. TIER SHORT-CIRCUIT — Super Admin / Admin bypass area-scoping entirely; area never
 *      consulted. Area-scoping only ever narrows a Staff member.
 *   2. DOES THE POSITION CARRY THIS PERMISSION AT ALL? No position, or a position without the
 *      value → deny. Unchanged from before.
 *   3. IS THE PERMISSION EVEN AREA-SCOPED? A GLOBAL permission (only `area.create` among the
 *      core seven) is granted here with no area check — it has no area to compare against.
 *   4. AREA COMPARISON. Org-level authority (scope null) grants for any target — "all areas"
 *      is the absence of a boundary. A NULL subject means "no area in context" (a nav
 *      question, a "may I ever…?" flag): granted, because the actor has authority in some
 *      area, with the real per-area gate applying once an area is known. Otherwise the target
 *      area must equal the authority area.
 *
 * The subject is PASSED EXPLICITLY, which is what makes the voter
 * unit-testable and usable off-route (commands, the API); {@see AreaValueResolver} is the
 * convenience that turns a `{uuid}` route param into the Area for controllers that want it.
 *
 * @extends Voter<string, ?AreaInterface>
 *
 * DEPRECATED, AND KEPT FOR ONE RELEASE.
 * {@see GrantVoter} decides a pair now, and
 * it asks three questions rather than two. This one still answers the old
 * seven so an installation mid-upgrade is not locked out of its own pages.
 *
 * @deprecated since 1.0, use {@see GrantVoter}
 * @see https://symfony.com/doc/current/security/voters.html — a voter extends Voter, answers supports() and voteOnAttribute()
 * @see vendor/symfony/security-core/Authorization/Voter/Voter.php — the two abstract signatures this class implements, the fourth ?Vote argument included
 */
final class PermissionVoter extends Voter
{
    public function __construct(
        private readonly PermissionCatalogue $catalogue,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $this->catalogue->has($attribute);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        // 1. Tier escape hatch — Super Admin / Admin hold everything, everywhere.
        if ($user->getTeamRole()->canManageContent()) {
            return true;
        }

        // 2. Does the position carry this permission at all?
        $position = $user->getPosition();
        if (null === $position || !$position->hasPermissionValue($attribute)) {
            return false;
        }

        // 3. A global permission has no area to compare against — grant on the
        //    permission alone.
        if (!$this->catalogue->isAreaScoped($attribute)) {
            return true;
        }

        // 4. Area comparison, asked of the placement — the ground recorded
        //    against this person. Unplaced reaches nothing: the model fails
        //    closed, so a missing placement is a refusal rather than a pass.
        $placement = $user->getPlacement();
        if (null === $placement) {
            return false;
        }

        // A null (or non-area) subject is "no area in context". The placement
        // answers it: it is covered where the person reaches some ground at
        // all, and the genuine per-area gate applies once an area is named
        // downstream.
        return $placement->coversArea($subject instanceof AreaInterface ? $subject : null);
    }
}
