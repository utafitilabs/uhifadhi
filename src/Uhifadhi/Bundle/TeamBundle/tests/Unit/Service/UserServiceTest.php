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

namespace Uhifadhi\Bundle\TeamBundle\Tests\Unit\Service;

use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Uhifadhi\Bundle\TeamBundle\Entity\Position;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Bundle\TeamBundle\Enum\TeamRoleEnum;
use Uhifadhi\Bundle\TeamBundle\Exception\PasswordTooShortException;
use Uhifadhi\Bundle\TeamBundle\Exception\PositionFullException;
use Uhifadhi\Bundle\TeamBundle\Repository\TeamSettingsRepository;
use Uhifadhi\Bundle\TeamBundle\Repository\UserRepository;
use Uhifadhi\Bundle\TeamBundle\Service\PositionVacancy;
use Uhifadhi\Bundle\TeamBundle\Service\SuperAdminInvariant;
use Uhifadhi\Bundle\TeamBundle\Service\TeamSettingsService;
use Uhifadhi\Bundle\TeamBundle\Service\UserService;

/**
 * WHAT AN ACCOUNT IS THE MOMENT IT IS MADE, with no database in the room.
 *
 * The service's collaborators are all doubles here: the point of the suite is
 * the SHAPE of the record — which fields are set, which are deliberately left
 * null, whether the credential was hashed — and none of that is a question about
 * storage. What actually reaches the table is asked next door, against a real
 * one.
 *
 * AND THE REFUSALS, which belong here for the same reason: whether a post has
 * a seat left is decided from who holds it, and who holds it is the one thing
 * the storage layer contributes. It is supplied to the service, and what the
 * service does with it is the specification.
 */
#[CoversClass(UserService::class)]
final class UserServiceTest extends TestCase
{
    public function testAnAccountCreatedWithAPasswordCanSignInImmediately(): void
    {
        $user = self::service()->create('Ada@Example.test', 'Ada', 'Mwangi', 'a-long-enough-passphrase');

        self::assertTrue($user->isVerified(), 'An administrator who typed the password has already proved the account is real.');
        self::assertTrue($user->isActive());
        self::assertSame('Ada Mwangi', $user->getFullName());
    }

    /** The email IS the identifier, so a different capitalisation is the same person. */
    public function testTheAddressIsFoldedToLowerCase(): void
    {
        $user = self::service()->create('Ada@Example.TEST', 'Ada', 'Mwangi', 'a-long-enough-passphrase');

        self::assertSame('ada@example.test', $user->getEmail());
    }

    public function testThePasswordIsNeverStoredAsItWasTyped(): void
    {
        $user = self::service()->create('ada@example.test', 'Ada', 'Mwangi', 'a-long-enough-passphrase');

        self::assertNotSame('a-long-enough-passphrase', $user->getPassword());
        self::assertNotSame('', $user->getPassword());
    }

    public function testAnAccountIsStaffUnlessAnotherTierIsAsked(): void
    {
        self::assertSame(TeamRoleEnum::Staff, self::service()->create('ada@example.test', 'Ada', 'Mwangi', 'a-long-enough-passphrase')->getTeamRole());
        self::assertSame(
            TeamRoleEnum::SuperAdmin,
            self::service()->create('bea@example.test', 'Bea', 'Kimaro', 'a-long-enough-passphrase', TeamRoleEnum::SuperAdmin)->getTeamRole(),
        );
    }

    public function testAPositionIsOptionalAndItsAbsenceIsARealChoice(): void
    {
        $position = new Position()->setName('Analyst');

        self::assertNull(self::service()->create('ada@example.test', 'Ada', 'Mwangi', 'a-long-enough-passphrase')->getPosition());
        self::assertSame($position, self::service()->create('bea@example.test', 'Bea', 'Kimaro', 'a-long-enough-passphrase', position: $position)->getPosition());
    }

    public function testAPasswordShorterThanTheOneRuleIsRefused(): void
    {
        $this->expectException(PasswordTooShortException::class);

        self::service()->create('ada@example.test', 'Ada', 'Mwangi', 'short');
    }

    /**
     * AN INVITED ACCOUNT IS THE OPPOSITE OF A CREATED ONE: nobody here knows its
     * password, it carries no usable credential, and it has no name until the
     * person supplies their own spelling of it.
     */
    public function testAnInvitedAccountCarriesNoUsableCredentialAndNoName(): void
    {
        $user = self::service()->invite('Ada@Example.test', null, null);

        self::assertFalse($user->isVerified());
        self::assertSame('ada@example.test', $user->getEmail());
        self::assertSame('', $user->getFirstName());
        self::assertSame('', $user->getLastName());
        self::assertNotSame('', $user->getPassword(), 'An empty hash is a hash some verifier will one day accept.');
        self::assertNotNull($user->getVerificationToken());
    }

    public function testAnInvitationRecordsWhoSentIt(): void
    {
        $inviter = new User()->setEmail('ada@example.test');

        self::assertSame($inviter, self::service()->invite('bea@example.test', null, $inviter)->getInvitedBy());
        self::assertNull(self::service()->invite('cara@example.test', null, null)->getInvitedBy());
    }

    /**
     * CREATED DIRECTLY IS NOT INVITED, and the roster reads the difference off
     * this null.
     */
    public function testAnAccountCreatedWithAPasswordWasInvitedByNobody(): void
    {
        $user = self::service()->create('ada@example.test', 'Ada', 'Mwangi', 'a-long-enough-passphrase');

        self::assertNull($user->getInvitedAt());
        self::assertNull($user->getInvitedBy());
    }

    // ─── A FULL POSITION REFUSES ─────────────────────────────────────────

    /**
     * SOME POSTS ARE SINGULAR. Seating a second person in a one-seat post is
     * not a thing an organization meant to allow, and the check is in the
     * service rather than on the screen because a second door that forgot to
     * ask would quietly seat one person too many.
     */
    public function testSeatingSomebodyInAPostThatIsAlreadyHeldIsRefused(): void
    {
        $head = new Position()->setName('Head of Protection')->setSeatCount(1);
        $joseph = new User()->setFirstName('Joseph')->setLastName('Mollel');

        $this->expectException(PositionFullException::class);

        self::service([$joseph])->assignPosition(new User(), $head);
    }

    /**
     * AND THE REFUSAL NAMES WHO HOLDS IT. "That position is full" is not
     * actionable; "Joseph Mollel holds it" is, because the administrator's
     * next move is to end that holding or to pick another post, and they
     * cannot choose without the name.
     */
    public function testTheRefusalNamesThePersonStandingInThePost(): void
    {
        $head = new Position()->setName('Head of Protection')->setSeatCount(1);
        $joseph = new User()->setFirstName('Joseph')->setLastName('Mollel');

        try {
            self::service([$joseph])->assignPosition(new User(), $head);
            self::fail('A one-seat post that is already held accepted a second person.');
        } catch (PositionFullException $refusal) {
            self::assertStringContainsString('Head of Protection', $refusal->getMessage());
            self::assertStringContainsString('Joseph Mollel', $refusal->getMessage());
        }
    }

    /** Unlimited is a real answer, and it never refuses however many hold it. */
    public function testAPostWithUnlimitedSeatsTakesEverybody(): void
    {
        $analyst = new Position()->setName('Data Analyst');
        self::assertTrue($analyst->hasUnlimitedSeats());

        $crowd = [
            new User()->setFirstName('Ada')->setLastName('Mwangi'),
            new User()->setFirstName('Bea')->setLastName('Kimaro'),
            new User()->setFirstName('Cara')->setLastName('Ndosi'),
        ];

        $joining = new User();
        self::service($crowd)->assignPosition($joining, $analyst);

        self::assertSame($analyst, $joining->getPosition());
    }

    /** Two seats is two: the second person is seated and the third is not. */
    public function testAPostOfTwoSeatsTakesASecondPersonAndRefusesTheThird(): void
    {
        $ranger = new Position()->setName('Ranger')->setSeatCount(2);
        $ada = new User()->setFirstName('Ada')->setLastName('Mwangi');
        $bea = new User()->setFirstName('Bea')->setLastName('Kimaro');

        $second = new User();
        self::service([$ada])->assignPosition($second, $ranger);
        self::assertSame($ranger, $second->getPosition());

        $this->expectException(PositionFullException::class);

        self::service([$ada, $bea])->assignPosition(new User(), $ranger);
    }

    /**
     * MOVING SOMEBODY TO THE POST THEY ALREADY HOLD IS NOT A SECOND SEATING.
     * The record page posts the whole picker, so re-submitting an unchanged
     * choice has to be a no-op rather than a refusal that says the person
     * holding it is in their own way.
     */
    public function testReassigningSomebodyToThePostTheyAlreadyHoldIsNotRefused(): void
    {
        $head = new Position()->setName('Head of Protection')->setSeatCount(1);
        $joseph = new User()->setFirstName('Joseph')->setLastName('Mollel');
        $joseph->setPosition($head);

        self::service([$joseph])->assignPosition($joseph, $head);

        self::assertSame($head, $joseph->getPosition());
    }

    /** Unseating somebody is always allowed: null is a real choice, not a post. */
    public function testUnseatingSomebodyIsNeverRefused(): void
    {
        $head = new Position()->setName('Head of Protection')->setSeatCount(1);
        $joseph = new User()->setFirstName('Joseph')->setLastName('Mollel');
        $joseph->setPosition($head);

        self::service([$joseph])->assignPosition($joseph, null);

        self::assertNull($joseph->getPosition());
    }

    /**
     * The invariant is built without its own collaborator because nothing here
     * reaches it: a tier is only questioned when one is CHANGED, and that
     * question counts rows, which is a question for the suite with a database.
     *
     * WHO ALREADY HOLDS A POST is the one read the refusals turn on, so it is
     * the one the caller of this helper supplies. That the query behind it
     * counts ACTIVE holders only is a fact about the table, asked where there
     * is one.
     *
     * @param list<User> $holders everybody standing in the post being assigned
     */
    private static function service(array $holders = []): UserService
    {
        $hasher = self::createStub(UserPasswordHasherInterface::class);
        $hasher->method('hashPassword')->willReturnCallback(
            static fn (PasswordAuthenticatedUserInterface $user, string $plain): string => 'hashed:'.$plain,
        );

        $users = self::createStub(UserRepository::class);
        $users->method('findActiveHolders')->willReturn($holders);

        return new UserService(
            self::createStub(EntityManagerInterface::class),
            $hasher,
            new \ReflectionClass(SuperAdminInvariant::class)->newInstanceWithoutConstructor(),
            // WHICH POSTS STAND EMPTY is a database question; this unit is
            // about the hashing and the refusals, so the collaborator is
            // real and its two reads are stubbed.
            new PositionVacancy(
                self::createStub(EntityManagerInterface::class),
                self::createStub(UserRepository::class),
            ),
            $users,
            // THE INVITATION RULES an invitation is stamped from: a row nobody
            // has written reads the defaults, which is all this unit needs.
            new TeamSettingsService(self::createStub(TeamSettingsRepository::class), self::createStub(EntityManagerInterface::class)),
        );
    }
}
