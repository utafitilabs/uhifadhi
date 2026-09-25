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

namespace Uhifadhi\Bundle\RegistryBundle\Tests\Integration\Facts;

use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Uhifadhi\Bundle\RegistryBundle\Message\RecomputeOpenFacts;
use Uhifadhi\Bundle\RegistryBundle\Scheduler\RecomputeOpenFactsTask;
use Uhifadhi\Bundle\RegistryBundle\Tests\Integration\Fixtures\PlainMessage;
use Uhifadhi\Bundle\RegistryBundle\Tests\Integration\Fixtures\PlainMessageHandler;
use Uhifadhi\Bundle\RegistryBundle\Tests\Integration\Fixtures\QueuedHostKernel;
use Uhifadhi\Bundle\RegistryBundle\Tests\Integration\Fixtures\SurveyFactProvider;

/**
 * IN AN INSTALLATION AS THE STARTER MAKES IT — the starter's messenger
 * configuration, the core's marker routed to `async`, and a `default`
 * schedule of the installation's own — the recompute joins that schedule and
 * what it sends is queued.
 */
#[CoversClass(RecomputeOpenFactsTask::class)]
final class QueuedFactsTest extends FactsTestCase
{
    protected static function getKernelClass(): string
    {
        return QueuedHostKernel::class;
    }

    protected function tearDown(): void
    {
        PlainMessageHandler::$handled = 0;

        parent::tearDown();
    }

    /** The core's task joins the installation's own `default` schedule rather than replacing it. */
    public function testItJoinsTheInstallationsOwnSchedule(): void
    {
        [$status, $output] = $this->console(['command' => 'debug:scheduler', 'schedule' => ['default'], '--date' => '2026-09-25 13:30:00']);

        self::assertSame(0, $status, $output);
        self::assertStringContainsString('30 4 * * 1', $output, 'the installation’s own task is kept');
        self::assertStringContainsString('0 6-20 * * *', $output);
    }

    /** The tick does not compute: it queues the recompute for the worker. */
    public function testTheTaskQueuesTheRecompute(): void
    {
        $task = self::getContainer()->get('test.registry.facts.schedule_task');
        self::assertInstanceOf(RecomputeOpenFactsTask::class, $task);

        $task();

        $sent = $this->async()->getSent();
        self::assertCount(1, $sent);
        self::assertInstanceOf(RecomputeOpenFacts::class, $sent[0]->getMessage());
        self::assertSame([], SurveyFactProvider::$asked, 'nothing is computed in the process that ticked');
    }

    /** A message carrying the core's marker goes to `async`; one that does not is handled where it was sent. */
    public function testTheMarkerRoutesAMessageToTheQueue(): void
    {
        $bus = self::getContainer()->get('messenger.default_bus');
        self::assertInstanceOf(MessageBusInterface::class, $bus);

        $bus->dispatch(new RecomputeOpenFacts());
        $bus->dispatch(new PlainMessage());

        self::assertCount(1, $this->async()->getSent());
        self::assertSame(1, PlainMessageHandler::$handled);
    }

    private function async(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }
}
