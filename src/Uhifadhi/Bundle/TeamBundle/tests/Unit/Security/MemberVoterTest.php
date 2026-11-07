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

namespace Uhifadhi\Bundle\TeamBundle\Tests\Unit\Security;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Bundle\TeamBundle\Enum\TeamRoleEnum;
use Uhifadhi\Bundle\TeamBundle\Security\MemberVoter;
use Uhifadhi\Bundle\TeamBundle\Security\TierChange;
use Uhifadhi\Bundle\TeamBundle\Service\OneTimePasswordService;
use Uhifadhi\Bundle\TeamBundle\Service\UserService;

/**
 * THE ACCOUNT RULES, ASKED AS VOTER QUESTIONS. A question a voter answers can
 * be asked for any account — a route asks it of the signed-in one, the
 * Permissions page of every one — so the tier and one-time-password rules
 * are put to the voter, which answers exactly what the rules answer and says
 * why when it refuses.
 */
#[CoversClass(MemberVoter::class)]
final class MemberVoterTest extends TestCase
{
    private const array TIERS = ['staff' => TeamRoleEnum::Staff, 'admin' => TeamRoleEnum::Admin, 'super' => TeamRoleEnum::SuperAdmin];

    /** @return iterable<string, array{string, string, string}> */
    public static function tierChanges(): iterable
    {
        foreach (array_keys(self::TIERS) as $by) {
            foreach (array_keys(self::TIERS) as $on) {
                foreach (array_keys(self::TIERS) as $to) {
                    yield "$by makes $on $to" => [$by, $on, $to];
                }
            }
        }
    }

    #[DataProvider('tierChanges')]
    public function testATierChangeIsAnsweredAsTheRuleAnswersIt(string $by, string $on, string $to): void
    {
        $actor = self::person($by, 1);
        $person = self::person($on, 2);
        $expected = UserService::mayChangeTier($actor, $person, self::TIERS[$to]);

        $vote = new Vote();
        $answer = new MemberVoter()->vote(self::token($actor), new TierChange($person, self::TIERS[$to]), [MemberVoter::TIER], $vote);

        self::assertSame($expected ? VoterInterface::ACCESS_GRANTED : VoterInterface::ACCESS_DENIED, $answer);
        if (!$expected) {
            self::assertNotSame([], $vote->reasons, 'a refusal says why');
        }
    }

    /** @return iterable<string, array{string, string, bool}> */
    public static function issues(): iterable
    {
        foreach (array_keys(self::TIERS) as $by) {
            foreach (array_keys(self::TIERS) as $on) {
                yield "$by for $on" => [$by, $on, false];
            }
            yield "$by for themselves" => [$by, $by, true];
        }
    }

    #[DataProvider('issues')]
    public function testAOneTimePasswordIsAnsweredAsTheRuleAnswersIt(string $by, string $on, bool $themselves): void
    {
        $actor = self::person($by, 1);
        $person = $themselves ? $actor : self::person($on, 2);
        $expected = OneTimePasswordService::mayIssue($actor, $person);

        $vote = new Vote();
        $answer = new MemberVoter()->vote(self::token($actor), $person, [MemberVoter::ONE_TIME_PASSWORD], $vote);

        self::assertSame($expected ? VoterInterface::ACCESS_GRANTED : VoterInterface::ACCESS_DENIED, $answer);
        if (!$expected) {
            self::assertNotSame([], $vote->reasons, 'a refusal says why');
        }
    }

    /** @return iterable<string, array{string}> */
    public static function belowSuperAdmin(): iterable
    {
        yield 'staff' => ['staff'];
        yield 'admin' => ['admin'];
    }

    /** RULED 8 NOV 2026: below a Super Admin, nobody gains power over their own standing. */
    #[DataProvider('belowSuperAdmin')]
    public function testNobodyBelowASuperAdminConfiguresTheirOwnRecord(string $tier): void
    {
        $actor = self::person($tier, 1);

        $vote = new Vote();
        $answer = new MemberVoter()->vote(self::token($actor), $actor, [MemberVoter::CONFIGURE], $vote);

        self::assertSame(VoterInterface::ACCESS_DENIED, $answer);
        self::assertSame(['nobody raises their own standing.'], $vote->reasons);
    }

    #[DataProvider('belowSuperAdmin')]
    public function testTheSameAccountLoadedTwiceIsStillThemselves(string $tier): void
    {
        $answer = new MemberVoter()->vote(self::token(self::person($tier, 1)), self::person($tier, 1), [MemberVoter::CONFIGURE]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $answer);
    }

    /** The Super Admin manages the installation, their own record included. */
    public function testASuperAdminStillConfiguresTheirOwnRecord(): void
    {
        $actor = self::person('super', 1);

        self::assertSame(VoterInterface::ACCESS_GRANTED, new MemberVoter()->vote(self::token($actor), $actor, [MemberVoter::CONFIGURE]));
    }

    /** @return iterable<string, array{string, string}> */
    public static function raises(): iterable
    {
        yield 'staff to admin' => ['staff', 'admin'];
        yield 'staff to super' => ['staff', 'super'];
        yield 'admin to super' => ['admin', 'super'];
    }

    #[DataProvider('raises')]
    public function testNobodyRaisesTheirOwnTier(string $tier, string $to): void
    {
        $actor = self::person($tier, 1);

        $vote = new Vote();
        $answer = new MemberVoter()->vote(self::token($actor), new TierChange($actor, self::TIERS[$to]), [MemberVoter::TIER], $vote);

        self::assertSame(VoterInterface::ACCESS_DENIED, $answer);
        self::assertSame(['nobody raises their own standing.'], $vote->reasons);
    }

    public function testAnAdminMayStillStepDownToStaff(): void
    {
        $actor = self::person('admin', 1);

        self::assertSame(VoterInterface::ACCESS_GRANTED, new MemberVoter()->vote(self::token($actor), new TierChange($actor, TeamRoleEnum::Staff), [MemberVoter::TIER]));
    }

    /** @return iterable<string, array{string, string}> */
    public static function others(): iterable
    {
        foreach (array_keys(self::TIERS) as $by) {
            foreach (array_keys(self::TIERS) as $on) {
                yield "$by configures $on" => [$by, $on];
            }
        }
    }

    #[DataProvider('others')]
    public function testConfiguringSomebodyElseIsAnsweredAsTheAccountRuleAnswersIt(string $by, string $on): void
    {
        $actor = self::person($by, 1);
        $person = self::person($on, 2);

        $answer = new MemberVoter()->vote(self::token($actor), $person, [MemberVoter::CONFIGURE]);

        self::assertSame(UserService::mayTouchAccount($actor, $person) ? VoterInterface::ACCESS_GRANTED : VoterInterface::ACCESS_DENIED, $answer);
    }

    public function testItSaysWhichQuestionsItAnswers(): void
    {
        $voter = new MemberVoter();

        foreach ([MemberVoter::CONFIGURE, MemberVoter::TIER, MemberVoter::ONE_TIME_PASSWORD] as $attribute) {
            self::assertTrue($voter->supportsAttribute($attribute), $attribute);
        }
        self::assertFalse($voter->supportsAttribute('directory.manage'));
        self::assertTrue($voter->supportsType(User::class));
        self::assertTrue($voter->supportsType(TierChange::class));
    }

    private static function person(string $tier, int $id): User
    {
        $user = new User()->setEmail($tier.$id.'@example.test')->setTeamRole(self::TIERS[$tier]);
        new \ReflectionProperty(User::class, 'id')->setValue($user, $id);

        return $user;
    }

    private static function token(User $user): UsernamePasswordToken
    {
        return new UsernamePasswordToken($user, 'main', $user->getRoles());
    }
}
