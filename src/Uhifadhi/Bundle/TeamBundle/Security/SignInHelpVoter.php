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
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Uhifadhi\Bundle\TeamBundle\Entity\User;

/**
 * WHETHER THE ACCOUNT ASKED ABOUT MAY HELP THIS PERSON BACK IN — send them a
 * reset link from their record. It takes Sign-in help, an account the
 * account rule lets them act on, and a reach that covers the person: a head
 * of station helps the people placed in their own area.
 *
 * The reach is built from the token's user, never from the session, so the
 * Permissions page can ask it of any account while a Super Admin reads.
 *
 * @see https://symfony.com/doc/current/security/voters.html#checking-for-roles-inside-a-voter
 *
 * @extends Voter<string, User>
 */
final class SignInHelpVoter extends Voter
{
    public const string HELP = 'team.member.sign_in_help';

    /** The pair a position holds to help people back in. */
    public const string PAIR = 'sign-in-help.manage';

    public function __construct(
        private readonly AccessDecisionManagerInterface $decisions,
    ) {
    }

    /** @see https://symfony.com/doc/current/security/voters.html#improving-voter-performance */
    public function supportsAttribute(string $attribute): bool
    {
        return self::HELP === $attribute;
    }

    /** A Doctrine proxy extends the entity, so the type is matched with is_a(), as the documentation's example does. */
    public function supportsType(string $subjectType): bool
    {
        return is_a($subjectType, User::class, true);
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return self::HELP === $attribute && $subject instanceof User;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $actor = $token->getUser();
        if (!$actor instanceof User) {
            return false;
        }

        if (!$this->decisions->decide($token, [self::PAIR])) {
            $vote?->addReason('their position does not hold Sign-in help.');

            return false;
        }

        if (!$this->decisions->decide($token, [MemberVoter::CONFIGURE], $subject)) {
            $vote?->addReason('the account rule does not let them act on this person.');

            return false;
        }

        $reach = new Reach($actor);
        if (!$reach->isUnbounded() && !$reach->reachesPerson($subject)) {
            $vote?->addReason('this person is placed beyond their area.');

            return false;
        }

        return true;
    }
}
