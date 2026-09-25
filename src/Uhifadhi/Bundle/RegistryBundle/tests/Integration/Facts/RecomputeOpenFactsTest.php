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
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\MessageBusInterface;
use Uhifadhi\Bundle\RegistryBundle\Message\RecomputeOpenFacts;
use Uhifadhi\Bundle\RegistryBundle\MessageHandler\RecomputeOpenFactsHandler;
use Uhifadhi\Bundle\RegistryBundle\Service\FactRebuildService;
use Uhifadhi\Bundle\RegistryBundle\Tests\Integration\Fixtures\SurveyFactProvider;
use Uhifadhi\Bundle\RegistryBundle\Tests\Integration\Fixtures\TallyFactProvider;
use Uhifadhi\Contracts\Facts\FactSubject;
use Uhifadhi\Contracts\Facts\FactValue;

/**
 * WHAT THE SCHEDULE RECOMPUTES: the periods open now, for every provider —
 * and a period that has just ended exactly once more, so its final figure
 * is written. A closed period is otherwise never recomputed by the schedule.
 */
#[CoversClass(FactRebuildService::class)]
#[CoversClass(RecomputeOpenFactsHandler::class)]
#[CoversClass(RecomputeOpenFacts::class)]
final class RecomputeOpenFactsTest extends FactsTestCase
{
    public function testTheOpenMonthQuarterAndYearAreRecomputed(): void
    {
        $this->recompute();

        self::assertSame([
            '2026-09: surveys.metres, surveys.share',
            '2026-Q3: surveys.share',
            '2026: surveys.share',
        ], self::asked(SurveyFactProvider::$asked));
        self::assertSame(['2026-09: tallies.count'], self::asked(TallyFactProvider::$asked));

        $fact = $this->reader()->latest(FactSubject::AREA, SurveyFactProvider::NORTH, 'surveys.metres', '2026-09');
        self::assertNotNull($fact);
        self::assertSame(900.0, $fact->value);
        self::assertSame('2026-09-25 14:00', $fact->computedAt->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i'), 'stamped with the clock’s now');
    }

    /** A closed period nobody computed is the operator's to rebuild, not the schedule's. */
    public function testAClosedPeriodNeverComputedIsLeftAlone(): void
    {
        $this->recompute();

        self::assertNull($this->stored(SurveyFactProvider::NORTH, 'surveys.metres', '2026-08'));
    }

    /**
     * THE CLOSING PASS. August was last computed at 20:00 on its last day;
     * the first run after it ended computes it once more, so the evening's
     * records are in its final figure — and never again after that.
     */
    public function testAPeriodThatHasJustEndedIsComputedOnceMore(): void
    {
        $this->clock()->modify('2026-09-01 02:00:00');
        $this->ledger()->upsert(
            [new FactValue(FactSubject::AREA, SurveyFactProvider::NORTH, 'surveys.metres', 1.0)],
            '2026-08',
            new \DateTimeImmutable('2026-08-31 20:00:00'),
        );

        $this->recompute();

        self::assertContains('2026-08: surveys.metres, surveys.share', self::asked(SurveyFactProvider::$asked));
        self::assertSame(800.0, $this->stored(SurveyFactProvider::NORTH, 'surveys.metres', '2026-08'));

        $fact = $this->reader()->latest(FactSubject::AREA, SurveyFactProvider::NORTH, 'surveys.metres', '2026-08');
        self::assertNotNull($fact);
        self::assertTrue($fact->isFinal());

        SurveyFactProvider::$asked = [];
        $this->clock()->modify('2026-09-01 06:00:00');
        $this->recompute();

        self::assertNotContains('2026-08: surveys.metres, surveys.share', self::asked(SurveyFactProvider::$asked), 'a period is closed once, never again');
    }

    /** The quarter that has just ended is closed the same way, for the figures stored at that length. */
    public function testAQuarterThatHasJustEndedIsComputedOnceMore(): void
    {
        $this->clock()->modify('2026-10-01 02:00:00');
        $this->ledger()->upsert(
            [new FactValue(FactSubject::AREA, SurveyFactProvider::NORTH, 'surveys.share', 0.1)],
            '2026-Q3',
            new \DateTimeImmutable('2026-09-30 20:00:00'),
        );

        $this->recompute();

        self::assertContains('2026-Q3: surveys.share', self::asked(SurveyFactProvider::$asked));
    }

    /** Dispatched on the bus, the message is handled by the worker's handler. */
    public function testTheMessageRunsTheRecompute(): void
    {
        $bus = self::getContainer()->get('messenger.default_bus');
        self::assertInstanceOf(MessageBusInterface::class, $bus);

        $bus->dispatch(new RecomputeOpenFacts());

        self::assertNotNull($this->stored(SurveyFactProvider::NORTH, 'surveys.metres', '2026-09'));
    }

    private function recompute(): void
    {
        $service = self::getContainer()->get('test.registry.facts.rebuild');
        self::assertInstanceOf(FactRebuildService::class, $service);

        $service->recomputeOpen();
    }

    private function clock(): MockClock
    {
        $clock = self::getContainer()->get('clock');
        self::assertInstanceOf(MockClock::class, $clock);

        return $clock;
    }
}
