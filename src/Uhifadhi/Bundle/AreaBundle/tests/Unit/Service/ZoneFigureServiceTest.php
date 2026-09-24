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
use Uhifadhi\Bundle\AreaBundle\Model\ZoneFigureSet;
use Uhifadhi\Bundle\AreaBundle\Service\ZoneFigureService;
use Uhifadhi\Contracts\Kpi\DepartmentKpi;
use Uhifadhi\Contracts\Kpi\FigurePeriod;
use Uhifadhi\Contracts\Kpi\ZoneFigureProviderInterface;
use Uhifadhi\Contracts\Kpi\ZoneFigureRequest;
use Uhifadhi\Contracts\Kpi\ZoneFigures;
use Uhifadhi\Contracts\Kpi\ZoneRef;

/**
 * THE CORE ASKS THE MODULES THAT ARE SWITCHED ON, AND NOBODY ELSE.
 *
 * NO MODULE IS NAMED HERE. The collector reads the tag, asks the area's ledger
 * which of those modules the area actually runs, and hands the rest back in
 * one shape — so adding a module that reports by zone is a tagged class in that
 * module and not a line in the core.
 *
 * AN EMPTY ANSWER IS THE HONEST ONE. Until a module implements the seam, every
 * zone surface reads "no module publishes figures for this zone" rather than
 * a row of naughts, and this is where that state is decided.
 */
#[CoversClass(ZoneFigureService::class)]
#[CoversClass(ZoneFigureSet::class)]
final class ZoneFigureServiceTest extends TestCase
{
    private const string CRATER = '01a0-crater';
    private const string SOUTHERN_VALLEY = '01a0-southern-valley';

    public function testItAsksEveryProviderWhoseModuleTheAreaRuns(): void
    {
        $set = $this->collect(
            providers: [
                $this->provider('patrols', [self::CRATER => [$this->figure('patrols', 'Patrols', 17.0)]]),
                $this->provider('incidents', [self::CRATER => [$this->figure('incidents', 'Incidents', 6.0)]]),
            ],
            running: ['patrols', 'incidents'],
        );

        self::assertCount(2, $set->forZone(self::CRATER));
        self::assertSame(['patrols', 'incidents'], array_map(
            static fn (DepartmentKpi $k): string => $k->key,
            $set->forZone(self::CRATER),
        ));
    }

    /** A module parked in this area contributes nothing here, and is not asked. */
    public function testAParkedModuleIsNotAsked(): void
    {
        $patrols = $this->provider('patrols', [self::CRATER => [$this->figure('patrols', 'Patrols', 17.0)]]);
        $incidents = $this->provider('incidents', [self::CRATER => [$this->figure('incidents', 'Incidents', 6.0)]]);

        $set = $this->collect([$patrols, $incidents], running: ['patrols']);

        self::assertCount(1, $set->forZone(self::CRATER));
        self::assertFalse($incidents->asked);
    }

    public function testAZoneNoProviderSpokeAboutHasNoFigures(): void
    {
        $set = $this->collect(
            [$this->provider('patrols', [self::CRATER => [$this->figure('patrols', 'Patrols', 17.0)]])],
            running: ['patrols'],
        );

        self::assertSame([], $set->forZone(self::SOUTHERN_VALLEY));
    }

    /**
     * WITH NOTHING INSTALLED THE SET IS EMPTY, and it says so as one fact
     * rather than leaving every surface to discover it zone by zone.
     */
    public function testWithNoProvidersTheSetIsEmptyAndSaysSo(): void
    {
        $set = $this->collect([], running: []);

        self::assertTrue($set->isEmpty());
        self::assertSame([], $set->forZone(self::CRATER));
    }

    /** One provider with figures is enough for the set not to be empty. */
    public function testASetWithAnyFigureIsNotEmpty(): void
    {
        $set = $this->collect(
            [$this->provider('patrols', [self::CRATER => [$this->figure('patrols', 'Patrols', 17.0)]])],
            running: ['patrols'],
        );

        self::assertFalse($set->isEmpty());
    }

    /**
     * THE COVERED FIGURE IS REACHABLE BY ITS NAMED KEY, because three surfaces
     * read that one card and none of them should be scanning a list for a
     * label.
     */
    public function testTheCoveredFigureIsReachableByItsKey(): void
    {
        $set = $this->collect(
            [$this->provider('patrols', [self::CRATER => [
                $this->figure('patrols', 'Patrols', 17.0),
                $this->figure(ZoneFigureProviderInterface::COVERED, 'Covered', 82.0, DepartmentKpi::SHARE),
            ]])],
            running: ['patrols'],
        );

        self::assertSame(82.0, $set->covered(self::CRATER)?->value);
        self::assertNull($set->covered(self::SOUTHERN_VALLEY));
    }

    /** Every provider is asked about the whole set at once, never zone by zone. */
    public function testTheWholeSetIsAskedForInOneCall(): void
    {
        $patrols = $this->provider('patrols', []);

        $this->collect([$patrols], running: ['patrols']);

        self::assertSame(1, $patrols->calls);
        self::assertSame([self::CRATER, self::SOUTHERN_VALLEY], $patrols->lastRequest?->zoneUuids());
    }

    /** Nothing is asked when there are no zones: an unzoned area has no question. */
    public function testAnAreaWithNoZonesAsksNobody(): void
    {
        $patrols = $this->provider('patrols', []);

        $set = new ZoneFigureService([$patrols])->collect(
            [],
            FigurePeriod::month(new \DateTimeImmutable('2026-08-14')),
            static fn (string $slug): bool => true,
        );

        self::assertSame(0, $patrols->calls);
        self::assertTrue($set->isEmpty());
    }

    // ---------------------------------------------------------------- fixtures

    /**
     * @param list<FakeZoneFigureProvider> $providers
     * @param list<string>                 $running
     */
    private function collect(array $providers, array $running): ZoneFigureSet
    {
        return new ZoneFigureService($providers)->collect(
            [new ZoneRef(self::CRATER, '01a0-area', 'Crater'), new ZoneRef(self::SOUTHERN_VALLEY, '01a0-area', 'Southern Valley')],
            FigurePeriod::month(new \DateTimeImmutable('2026-08-14')),
            static fn (string $slug): bool => \in_array($slug, $running, true),
        );
    }

    /** @param array<string, list<DepartmentKpi>> $byZone */
    private function provider(string $slug, array $byZone): FakeZoneFigureProvider
    {
        return new FakeZoneFigureProvider($slug, $byZone);
    }

    private function figure(string $key, string $label, float $value, string $unit = ''): DepartmentKpi
    {
        return new DepartmentKpi($key, $label, 'patrols', 'Patrols', $value, $unit);
    }
}

/**
 * A module's provider, played by a fixture: the seam is a tag and an interface,
 * so the thing under test is the collector's rule and not any real module's
 * query.
 */
final class FakeZoneFigureProvider implements ZoneFigureProviderInterface
{
    public int $calls = 0;
    public bool $asked = false;
    public ?ZoneFigureRequest $lastRequest = null;

    /** @param array<string, list<DepartmentKpi>> $byZone */
    public function __construct(
        private readonly string $slug,
        private readonly array $byZone,
    ) {
    }

    public function moduleSlug(): string
    {
        return $this->slug;
    }

    public function figuresFor(ZoneFigureRequest $request): ZoneFigures
    {
        ++$this->calls;
        $this->asked = true;
        $this->lastRequest = $request;

        return new ZoneFigures($this->byZone, $request->period);
    }
}
