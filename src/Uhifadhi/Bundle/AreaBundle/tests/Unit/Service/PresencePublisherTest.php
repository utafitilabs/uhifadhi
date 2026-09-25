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

namespace Uhifadhi\Bundle\AreaBundle\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Mercure\Jwt\StaticTokenProvider;
use Symfony\Component\Mercure\MockHub;
use Symfony\Component\Mercure\Update;
use Uhifadhi\Bundle\AreaBundle\Service\PresencePublisher;
use Uhifadhi\Contracts\Area\DayState;
use Uhifadhi\Contracts\Area\LivePosition;
use Uhifadhi\Contracts\Area\LivePositionsInterface;
use Uhifadhi\Contracts\Area\LivePresence;
use Uhifadhi\Contracts\Shell\Scope;

/**
 * ONE PERSON'S MARK ON THE WIRE, the moment their handset spoke.
 *
 * Three things hold whatever the hub does. The frame is the SAME feature the
 * plate draws at page load — the person's key, the point, the age, whether it
 * is stale and what makes it so — and not one field more: no email, no
 * station, nothing a viewer of the plate cannot already see. A hub with no
 * address publishes nothing. A hub that fails is logged and swallowed, because
 * a lost frame is never worth a lost ping.
 *
 * The hub is the component's own {@see MockHub}: its publish callable is the
 * seam, so one instance records the wire and another throws from it.
 */
#[CoversClass(PresencePublisher::class)]
final class PresencePublisherTest extends TestCase
{
    private const string AREA = '0f6b0a60-0000-7000-8000-00000000000a';
    private const string PERSON = '0f6b0a60-0000-7000-8000-00000000000b';
    private const string NOW = '2026-09-19T07:00:00+03:00';

    public function testItPublishesTheOneMarkToTheAreasPrivateTopic(): void
    {
        $updates = [];
        $publisher = self::publisher(self::hub($updates), self::presence(self::aPosition(minutesAgo: 4)));

        $publisher->publish(self::AREA, self::PERSON);

        self::assertCount(1, $updates);
        $update = $updates[0];
        self::assertTrue($update->isPrivate(), 'the topic is private: subscriber-authorized on the page');
        self::assertSame(['area/'.self::AREA.'/presence'], $update->getTopics());
        self::assertSame('area/'.self::AREA.'/presence', PresencePublisher::topicFor(self::AREA));

        $frame = self::decoded($update->getData());
        self::assertSame('Feature', $frame['type']);
        self::assertSame(self::PERSON, $frame['id']);
        self::assertSame(['type' => 'Point', 'coordinates' => [-29.5, -3.2]], $frame['geometry']);

        $properties = $frame['properties'];
        self::assertIsArray($properties);
        self::assertSame(['name', 'initials', 'age', 'stale', 'at', 'staleAfterSeconds'], array_keys($properties));
        self::assertSame('Asha Mollel', $properties['name']);
        self::assertSame('AM', $properties['initials']);
        self::assertSame('4 min', $properties['age']);
        self::assertFalse($properties['stale']);
        self::assertSame('2026-09-19T06:56:00+03:00', $properties['at']);
        // Fifteen-minute pings: two missed is thirty minutes.
        self::assertSame(1800, $properties['staleAfterSeconds']);
    }

    /** The wire carries what the mark shows and nothing a viewer of the plate cannot already see. */
    public function testTheWireCarriesNothingBeyondTheMark(): void
    {
        $updates = [];
        self::publisher(self::hub($updates), self::presence(self::aPosition(minutesAgo: 4)))->publish(self::AREA, self::PERSON);

        $data = $updates[0]->getData();
        self::assertStringNotContainsString('example.test', $data, 'no email');
        self::assertStringNotContainsString('Eastgate', $data, 'no station');
        self::assertStringNotContainsString('w-1', $data, 'no claim reference');
        self::assertStringNotContainsString('battery', $data);
    }

    /**
     * SOMEBODY WHO IS NO LONGER ON THE GROUND — checked out, or their watch
     * ended — is a frame with no point: the plate takes the mark off rather
     * than leaving a ranger standing at a post they went home from.
     */
    public function testAPersonWithNoLivePositionIsPublishedAsGone(): void
    {
        $updates = [];
        self::publisher(self::hub($updates), self::presence())->publish(self::AREA, self::PERSON);

        self::assertCount(1, $updates);
        $frame = self::decoded($updates[0]->getData());
        self::assertSame(self::PERSON, $frame['id']);
        self::assertNull($frame['geometry']);
        self::assertSame(['gone' => true], $frame['properties']);
    }

    /**
     * A HUB WITH NO ADDRESS IS A DEPLOYMENT THAT CONFIGURED NONE. The hub
     * service is always there; what an empty public URL means is "no hub
     * here", and nothing is attempted.
     */
    public function testAHubWithNoAddressPublishesNothing(): void
    {
        $updates = [];
        self::publisher(self::hub($updates, url: ''), self::presence(self::aPosition(minutesAgo: 4)))->publish(self::AREA, self::PERSON);

        self::assertSame([], $updates);
    }

    public function testAFailingHubIsLoggedAndSwallowed(): void
    {
        $hub = new MockHub(
            'https://hub.example.test/.well-known/mercure',
            new StaticTokenProvider('test.publisher.token'),
            static fn (Update $update): string => throw new \RuntimeException('hub is down'),
        );
        $logger = new class extends AbstractLogger {
            /** @var list<array{level: mixed, message: string|\Stringable}> */
            public array $records = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = ['level' => $level, 'message' => $message];
            }
        };

        new PresencePublisher($hub, self::presence(self::aPosition(minutesAgo: 4)), new MockClock(self::NOW), $logger)
            ->publish(self::AREA, self::PERSON);

        self::assertCount(1, $logger->records);
        self::assertSame('error', $logger->records[0]['level']);
    }

    private static function publisher(MockHub $hub, LivePositionsInterface $positions): PresencePublisher
    {
        return new PresencePublisher($hub, $positions, new MockClock(self::NOW), new class extends AbstractLogger {
            public function log($level, string|\Stringable $message, array $context = []): void
            {
            }
        });
    }

    /**
     * @param list<Update> $updates collects everything the publisher puts on the wire
     */
    private static function hub(array &$updates, string $url = 'https://hub.example.test/.well-known/mercure'): MockHub
    {
        return new MockHub(
            $url,
            new StaticTokenProvider('test.publisher.token'),
            static function (Update $update) use (&$updates): string {
                $updates[] = $update;

                return 'id';
            },
        );
    }

    private static function presence(LivePosition ...$positions): LivePositionsInterface
    {
        $presence = new LivePresence(array_values($positions), 15, new \DateTimeImmutable(self::NOW));

        return new class($presence) implements LivePositionsInterface {
            public function __construct(private readonly LivePresence $presence)
            {
            }

            public function liveIn(string $areaUuid, \DateTimeImmutable $asOf): LivePresence
            {
                return $this->presence;
            }

            public function forScope(Scope $scope, \DateTimeImmutable $asOf): LivePresence
            {
                return $this->presence;
            }
        };
    }

    private static function aPosition(int $minutesAgo): LivePosition
    {
        return new LivePosition(
            personUuid: self::PERSON,
            personName: 'Asha Mollel',
            clientRef: 'w-1',
            state: DayState::AtPostVerified,
            latitude: -3.2,
            longitude: -29.5,
            recordedAt: new \DateTimeImmutable(self::NOW)->modify('-'.$minutesAgo.' minutes'),
            stationUuid: '0f6b0a60-0000-7000-8000-00000000000c',
            stationName: 'Eastgate Post',
            batteryPct: 61,
        );
    }

    /** @return array<string, mixed> */
    private static function decoded(string $data): array
    {
        $frame = json_decode($data, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($frame);

        /** @var array<string, mixed> $frame */
        return $frame;
    }
}
