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

namespace Uhifadhi\Bundle\TeamBundle\Tests\Unit\EventListener;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Uhifadhi\Bundle\RegistryBundle\Event\ModuleInstalledEvent;
use Uhifadhi\Bundle\TeamBundle\EventListener\ModuleHistoryListener;
use Uhifadhi\Bundle\TeamBundle\Message\BackfillModuleHistory;
use Uhifadhi\Bundle\TeamBundle\Tests\Integration\Fixtures\Area\HostArea;
use Uhifadhi\Contracts\Queue\AsyncMessageInterface;

/**
 * THE INSTALL CLICK QUEUES THE BACKFILL AND WAITS FOR NOTHING. Asking a new
 * module about six closed months, for every department, is work that grows
 * with the history; the click that installs the module hands it to the
 * worker and returns.
 */
#[CoversClass(ModuleHistoryListener::class)]
#[CoversClass(BackfillModuleHistory::class)]
final class ModuleHistoryListenerTest extends TestCase
{
    public function testInstallingAModuleQueuesItsBackfill(): void
    {
        $bus = new class implements MessageBusInterface {
            /** @var list<object> */
            public array $sent = [];

            public function dispatch(object $message, array $stamps = []): Envelope
            {
                $this->sent[] = $message;

                return new Envelope($message, $stamps);
            }
        };

        new ModuleHistoryListener($bus)->onModuleInstalled(new ModuleInstalledEvent(new HostArea()->setName('Northern Reserve'), 'surveys'));

        self::assertCount(1, $bus->sent);
        $message = $bus->sent[0];
        self::assertInstanceOf(BackfillModuleHistory::class, $message);
        self::assertInstanceOf(AsyncMessageInterface::class, $message, 'the worker runs it, not the click');
        self::assertSame('surveys', $message->slug);
    }
}
