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

namespace Uhifadhi\Bundle\TeamBundle\Tests\Integration\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Uhifadhi\Bundle\TeamBundle\Entity\Position;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Bundle\TeamBundle\Enum\TeamRoleEnum;
use Uhifadhi\Bundle\TeamBundle\Exception\EmailAlreadyUsedException;
use Uhifadhi\Bundle\TeamBundle\Exception\LastSuperAdminException;
use Uhifadhi\Bundle\TeamBundle\Repository\UserRepository;
use Uhifadhi\Bundle\TeamBundle\Service\UserService;
use Uhifadhi\Bundle\TeamBundle\Tests\Integration\IntegrationTestCase;

/**
 * WHAT ACTUALLY REACHES THE TABLE when an account is written.
 *
 * The unit suite next door asks what the record LOOKS like; this one asks the
 * questions only a database can answer — that the row is there and findable by
 * the address, that the stored hash is one the framework's own verifier accepts
 * (which is the difference between an account and an account that can sign in),
 * that the unique index refuses a second account on one address, and that the
 * installation cannot be emptied of Super Admins.
 */
#[CoversClass(UserService::class)]
final class UserServiceTest extends IntegrationTestCase
{
    public function testACreatedAccountIsStoredAndFoundByItsAddress(): void
    {
        $this->accounts()->create('Ada@Example.test', 'Ada', 'Mwangi', 'a-long-enough-passphrase', TeamRoleEnum::SuperAdmin);

        $stored = $this->users()->findOneByEmail('ada@example.test');

        self::assertNotNull($stored);
        self::assertSame('Ada Mwangi', $stored->getFullName());
        self::assertSame(TeamRoleEnum::SuperAdmin, $stored->getTeamRole());
    }

    /**
     * The credential has to be one the FIREWALL would accept, so it is checked
     * back through the service a firewall uses rather than by looking at the
     * string.
     */
    public function testTheStoredPasswordVerifiesThroughTheFrameworksOwnHasher(): void
    {
        $this->accounts()->create('ada@example.test', 'Ada', 'Mwangi', 'a-long-enough-passphrase');

        $stored = $this->users()->findOneByEmail('ada@example.test');
        self::assertNotNull($stored);

        $hasher = static::getContainer()->get('test_public.hasher');
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);
        self::assertTrue($hasher->isPasswordValid($stored, 'a-long-enough-passphrase'));
    }

    public function testASecondAccountOnOneAddressIsRefused(): void
    {
        $this->accounts()->create('ada@example.test', 'Ada', 'Mwangi', 'a-long-enough-passphrase');

        $this->expectException(EmailAlreadyUsedException::class);

        $this->accounts()->create('ADA@example.test', 'Someone', 'Else', 'another-long-passphrase');
    }

    public function testAnInvitedAccountIsStoredUnverifiedWithATokenThatAddressesIt(): void
    {
        $invited = $this->accounts()->invite('bea@example.test', null, null);
        $token = (string) $invited->getVerificationToken();

        $stored = $this->users()->findOneBy(['verificationToken' => $token]);

        self::assertInstanceOf(User::class, $stored);
        self::assertFalse($stored->isVerified());
        self::assertSame('bea@example.test', $stored->getEmail());
    }

    public function testSeatingSomebodyIsStored(): void
    {
        $position = new Position()->setName('Analyst');
        $this->em->persist($position);
        $this->em->flush();

        $user = $this->accounts()->create('ada@example.test', 'Ada', 'Mwangi', 'a-long-enough-passphrase');
        $this->accounts()->assignPosition($user, $position);

        self::assertSame('Analyst', $this->users()->findOneByEmail('ada@example.test')?->getPosition()?->getName());
    }

    public function testUnseatingSomebodyLeavesThemAbleToSignInAndDoNothing(): void
    {
        $position = new Position()->setName('Analyst');
        $this->em->persist($position);
        $this->em->flush();

        $user = $this->accounts()->create('ada@example.test', 'Ada', 'Mwangi', 'a-long-enough-passphrase', position: $position);
        $this->accounts()->assignPosition($user, null);

        $stored = $this->users()->findOneByEmail('ada@example.test');
        self::assertNotNull($stored);
        self::assertNull($stored->getPosition());
        self::assertTrue($stored->isActive());
    }

    public function testTheRecordsFourFieldsAreStored(): void
    {
        $user = $this->accounts()->create('ada@example.test', 'Ada', 'Mwangi', 'a-long-enough-passphrase');

        $this->accounts()->updateRecord($user, 'Adaeze', 'Mwangi-Otieno', 'adaeze@example.test', 'R-114');

        $stored = $this->users()->findOneByEmail('adaeze@example.test');
        self::assertNotNull($stored);
        self::assertSame('Adaeze Mwangi-Otieno', $stored->getFullName());
        self::assertSame('r-114', $stored->getRangerCode());
    }

    /** Deactivating is not deleting: the row stays and reactivating is one call. */
    public function testDeactivatingKeepsTheRowAndReactivatingBringsItBack(): void
    {
        $this->accounts()->create('ada@example.test', 'Ada', 'Mwangi', 'a-long-enough-passphrase', TeamRoleEnum::SuperAdmin);
        $bea = $this->accounts()->create('bea@example.test', 'Bea', 'Kimaro', 'a-long-enough-passphrase');

        $this->accounts()->deactivate($bea);

        $stored = $this->users()->findOneByEmail('bea@example.test');
        self::assertNotNull($stored);
        self::assertFalse($stored->isActive());

        $this->accounts()->reactivate($stored);
        self::assertTrue($this->users()->findOneByEmail('bea@example.test')?->isActive());
    }

    public function testTheLastActiveSuperAdminCannotBeDeactivated(): void
    {
        $ada = $this->accounts()->create('ada@example.test', 'Ada', 'Mwangi', 'a-long-enough-passphrase', TeamRoleEnum::SuperAdmin);

        $this->expectException(LastSuperAdminException::class);

        $this->accounts()->deactivate($ada);
    }

    public function testTheLastActiveSuperAdminCannotBeDemoted(): void
    {
        $ada = $this->accounts()->create('ada@example.test', 'Ada', 'Mwangi', 'a-long-enough-passphrase', TeamRoleEnum::SuperAdmin);

        $this->expectException(LastSuperAdminException::class);

        $this->accounts()->changeTier($ada, TeamRoleEnum::Staff, $ada);
    }

    public function testATierChangeIsStoredOnceThereIsASuccessor(): void
    {
        $ada = $this->accounts()->create('ada@example.test', 'Ada', 'Mwangi', 'a-long-enough-passphrase', TeamRoleEnum::SuperAdmin);
        $bea = $this->accounts()->create('bea@example.test', 'Bea', 'Kimaro', 'a-long-enough-passphrase', TeamRoleEnum::SuperAdmin);

        $this->accounts()->changeTier($ada, TeamRoleEnum::Staff, $bea);

        self::assertSame(TeamRoleEnum::Staff, $this->users()->findOneByEmail('ada@example.test')?->getTeamRole());
    }

    /**
     * THE INVITATION SENT AGAIN ROTATES THE TOKEN, so an old email sitting in
     * an inbox stops working: two live links to one account is one more way
     * in than anybody authorised.
     */
    public function testSendingAnInvitationAgainRotatesTheTokenAndTouchesNoPassword(): void
    {
        $invited = $this->accounts()->invite('bea@example.test', null, null);
        $first = $invited->getVerificationToken();
        $password = $invited->getPassword();

        $second = $this->accounts()->reinvite($invited);

        $stored = $this->users()->findOneByEmail('bea@example.test');

        self::assertNotSame($first, $second);
        self::assertNotNull($stored);
        self::assertSame($second, $stored->getVerificationToken());
        self::assertSame($password, $stored->getPassword());
        self::assertFalse($stored->isVerified());
    }

    /**
     * ONCE THE TOKEN IS SPENT THE ACCOUNT IS THEIRS, and the way back in is a
     * password reset — a different letter with a different lifetime. Asking
     * for an invitation there is refused rather than quietly reissued.
     */
    public function testAnAccountThatHasSignedInCannotBeInvitedAgain(): void
    {
        $arrived = $this->accounts()->create('ada@example.test', 'Ada', 'Mwangi', 'a-long-enough-passphrase');

        $this->expectException(\LogicException::class);

        $this->accounts()->reinvite($arrived);
    }

    private function accounts(): UserService
    {
        return $this->service(UserService::class);
    }

    private function users(): UserRepository
    {
        $this->em->clear();

        return $this->service(UserRepository::class);
    }
}
