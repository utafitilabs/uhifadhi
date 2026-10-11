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

namespace Uhifadhi\Bundle\TeamBundle\Tests\Integration\Permission;

use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\SwitchUserToken;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Uhifadhi\Bundle\RegistryBundle\Entity\Module;
use Uhifadhi\Bundle\RegistryBundle\Enum\ModuleCategory;
use Uhifadhi\Bundle\RegistryBundle\Enum\ModuleStatus;
use Uhifadhi\Bundle\TeamBundle\Entity\Department;
use Uhifadhi\Bundle\TeamBundle\Entity\PermissionChange;
use Uhifadhi\Bundle\TeamBundle\Entity\Placement;
use Uhifadhi\Bundle\TeamBundle\Entity\Position;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Bundle\TeamBundle\Enum\PermissionChangeEnum;
use Uhifadhi\Bundle\TeamBundle\Enum\TeamRoleEnum;
use Uhifadhi\Bundle\TeamBundle\Service\DepartmentService;
use Uhifadhi\Bundle\TeamBundle\Service\OneTimePasswordService;
use Uhifadhi\Bundle\TeamBundle\Service\PositionService;
use Uhifadhi\Bundle\TeamBundle\Service\UserService;
use Uhifadhi\Bundle\TeamBundle\Tests\Integration\Fixtures\Area\HostArea;
use Uhifadhi\Bundle\TeamBundle\Tests\Integration\IntegrationTestCase;

/**
 * EVERY CHANGE TO WHO MAY DO WHAT LEAVES ONE LINE (ruled 11 Oct 2026, #75):
 * written in the same flush as the change, naming who made it — the person
 * signed in, or the Super Admin behind a switch — and nothing when nothing
 * changed.
 */
final class PermissionChangeLogTest extends IntegrationTestCase
{
    private User $naomi;
    private User $thabo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->naomi = $this->person('Naomi', 'Kileo', TeamRoleEnum::SuperAdmin);
        $this->thabo = $this->person('Thabo', 'Ndlovu');
        $this->signIn($this->naomi);
    }

    public function testATierChangeNamesWhoMadeItAndBothTiers(): void
    {
        $this->accounts()->changeTier($this->thabo, TeamRoleEnum::Admin, $this->naomi);

        $line = $this->onlyLine();
        self::assertSame(PermissionChangeEnum::TierChanged, $line->getAction());
        self::assertSame('Naomi Kileo', $line->getByName());
        self::assertSame($this->naomi->getId(), $line->getById());
        self::assertSame(PermissionChange::PERSON, $line->getSubjectKind());
        self::assertSame('Thabo Ndlovu', $line->getSubjectName());
        self::assertSame((string) $this->thabo->getUuidString(), $line->getSubjectUuid());
        self::assertSame(['Staff', 'Admin'], [$line->getBefore(), $line->getAfter()]);
    }

    public function testSeatingAndUnseatingNameThePosition(): void
    {
        $ranger = $this->position('Ranger');
        $this->accounts()->assignPosition($this->thabo, $ranger);
        $this->accounts()->assignPosition($this->thabo, $ranger);
        $this->accounts()->assignPosition($this->thabo, null);

        self::assertSame(
            [[PermissionChangeEnum::Seated, null, 'Ranger'], [PermissionChangeEnum::Unseated, 'Ranger', null]],
            array_map(static fn (PermissionChange $c): array => [$c->getAction(), $c->getBefore(), $c->getAfter()], $this->lines()),
            'saving the position they already hold is no change',
        );
    }

    public function testAPlacementNamesTheGroundAndTheDepartments(): void
    {
        $kilimani = $this->area('Kilimani');
        $operations = $this->department('Operations');
        $ecology = $this->department('Ecology');

        $this->accounts()->place($this->thabo, new Placement()->inAreas([$kilimani])->inDepartment($operations));
        $this->accounts()->place($this->thabo, new Placement()->inAreas([$kilimani])->inDepartment($operations));
        $this->accounts()->place($this->thabo, new Placement()->inAreas([$kilimani])->inDepartment($operations)->supporting([$ecology]));

        self::assertSame(
            [['placed nowhere', 'Kilimani · Operations'], ['Kilimani · Operations', 'Kilimani · Operations · supports Ecology']],
            array_map(static fn (PermissionChange $c): array => [$c->getBefore(), $c->getAfter()], $this->lines()),
        );
    }

    public function testTheAccountsOwnChangesAreLines(): void
    {
        $this->accounts()->updateRecord($this->thabo, 'Thabo', 'Ndlovu', 'thabo.ndlovu@example.test', null);
        $this->accounts()->updateRecord($this->thabo, 'Thabo', 'Ndlovu', 't.ndlovu@example.test', null);
        $this->accounts()->deactivate($this->thabo);
        $this->accounts()->reactivate($this->thabo);
        $this->accounts()->resetLinkSent($this->thabo, $this->naomi);
        $this->accounts()->invite('upendo@example.test', null, $this->naomi);

        self::assertSame(
            [PermissionChangeEnum::EmailChanged, PermissionChangeEnum::Deactivated, PermissionChangeEnum::Reactivated, PermissionChangeEnum::ResetLinkSent, PermissionChangeEnum::Invited],
            array_map(static fn (PermissionChange $c): PermissionChangeEnum => $c->getAction(), $this->lines()),
            'an unchanged address is no change',
        );
        self::assertSame(['thabo.ndlovu@example.test', 't.ndlovu@example.test'], [$this->lines()[0]->getBefore(), $this->lines()[0]->getAfter()]);
    }

    public function testAnAccountMadeWithAPasswordIsALine(): void
    {
        $this->accounts()->create('upendo@example.test', 'Upendo', 'Massawe', 'a long enough password', TeamRoleEnum::Admin);

        $line = $this->onlyLine();
        self::assertSame(PermissionChangeEnum::AccountCreated, $line->getAction());
        self::assertSame('Upendo Massawe', $line->getSubjectName());
        self::assertSame('Admin', $line->getAfter());
    }

    public function testAOneTimePasswordIsALineAndTheCodeIsNot(): void
    {
        $service = $this->service(OneTimePasswordService::class);
        $code = $service->issue($this->naomi, $this->thabo);

        $line = $this->onlyLine();
        self::assertSame(PermissionChangeEnum::OneTimePasswordIssued, $line->getAction());
        self::assertStringNotContainsString($code, (string) $line->getAfter().$line->getBefore().$line->getReason());
    }

    public function testAPositionsGrantsAreTheSetBeforeAndTheSetAfter(): void
    {
        $ranger = $this->position('Ranger');
        $this->positions()->setGrants($ranger, ['departments.read']);
        $this->positions()->setGrants($ranger, ['departments.read']);
        $this->positions()->setGrants($ranger, ['positions.read', 'departments.read']);

        self::assertSame(
            [[null, 'departments.read'], ['departments.read', 'departments.read, positions.read']],
            array_map(static fn (PermissionChange $c): array => [$c->getBefore(), $c->getAfter()], $this->lines()),
            'the same set saved again is no change',
        );
        self::assertSame(PermissionChange::POSITION, $this->lines()[0]->getSubjectKind());
    }

    public function testAnExceptionKeepsItsReason(): void
    {
        $room = $this->position('Control room operator');
        $this->positions()->grantException($room, 'locations.read', 'The radio room coordinates every rescue.', $this->naomi);
        $this->positions()->revokeException($room, 'locations.read', $this->naomi);

        [$given, $taken] = $this->lines();
        self::assertSame(PermissionChangeEnum::ExceptionGiven, $given->getAction());
        self::assertSame('The radio room coordinates every rescue.', $given->getReason());
        self::assertNull($given->getBefore());
        self::assertSame(PermissionChangeEnum::ExceptionTaken, $taken->getAction());
        self::assertSame($given->getAfter(), $taken->getBefore());
    }

    public function testADepartmentsScopeAndModulesAreLines(): void
    {
        $ecology = $this->department('Ecology');
        $surveys = $this->module('surveys');

        $this->departments()->changeScope($ecology, $this->area('Kilimani'), $this->naomi, 'Ecology works only Kilimani now.');
        $this->departments()->attach($ecology, $surveys);
        $this->departments()->attach($ecology, $surveys);
        $this->departments()->detach($ecology, $surveys);

        $lines = $this->lines();
        self::assertSame(
            [[PermissionChangeEnum::ScopeChanged, 'the whole organization', 'Kilimani only'], [PermissionChangeEnum::ModuleAttached, null, 'Surveys'], [PermissionChangeEnum::ModuleDetached, 'Surveys', null]],
            array_map(static fn (PermissionChange $c): array => [$c->getAction(), $c->getBefore(), $c->getAfter()], $lines),
            'attaching what is attached is no change',
        );
        self::assertSame('Ecology works only Kilimani now.', $lines[0]->getReason());
        self::assertSame(PermissionChange::DEPARTMENT, $lines[0]->getSubjectKind());
    }

    /** Behind a switch, the change was made by the Super Admin who switched, not by the person they became. */
    public function testASwitchedSessionNamesWhoSwitched(): void
    {
        $original = new UsernamePasswordToken($this->naomi, 'main', $this->naomi->getRoles());
        $this->tokens()->setToken(new SwitchUserToken($this->thabo, 'main', $this->thabo->getRoles(), $original));

        $this->accounts()->deactivate($this->person('Kito', 'Mwenda'));

        self::assertSame('Naomi Kileo', $this->onlyLine()->getByName());
    }

    /** Nobody signed in — the console, an import — is said so, never left blank. */
    public function testAChangeWithNobodySignedInIsTheSystems(): void
    {
        $this->tokens()->setToken(null);
        $this->accounts()->deactivate($this->thabo);

        self::assertNull($this->onlyLine()->getById());
        self::assertSame('the system', $this->onlyLine()->getByName());
    }

    private function onlyLine(): PermissionChange
    {
        $lines = $this->lines();
        self::assertCount(1, $lines);

        return $lines[0];
    }

    /** @return list<PermissionChange> oldest first */
    private function lines(): array
    {
        $this->em->clear();

        return $this->em->getRepository(PermissionChange::class)->findBy([], ['id' => 'ASC']);
    }

    private function person(string $first, string $last, TeamRoleEnum $tier = TeamRoleEnum::Staff): User
    {
        $user = new User()->setPassword('x')->setEmail(strtolower($first.'.'.$last).'@example.test')
            ->setFirstName($first)->setLastName($last)->setTeamRole($tier);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function position(string $name): Position
    {
        $position = new Position()->setName($name);
        $this->em->persist($position);
        $this->em->flush();

        return $position;
    }

    private function department(string $name): Department
    {
        $department = new Department()->setName($name);
        $this->em->persist($department);
        $this->em->flush();

        return $department;
    }

    private function area(string $name): HostArea
    {
        $area = new HostArea()->setName($name);
        $this->em->persist($area);
        $this->em->flush();

        return $area;
    }

    private function module(string $slug): Module
    {
        $module = new Module()->setSlug($slug)->setName(ucfirst($slug))->setCategory(ModuleCategory::Operations)
            ->setStatus(ModuleStatus::Live)->setDataSource('a fixture')->setPinned(false)->setPosition(1);
        $this->em->persist($module);
        $this->em->flush();

        return $module;
    }

    private function signIn(User $user): void
    {
        $this->tokens()->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
    }

    private function tokens(): TokenStorageInterface
    {
        $tokens = static::getContainer()->get('security.token_storage');
        self::assertInstanceOf(TokenStorageInterface::class, $tokens);

        return $tokens;
    }

    private function accounts(): UserService
    {
        return $this->service(UserService::class);
    }

    private function positions(): PositionService
    {
        return $this->service(PositionService::class);
    }

    private function departments(): DepartmentService
    {
        return $this->service(DepartmentService::class);
    }
}
