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

use PHPUnit\Framework\Attributes\CoversNothing;
use Uhifadhi\Bundle\RegistryBundle\Entity\Module;
use Uhifadhi\Bundle\RegistryBundle\Enum\ModuleCategory;
use Uhifadhi\Bundle\RegistryBundle\Enum\ModuleStatus;
use Uhifadhi\Bundle\TeamBundle\Access\ConcernCatalogue;
use Uhifadhi\Bundle\TeamBundle\Entity\Department;
use Uhifadhi\Bundle\TeamBundle\Entity\Placement;
use Uhifadhi\Bundle\TeamBundle\Entity\Position;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Bundle\TeamBundle\Enum\TeamRoleEnum;
use Uhifadhi\Bundle\TeamBundle\Tests\Integration\Fixtures\Area\HostArea;
use Uhifadhi\Bundle\TeamBundle\Tests\Integration\IntegrationTestCase;
use Uhifadhi\Contracts\Access\ScopeKind;

/**
 * ONE FULL RUN AT AN INSTALLATION'S SIZE: about 360 people, 40 positions,
 * four areas and eight departments, invented and seeded the same way every
 * time. The run must finish inside a minute; its time and question count are
 * the assertion's message.
 */
#[CoversNothing]
final class PermissionRunTimingTest extends IntegrationTestCase
{
    use RunsPermissions;

    /** @var array<string, HostArea> */
    private array $areas = [];

    /** @var array<string, Position> */
    private array $positions = [];

    public function testAFullRunAtTheTestbedsSizeFinishesInsideAMinute(): void
    {
        $this->seed();

        $run = $this->runNow();

        self::assertLessThan(60_000, $run->getCount('ms'), \sprintf('%d people · %d questions · %d shapes · %d answers · %d ms',
            $run->getCount('people'), $run->getCount('questions'), $run->getCount('shapes'), $run->getCount('answers'), $run->getCount('ms')));
        self::assertSame(363, $run->getCount('people'));
    }

    private function seed(): void
    {
        mt_srand(66);
        $areas = [];
        foreach (['North Plateau', 'Lake Basin', 'Crater Rim', 'River Flats'] as $name) {
            $area = new HostArea()->setName($name);
            $this->em->persist($area);
            $areas[] = $this->areas[$name] = $area;
        }
        $surveys = new Module()->setSlug('surveys')->setName('Surveys')->setCategory(ModuleCategory::Operations)
            ->setStatus(ModuleStatus::Live)->setDataSource('a fixture')->setPinned(false)->setPosition(1);
        $this->em->persist($surveys);

        $departments = [];
        foreach (range(1, 8) as $i) {
            $department = new Department()->setName('Department '.$i);
            if ($i <= 3) {
                $department->attachModule($surveys);
            }
            $this->em->persist($department);
            $departments[] = $department;
        }

        $pairs = $this->service(ConcernCatalogue::class)->positionPairs();
        $positions = [];
        foreach (range(1, 40) as $i) {
            $held = array_values(array_filter($pairs, static fn (): bool => mt_rand(1, 100) <= 30));
            $kinds = 0 === $i % 5 ? [ScopeKind::Organization] : [ScopeKind::Area];
            $position = new Position()->setName(\sprintf('Position %02d', $i))->setAllowedKinds($kinds)->setGrantValues($held, $pairs);
            $this->em->persist($position);
            $positions[] = $this->positions[$position->getName() ?? ''] = $position;
        }

        foreach (range(1, 363) as $i) {
            $tier = match (true) {
                1 === $i => TeamRoleEnum::SuperAdmin,
                $i <= 5 => TeamRoleEnum::Admin,
                default => TeamRoleEnum::Staff,
            };
            $position = $positions[mt_rand(0, \count($positions) - 1)];
            $placement = $position->allows(ScopeKind::Organization)
                ? new Placement()->acrossTheOrganization()
                : new Placement()->inAreas(mt_rand(1, 10) > 8 ? [$areas[mt_rand(0, 3)], $areas[mt_rand(0, 3)]] : [$areas[mt_rand(0, 3)]]);
            $placement->inDepartment($departments[mt_rand(0, 7)]);
            if (mt_rand(1, 10) > 8) {
                $placement->supporting([$departments[mt_rand(0, 7)]]);
            }
            $this->em->persist($placement);
            $person = new User()->setEmail(\sprintf('person%03d@example.test', $i))->setFirstName('Person')->setLastName(\sprintf('%03d', $i))
                ->setPassword('x')->setTeamRole($tier)->setPosition($position)->setPlacement($placement);
            $this->em->persist($person);
        }
        $this->em->flush();
    }
}
