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
use Uhifadhi\Bundle\TeamBundle\Service\UserService;
use Uhifadhi\Contracts\Access\PersonAccess;

/**
 * WHO MAY CONFIGURE A PERSON — ruled 28 Sep 2026: only a Super Admin
 * configures a Super Admin, only an Admin or a Super Admin an Admin, and
 * Staff whom their grants allow configure Staff. Anybody else may read the
 * record and nothing more.
 *
 * A voter because it is a permission on one object, and "voters are Symfony's
 * most powerful way of managing permissions. They allow you to centralize all
 * permission logic, then reuse them in many places" — every route that
 * changes a person asks this one question, in this bundle or any other
 * (`isGranted('team.member.configure', $person)`), and a page asks it before
 * drawing the door. The grant that opens the directory at all
 * (`directory.manage`) is the GrantVoter's and is asked as well; this voter
 * only adds the tiers.
 *
 * @see https://symfony.com/doc/current/security/voters.html
 * @see vendor/symfony/security-core/Authorization/Voter/Voter.php — supports() + voteOnAttribute(), the base GrantVoter extends too
 *
 * @extends Voter<string, mixed>
 */
final class MemberVoter extends Voter
{
    /** The contract's name for it, so any bundle can ask without knowing this voter. */
    public const string CONFIGURE = PersonAccess::CONFIGURE;

    protected function supports(string $attribute, mixed $subject): bool
    {
        return self::CONFIGURE === $attribute && $subject instanceof User;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $viewer = $token->getUser();

        return $subject instanceof User
            && $viewer instanceof User
            && UserService::mayTouchAccount($viewer, $subject);
    }
}
