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

namespace Uhifadhi\Contracts\Tests\Area;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Uhifadhi\Contracts\Area\DayState;
use Uhifadhi\Contracts\Area\LivePosition;
use Uhifadhi\Contracts\Area\LivePresence;

/**
 * WHEN TO STOP BELIEVING A POSITION — the one judgment the live read
 * makes, and the reason the interval travels with the answer.
 */
#[CoversClass(LivePresence::class)]
#[CoversClass(LivePosition::class)]
final class LivePresenceTest extends TestCase
{
    private const string NOW = '2026-09-19 14:00:00';

    /**
     * A PHONE TOLD TO PING EVERY THIRTY MINUTES IS SILENT FOR THIRTY
     * MINUTES BY DESIGN. Calling one missed deadline stale would paint
     * every ranger amber for half of every hour.
     */
    public function testAFixInsideOneIntervalIsFresh(): void
    {
        self::assertFalse($this->presence(30, '2026-09-19 13:45:00')->isStale($this->at('2026-09-19 13:45:00')));
    }

    /** And one that has only just missed its ping is still not stale. */
    public function testAFixThatHasMissedOneIntervalIsStillBelieved(): void
    {
        self::assertFalse($this->presence(30, '2026-09-19 13:15:00')->isStale($this->at('2026-09-19 13:15:00')));
    }

    /** Two intervals, and the phone has actually missed a ping. */
    public function testAFixPastTwoIntervalsIsStale(): void
    {
        self::assertTrue($this->presence(30, '2026-09-19 12:55:00')->isStale($this->at('2026-09-19 12:55:00')));
    }

    /**
     * THE SAME SILENCE READS DIFFERENTLY IN TWO AREAS, which is the
     * whole reason a consumer is not allowed to hardcode a threshold:
     * twenty minutes is four missed pings at five and none at thirty.
     */
    public function testTheSameSilenceIsStaleInOneAreaAndFreshInAnother(): void
    {
        $fix = '2026-09-19 13:40:00';

        self::assertTrue($this->presence(5, $fix)->isStale($this->at($fix)));
        self::assertFalse($this->presence(30, $fix)->isStale($this->at($fix)));
    }

    /** A surface that wants to say "3 of 7 have gone quiet" is given the count. */
    public function testTheAnswerCountsTheOnesNobodyShouldBelieve(): void
    {
        $presence = new LivePresence(
            [$this->at('2026-09-19 13:55:00'), $this->at('2026-09-19 12:00:00'), $this->at('2026-09-19 11:00:00')],
            30,
            new \DateTimeImmutable(self::NOW),
        );

        self::assertSame(2, $presence->staleCount());
        self::assertFalse($presence->isEmpty());
    }

    /** An age is never negative, however far ahead a handset's clock is. */
    public function testAFixFromTheFutureIsNoAgeAtAll(): void
    {
        self::assertSame(0, $this->at('2026-09-19 15:00:00')->ageSeconds(new \DateTimeImmutable(self::NOW)));
    }

    /** An area where nobody is on an open watch says so, and is not an error. */
    public function testAnAreaWithNobodyOnAnOpenWatchIsEmpty(): void
    {
        self::assertTrue(new LivePresence([], 30, new \DateTimeImmutable(self::NOW))->isEmpty());
    }

    private function presence(int $intervalMinutes, string $recordedAt): LivePresence
    {
        return new LivePresence([$this->at($recordedAt)], $intervalMinutes, new \DateTimeImmutable(self::NOW));
    }

    private function at(string $recordedAt): LivePosition
    {
        return new LivePosition(
            personUuid: 'p1',
            personName: 'A Ranger',
            clientRef: 'w1',
            state: DayState::AtPostVerified,
            latitude: -3.2,
            longitude: -29.5,
            recordedAt: new \DateTimeImmutable($recordedAt),
        );
    }
}
