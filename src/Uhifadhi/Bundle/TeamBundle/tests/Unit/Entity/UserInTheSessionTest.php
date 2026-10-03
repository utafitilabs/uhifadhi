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

namespace Uhifadhi\Bundle\TeamBundle\Tests\Unit\Entity;

use PHPUnit\Framework\TestCase;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Bundle\TeamBundle\Enum\TeamRoleEnum;

/**
 * WHAT A SESSION KEEPS OF AN ACCOUNT, AND WHEN IT IS STILL THE SAME ONE.
 *
 * A session store — files, a cache — is often guarded less closely than the
 * database, so the session keeps a checksum of the password hash, never the
 * hash. And the account read back on every request is compared with the one
 * the session holds: the same email, password, roles and active flag, or the
 * session ends.
 */
final class UserInTheSessionTest extends TestCase
{
    private const string HASH = '$2y$13$abcdefghijklmnopqrstuuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ012';

    public function testTheSessionKeepsAChecksumAndNeverTheHash(): void
    {
        $serialized = serialize($this->grace());

        self::assertStringNotContainsString(self::HASH, $serialized);
        self::assertStringContainsString(hash('crc32c', self::HASH), $serialized);
    }

    public function testAnAccountReadBackFromASessionIsTheSameAccount(): void
    {
        $fromTheSession = unserialize(serialize($this->grace()));
        \assert($fromTheSession instanceof User);

        self::assertTrue($fromTheSession->isEqualTo($this->grace()));
    }

    public function testAPasswordChangedSinceIsNotTheSameAccount(): void
    {
        $fromTheSession = unserialize(serialize($this->grace()));
        \assert($fromTheSession instanceof User);

        self::assertFalse($fromTheSession->isEqualTo($this->grace()->setPassword('$2y$13$a different hash entirely')));
    }

    public function testADeactivatedAccountIsNotTheSameAccount(): void
    {
        self::assertFalse($this->grace()->isEqualTo($this->grace()->deactivate()));
    }

    public function testATierOrRoleChangeIsNotTheSameAccount(): void
    {
        self::assertFalse($this->grace()->isEqualTo($this->grace()->setTeamRole(TeamRoleEnum::Admin)));
        self::assertFalse($this->grace()->isEqualTo($this->grace()->setRoles(['ROLE_AUDITOR'])));
    }

    public function testAnotherEmailIsNotTheSameAccount(): void
    {
        self::assertFalse($this->grace()->isEqualTo($this->grace()->setEmail('someone.else@example.test')));
    }

    private function grace(): User
    {
        return (new User())->setEmail('g.ndosi@example.test')->setFirstName('Grace')->setLastName('Ndosi')
            ->setPassword(self::HASH)->setTeamRole(TeamRoleEnum::Staff)->setVerified(true);
    }
}
