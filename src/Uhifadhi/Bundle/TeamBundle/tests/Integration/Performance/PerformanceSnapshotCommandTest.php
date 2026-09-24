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

namespace Uhifadhi\Bundle\TeamBundle\Tests\Integration\Performance;

use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Uhifadhi\Bundle\TeamBundle\Command\PerformanceSnapshotCommand;
use Uhifadhi\Bundle\TeamBundle\Entity\Department;
use Uhifadhi\Bundle\TeamBundle\Entity\Placement;
use Uhifadhi\Bundle\TeamBundle\Entity\Position;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Bundle\TeamBundle\Service\PerformanceHistory;
use Uhifadhi\Bundle\TeamBundle\Tests\Integration\IntegrationTestCase;

/**
 * WRITING THE PERIOD DOWN BEFORE IT STOPS BEING TRUE.
 *
 * THE COMMAND IS THE ONLY WAY HISTORY IS MADE. It runs on the first of a
 * period for the period that has just closed, by hand whenever somebody
 * wants to correct a run, and it is idempotent — the same period recorded
 * twice corrects rather than doubles.
 *
 * IT WRITES WHAT THE HOST CAN ANSWER FOR: the staffing figures every
 * department has whatever it attaches, and whatever the installed modules
 * published for it. A figure nobody published is written as ABSENT rather
 * than skipped, so a sparkline can draw the hole.
 */
#[CoversClass(PerformanceSnapshotCommand::class)]
final class PerformanceSnapshotCommandTest extends IntegrationTestCase
{
    public function testItWritesTheStaffingFiguresEveryDepartmentHas(): void
    {
        $ecology = $this->aStaffedDepartment();

        $this->snapshot(['--period' => '2026-08']);

        $history = $this->history();
        self::assertSame(2.0, $history->valueAt($ecology, 'staffing.positions', '2026-08'));
        self::assertSame(1.0, $history->valueAt($ecology, 'staffing.filled', '2026-08'));
        self::assertSame(1.0, $history->valueAt($ecology, 'staffing.vacant', '2026-08'));
        self::assertSame(1.0, $history->valueAt($ecology, 'staffing.people', '2026-08'));
    }

    /** Twice is a correction, not a second history. */
    public function testRunningItTwiceCorrectsRatherThanDoubles(): void
    {
        $ecology = $this->aStaffedDepartment();

        $this->snapshot(['--period' => '2026-08']);
        $this->snapshot(['--period' => '2026-08']);

        $rows = $this->em->getConnection()->fetchOne(
            "SELECT COUNT(*) FROM team_department_period_figure WHERE figure_key = 'staffing.filled'",
        );
        self::assertIsNumeric($rows);
        self::assertSame(1, (int) (string) $rows);
        self::assertSame(1.0, $this->history()->valueAt($ecology, 'staffing.filled', '2026-08'));
    }

    /**
     * WITH NO PERIOD NAMED IT WRITES THE ONE THAT HAS JUST CLOSED, because
     * that is what running it on the first of a month is for — the current
     * period is computed live and never snapshotted while it is still
     * moving.
     */
    public function testWithNoPeriodItWritesTheOneThatHasJustClosed(): void
    {
        $ecology = $this->aStaffedDepartment();

        $this->snapshot([]);

        $closed = PerformanceHistory::monthKey(new \DateTimeImmutable('first day of last month'));
        self::assertNotNull($this->history()->valueAt($ecology, 'staffing.filled', $closed));
    }

    /** It says what it wrote, so a scheduled run leaves a legible log. */
    public function testItReportsWhatItWrote(): void
    {
        $this->aStaffedDepartment();

        $output = $this->snapshot(['--period' => '2026-08']);

        self::assertStringContainsString('2026-08', $output);
        self::assertStringContainsString('1 department', $output);
    }

    /** @param array<string, string> $input */
    private function snapshot(array $input): string
    {
        $application = new Application(self::$kernel ?? self::bootKernel());
        $application->setAutoExit(false);

        $tester = new CommandTester($application->find('team:performance:snapshot'));
        $tester->execute($input);
        $tester->assertCommandIsSuccessful();

        return $tester->getDisplay();
    }

    /**
     * ONE DEPARTMENT, TWO POSITIONS, ONE OF THEM STOOD IN.
     *
     * A DEPARTMENT'S POSITIONS ARE THE ONES ITS MEMBERS HOLD. A position used
     * to be filed under a department, so a department's posts were a column;
     * the ruling made a department a placement, so they are derived from the
     * people placed in it — and the second post is here because the ranger
     * who held it was deactivated, which is exactly what a vacancy is.
     */
    private function aStaffedDepartment(): Department
    {
        $ecology = new Department()->setName('Ecology');
        $this->em->persist($ecology);

        $held = new Position()->setName('Lead Ecologist');
        $held->setGrantValues([], []);
        $vacant = new Position()->setName('Field Ecologist');
        $vacant->setGrantValues([], []);
        $this->em->persist($held);
        $this->em->persist($vacant);

        $this->placedInEcology('Asha', 'Mollel', $held, $ecology);
        $this->placedInEcology('Baraka', 'Sawe', $vacant, $ecology)->deactivate();

        $this->em->flush();

        return $ecology;
    }

    /** Somebody holding a position and placed in this one department. */
    private function placedInEcology(string $first, string $last, Position $position, Department $department): User
    {
        $placement = new Placement()->acrossTheOrganization()->inDepartments([$department]);
        $this->em->persist($placement);

        $person = new User()
            ->setEmail(strtolower($first[0].'.'.$last).'@example.test')
            ->setFirstName($first)->setLastName($last)
            ->setPassword('x')->setVerified(true)
            ->setPosition($position)->setPlacement($placement);
        $this->em->persist($person);

        return $person;
    }

    private function history(): PerformanceHistory
    {
        /** @var PerformanceHistory $history */
        $history = static::getContainer()->get('test_public.'.PerformanceHistory::class);

        return $history;
    }
}
