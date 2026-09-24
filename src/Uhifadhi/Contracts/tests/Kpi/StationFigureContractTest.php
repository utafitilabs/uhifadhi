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

namespace Uhifadhi\Contracts\Tests\Kpi;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Uhifadhi\Contracts\Kpi\DepartmentKpi;
use Uhifadhi\Contracts\Kpi\FigurePeriod;
use Uhifadhi\Contracts\Kpi\StationFigureProviderInterface;
use Uhifadhi\Contracts\Kpi\StationFigureRequest;
use Uhifadhi\Contracts\Kpi\StationFigures;
use Uhifadhi\Contracts\Kpi\StationRef;

/**
 * THE SHAPE A MODULE IMPLEMENTS TO PUT FIGURES ON A STATION.
 *
 * A ZONE HAS NO NUMBERS OF ITS OWN, exactly as a department has none: the area
 * module owns the ground and the names, and every count over that ground —
 * patrols, incidents, how much of it was walked — belongs to whichever module
 * recorded it. So this seam is the only way such a figure reaches a zone page,
 * and the core neither computes one nor knows which modules might.
 *
 * ONE CALL FOR EVERY STATION OF AN AREA. The stations tab draws a row per
 * post; asking once per station would be a round trip per row per module, and
 * a provider answering "incidents within 12 km" with a spatial query would
 * make that unaffordable. The request carries the whole set and the answer is
 * keyed by station.
 *
 * THE FIGURES ARE {@see DepartmentKpi}, DELIBERATELY. It is already the
 * product's word for "one figure a module computed over one period" — unknown
 * is null and never zero, a share moves in points and a count in percent — and
 * one KPI card renderer serving both scopes is the reason a zone page and a
 * performance page cannot drift apart.
 */
#[CoversClass(StationFigureProviderInterface::class)]
#[CoversClass(StationRef::class)]
#[CoversClass(StationFigures::class)]
#[CoversClass(StationFigureRequest::class)]
#[CoversClass(FigurePeriod::class)]
final class StationFigureContractTest extends TestCase
{
    public function testTheTagIsPublishedOnTheInterfaceSoNobodySpellsIt(): void
    {
        self::assertSame('uhifadhi.station_kpi', StationFigureProviderInterface::TAG);
    }

    /**
     * THE HEADLINE KEY IS NAMED HERE, not agreed by convention. The dock
     * reads one key per module, and a provider that spelled it differently
     * would leave its row blank with nothing to point at.
     */
    public function testTheWellKnownKeysAreNamedOnTheContract(): void
    {
        self::assertSame('headline', StationFigureProviderInterface::HEADLINE);
    }

    public function testAStationRefCarriesWhatAProviderNeedsToFindItsRecords(): void
    {
        $ref = new StationRef('01a0-station', '01a0-area', 'Eastgate Post');

        self::assertSame('01a0-station', $ref->stationUuid);
        self::assertSame('01a0-area', $ref->areaUuid);
        self::assertSame('Eastgate Post', $ref->name);
    }

    /** A ref with no name is a ref no caption can print. */
    public function testARefWithoutANameIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new StationRef('01a0-station', '01a0-area', '');
    }

    public function testARequestCarriesEveryStationOfTheAreaAndThePeriodAsked(): void
    {
        $request = new StationFigureRequest(
            [new StationRef('s1', 'a1', 'Eastgate'), new StationRef('s2', 'a1', 'Fig Tree')],
            FigurePeriod::month(new \DateTimeImmutable('2026-08-14 10:00:00')),
        );

        self::assertSame(['s1', 's2'], $request->stationUuids());
        self::assertSame('August 2026', $request->period->label);
    }

    /** The month containing the instant, half-open so the last day is whole. */
    public function testTheMonthPeriodIsTheWholeMonthContainingTheInstant(): void
    {
        $period = FigurePeriod::month(new \DateTimeImmutable('2026-08-14 10:00:00'));

        self::assertSame('2026-08-01 00:00:00', $period->from->format('Y-m-d H:i:s'));
        self::assertSame('2026-09-01 00:00:00', $period->until->format('Y-m-d H:i:s'));
        self::assertTrue($period->contains(new \DateTimeImmutable('2026-08-31 23:59:59')));
        self::assertFalse($period->contains(new \DateTimeImmutable('2026-09-01 00:00:00')));
    }

    /**
     * THE ANSWER STATES THE PERIOD IT ACTUALLY COVERS. A provider asked for
     * August may only be able to answer over a rolling ninety days, and a card
     * captioned "aug" over ninety days of data is a lie nobody can see.
     */
    public function testTheAnswerStatesThePeriodItCovers(): void
    {
        $period = FigurePeriod::month(new \DateTimeImmutable('2026-08-14'));
        $figures = new StationFigures(['s1' => [$this->aFigure()]], $period);

        self::assertSame($period, $figures->period);
    }

    public function testFiguresAreReadBackByStation(): void
    {
        $figures = new StationFigures(['s1' => [$this->aFigure()]], FigurePeriod::month(new \DateTimeImmutable()));

        self::assertCount(1, $figures->forStation('s1'));
        // A station the provider had nothing for is an empty list, never a null
        // the caller has to test for.
        self::assertSame([], $figures->forStation('s2'));
    }

    /** A provider with nothing to say answers with nothing, and that is not zero. */
    public function testAProviderMayAnswerWithNothing(): void
    {
        $figures = StationFigures::none(FigurePeriod::month(new \DateTimeImmutable()));

        self::assertSame([], $figures->forStation('s1'));
        self::assertTrue($figures->isEmpty());
    }

    private function aFigure(): DepartmentKpi
    {
        return new DepartmentKpi(
            key: StationFigureProviderInterface::HEADLINE,
            label: 'Patrols',
            moduleSlug: 'patrols',
            moduleName: 'Patrols',
            value: 23.0,
            caption: 'out of here · 412 km',
        );
    }
}
