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

use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Uhifadhi\Bundle\TeamBundle\Command\RecomputePermissionsCommand;
use Uhifadhi\Bundle\TeamBundle\Entity\PermissionRun;
use Uhifadhi\Bundle\TeamBundle\Enum\PermissionChangeEnum;
use Uhifadhi\Bundle\TeamBundle\Enum\PermissionRunStatusEnum;
use Uhifadhi\Bundle\TeamBundle\Enum\PermissionRunTriggerEnum;
use Uhifadhi\Bundle\TeamBundle\EventListener\PermissionRunListener;
use Uhifadhi\Bundle\TeamBundle\Message\RecomputePermissions;
use Uhifadhi\Bundle\TeamBundle\Repository\PermissionRunRepository;
use Uhifadhi\Bundle\TeamBundle\Scheduler\RecomputePermissionsTask;
use Uhifadhi\Bundle\TeamBundle\Service\PermissionChangeLog;
use Uhifadhi\Bundle\TeamBundle\Tests\Integration\IntegrationTestCase;

/**
 * FOUR THINGS ASK FOR A RUN: every history line, the nightly schedule, the
 * deploy, and a Super Admin's button. Each writes a pending run, and the
 * worker is woken through the queue.
 */
#[CoversClass(PermissionRunListener::class)]
#[CoversClass(RecomputePermissionsTask::class)]
#[CoversClass(RecomputePermissionsCommand::class)]
final class PermissionRunTriggersTest extends IntegrationTestCase
{
    use BuildsAnOrganization;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildOrganization($this->em);
    }

    public function testAHistoryLineAsksForARunInTheSameFlush(): void
    {
        $this->line('Patrol, security and inspection');

        $pending = $this->runs()->findOnePending();
        self::assertNotNull($pending, 'the line and the request are one transaction');
        self::assertSame(PermissionRunTriggerEnum::HistoryLine, $pending->getTrigger());
        self::assertSame('Naomi Kileo', $pending->getRequestedByName());

        $this->line('Station head');
        self::assertCount(1, $this->runs()->findPending(), 'a request already waiting is the same request');
    }

    public function testTheWorkerIsWokenOnceTheWorkIsOverAndNotFromInsideTheFlush(): void
    {
        $this->line('Patrol, security and inspection');
        self::assertCount(0, $this->transport()->getSent(), 'nothing is sent during the flush');

        $listener = $this->service(PermissionRunListener::class);
        $listener->wake();
        $listener->wake();

        $sent = $this->transport()->getSent();
        self::assertCount(1, $sent);
        self::assertInstanceOf(RecomputePermissions::class, $sent[0]->getMessage());
    }

    public function testAFlushWithNoLineAsksForNothing(): void
    {
        $this->positions['Driver']->setSeatCount(4);
        $this->em->flush();

        self::assertNull($this->runs()->findOnePending());
    }

    public function testTheNightlyTaskAsksForARun(): void
    {
        $this->service(RecomputePermissionsTask::class)();

        self::assertSame(PermissionRunTriggerEnum::Nightly, $this->runs()->findOnePending()?->getTrigger());
        self::assertSame('0 2 * * *', static::getContainer()->getParameter('team.permissions.schedule'));
    }

    public function testTheDeployAsksForARunAndNowRunsIt(): void
    {
        $queued = $this->command([]);
        self::assertStringContainsString('asked for; the worker answers it', $queued);
        self::assertSame(PermissionRunTriggerEnum::Deploy, $this->runs()->findOnePending()?->getTrigger());

        $ran = $this->command(['--now' => true]);
        self::assertMatchesRegularExpression('/15 people, \d+ permissions, 5 places, \d+ questions in\s+\d+\s+shapes/', $ran);
        self::assertNull($this->runs()->findOnePending());
        self::assertSame(PermissionRunStatusEnum::Finished, $this->runs()->findOneLatestFinished()?->getStatus());
    }

    public function testARunsOwnWritingAsksForNoFurtherRun(): void
    {
        $this->command(['--now' => true]);

        self::assertNull($this->runs()->findOnePending());
        self::assertInstanceOf(PermissionRun::class, $this->runs()->findOneLatestFinished());
    }

    private function line(string $position): void
    {
        $this->service(PermissionChangeLog::class)->record(PermissionChangeEnum::GrantsChanged, $this->positions[$position], null, 'stations.read', null, $this->people['Naomi Kileo']);
        $this->em->flush();
    }

    /** @param array<string, mixed> $input */
    private function command(array $input): string
    {
        $application = new Application(self::$kernel ?? self::bootKernel());
        $application->setAutoExit(false);
        $tester = new CommandTester($application->find('team:permissions:recompute'));
        $tester->execute($input);
        $tester->assertCommandIsSuccessful();

        return $tester->getDisplay();
    }

    private function runs(): PermissionRunRepository
    {
        return $this->service(PermissionRunRepository::class);
    }

    private function transport(): InMemoryTransport
    {
        $transport = static::getContainer()->get('test_public.permissions_transport');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }
}
