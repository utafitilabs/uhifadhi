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

namespace Uhifadhi\Bundle\TeamBundle\Service;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Uhifadhi\Bundle\RegistryBundle\Entity\Module;
use Uhifadhi\Bundle\TeamBundle\Entity\Department;
use Uhifadhi\Bundle\TeamBundle\Entity\DepartmentGoal;
use Uhifadhi\Bundle\TeamBundle\Entity\Position;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Bundle\TeamBundle\Enum\GoalDirectionEnum;
use Uhifadhi\Bundle\TeamBundle\Enum\PermissionChangeEnum;
use Uhifadhi\Bundle\TeamBundle\Exception\MissingScopeChangeReasonException;
use Uhifadhi\Bundle\TeamBundle\Exception\NameNotUniqueException;
use Uhifadhi\Contracts\Entity\AreaInterface;

/**
 * THE ORG CHART'S WRITES — every way a department is shaped.
 *
 * A DEPARTMENT CARRIES A SCOPE. It is either AREA-LEVEL, confined to one area,
 * or ORG-LEVEL, spanning every area, and the scope is derived from the nullable
 * area on the entity rather than stored twice. A name is unique WITHIN its
 * scope and nowhere else: two areas may each run an Anti-Poaching unit, and the
 * organization-wide ones each stand alone.
 *
 * A DEPARTMENT GRANTS NOTHING DIRECTLY. Filing a position into one changes
 * where its work is read; confining one to an area changes where its people's
 * authority reaches; neither is itself a grant. Capability arrives through a
 * position's permissions.
 *
 * CHANGING A SCOPE IS AUDITED, BOTH DIRECTIONS, and the entity is what records
 * it: {@see Department::changeScopeTo()} is the one door, it refuses a change
 * with no reason, and it appends the transition. This only supplies who and why.
 *
 * WHICH MODULES IT RUNS DECIDES WHO READS THEM. {@see attach()} and
 * {@see detach()} decide what a department's people meet first and what figures
 * its performance surfaces roll up, and — once any department runs a module —
 * that only the people of a department running it reach its records; so each
 * leaves a line in the permission history.
 *
 * A DEPARTMENT DEACTIVATES, IT NEVER DELETES. Winding one down flips a flag: the
 * register draws it greyed, the pickers drop it, its scope history and filed
 * positions are untouched, and reactivating is one call. How much is filed under
 * it INFORMS the sentence a screen prints and never guards the write.
 *
 * IT DECIDES NOTHING ABOUT WHO IS ASKING — that is an area-scope question about
 * the signed-in session ({@see \Uhifadhi\Bundle\TeamBundle\Security\AreaAuthority}),
 * settled by the screen before it calls in here.
 */
final readonly class DepartmentService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ?PermissionChangeLog $changes = null,
    ) {
    }

    /**
     * An area makes it area-level; its absence makes it organization-wide.
     *
     * @throws NameNotUniqueException when that scope already runs a department of the name
     */
    public function create(string $name, ?AreaInterface $area): Department
    {
        $department = new Department()->setName($name);
        if (null !== $area) {
            $department->setArea($area);
        }

        $this->entityManager->persist($department);
        $this->flush($name);

        return $department;
    }

    /**
     * Every position it owns is named after it, so all of them read differently
     * afterwards.
     *
     * @throws NameNotUniqueException when that scope already runs a department of the name
     */
    public function rename(Department $department, string $name): void
    {
        $department->setName($name);

        $this->flush($name);
    }

    /**
     * CONFINE TO AN AREA, OR PROMOTE TO ORG-WIDE — the one act that moves the
     * authority of everyone filed under the department, so the reason is
     * required and the transition is recorded.
     *
     * @throws MissingScopeChangeReasonException when no reason was given
     * @throws NameNotUniqueException            when the destination area already runs a department of the name
     */
    public function changeScope(Department $department, ?AreaInterface $area, ?User $by, string $reason): void
    {
        $was = PermissionChangeLog::scopeOf($department);
        $department->changeScopeTo($area, $by, $reason);
        $this->changes?->record(PermissionChangeEnum::ScopeChanged, $department, $was, PermissionChangeLog::scopeOf($department), trim($reason), $by);

        $this->flush((string) $department->getName());
    }

    /**
     * ATTACH A MODULE — the department runs it.
     *
     * Idempotent, because the control is a chip and a chip can be pressed twice.
     *
     * IT MOVES WHO READS THE MODULE: once any department runs a module, only the
     * people who belong to or support a department running it reach its records
     * ({@see \Uhifadhi\Bundle\TeamBundle\Security\GrantVoter}). So attaching and
     * detaching are changes to who may do what, and each leaves its line.
     */
    public function attach(Department $department, Module $module): void
    {
        if (!$department->hasModule($module)) {
            $was = self::modulesOf($department);
            $department->attachModule($module);
            $this->changes?->record(PermissionChangeEnum::ModuleAttached, $department, $was, self::modulesOf($department));
        }
        $this->entityManager->flush();
    }

    public function detach(Department $department, Module $module): void
    {
        if ($department->hasModule($module)) {
            $was = self::modulesOf($department);
            $department->detachModule($module);
            $this->changes?->record(PermissionChangeEnum::ModuleDetached, $department, $was, self::modulesOf($department));
        }
        $this->entityManager->flush();
    }

    private static function modulesOf(Department $department): ?string
    {
        return PermissionChangeLog::listOf(array_values(array_map(static fn (Module $m): string => (string) $m->getName(), $department->getModules()->toArray())));
    }

    /**
     * DECLARE A GOAL. Everything about it is settled by the screen: what it
     * says, which published figure answers it, the number, the direction
     * and the window. This writes it and nothing else — a goal grants
     * nobody anything, so there is no authority to re-check here.
     *
     * @throws \InvalidArgumentException when the sentence is empty or the window closes before it opens
     */
    public function declareGoal(
        Department $department,
        string $statement,
        float $target,
        string $unit,
        GoalDirectionEnum $direction,
        \DateTimeImmutable $opensAt,
        \DateTimeImmutable $closesAt,
        ?string $kpiRef = null,
        ?Position $owner = null,
    ): DepartmentGoal {
        $statement = trim($statement);
        if ('' === $statement) {
            throw new \InvalidArgumentException('A goal is a sentence with a number in it; this one has no sentence.');
        }

        if ($closesAt < $opensAt) {
            throw new \InvalidArgumentException('A goal cannot close before it opens.');
        }

        $goal = new DepartmentGoal()
            ->setDepartment($department)
            ->setStatement($statement)
            ->setTarget($target)
            ->setUnit(trim($unit))
            ->setDirection($direction)
            ->setOpensAt($opensAt)
            ->setClosesAt($closesAt)
            ->setKpiRef($kpiRef)
            ->setOwner($owner);

        $this->entityManager->persist($goal);
        $this->entityManager->flush();

        return $goal;
    }

    /**
     * WITHDRAW ONE, ENTIRELY. A goal is not an audit record: it is a
     * commitment somebody made and may unmake, and a withdrawn one that
     * stayed on the page greyed out would be read as a miss.
     */
    public function withdrawGoal(DepartmentGoal $goal): void
    {
        $this->entityManager->remove($goal);
        $this->entityManager->flush();
    }

    public function deactivate(Department $department): void
    {
        $department->deactivate();
        $this->entityManager->flush();
    }

    public function reactivate(Department $department): void
    {
        $department->reactivate();
        $this->entityManager->flush();
    }

    /**
     * The unique index within a scope is what actually refuses a repeated name.
     * Carried out of the storage layer so a caller catches a fact about the org
     * chart and words it with the scope in it — which it has to, because the
     * same name in another area is fine.
     *
     * @throws NameNotUniqueException
     */
    private function flush(string $name): void
    {
        try {
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException $clash) {
            throw new NameNotUniqueException($name, $clash);
        }
    }
}
