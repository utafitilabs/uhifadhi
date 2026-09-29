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
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Bundle\TeamBundle\Enum\TeamRoleEnum;
use Uhifadhi\Bundle\TeamBundle\Service\OneTimePasswordService;
use Uhifadhi\Bundle\TeamBundle\Service\UserService;

/**
 * THE ESCALATION MATRIX — every power-conferring rule, asked by every kind of
 * actor about every kind of person, in one table (OPEN-ITEMS #57).
 *
 * The rules as ruled on 28 September 2026: the Super Admin is the developer's
 * account and touches anything; Admins run the organization and are peers —
 * they make, unmake and demote Admins, issue one-time passwords for Admins,
 * and give the Live locations exception — but never make or touch a Super
 * Admin; a position never confers a tier, whatever it grants; nobody issues a
 * one-time password for themselves; only a Super Admin switches user.
 *
 * WHY A TABLE. The tier hole fixed in core 0.1.12 was one cell nobody had
 * written down. Here every cell is written, so a missing rule is a missing
 * row, visible, rather than a gap discovered in production.
 *
 * WHAT IS PROVEN ELSEWHERE, at the route: each action's own functional test
 * posts it as an allowed and a refused actor — TierChangeAuthorityTest (tier),
 * OneTimePasswordTest (codes), PositionExceptionTest (the exception: Super
 * Admin, Admin allowed; Staff refused), MemberRecordTest (switch user absent
 * for an Admin), AreaScopedAssignmentTest (an area administrator's reach, and
 * account creation), ContributionsAskForTheirPairTest (every door asks its pair).
 * This table proves the rules those routes call.
 */
#[CoversClass(UserService::class)]
#[CoversClass(OneTimePasswordService::class)]
#[CoversClass(TeamRoleEnum::class)]
final class EscalationMatrixTest extends TestCase
{
    private const array TIERS = ['staff' => TeamRoleEnum::Staff, 'admin' => TeamRoleEnum::Admin, 'super' => TeamRoleEnum::SuperAdmin];

    /**
     * WHO MAY SET WHICH TIER ON WHOM. Nobody signed in, and Staff whatever
     * their position, set none; an Admin sets Staff and Admin on anybody who
     * is not a Super Admin; a Super Admin sets anything.
     *
     * @return \Generator<string, array{string|null, string, string, bool}>
     */
    public static function tierChanges(): \Generator
    {
        foreach ([null, 'staff', 'admin', 'super'] as $by) {
            foreach (array_keys(self::TIERS) as $target) {
                foreach (array_keys(self::TIERS) as $to) {
                    $allowed = match ($by) {
                        'super' => true,
                        'admin' => 'super' !== $target && 'super' !== $to,
                        default => false,
                    };
                    yield \sprintf('%s sets %s → %s', $by ?? 'nobody', $target, $to) => [$by, $target, $to, $allowed];
                }
            }
        }
    }

    #[DataProvider('tierChanges')]
    public function testWhoMaySetWhichTierOnWhom(?string $by, string $target, string $to, bool $allowed): void
    {
        self::assertSame(
            $allowed,
            UserService::mayChangeTier(null === $by ? null : self::person($by, 1), self::person($target, 2), self::TIERS[$to]),
        );
    }

    /** Raising oneself is the same question about the same person, and gets the same answer. */
    public function testNobodyRaisesThemselvesPastTheirOwnReach(): void
    {
        $admin = self::person('admin', 1);
        $staff = self::person('staff', 2);

        self::assertFalse(UserService::mayChangeTier($admin, $admin, TeamRoleEnum::SuperAdmin), 'an Admin does not make themselves a Super Admin');
        self::assertFalse(UserService::mayChangeTier($staff, $staff, TeamRoleEnum::Admin), 'Staff do not make themselves an Admin');
    }

    /**
     * WHOSE ACCOUNT ONE MAY TOUCH — email, password, deactivation, postings.
     * Staff by anybody signed in (the position's own pair is asked beside
     * this); an Admin by the tiers above the matrix; a Super Admin by a Super
     * Admin alone.
     *
     * @return \Generator<string, array{string|null, string, bool}>
     */
    public static function accountTouches(): \Generator
    {
        foreach ([null, 'staff', 'admin', 'super'] as $by) {
            foreach (array_keys(self::TIERS) as $target) {
                $allowed = match ($target) {
                    'staff' => null !== $by,
                    'admin' => \in_array($by, ['admin', 'super'], true),
                    'super' => 'super' === $by,
                };
                yield \sprintf('%s touches %s', $by ?? 'nobody', $target) => [$by, $target, $allowed];
            }
        }
    }

    #[DataProvider('accountTouches')]
    public function testWhoseAccountOneMayTouch(?string $by, string $target, bool $allowed): void
    {
        self::assertSame($allowed, UserService::mayTouchAccount(null === $by ? null : self::person($by, 1), self::person($target, 2)));
    }

    /**
     * WHO ISSUES A ONE-TIME PASSWORD FOR WHOM. Only the tiers above the
     * matrix, never for themselves, and for a Super Admin only a Super Admin.
     *
     * @return \Generator<string, array{string|null, string, bool}>
     */
    public static function oneTimePasswords(): \Generator
    {
        foreach ([null, 'staff', 'admin', 'super'] as $by) {
            foreach (array_keys(self::TIERS) as $target) {
                $allowed = match ($by) {
                    'super' => true,
                    'admin' => 'super' !== $target,
                    default => false,
                };
                yield \sprintf('%s issues for %s', $by ?? 'nobody', $target) => [$by, $target, $allowed];
            }
        }
    }

    #[DataProvider('oneTimePasswords')]
    public function testWhoIssuesAOneTimePasswordForWhom(?string $by, string $target, bool $allowed): void
    {
        self::assertSame($allowed, self::codes()->mayIssue(null === $by ? null : self::person($by, 1), self::person($target, 2)));
    }

    public function testNobodyIssuesAOneTimePasswordForThemselves(): void
    {
        foreach (self::TIERS as $key => $tier) {
            $self = self::person($key, 7);
            self::assertFalse(self::codes()->mayIssue($self, $self), $key.' does not issue a code for themselves');
        }
    }

    /**
     * WHO STANDS ABOVE THE MATRIX, AND WHO SWITCHES USER. The two tiers above
     * it hold every pair — which is what lets an Admin give the Live locations
     * exception — and only the Super Admin borrows another person's session.
     */
    public function testWhoStandsAboveTheMatrixAndWhoSwitchesUser(): void
    {
        self::assertSame(
            ['staff' => [false, false], 'admin' => [true, false], 'super' => [true, true]],
            array_map(static fn (TeamRoleEnum $tier): array => [$tier->canManageContent(), $tier->canSwitch()], self::TIERS),
        );
    }

    private static function person(string $tier, int $id): User
    {
        $user = new User()->setEmail($tier.$id.'@example.test')->setTeamRole(self::TIERS[$tier]);
        new \ReflectionProperty(User::class, 'id')->setValue($user, $id);

        return $user;
    }

    private static function codes(): OneTimePasswordService
    {
        return new \ReflectionClass(OneTimePasswordService::class)->newInstanceWithoutConstructor();
    }
}
