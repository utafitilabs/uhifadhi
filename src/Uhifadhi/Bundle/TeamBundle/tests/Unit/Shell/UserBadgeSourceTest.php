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

namespace Uhifadhi\Bundle\TeamBundle\Tests\Unit\Shell;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\UserInterface as SecurityUserInterface;
use Uhifadhi\Bundle\TeamBundle\Entity\Position;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Bundle\TeamBundle\Enum\TeamRoleEnum;
use Uhifadhi\Bundle\TeamBundle\Shell\UserBadgeSource;
use Uhifadhi\Contracts\Shell\UserBadge;

/**
 * TEAM'S ANSWER TO "WHO DOES THE TOP BAR NAME". Team owns the account, the
 * position and the tier, so it is the bundle that folds them into the card the
 * shell draws — a name, its derived initials, and a context line that is the
 * position for Staff and the tier label for everyone else.
 *
 * The two answers that matter are the populated card for a signed-in User and
 * the deliberate NOTHING for anyone who is not one — the sign-in screen, a fresh
 * installation, an anonymous request — because a source that invented a card for
 * a viewer it does not have would put a phantom in every top bar.
 */
#[CoversClass(UserBadgeSource::class)]
final class UserBadgeSourceTest extends TestCase
{
    public function testStaffAreNamedWithTheirPositionAsTheContextLine(): void
    {
        $user = self::staffMember('Naserian', 'Kileo', 'Senior Ranger');

        $badge = new UserBadgeSource($this->tokenStorageHolding($user))->badge();

        self::assertInstanceOf(UserBadge::class, $badge);
        self::assertSame('Naserian Kileo', $badge->name);
        self::assertSame('NK', $badge->initials);
        self::assertSame('Senior Ranger', $badge->context, 'A Staff member is named by their position.');
    }

    public function testATierHolderIsNamedWithTheTierLabelWhenTheyHoldNoPosition(): void
    {
        $user = new User();
        $user->setFirstName('Amina');
        $user->setLastName('Said');
        $user->setTeamRole(TeamRoleEnum::SuperAdmin);

        $badge = new UserBadgeSource($this->tokenStorageHolding($user))->badge();

        self::assertInstanceOf(UserBadge::class, $badge);
        self::assertSame('Amina Said', $badge->name);
        self::assertSame(TeamRoleEnum::SuperAdmin->label(), $badge->context, 'A tier holder with no position gets the tier label.');
    }

    public function testAStaffMemberWithNoPositionReadsNoPositionNotATier(): void
    {
        $user = new User();
        $user->setFirstName('Juma');
        $user->setLastName('Ali');
        $user->setTeamRole(TeamRoleEnum::Staff);

        $badge = new UserBadgeSource($this->tokenStorageHolding($user))->badge();

        self::assertInstanceOf(UserBadge::class, $badge);
        self::assertSame('No position', $badge->context, 'Never an empty line, and never a tier: Staff see none (ruled 28 Sep 2026).');
    }

    public function testAnAnonymousRequestNamesNobody(): void
    {
        self::assertNull(
            new UserBadgeSource($this->tokenStorageHolding(null))->badge(),
            'No signed-in User means no card — the sign-in screen and a fresh install draw a top bar with no viewer pill.',
        );
    }

    /**
     * NO TOKEN AT ALL is the anonymous shape the storage itself reports, and it
     * is a different fact from a token whose user is somebody else. Reading the
     * token rather than the SecurityBundle helper means the source meets it
     * directly, so it is asserted directly.
     */
    public function testAnEmptyTokenStorageNamesNobody(): void
    {
        self::assertNull(new UserBadgeSource(new TokenStorage())->badge());
    }

    public function testANonUserPrincipalNamesNobody(): void
    {
        // A token whose user is some other UserInterface implementation is still
        // not team's account, and the source refuses to guess a card from it.
        $foreign = new class implements SecurityUserInterface {
            public function getRoles(): array
            {
                return [];
            }

            public function eraseCredentials(): void
            {
            }

            public function getUserIdentifier(): string
            {
                return 'someone@example.test';
            }
        };

        self::assertNull(new UserBadgeSource($this->tokenStorageHolding($foreign))->badge());
    }

    private static function staffMember(string $first, string $last, string $positionName): User
    {
        $position = new Position();
        $position->setName($positionName);

        $user = new User();
        $user->setFirstName($first);
        $user->setLastName($last);
        $user->setTeamRole(TeamRoleEnum::Staff);
        $user->setPosition($position);

        return $user;
    }

    /**
     * A token storage holding a token for this principal — the shape a real
     * firewall leaves behind, so the source is exercised through the same
     * object graph a request has.
     */
    private function tokenStorageHolding(?SecurityUserInterface $user): TokenStorageInterface
    {
        $storage = new TokenStorage();

        if ($user instanceof SecurityUserInterface) {
            $storage->setToken(new UsernamePasswordToken($user, 'main'));
        }

        return $storage;
    }
}
