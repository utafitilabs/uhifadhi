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

namespace Uhifadhi\Bundle\AreaBundle\Tests\Integration\Web;

use PHPUnit\Framework\Attributes\CoversNothing;
use Uhifadhi\Bundle\AreaBundle\Model\ZoneListQuery;
use Uhifadhi\Bundle\AreaBundle\Service\ZoneListService;
use Uhifadhi\Bundle\AtlasBundle\Calendar\Periods;

/**
 * WHICH MONTH A PAGE IS ABOUT FOLLOWS THE CLOCK, AND THE CLOCK ONLY.
 *
 * THE DEFECT THIS PINS. "This month's figures" was decided separately in
 * eleven places across two bundles, each writing
 * `FigurePeriod::month(new \DateTimeImmutable())`. Every one asked the WALL
 * CLOCK, so every one turned over on the 1st with nothing able to pin it —
 * and two surfaces rendered either side of midnight on the last of the month
 * could caption two different months in one reading.
 *
 * SO THE BOUNDARY IS THE TEST, and it is asked of the whole wiring rather
 * than of the class: the container is built at an instant, the service is
 * resolved out of it, and what it answers has to move with the instant. A
 * unit test of the period source proves the arithmetic; this proves that the
 * clock actually reaches the page.
 */
#[CoversNothing]
final class PeriodFollowsTheClockTest extends WebTestCase
{
    /** The last minute of January is January, all the way through the wiring. */
    public function testAPageReadOnTheLastMinuteOfTheMonthIsAboutThatMonth(): void
    {
        $this->boot(clock: '2026-01-31 23:59:00');

        self::assertSame('January 2026', $this->zonePeriodLabel());
    }

    /** And a minute later the same wiring answers the next month. */
    public function testAMinuteLaterItIsAboutTheNext(): void
    {
        $this->boot(clock: '2026-02-01 00:01:00');

        self::assertSame('February 2026', $this->zonePeriodLabel());
    }

    /**
     * AND THE SURFACES AGREE WITH EACH OTHER, which is the half a per-call
     * `new \DateTimeImmutable()` could never guarantee: two reads a
     * microsecond apart across midnight used to be able to caption two
     * different months on one page.
     */
    public function testEverySurfaceReadsTheSameMonth(): void
    {
        $this->boot(clock: '2026-01-31 23:59:59');
        $area = $this->anArea();

        $zones = $this->zoneList()->register($area, new ZoneListQuery());

        self::assertSame('January', $zones->period, 'The zones register captions January.');
        self::assertSame(
            'January 2026',
            $this->periods()->month()->label,
            'And the one source every other surface asks agrees with it.',
        );
    }

    private function zonePeriodLabel(): string
    {
        return $this->periods()->month()->label;
    }

    private function periods(): Periods
    {
        $periods = static::getContainer()->get('test_public.atlas.periods');
        \assert($periods instanceof Periods);

        return $periods;
    }

    private function zoneList(): ZoneListService
    {
        $zones = static::getContainer()->get('test_public.area.zone_list');
        \assert($zones instanceof ZoneListService);

        return $zones;
    }
}
