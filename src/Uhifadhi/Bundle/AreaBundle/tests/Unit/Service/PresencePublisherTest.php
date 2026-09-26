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
use Uhifadhi\Bundle\AreaBundle\Service\LiveVisibility;
use Uhifadhi\Bundle\AreaBundle\Service\PersonLivePositionsInterface;
use Uhifadhi\Bundle\AreaBundle\Service\PresencePublisher;
use Uhifadhi\Contracts\Area\DayState;
use Uhifadhi\Contracts\Area\LivePosition;
use Uhifadhi\Contracts\Area\LivePresence;
use Uhifadhi\Contracts\People\RankLadderInterface;

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
    public const string NOW = '2026-09-19T07:00:00+03:00';

    public function testItPublishesTheOneMarkToTheAreasPrivateTopic(): void
    {
        $updates = [];
        $publisher = self::publisher(self::hub($updates), self::presence(self::aPosition(minutesAgo: 4)));

        $publisher->publish(self::AREA, self::PERSON);

        self::assertCount(1, $updates);
        $update = $updates[0];
        self::assertTrue($update->isPrivate(), 'the topic is private: subscriber-authorized on the page');
        self::assertSame(['area/'.self::AREA.'/presence/all'], $update->getTopics(), 'without the rank rule wired, the control room\'s topic alone');
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
        self::assertSame('2026-09-19T03:56:00+00:00', $properties['at']);
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

    /**
     * THE HUB ENFORCES THE RANK RULE: a position goes out on the control
     * room's topic and on the topic of every place senior to its owner — and
     * on no other, so a peer or a junior subscribed to their own place never
     * receives it.
     */
    public function testARankedPersonsMarkGoesOutToTheControlRoomAndEveryPlaceAboveThem(): void
    {
        $updates = [];
        $publisher = self::publisher(self::hub($updates), self::presence(self::aPosition(minutesAgo: 4)), self::ranked(4));

        $publisher->publish(self::AREA, self::PERSON);

        $base = 'area/'.self::AREA.'/presence';
        self::assertSame([$base.'/all', $base.'/for/1', $base.'/for/2', $base.'/for/3'], $updates[0]->getTopics());
    }

    public function testTheMostSeniorPersonsMarkGoesOutToTheControlRoomAlone(): void
    {
        $updates = [];
        self::publisher(self::hub($updates), self::presence(self::aPosition(minutesAgo: 4)), self::ranked(1))->publish(self::AREA, self::PERSON);

        self::assertSame(['area/'.self::AREA.'/presence/all'], $updates[0]->getTopics());
    }

    public function testARanklessPersonsMarkGoesOutToTheControlRoomAlone(): void
    {
        $updates = [];
        self::publisher(self::hub($updates), self::presence(self::aPosition(minutesAgo: 4)), self::ranked(null))->publish(self::AREA, self::PERSON);

        self::assertSame(['area/'.self::AREA.'/presence/all'], $updates[0]->getTopics());
    }

    /** "Gone" takes the same topics as the mark, so everybody who saw it sees it leave, and nobody else learns of it. */
    public function testAGoneFrameTakesTheSameTopicsAsTheMark(): void
    {
        $updates = [];
        self::publisher(self::hub($updates), self::presence(), self::ranked(3))->publish(self::AREA, self::PERSON);

        $base = 'area/'.self::AREA.'/presence';
        self::assertSame([$base.'/all', $base.'/for/1', $base.'/for/2'], $updates[0]->getTopics());
        self::assertSame(['gone' => true], self::decoded($updates[0]->getData())['properties'] ?? null);
    }

    private static function ranked(?int $place): LiveVisibility
    {
        $ladder = new class($place) implements RankLadderInterface {
            public function __construct(private readonly ?int $place)
            {
            }

            public function placesOf(array $personUuids): array
            {
                return null === $this->place ? [] : array_fill_keys($personUuids, $this->place);
            }

            public function length(): int
            {
                return 12;
            }
        };

        return new LiveVisibility(null, null, $ladder);
    }

    private static function publisher(MockHub $hub, PersonLivePositionsInterface $positions, ?LiveVisibility $visibility = null): PresencePublisher
    {
        return new PresencePublisher($hub, $positions, new MockClock(self::NOW), new class extends AbstractLogger {
            public function log($level, string|\Stringable $message, array $context = []): void
            {
            }
        }, $visibility);
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

    /**
     * The one person's reading, as the area answers it: the positions it was
     * built with when the person is asked for, nothing for anybody else.
     */
    private static function presence(LivePosition ...$positions): PersonLivePositionsInterface
    {
        return new class(array_values($positions)) implements PersonLivePositionsInterface {
            /** @param list<LivePosition> $positions */
            public function __construct(private readonly array $positions)
            {
            }

            public function liveOf(string $areaUuid, string $personUuid, \DateTimeImmutable $asOf): LivePresence
            {
                return new LivePresence(
                    array_values(array_filter($this->positions, static fn (LivePosition $p): bool => $p->personUuid === $personUuid)),
                    15,
                    new \DateTimeImmutable(PresencePublisherTest::NOW),
                );
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
