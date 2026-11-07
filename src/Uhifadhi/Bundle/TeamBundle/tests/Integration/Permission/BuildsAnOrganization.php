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

use Doctrine\ORM\EntityManagerInterface;
use Uhifadhi\Bundle\RegistryBundle\Entity\Module;
use Uhifadhi\Bundle\RegistryBundle\Enum\ModuleCategory;
use Uhifadhi\Bundle\RegistryBundle\Enum\ModuleStatus;
use Uhifadhi\Bundle\TeamBundle\Entity\Department;
use Uhifadhi\Bundle\TeamBundle\Entity\GrantJustification;
use Uhifadhi\Bundle\TeamBundle\Entity\Placement;
use Uhifadhi\Bundle\TeamBundle\Entity\Position;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Bundle\TeamBundle\Enum\TeamRoleEnum;
use Uhifadhi\Bundle\TeamBundle\Tests\Integration\Fixtures\Area\HostArea;
use Uhifadhi\Contracts\Access\ScopeKind;

/**
 * THE INVENTED AUTHORITY OF THE SEMANTICS' WORKED EXAMPLES, at the size a
 * suite can afford: four areas, three departments (Protection Service runs
 * the kernel's one module, `surveys`, standing in for Patrols), seven
 * positions and the people the standing lists need one of each of.
 *
 *   - three rangers at Kilimani in Protection Service, one shape between them;
 *     one ranger at Sinde Flats; one at Kilimani in Ecology, which runs nothing;
 *   - a station head at Ziwa Kavu;
 *   - the Head of Operations, organization-wide, supporting Ecology, holding
 *     Live locations with a reason in force;
 *   - the Rhino coordinator, holding Live locations with no reason;
 *   - the People officer, storing positions.configure, which only the tiers hold;
 *   - the Tourism warden, held by nobody;
 *   - somebody with no position and no placement, somebody placed with no
 *     position, a ranger with no department, a ranger with no ground, and a
 *     deactivated ranger;
 *   - one Super Admin and one Admin.
 *
 * The positions' pairs are the kernel's catalogue: the team's own, `surveys`
 * and the ground fixture's stations, locations and assignments.
 */
trait BuildsAnOrganization
{
    /** @var array<string, HostArea> */
    protected array $areas = [];

    /** @var array<string, Department> */
    protected array $departments = [];

    /** @var array<string, Position> */
    protected array $positions = [];

    /** @var array<string, User> */
    protected array $people = [];

    protected const array RANGER = ['stations.read', 'surveys.read', 'surveys.record'];

    protected function buildOrganization(EntityManagerInterface $em): void
    {
        foreach (['Kilimani Game Reserve', 'Ziwa Kavu', 'Mlima Mkuu', 'Sinde Flats'] as $name) {
            $area = new HostArea()->setName($name);
            $em->persist($area);
            $this->areas[$name] = $area;
        }

        $surveys = new Module()->setSlug('surveys')->setName('Surveys')->setCategory(ModuleCategory::Operations)
            ->setStatus(ModuleStatus::Live)->setDataSource('a fixture')->setPinned(false)->setPosition(1);
        $em->persist($surveys);

        foreach (['Protection Service', 'Ecology and Monitoring', 'ICT'] as $name) {
            $department = new Department()->setName($name);
            $em->persist($department);
            $this->departments[$name] = $department;
        }
        $this->departments['Protection Service']->attachModule($surveys);

        $area = [ScopeKind::Area];
        $org = [ScopeKind::Organization];
        $this->position($em, 'Patrol, security and inspection', $area, self::RANGER);
        $this->position($em, 'Station head', $area, [...self::RANGER, 'assignments.manage', 'sign-in-help.manage']);
        $this->position($em, 'Head of Operations', $org, ['stations.read', 'stations.configure', 'surveys.read', 'locations.read']);
        $this->position($em, 'Rhino coordinator', $org, ['surveys.read', 'locations.read']);
        $this->position($em, 'People officer', $org, ['directory.read', 'positions.configure']);
        $this->position($em, 'Tourism warden', $area, ['stations.read'])->setVacantSince(new \DateTimeImmutable('2026-08-14'));
        $this->position($em, 'Driver', $area, ['stations.read']);

        $ps = $this->departments['Protection Service'];
        $eco = $this->departments['Ecology and Monitoring'];
        $ranger = 'Patrol, security and inspection';

        $this->person($em, 'Naomi', 'Kileo', null, new Placement()->acrossTheOrganization()->inDepartment($ps), TeamRoleEnum::SuperAdmin);
        $this->person($em, 'Desta', 'Haile', null, new Placement()->acrossTheOrganization()->inDepartment($ps), TeamRoleEnum::Admin);
        $this->person($em, 'Thabo', 'Ndlovu', $ranger, $this->at('Kilimani Game Reserve', $ps));
        $this->person($em, 'Imani', 'Laizer', $ranger, $this->at('Kilimani Game Reserve', $ps));
        $this->person($em, 'Omari', 'Mollel', $ranger, $this->at('Kilimani Game Reserve', $ps));
        $this->person($em, 'Juma', 'Mrema', $ranger, $this->at('Sinde Flats', $ps));
        $this->person($em, 'Neema', 'Swai', $ranger, $this->at('Kilimani Game Reserve', $eco));
        $this->person($em, 'Rehema', 'Mushi', 'Station head', $this->at('Ziwa Kavu', $ps));
        $this->person($em, 'Grace', 'Ndosi', 'Head of Operations', new Placement()->acrossTheOrganization()->inDepartment($ps)->supporting([$eco]));
        $this->person($em, 'Elias', 'Mdee', 'Rhino coordinator', new Placement()->acrossTheOrganization()->inDepartment($eco));
        $this->person($em, 'Mariam', 'Temba', 'People officer', new Placement()->acrossTheOrganization()->inDepartment($this->departments['ICT']));
        $this->person($em, 'Frank', 'Massawe', null, null);
        $this->person($em, 'Latifa', 'Macha', null, $this->at('Ziwa Kavu', $ps));
        $this->person($em, 'Bakari', 'Chuwa', 'Driver', new Placement()->inAreas([$this->areas['Mlima Mkuu']]));
        $this->person($em, 'Chiku', 'Nnko', 'Driver', new Placement()->inDepartment($ps));
        $this->person($em, 'Kito', 'Lema', $ranger, $this->at('Kilimani Game Reserve', $ps))->deactivate(new \DateTimeImmutable('2026-10-01'));
        $em->flush();

        $em->persist(new GrantJustification($this->positions['Head of Operations'], 'locations.read', 'Runs every operation across the four areas.', $this->people['Naomi Kileo'], new \DateTimeImmutable('2026-10-03 10:18')));
        $em->flush();
    }

    /**
     * @param list<ScopeKind> $kinds
     * @param list<string>    $pairs
     */
    private function position(EntityManagerInterface $em, string $name, array $kinds, array $pairs): Position
    {
        $position = new Position()->setName($name)->setAllowedKinds($kinds)->setGrantValues($pairs, $pairs);
        $em->persist($position);

        return $this->positions[$name] = $position;
    }

    private function at(string $area, Department $department): Placement
    {
        return new Placement()->inAreas([$this->areas[$area]])->inDepartment($department);
    }

    private function person(EntityManagerInterface $em, string $first, string $last, ?string $position, ?Placement $placement, TeamRoleEnum $tier = TeamRoleEnum::Staff): User
    {
        if (null !== $placement) {
            $em->persist($placement);
        }
        $person = new User()->setEmail(strtolower($first.'.'.$last).'@example.test')->setFirstName($first)->setLastName($last)
            ->setPassword('x')->setTeamRole($tier)
            ->setPosition(null === $position ? null : $this->positions[$position])
            ->setPlacement($placement);
        $em->persist($person);

        return $this->people[$first.' '.$last] = $person;
    }
}
