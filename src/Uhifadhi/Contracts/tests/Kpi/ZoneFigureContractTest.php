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
use Uhifadhi\Contracts\Kpi\ZoneFigureProviderInterface;
use Uhifadhi\Contracts\Kpi\ZoneFigureRequest;
use Uhifadhi\Contracts\Kpi\ZoneFigures;
use Uhifadhi\Contracts\Kpi\ZoneRef;

/**
 * THE SHAPE A MODULE IMPLEMENTS TO PUT FIGURES ON A ZONE.
 *
 * A ZONE HAS NO NUMBERS OF ITS OWN, exactly as a department has none: the area
 * module owns the ground and the names, and every count over that ground —
 * patrols, incidents, how much of it was walked — belongs to whichever module
 * recorded it. So this seam is the only way such a figure reaches a zone page,
 * and the core neither computes one nor knows which modules might.
 *
 * ONE CALL FOR EVERY ZONE OF AN AREA. The all-zones view draws eleven rows and
 * the map legend draws eleven more; asking a provider once per zone would be
 * eleven round trips per module per page, and the first module to run a
 * per-zone spatial query would make that unaffordable. The request therefore
 * carries the whole set and the answer is keyed by zone.
 *
 * THE FIGURES ARE {@see DepartmentKpi}, DELIBERATELY. It is already the
 * product's word for "one figure a module computed over one period" — unknown
 * is null and never zero, a share moves in points and a count in percent — and
 * one KPI card renderer serving both scopes is the reason a zone page and a
 * performance page cannot drift apart.
 */
#[CoversClass(ZoneFigureProviderInterface::class)]
#[CoversClass(ZoneRef::class)]
#[CoversClass(ZoneFigures::class)]
#[CoversClass(ZoneFigureRequest::class)]
#[CoversClass(FigurePeriod::class)]
final class ZoneFigureContractTest extends TestCase
{
    public function testTheTagIsPublishedOnTheInterfaceSoNobodySpellsIt(): void
    {
        self::assertSame('uhifadhi.zone_kpi', ZoneFigureProviderInterface::TAG);
    }

    /**
     * THE COVERED KEY IS NAMED HERE, not agreed by convention. Three surfaces
     * read it — the zones tab's card, a zone record's band and the legend —
     * and a provider that spelled it differently would leave all three blank
     * with nothing to point at.
     */
    public function testTheWellKnownKeysAreNamedOnTheContract(): void
    {
        self::assertSame('covered', ZoneFigureProviderInterface::COVERED);
    }

    public function testAZoneRefCarriesWhatAProviderNeedsToFindItsRecords(): void
    {
        $ref = new ZoneRef('01a0-zone', '01a0-area', 'Crater');

        self::assertSame('01a0-zone', $ref->zoneUuid);
        self::assertSame('01a0-area', $ref->areaUuid);
        self::assertSame('Crater', $ref->name);
    }

    /** A ref with no name is a ref no caption can print. */
    public function testARefWithoutANameIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ZoneRef('01a0-zone', '01a0-area', '');
    }

    public function testARequestCarriesEveryZoneOfTheAreaAndThePeriodAsked(): void
    {
        $request = new ZoneFigureRequest(
            [new ZoneRef('z1', 'a1', 'Crater'), new ZoneRef('z2', 'a1', 'Southern Valley')],
            FigurePeriod::month(new \DateTimeImmutable('2026-08-14 10:00:00')),
        );

        self::assertSame(['z1', 'z2'], $request->zoneUuids());
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
     * A ROLLING WINDOW, ENDING NOW, AND IT SAYS SO IN ITS LABEL — what a card
     * opened inside another surface asks for, so that a calendar boundary
     * cannot make busy ground read as quiet on the second of the month.
     */
    public function testARollingWindowEndsAtTheInstantAndNamesItsLength(): void
    {
        $period = FigurePeriod::days(90, new \DateTimeImmutable('2026-09-19 10:00:00'));

        self::assertSame('2026-06-21 10:00:00', $period->from->format('Y-m-d H:i:s'));
        self::assertSame('2026-09-19 10:00:00', $period->until->format('Y-m-d H:i:s'));
        self::assertSame('90 days', $period->label);
        self::assertTrue($period->contains(new \DateTimeImmutable('2026-08-14')));
        self::assertFalse($period->contains(new \DateTimeImmutable('2026-06-20')));
    }

    /** A window of no days is not a window; it is a mistake worth refusing. */
    public function testAWindowOfNoDaysIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        FigurePeriod::days(0, new \DateTimeImmutable());
    }

    /**
     * THE ANSWER STATES THE PERIOD IT ACTUALLY COVERS. A provider asked for
     * August may only be able to answer over a rolling ninety days, and a card
     * captioned "aug" over ninety days of data is a lie nobody can see.
     */
    public function testTheAnswerStatesThePeriodItCovers(): void
    {
        $period = FigurePeriod::month(new \DateTimeImmutable('2026-08-14'));
        $figures = new ZoneFigures(['z1' => [$this->aFigure()]], $period);

        self::assertSame($period, $figures->period);
    }

    public function testFiguresAreReadBackByZone(): void
    {
        $figures = new ZoneFigures(['z1' => [$this->aFigure()]], FigurePeriod::month(new \DateTimeImmutable()));

        self::assertCount(1, $figures->forZone('z1'));
        // A zone the provider had nothing for is an empty list, never a null
        // the caller has to test for.
        self::assertSame([], $figures->forZone('z2'));
    }

    /** A provider with nothing to say answers with nothing, and that is not zero. */
    public function testAProviderMayAnswerWithNothing(): void
    {
        $figures = ZoneFigures::none(FigurePeriod::month(new \DateTimeImmutable()));

        self::assertSame([], $figures->forZone('z1'));
        self::assertTrue($figures->isEmpty());
    }

    private function aFigure(): DepartmentKpi
    {
        return new DepartmentKpi(
            key: ZoneFigureProviderInterface::COVERED,
            label: 'Covered',
            moduleSlug: 'patrols',
            moduleName: 'Patrols',
            value: 82.0,
            unit: DepartmentKpi::SHARE,
        );
    }
}
