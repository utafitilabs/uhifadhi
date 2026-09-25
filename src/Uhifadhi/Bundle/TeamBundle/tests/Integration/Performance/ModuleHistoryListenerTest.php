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
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Uhifadhi\Bundle\RegistryBundle\Event\ModuleInstalledEvent;
use Uhifadhi\Bundle\TeamBundle\EventListener\ModuleHistoryListener;
use Uhifadhi\Bundle\TeamBundle\MessageHandler\BackfillModuleHistoryHandler;
use Uhifadhi\Bundle\TeamBundle\Service\PerformanceHistory;
use Uhifadhi\Bundle\TeamBundle\Tests\Integration\Fixtures\Area\HostArea;
use Uhifadhi\Bundle\TeamBundle\Tests\Integration\IntegrationTestCase;

/**
 * A MODULE SWITCHED ON TODAY IS ASKED ABOUT THE PERIODS THAT HAVE CLOSED.
 *
 * The snapshot writes what the INSTALLED modules published, once a
 * period. A module switched on afterwards was not installed when any of
 * those runs happened, so without this its first six periods are holes —
 * and the page it appears on would show a module with no past at all,
 * every movement reading "no history yet" long after the module had one.
 *
 * IT INVENTS NOTHING. What is written is what the module answers when
 * asked about that month; where it answers with nothing, the period stays
 * a hole.
 *
 * This kernel routes nothing to a queue, so the backfill the install
 * dispatches is handled in the same process — which is what lets this
 * suite see the listener and the worker's handler as one path.
 */
#[CoversClass(ModuleHistoryListener::class)]
#[CoversClass(BackfillModuleHistoryHandler::class)]
final class ModuleHistoryListenerTest extends IntegrationTestCase
{
    public function testInstallingAModuleWritesTheClosedPeriodsItCanAnswerFor(): void
    {
        $department = $this->aDepartmentAttaching('surveys');

        $this->install('surveys');

        $written = $this->history()->runFor(
            $department,
            'surveys.surveys',
            PerformanceHistory::monthsEndingAt(new \DateTimeImmutable('first day of last month'), BackfillModuleHistoryHandler::PERIODS),
        );

        self::assertCount(BackfillModuleHistoryHandler::PERIODS, $written);
        self::assertNotContains(null, $written, 'the stand-in answers for every month it is asked about');
    }

    /** Only the module that was installed, and not everything else on the page. */
    public function testItWritesNothingForTheOtherModules(): void
    {
        $department = $this->aDepartmentAttaching('surveys', 'roster');

        $this->install('surveys');

        $month = PerformanceHistory::monthKey(new \DateTimeImmutable('first day of last month'));
        self::assertNotNull($this->history()->valueAt($department, 'surveys.surveys', $month));
        self::assertNull($this->history()->valueAt($department, 'roster.on_duty', $month));
    }

    /** A department attaching nothing has nothing written for it. */
    public function testADepartmentAttachingNothingIsUntouched(): void
    {
        $department = $this->aDepartmentAttaching();

        $this->install('surveys');

        $rows = $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM team_department_period_figure WHERE department_id = :id',
            ['id' => $department->getId()],
        );
        self::assertIsNumeric($rows);
        self::assertSame(0, (int) (string) $rows);
    }

    private function install(string $slug): void
    {
        $dispatcher = static::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);

        $dispatcher->dispatch(new ModuleInstalledEvent(new HostArea()->setName('Northern Reserve'), $slug));
    }

    private function aDepartmentAttaching(string ...$slugs): \Uhifadhi\Bundle\TeamBundle\Entity\Department
    {
        $department = new \Uhifadhi\Bundle\TeamBundle\Entity\Department()->setName('Ecology');
        $this->em->persist($department);

        foreach ($slugs as $slug) {
            $module = new \Uhifadhi\Bundle\RegistryBundle\Entity\Module()
                ->setSlug($slug)
                ->setName(ucfirst($slug))
                ->setCategory(\Uhifadhi\Bundle\RegistryBundle\Enum\ModuleCategory::Pressure)
                ->setStatus(\Uhifadhi\Bundle\RegistryBundle\Enum\ModuleStatus::Live)
                ->setDataSource('the stand-in')
                ->setPosition(0);
            $this->em->persist($module);
            $department->attachModule($module);
        }

        $this->em->flush();

        return $department;
    }

    private function history(): PerformanceHistory
    {
        /** @var PerformanceHistory $history */
        $history = static::getContainer()->get('test_public.'.PerformanceHistory::class);

        return $history;
    }
}
