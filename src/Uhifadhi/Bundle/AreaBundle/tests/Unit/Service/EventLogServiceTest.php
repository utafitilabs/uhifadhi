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
use Uhifadhi\Bundle\AreaBundle\Entity\StationEvent;
use Uhifadhi\Bundle\AreaBundle\Entity\ZoneEvent;
use Uhifadhi\Bundle\AreaBundle\Enum\StationEventKind;
use Uhifadhi\Bundle\AreaBundle\Enum\ZoneEventKind;
use Uhifadhi\Bundle\AreaBundle\Model\FilterOption;
use Uhifadhi\Bundle\AreaBundle\Service\EventLogService;

/**
 * WHAT HAPPENED HERE, NARROWED: the kind chip and the search over a
 * record's log, the chip's counts of the whole log.
 */
#[CoversClass(EventLogService::class)]
final class EventLogServiceTest extends TestCase
{
    public function testEveryKindIsAnOptionCountedOverTheWholeLog(): void
    {
        $log = new EventLogService()->narrow($this->stationLog(), StationEventKind::cases(), 'posted', '');

        self::assertSame(array_map(static fn (StationEventKind $k): string => $k->value, StationEventKind::cases()), array_map(static fn (FilterOption $o): string => $o->value, $log['kinds']));
        $counts = array_combine(array_map(static fn (FilterOption $o): string => $o->value, $log['kinds']), array_map(static fn (FilterOption $o): int => $o->count, $log['kinds']));
        self::assertSame(2, $counts['posted']);
        self::assertSame(1, $counts['point_moved']);
        self::assertSame(0, $counts['deactivated'], 'A kind with no line is still an option, at nought.');
        $labels = array_combine(array_map(static fn (FilterOption $o): string => $o->value, $log['kinds']), array_map(static fn (FilterOption $o): string => $o->label, $log['kinds']));
        self::assertSame('Posted', $labels['posted']);
    }

    public function testTheKindNarrowsTheLines(): void
    {
        $log = new EventLogService()->narrow($this->stationLog(), StationEventKind::cases(), 'posted', '');

        self::assertSame('posted', $log['kind']);
        self::assertSame(['A. Tumbo posted here', 'B. Kaaya posted here'], array_map(static fn (StationEvent $e): ?string => $e->getHeadline(), $log['rows']));
    }

    public function testTheSearchReadsTheHeadlineTheDetailAndTheActor(): void
    {
        $service = new EventLogService();

        self::assertCount(1, $service->narrow($this->stationLog(), StationEventKind::cases(), null, 'kaaya')['rows']);
        self::assertCount(1, $service->narrow($this->stationLog(), StationEventKind::cases(), null, 'WEST')['rows'], 'The detail is read, whatever the case.');
        self::assertCount(2, $service->narrow($this->stationLog(), StationEventKind::cases(), null, 'c. lyimo')['rows'], 'The actor is read.');
        self::assertCount(1, $service->narrow($this->stationLog(), StationEventKind::cases(), 'posted', 'tumbo')['rows'], 'The search narrows what the chip left.');
    }

    public function testAKindNotOfferedIsNoFilter(): void
    {
        $log = new EventLogService()->narrow($this->stationLog(), StationEventKind::cases(), 'nonsense', '  ');

        self::assertNull($log['kind']);
        self::assertSame('', $log['search']);
        self::assertCount(4, $log['rows']);
    }

    public function testAZonesLogIsNarrowedTheSameWay(): void
    {
        $imported = new ZoneEvent()->setKind(ZoneEventKind::Imported)->setHeadline('Zones imported')->setOccurredAt(new \DateTimeImmutable('2026-01-02'));
        $renamed = new ZoneEvent()->setKind(ZoneEventKind::Renamed)->setHeadline('Renamed to Ridge')->setOccurredAt(new \DateTimeImmutable('2026-01-03'));

        $log = new EventLogService()->narrow([$renamed, $imported], ZoneEventKind::cases(), 'renamed', '');

        self::assertSame([$renamed], $log['rows']);
        self::assertSame('Boundary replaced', $log['kinds'][2]->label);
    }

    /** @return list<StationEvent> */
    private function stationLog(): array
    {
        $line = static fn (StationEventKind $kind, string $headline, ?string $detail, string $actor): StationEvent => new StationEvent()
            ->setKind($kind)->setHeadline($headline)->setDetail($detail)->setActor($actor)->setOccurredAt(new \DateTimeImmutable('2026-03-01'));

        return [
            $line(StationEventKind::Posted, 'A. Tumbo posted here', 'from this page', 'C. Lyimo'),
            $line(StationEventKind::PointMoved, 'Point moved 120 m', 'heading west', 'D. Massawe'),
            $line(StationEventKind::Posted, 'B. Kaaya posted here', 'from their own page', 'C. Lyimo'),
            $line(StationEventKind::Renamed, 'Name corrected', null, 'D. Massawe'),
        ];
    }
}
