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
use Uhifadhi\Bundle\TeamBundle\EventListener\PermissionRunListener;
use Uhifadhi\Bundle\TeamBundle\Message\RecomputePermissions;
use Uhifadhi\Contracts\Queue\AsyncMessageInterface;

/** Nothing asked, nothing woken; and the wake-up goes to the queue, not the request. */
#[CoversClass(PermissionRunListener::class)]
#[CoversClass(RecomputePermissions::class)]
final class PermissionRunListenerTest extends TestCase
{
    public function testWithNoLineWrittenNothingIsWoken(): void
    {
        $bus = self::bus();
        new PermissionRunListener($bus)->wake();

        self::assertSame([], $bus->sent);
    }

    public function testTheWakeUpCarriesTheQueueMarker(): void
    {
        self::assertInstanceOf(AsyncMessageInterface::class, new RecomputePermissions());
        self::assertNull(new RecomputePermissions()->runId, 'no id: the oldest request waiting');
    }

    /** @return MessageBusInterface&object{sent: list<object>} */
    private static function bus(): MessageBusInterface
    {
        return new class implements MessageBusInterface {
            /** @var list<object> */
            public array $sent = [];

            public function dispatch(object $message, array $stamps = []): Envelope
            {
                $this->sent[] = $message;

                return new Envelope($message, $stamps);
            }
        };
    }
}
