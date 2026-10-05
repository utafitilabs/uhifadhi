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
use Uhifadhi\Bundle\TeamBundle\Enum\TeamRoleEnum;
use Uhifadhi\Bundle\TeamBundle\Service\OneTimePasswordService;
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

    /** Whether the viewer may give this person this tier — the subject is a {@see TierChange}. */
    public const string TIER = 'team.member.tier';

    /** Whether the viewer may issue this person a one-time password. */
    public const string ONE_TIME_PASSWORD = 'team.member.one_time_password';

    /** @see https://symfony.com/doc/current/security/voters.html#improving-voter-performance */
    public function supportsAttribute(string $attribute): bool
    {
        return \in_array($attribute, [self::CONFIGURE, self::TIER, self::ONE_TIME_PASSWORD], true);
    }

    /** A Doctrine proxy extends the entity, so the type is matched with is_a(), as the documentation's example does. */
    public function supportsType(string $subjectType): bool
    {
        return is_a($subjectType, User::class, true) || is_a($subjectType, TierChange::class, true);
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return match ($attribute) {
            self::CONFIGURE, self::ONE_TIME_PASSWORD => $subject instanceof User,
            self::TIER => $subject instanceof TierChange,
            default => false,
        };
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $viewer = $token->getUser();
        if (!$viewer instanceof User) {
            $vote?->addReason('nobody is signed in.');

            return false;
        }

        if ($subject instanceof TierChange) {
            $granted = UserService::mayChangeTier($viewer, $subject->person, $subject->tier);
            $granted || $vote?->addReason(match ($viewer->getTeamRole()) {
                TeamRoleEnum::Staff => 'a position never confers a tier.',
                default => 'only a Super Admin makes a Super Admin or changes one.',
            });

            return $granted;
        }

        if (!$subject instanceof User) {
            return false;
        }

        if (self::ONE_TIME_PASSWORD === $attribute) {
            $granted = OneTimePasswordService::mayIssue($viewer, $subject);
            $granted || $vote?->addReason(match (true) {
                !$viewer->getTeamRole()->canManageContent() => 'only an Admin or a Super Admin issues a one-time password.',
                $viewer->getId() === $subject->getId() => 'nobody issues a one-time password for themselves.',
                default => 'only a Super Admin issues one for a Super Admin.',
            });

            return $granted;
        }

        $granted = UserService::mayTouchAccount($viewer, $subject);
        $granted || $vote?->addReason(TeamRoleEnum::SuperAdmin === $subject->getTeamRole()
            ? 'only a Super Admin acts on a Super Admin\'s account.'
            : 'only an Admin or a Super Admin acts on an Admin\'s account.');

        return $granted;
    }
}
