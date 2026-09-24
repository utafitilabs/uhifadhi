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

namespace Uhifadhi\Bundle\TeamBundle\Tests\Integration\Identity;

use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Uid\UuidV7;
use Uhifadhi\Bundle\TeamBundle\Access\ConcernCatalogue;
use Uhifadhi\Bundle\TeamBundle\Entity\Department;
use Uhifadhi\Bundle\TeamBundle\Entity\Placement;
use Uhifadhi\Bundle\TeamBundle\Entity\Position;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Bundle\TeamBundle\Enum\TeamRoleEnum;
use Uhifadhi\Bundle\TeamBundle\Repository\UserRepository;
use Uhifadhi\Bundle\TeamBundle\Tests\Integration\IntegrationTestCase;

/**
 * The account, stored. Its public address is a UUID, its password is a hash
 * the framework verifies, and the roles it reports are its tier's plus its
 * position's — read back from the database, not from the object in memory.
 */
final class UserPersistenceTest extends IntegrationTestCase
{
    public function testAnAccountPersistsWithAUuidAndTimestamps(): void
    {
        $user = (new User())
            ->setEmail('Warden@Example.test')
            ->setFirstName('Asha')
            ->setLastName('Mollel')
            ->setPassword('irrelevant-here');

        $this->em->persist($user);
        $this->em->flush();
        $this->em->clear();

        $users = $this->service(UserRepository::class);
        $stored = $users->findOneByEmail('warden@example.test');

        self::assertInstanceOf(User::class, $stored);
        // Public addressing is by UUID; the sequential id is never the handle.
        self::assertNotNull($stored->getUuid());
        self::assertInstanceOf(UuidV7::class, $stored->getUuid());
        self::assertNotNull($stored->getCreatedAt());
        // The email is the identifier and is stored folded, so signing in can
        // never turn on how somebody capitalised it.
        self::assertSame('warden@example.test', $stored->getEmail());
        self::assertSame('Asha Mollel', $stored->getFullName());
    }

    public function testTheTableIsPrefixed(): void
    {
        self::assertSame('team_user', $this->em->getClassMetadata(User::class)->getTableName());
        self::assertSame('team_position', $this->em->getClassMetadata(Position::class)->getTableName());
    }

    public function testAStoredPasswordVerifiesThroughTheFrameworksHasher(): void
    {
        $hasher = static::getContainer()->get('test_public.hasher');
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);

        $user = (new User())->setEmail('ranger@example.test')->setFirstName('J')->setLastName('K');
        $user->setPassword($hasher->hashPassword($user, 'correct horse'));

        $this->em->persist($user);
        $this->em->flush();
        $this->em->clear();

        $users = $this->service(UserRepository::class);
        $stored = $users->findOneByEmail('ranger@example.test');
        self::assertInstanceOf(User::class, $stored);

        // The hash is not the password, and the right password verifies.
        self::assertNotSame('correct horse', $stored->getPassword());
        self::assertTrue($hasher->isPasswordValid($stored, 'correct horse'));
        self::assertFalse($hasher->isPasswordValid($stored, 'wrong horse'));
    }

    public function testAPositionAddsNoRole(): void
    {
        $position = (new Position())->setName('Analyst')->setGrantValues(
            ['directory.read', 'positions.read'],
            $this->service(ConcernCatalogue::class)->pairs(),
        );

        $staff = (new User())->setEmail('staff@example.test')->setFirstName('S')->setLastName('T')
            ->setPassword('x')->setTeamRole(TeamRoleEnum::Staff)->setPosition($position);

        $this->em->persist($position);
        $this->em->persist($staff);
        $this->em->flush();
        $this->em->clear();

        $users = $this->service(UserRepository::class);
        $stored = $users->findOneByEmail('staff@example.test');
        self::assertInstanceOf(User::class, $stored);

        // A POSITION OPENS NO REGION. Whatever the position carries, a Staff
        // member's roles are ROLE_USER and nothing else: the permissions are
        // decided by asking about the person, so a role would be the same
        // authority granted a second time, coarsely, where nobody looks.
        self::assertSame(['ROLE_USER'], $stored->getRoles());
    }

    /**
     * THE PLACEMENT IS STORED BESIDE THE POSITION AND ANSWERED SEPARATELY.
     * Where somebody reaches used to be read through their position's
     * department; the ruling wrote it against the person instead, so it is a
     * row of its own and the person's departments come back off it — several
     * of them, which is the whole reason it exists.
     */
    public function testAPlacementIsStoredAgainstThePersonAndReadsBackItsDepartments(): void
    {
        $ecology = (new Department())->setName('Ecology');
        $protection = (new Department())->setName('Protection Service');
        $this->em->persist($ecology);
        $this->em->persist($protection);

        $position = (new Position())->setName('Analyst');
        $this->em->persist($position);

        $placement = (new Placement())->acrossTheOrganization()->inDepartments([$ecology, $protection]);
        $this->em->persist($placement);

        $analyst = (new User())->setEmail('analyst@example.test')->setFirstName('Thabo')->setLastName('Ndlovu')
            ->setPassword('x')->setTeamRole(TeamRoleEnum::Staff)
            ->setPosition($position)->setPlacement($placement);
        $this->em->persist($analyst);
        $this->em->flush();
        $this->em->clear();

        $stored = $this->service(UserRepository::class)->findOneByEmail('analyst@example.test');
        self::assertInstanceOf(User::class, $stored);
        self::assertNotNull($stored->getPlacement());

        // Null areas is the whole organization — "everywhere" is an answer
        // somebody wrote down, not an empty list.
        self::assertNull($stored->getPlacement()->getAreas());
        self::assertSame(
            ['Ecology', 'Protection Service'],
            array_map(static fn (Department $d): ?string => $d->getName(), $stored->getDepartments() ?? []),
        );
        self::assertSame('Ecology +1', $stored->getDepartmentLabel());
    }

    public function testASuperAdminHoldsTheTierRolesWithoutAPosition(): void
    {
        $user = (new User())->setEmail('boss@example.test')->setFirstName('B')->setLastName('O')
            ->setPassword('x')->setTeamRole(TeamRoleEnum::SuperAdmin);

        self::assertContains('ROLE_SUPER_ADMIN', $user->getRoles());
        self::assertContains('ROLE_ALLOWED_TO_SWITCH', $user->getRoles());
    }

    public function testTheFieldIdentifierResolvesAServiceNumberOrAnEmail(): void
    {
        $user = (new User())->setEmail('field@example.test')->setFirstName('F')->setLastName('D')
            ->setPassword('x')->setRangerCode('SL-0142');

        $this->em->persist($user);
        $this->em->flush();
        $this->em->clear();

        $users = $this->service(UserRepository::class);

        // Stored folded, so a phone keyboard's capitals cannot fail a sign-in.
        self::assertSame('sl-0142', $users->findOneByFieldIdentifier('sl-0142')?->getRangerCode());
        self::assertNotNull($users->findOneByFieldIdentifier('SL-0142'));
        self::assertNotNull($users->findOneByFieldIdentifier('field@example.test'));
        self::assertNull($users->findOneByFieldIdentifier('nobody'));
    }
}
