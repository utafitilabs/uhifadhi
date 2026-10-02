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

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Uhifadhi\Bundle\TeamBundle\Entity\Department;
use Uhifadhi\Bundle\TeamBundle\Entity\Placement;
use Uhifadhi\Bundle\TeamBundle\Entity\Position;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Bundle\TeamBundle\Enum\TeamRoleEnum;
use Uhifadhi\Bundle\TeamBundle\Repository\DepartmentRepository;
use Uhifadhi\Bundle\TeamBundle\Repository\UserRepository;
use Uhifadhi\Bundle\TeamBundle\Service\DepartmentMembership;
use Uhifadhi\Bundle\TeamBundle\Tests\Integration\IntegrationTestCase;

/**
 * A DEPARTMENT IS A REAL ENTITY AND THIS BUNDLE OWNS IT — AND IT IS A
 * PLACEMENT, NOT AN OWNER.
 *
 * The previous release let a position belong to a department, and scoped the
 * position's name to it: Ecology had an Analyst, Protection Service had an
 * Analyst, and they were two rows. The ruling replaced that. A data scientist
 * supporting Ecology and Protection is ONE Analyst placed against two
 * departments, and the old shape could only express them by inventing a
 * second position or a department of convenience.
 *
 * So the department left the position, and with it the department-scoped
 * name: THE NAME IS UNIQUE ACROSS THE ORGANIZATION, there is one Analyst, and
 * who is in a department — and therefore which positions it sees — is read off
 * each person's {@see Placement} by {@see DepartmentMembership}.
 */
final class DepartmentTest extends IntegrationTestCase
{
    public function testTheTableIsPrefixed(): void
    {
        self::assertSame('team_department', $this->em->getClassMetadata(Department::class)->getTableName());
    }

    public function testADepartmentPersistsWithAUuid(): void
    {
        $department = (new Department())->setName('Protection Service');

        $this->em->persist($department);
        $this->em->flush();
        $this->em->clear();

        $stored = $this->service(DepartmentRepository::class)->findOneByName('Protection Service');

        self::assertInstanceOf(Department::class, $stored);
        self::assertNotNull($stored->getUuid());
        self::assertNotNull($stored->getCreatedAt());
    }

    /**
     * THE NAME IS THE POSITION'S IDENTITY, ORGANIZATION-WIDE. There is one
     * Analyst, held by as many people as the work needs, and a reader of
     * somebody's record never has to ask which Analyst is meant.
     */
    public function testTwoPositionsMayNotShareAName(): void
    {
        $this->em->persist((new Position())->setName('Analyst'));
        $this->em->persist((new Position())->setName('Analyst'));

        $this->expectException(UniqueConstraintViolationException::class);
        $this->em->flush();
    }

    /**
     * THE ORGANIZATION-WIDE INDEX IS NAMED HERE, so that it is a decision
     * somebody asserted rather than a diff nobody read — and so that the
     * department-scoped index the old model carried cannot creep back.
     */
    public function testThePositionNameIsUniqueAcrossTheInstallation(): void
    {
        $constraints = $this->em->getClassMetadata(Position::class)->table['uniqueConstraints'] ?? [];

        self::assertSame(
            ['uniq_team_position_name' => ['fields' => ['name']]],
            $constraints,
            'One unique constraint, and it is the organization-wide one. There is no uniq_team_position_department_name.',
        );
    }

    /**
     * A DEPARTMENT'S POSITIONS ARE DERIVED, not filed. They are the distinct
     * positions held by the people who belong to it. Somebody who supports
     * another department is not in its list: supporting is not belonging.
     */
    public function testADepartmentSeesThePositionsItsMembersHold(): void
    {
        $ecology = (new Department())->setName('Ecology');
        $protection = (new Department())->setName('Protection Service');
        $this->em->persist($ecology);
        $this->em->persist($protection);

        $analyst = (new Position())->setName('Analyst');
        $vet = (new Position())->setName('Veterinary Officer');
        $sergeant = (new Position())->setName('Sergeant');
        $this->em->persist($analyst);
        $this->em->persist($vet);
        $this->em->persist($sergeant);

        $this->placePerson('Asha', 'Mwinyi', $analyst, [$ecology, $protection]);
        $this->placePerson('Baraka', 'Sawe', $vet, [$ecology]);
        $this->placePerson('Joseph', 'Mollel', $sergeant, [$protection]);
        $this->em->flush();

        $membership = new DepartmentMembership($this->service(UserRepository::class));

        self::assertSame(
            ['Analyst', 'Veterinary Officer'],
            array_map(static fn (Position $p): ?string => $p->getName(), $membership->positionsIn($ecology)),
        );
        self::assertSame(
            ['Sergeant'],
            array_map(static fn (Position $p): ?string => $p->getName(), $membership->positionsIn($protection)),
        );
    }

    /**
     * SUPPORTING IS NOT BELONGING (ruled 2 Oct 2026): a ranger who supports
     * Ecology is a member of Protection Service, their own, and of no other.
     */
    public function testSupportingADepartmentIsNotMembershipOfIt(): void
    {
        $ecology = (new Department())->setName('Ecology');
        $protection = (new Department())->setName('Protection Service');
        $this->em->persist($ecology);
        $this->em->persist($protection);

        $ranger = (new Position())->setName('Ranger');
        $this->em->persist($ranger);
        $this->placePerson('Grace', 'Ngowi', $ranger, [$protection, $ecology]);
        $this->em->flush();

        $membership = new DepartmentMembership($this->service(UserRepository::class));

        self::assertSame(
            ['Grace Ngowi'],
            array_map(static fn (User $u): string => $u->getFullName(), $membership->membersOf($protection)),
        );
        self::assertSame([], $membership->membersOf($ecology));
    }

    /**
     * BEING UNPLACED IS A REAL STATE, and it is membership of nothing rather
     * than membership of everything — the same fail-closed reading the voter
     * gives it.
     */
    public function testAnUnplacedPersonIsInNoDepartment(): void
    {
        $ecology = (new Department())->setName('Ecology');
        $this->em->persist($ecology);

        $warden = (new Position())->setName('Chief Warden');
        $this->em->persist($warden);
        $unplaced = (new User())->setEmail('u@example.test')->setFirstName('Unplaced')->setLastName('Person')
            ->setPassword('x')->setTeamRole(TeamRoleEnum::Staff)->setPosition($warden);
        $this->em->persist($unplaced);
        $this->em->flush();

        $membership = new DepartmentMembership($this->service(UserRepository::class));

        self::assertSame([], $membership->membersOf($ecology));
        self::assertSame([], $membership->positionsIn($ecology));
        self::assertFalse($membership->covers($unplaced, $ecology));
    }

    /**
     * DEACTIVATE, NEVER DELETE. A wound-down department stays a row: the flag
     * flips, the moment is recorded, and reactivation clears it. Deactivate is
     * idempotent — a department already inactive keeps its first moment.
     */
    public function testADepartmentDeactivatesAndReactivatesWithoutBeingDeleted(): void
    {
        $department = (new Department())->setName('Tourism Concessions');
        self::assertTrue($department->isActive());
        self::assertNull($department->getDeactivatedAt());

        $first = new \DateTimeImmutable('2026-01-01 09:00:00');
        $department->deactivate($first);
        self::assertFalse($department->isActive());
        self::assertEquals($first, $department->getDeactivatedAt());

        // Idempotent: pressing it again keeps the first moment.
        $department->deactivate(new \DateTimeImmutable('2026-02-02 09:00:00'));
        self::assertEquals($first, $department->getDeactivatedAt());

        $this->em->persist($department);
        $this->em->flush();
        $this->em->clear();

        $stored = $this->service(DepartmentRepository::class)->findOneByName('Tourism Concessions');
        self::assertInstanceOf(Department::class, $stored);
        self::assertFalse($stored->isActive(), 'the row survives, deactivated');

        $stored->reactivate();
        self::assertTrue($stored->isActive());
        self::assertNull($stored->getDeactivatedAt(), 'a live department carries no stale deactivated-at');
    }

    /** The pickers read active departments only; the register reads them all. */
    public function testFindAllActiveOrderedExcludesDeactivated(): void
    {
        $live = (new Department())->setName('Ecology');
        $gone = (new Department())->setName('Tourism Concessions')->deactivate();
        $this->em->persist($live);
        $this->em->persist($gone);
        $this->em->flush();

        $repo = $this->service(DepartmentRepository::class);
        $activeNames = array_map(static fn (Department $d): ?string => $d->getName(), $repo->findAllActiveOrdered());
        $allNames = array_map(static fn (Department $d): ?string => $d->getName(), $repo->findAllOrdered());

        self::assertContains('Ecology', $activeNames);
        self::assertNotContains('Tourism Concessions', $activeNames);
        self::assertContains('Tourism Concessions', $allNames, 'the register still draws it, greyed');
    }

    /**
     * Somebody holding a position and placed at the named departments — null
     * departments being "across all of them".
     *
     * @param list<Department>|null $departments
     */
    private function placePerson(string $first, string $last, Position $position, ?array $departments): User
    {
        $placement = (new Placement())->acrossTheOrganization();
        null === $departments ? $placement->inDepartment($this->homeDepartment($this->em)) : $placement->inDepartment($departments[0])->supporting(\array_slice($departments, 1));
        $this->em->persist($placement);

        $user = (new User())
            ->setEmail(strtolower($first[0].'.'.$last).'@example.test')
            ->setFirstName($first)->setLastName($last)->setPassword('x')
            ->setTeamRole(TeamRoleEnum::Staff)
            ->setPosition($position)->setPlacement($placement);
        $this->em->persist($user);

        return $user;
    }
}
