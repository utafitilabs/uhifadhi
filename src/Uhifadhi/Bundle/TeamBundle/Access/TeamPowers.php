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

namespace Uhifadhi\Bundle\TeamBundle\Access;

use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Bundle\TeamBundle\Enum\TeamRoleEnum;
use Uhifadhi\Bundle\TeamBundle\Security\MemberVoter;
use Uhifadhi\Bundle\TeamBundle\Security\SignInHelpVoter;
use Uhifadhi\Bundle\TeamBundle\Security\TierChange;
use Uhifadhi\Contracts\Access\Power;
use Uhifadhi\Contracts\Access\PowerGroup;
use Uhifadhi\Contracts\Access\PowerSourceInterface;
use Uhifadhi\Contracts\Access\PowerTarget;
use Uhifadhi\Contracts\Access\Verb;

/**
 * THE TEAM'S POWERS — the tiers, the positions and their pairs, ranks and
 * accounts: every action here that confers power, with the questions the
 * routes ask before they allow it.
 */
final readonly class TeamPowers implements PowerSourceInterface
{
    private const string DIRECTORY = TeamConcerns::DIRECTORY.'.'.Verb::Manage->value;
    private const string PERSONAL_DETAILS = TeamConcerns::PERSONAL_DETAILS.'.'.Verb::Manage->value;
    private const string POSITIONS = TeamConcerns::POSITIONS.'.'.Verb::Configure->value;
    private const string RANKS = TeamConcerns::RANKS.'.'.Verb::Configure->value;
    private const string SWITCH = 'ROLE_ALLOWED_TO_SWITCH';

    /** The tier each tier-changing power gives; raising one's own is the next tier up. */
    private const array TIER_GIVEN = [
        'make-admin' => TeamRoleEnum::Admin,
        'demote-to-staff' => TeamRoleEnum::Staff,
        'make-super-admin' => TeamRoleEnum::SuperAdmin,
    ];

    public function powers(): iterable
    {
        $account = [PowerTarget::AColleague, PowerTarget::SomebodyBeyondTheirArea, PowerTarget::AnAdmin, PowerTarget::ASuperAdmin];
        $gaining = PowerGroup::GainingPower;
        $giving = PowerGroup::GivingPower;
        $takeover = PowerGroup::TakingOver;

        yield new Power('raise-their-own-tier', $gaining, 'raise their own tier', [MemberVoter::TIER], [PowerTarget::Themselves]);
        yield new Power('seat-themselves-in-a-stronger-position', $gaining, 'seat themselves in a stronger position', [self::DIRECTORY, MemberVoter::CONFIGURE], [PowerTarget::Themselves]);
        yield new Power('add-a-pair-to-their-own-position', $gaining, 'add a pair to their own position', [self::POSITIONS], [PowerTarget::TheirOwnPosition]);
        yield new Power('raise-their-own-rank', $gaining, 'raise their own rank', [self::DIRECTORY, MemberVoter::CONFIGURE], [PowerTarget::Themselves]);
        yield new Power('move-their-own-rank-up-the-scale', $gaining, 'move their own rank up the scale', [self::RANKS], [PowerTarget::TheRankScale]);
        yield new Power('create-an-account-in-a-stronger-position', $gaining, 'create an account in a stronger position', [self::DIRECTORY], [PowerTarget::ANewAccount]);

        yield new Power('make-admin', $giving, 'make Admin', [MemberVoter::TIER], [PowerTarget::AColleague, PowerTarget::SomebodyBeyondTheirArea]);
        yield new Power('demote-to-staff', $giving, 'demote to Staff', [MemberVoter::TIER], [PowerTarget::AnAdmin, PowerTarget::ASuperAdmin]);
        yield new Power('make-super-admin', $giving, 'make Super Admin', [MemberVoter::TIER], [PowerTarget::AColleague, PowerTarget::AnAdmin]);
        yield new Power('seat-in-a-stronger-position', $giving, 'seat in a stronger position', [self::DIRECTORY, MemberVoter::CONFIGURE], [PowerTarget::AColleague, PowerTarget::SomebodyBeyondTheirArea, PowerTarget::AnAdmin]);
        yield new Power('add-a-pair-to-a-position', $giving, 'add a pair they do not hold to a position', [self::POSITIONS], [PowerTarget::AStrongerPosition]);
        yield new Power('take-pairs-from-a-position-held-elsewhere', $giving, 'take pairs away from a position held elsewhere', [self::POSITIONS], [PowerTarget::APositionHeldElsewhere]);
        yield new Power('retire-a-position-held-elsewhere', $giving, 'retire a position held elsewhere', [self::POSITIONS], [PowerTarget::APositionHeldElsewhere]);
        yield new Power('give-live-locations', $giving, 'give Live locations', [self::POSITIONS], [PowerTarget::AStrongerPosition]);
        yield new Power('take-live-locations-away', $giving, 'take Live locations away', [self::POSITIONS], [PowerTarget::AStrongerPosition]);

        yield new Power('open-their-account', $takeover, 'open their account for changes', [self::DIRECTORY, MemberVoter::CONFIGURE], $account);
        yield new Power('change-their-email', $takeover, 'change their email', [self::DIRECTORY, MemberVoter::CONFIGURE], $account);
        yield new Power('send-a-reset-link', $takeover, 'send a reset link', [SignInHelpVoter::PAIR, SignInHelpVoter::HELP], $account);
        yield new Power('send-the-invitation-again', $takeover, 'send the invitation again', [self::PERSONAL_DETAILS, MemberVoter::CONFIGURE], [PowerTarget::AColleague, PowerTarget::AnAdmin]);
        yield new Power('issue-a-one-time-password', $takeover, 'issue a one-time password', [MemberVoter::ONE_TIME_PASSWORD], [PowerTarget::Themselves, PowerTarget::AColleague, PowerTarget::ASuperAdmin]);
        yield new Power('deactivate-or-reactivate', $takeover, 'deactivate or reactivate', [self::DIRECTORY, MemberVoter::CONFIGURE], $account);
        yield new Power('switch-user', $takeover, 'switch user', [self::SWITCH], [PowerTarget::AColleague, PowerTarget::AnAdmin, PowerTarget::ASuperAdmin]);
    }

    public function subject(Power $power, string $question, PowerTarget $kind, ?object $target): mixed
    {
        if (MemberVoter::TIER === $question) {
            if (!$target instanceof User) {
                return null;
            }
            $tier = self::TIER_GIVEN[$power->key] ?? self::tierAbove($target->getTeamRole());

            return null === $tier ? null : new TierChange($target, $tier);
        }

        // A QUESTION ABOUT A PERSON is asked of the person; a pair is asked of
        // no particular ground, as the routes that hold it ask it.
        return \in_array($question, [MemberVoter::CONFIGURE, MemberVoter::ONE_TIME_PASSWORD, SignInHelpVoter::HELP, self::SWITCH], true) ? $target : null;
    }

    private static function tierAbove(TeamRoleEnum $tier): ?TeamRoleEnum
    {
        return match ($tier) {
            TeamRoleEnum::Staff => TeamRoleEnum::Admin,
            TeamRoleEnum::Admin => TeamRoleEnum::SuperAdmin,
            TeamRoleEnum::SuperAdmin => null,
        };
    }
}
