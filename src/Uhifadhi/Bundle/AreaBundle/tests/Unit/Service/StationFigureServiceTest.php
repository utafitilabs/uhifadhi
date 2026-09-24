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
use Uhifadhi\Bundle\AreaBundle\Model\StationFigureSet;
use Uhifadhi\Bundle\AreaBundle\Service\StationFigureService;
use Uhifadhi\Contracts\Kpi\DepartmentKpi;
use Uhifadhi\Contracts\Kpi\FigurePeriod;
use Uhifadhi\Contracts\Kpi\StationFigureProviderInterface;
use Uhifadhi\Contracts\Kpi\StationFigureRequest;
use Uhifadhi\Contracts\Kpi\StationFigures;
use Uhifadhi\Contracts\Kpi\StationRef;

/**
 * THE CORE ASKS THE MODULES THAT ARE SWITCHED ON, AND NOBODY ELSE — the
 * station seam, which is the zone seam one rung down.
 *
 * NO MODULE IS NAMED HERE. The collector reads the tag, asks the area's ledger
 * which of those modules the area actually runs, and hands the rest back in
 * one shape — so adding a module that reports by zone is a tagged class in that
 * module and not a line in the core.
 *
 * AN EMPTY ANSWER IS THE HONEST ONE. Until a module implements the seam, the
 * dock reads "no module publishes figures for this station" rather than four
 * rows of naughts, and this is where that state is decided.
 */
#[CoversClass(StationFigureService::class)]
#[CoversClass(StationFigureSet::class)]
final class StationFigureServiceTest extends TestCase
{
    private const string EASTGATE = '01a0-eastgate';
    private const string FIG_TREE = '01a0-fig-tree';

    public function testItAsksEveryProviderWhoseModuleTheAreaRuns(): void
    {
        $set = $this->collect(
            providers: [
                $this->provider('patrols', [self::EASTGATE => [$this->figure('patrols', 'Patrols', 17.0)]]),
                $this->provider('incidents', [self::EASTGATE => [$this->figure('incidents', 'Incidents', 6.0)]]),
            ],
            running: ['patrols', 'incidents'],
        );

        self::assertCount(2, $set->forStation(self::EASTGATE));
        self::assertSame(['patrols', 'incidents'], array_map(
            static fn (DepartmentKpi $k): string => $k->key,
            $set->forStation(self::EASTGATE),
        ));
    }

    /** A module parked in this area contributes nothing here, and is not asked. */
    public function testAParkedModuleIsNotAsked(): void
    {
        $patrols = $this->provider('patrols', [self::EASTGATE => [$this->figure('patrols', 'Patrols', 17.0)]]);
        $incidents = $this->provider('incidents', [self::EASTGATE => [$this->figure('incidents', 'Incidents', 6.0)]]);

        $set = $this->collect([$patrols, $incidents], running: ['patrols']);

        self::assertCount(1, $set->forStation(self::EASTGATE));
        self::assertFalse($incidents->asked);
    }

    public function testAZoneNoProviderSpokeAboutHasNoFigures(): void
    {
        $set = $this->collect(
            [$this->provider('patrols', [self::EASTGATE => [$this->figure('patrols', 'Patrols', 17.0)]])],
            running: ['patrols'],
        );

        self::assertSame([], $set->forStation(self::FIG_TREE));
    }

    /**
     * WITH NOTHING INSTALLED THE SET IS EMPTY, and it says so as one fact
     * rather than leaving every surface to discover it zone by zone.
     */
    public function testWithNoProvidersTheSetIsEmptyAndSaysSo(): void
    {
        $set = $this->collect([], running: []);

        self::assertTrue($set->isEmpty());
        self::assertSame([], $set->forStation(self::EASTGATE));
    }

    /** One provider with figures is enough for the set not to be empty. */
    public function testASetWithAnyFigureIsNotEmpty(): void
    {
        $set = $this->collect(
            [$this->provider('patrols', [self::EASTGATE => [$this->figure('patrols', 'Patrols', 17.0)]])],
            running: ['patrols'],
        );

        self::assertFalse($set->isEmpty());
    }

    /**
     * THE DOCK IS ONE ROW PER MODULE, read by the named key. A module that
     * published something under another key is not a dock row, which is how
     * the dock stays four rows rather than however many figures arrived.
     */
    public function testTheDockIsTheHeadlineFigures(): void
    {
        $set = $this->collect(
            [$this->provider('patrols', [self::EASTGATE => [
                $this->figure('something-else', 'Distance', 412.0),
                $this->figure(StationFigureProviderInterface::HEADLINE, 'Patrols', 23.0),
            ]])],
            running: ['patrols'],
        );

        self::assertCount(1, $set->dockFor(self::EASTGATE));
        self::assertSame('Patrols', $set->dockFor(self::EASTGATE)[0]->label);
        self::assertSame([], $set->dockFor(self::FIG_TREE));
    }

    /** Every provider is asked about the whole set at once, never zone by zone. */
    public function testTheWholeSetIsAskedForInOneCall(): void
    {
        $patrols = $this->provider('patrols', []);

        $this->collect([$patrols], running: ['patrols']);

        self::assertSame(1, $patrols->calls);
        self::assertSame([self::EASTGATE, self::FIG_TREE], $patrols->lastRequest?->stationUuids());
    }

    /** Nothing is asked when there are no zones: an unzoned area has no question. */
    public function testAnAreaWithNoZonesAsksNobody(): void
    {
        $patrols = $this->provider('patrols', []);

        $set = new StationFigureService([$patrols])->collect(
            [],
            FigurePeriod::month(new \DateTimeImmutable('2026-08-14')),
            static fn (string $slug): bool => true,
        );

        self::assertSame(0, $patrols->calls);
        self::assertTrue($set->isEmpty());
    }

    // ---------------------------------------------------------------- fixtures

    /**
     * @param list<FakeStationFigureProvider> $providers
     * @param list<string>                    $running
     */
    private function collect(array $providers, array $running): StationFigureSet
    {
        return new StationFigureService($providers)->collect(
            [new StationRef(self::EASTGATE, '01a0-area', 'Eastgate Post'), new StationRef(self::FIG_TREE, '01a0-area', 'Fig Tree Ranger Post')],
            FigurePeriod::month(new \DateTimeImmutable('2026-08-14')),
            static fn (string $slug): bool => \in_array($slug, $running, true),
        );
    }

    /** @param array<string, list<DepartmentKpi>> $byStation */
    private function provider(string $slug, array $byStation): FakeStationFigureProvider
    {
        return new FakeStationFigureProvider($slug, $byStation);
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
final class FakeStationFigureProvider implements StationFigureProviderInterface
{
    public int $calls = 0;
    public bool $asked = false;
    public ?StationFigureRequest $lastRequest = null;

    /** @param array<string, list<DepartmentKpi>> $byStation */
    public function __construct(
        private readonly string $slug,
        private readonly array $byStation,
    ) {
    }

    public function moduleSlug(): string
    {
        return $this->slug;
    }

    public function figuresFor(StationFigureRequest $request): StationFigures
    {
        ++$this->calls;
        $this->asked = true;
        $this->lastRequest = $request;

        return new StationFigures($this->byStation, $request->period);
    }
}
