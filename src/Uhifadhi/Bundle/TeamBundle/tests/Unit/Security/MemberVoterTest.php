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
