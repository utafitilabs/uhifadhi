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
use Uhifadhi\Bundle\AreaBundle\Model\ContributedStationSection;
use Uhifadhi\Bundle\AreaBundle\Service\StationSectionService;
use Uhifadhi\Contracts\Area\StationSection;
use Uhifadhi\Contracts\Area\StationSectionRequest;
use Uhifadhi\Contracts\Area\StationSections;
use Uhifadhi\Contracts\Area\StationSectionsInterface;
use Uhifadhi\Contracts\Area\StationSurface;
use Uhifadhi\Contracts\Kpi\StationRef;

/**
 * THE STATION IS THE AREA'S AND THE WATCH IS THE ROSTER'S — the seam that
 * lets the second sentence be true without the area knowing the roster
 * exists.
 *
 * NO MODULE IS NAMED IN THE COLLECTOR. It reads the tag, asks the area's
 * ledger which of those modules the area actually runs, and returns the
 * bands keyed by post — so adding a module that has something to say about
 * a post is a tagged class in that module and not a line in the core.
 *
 * AN EMPTY ANSWER DRAWS NOTHING. With no contributor there is no band, no
 * heading and no placeholder, and this is where that state is decided.
 */
#[CoversClass(StationSectionService::class)]
#[CoversClass(StationSections::class)]
#[CoversClass(StationSectionRequest::class)]
#[CoversClass(ContributedStationSection::class)]
final class StationSectionServiceTest extends TestCase
{
    private const string EASTGATE = '01a0-eastgate';
    private const string FIG_TREE = '01a0-fig-tree';

    public function testItAsksEveryContributorWhoseModuleTheAreaRuns(): void
    {
        $bands = $this->collect(
            contributors: [
                $this->contributor('roster', [self::EASTGATE => [$this->section('watch', 'Watch and presence')]]),
                $this->contributor('permits', [self::EASTGATE => [$this->section('gate', 'Gate permits')]]),
            ],
            running: ['roster', 'permits'],
        );

        self::assertSame(['roster', 'permits'], array_map(
            static fn (ContributedStationSection $band): string => $band->moduleSlug,
            $bands[self::EASTGATE],
        ));
    }

    /**
     * EVERY BAND CARRIES WHO PUT IT THERE, and the contributor does not get
     * to say: when the module is parked and the band goes, a person reads
     * that as the system working rather than as a page that broke.
     */
    public function testEveryBandCarriesTheSlugOfTheModuleThatContributedIt(): void
    {
        $bands = $this->collect(
            [$this->contributor('roster', [self::EASTGATE => [$this->section('watch', 'Watch and presence')]])],
            running: ['roster'],
        );

        self::assertSame('roster', $bands[self::EASTGATE][0]->moduleSlug);
        self::assertSame('watch', $bands[self::EASTGATE][0]->section->id);
    }

    /** A module parked in this area contributes nothing here, and is not asked. */
    public function testAParkedModuleIsNotAsked(): void
    {
        $roster = $this->contributor('roster', [self::EASTGATE => [$this->section('watch', 'Watch and presence')]]);
        $permits = $this->contributor('permits', [self::EASTGATE => [$this->section('gate', 'Gate permits')]]);

        $bands = $this->collect([$roster, $permits], running: ['roster']);

        self::assertCount(1, $bands[self::EASTGATE]);
        self::assertFalse($permits->asked);
    }

    /**
     * A POST NOBODY SPOKE ABOUT HAS NO BANDS — an empty list, which every
     * surface draws as nothing at all rather than as an empty card.
     */
    public function testAPostNoContributorSpokeAboutHasNoBands(): void
    {
        $bands = $this->collect(
            [$this->contributor('roster', [self::EASTGATE => [$this->section('watch', 'Watch and presence')]])],
            running: ['roster'],
        );

        self::assertSame([], $bands[self::FIG_TREE]);
    }

    /** With nothing installed every post reads the same empty answer. */
    public function testWithNoContributorsEveryPostIsEmpty(): void
    {
        $bands = $this->collect([], running: []);

        self::assertSame([], $bands[self::EASTGATE]);
        self::assertSame([], $bands[self::FIG_TREE]);
    }

    /** Every contributor is asked about the whole set at once, never post by post. */
    public function testTheWholeSetIsAskedForInOneCall(): void
    {
        $roster = $this->contributor('roster', []);

        $this->collect([$roster], running: ['roster']);

        self::assertSame(1, $roster->calls);
        self::assertSame([self::EASTGATE, self::FIG_TREE], $roster->lastRequest?->stationUuids());
    }

    /** The surface travels with the question, because the two differ. */
    public function testTheSurfaceBeingDrawnIsPartOfTheQuestion(): void
    {
        $roster = $this->contributor('roster', []);

        new StationSectionService([$roster])->collect(
            [new StationRef(self::EASTGATE, '01a0-area', 'Eastgate Post')],
            StationSurface::Configure,
            static fn (string $slug): bool => true,
        );

        self::assertSame(StationSurface::Configure, $roster->lastRequest?->surface);
    }

    /** Nothing is asked when there are no posts: an area with no stations has no question. */
    public function testAnAreaWithNoStationsAsksNobody(): void
    {
        $roster = $this->contributor('roster', []);

        $bands = new StationSectionService([$roster])->collect([], StationSurface::Record, static fn (string $slug): bool => true);

        self::assertSame(0, $roster->calls);
        self::assertSame([], $bands);
    }

    /** The record page asks the same question about a set of one. */
    public function testOnePostIsTheSameQuestionWithASetOfOne(): void
    {
        $roster = $this->contributor('roster', [self::EASTGATE => [$this->section('watch', 'Watch and presence')]]);

        $bands = new StationSectionService([$roster])->forOne(
            new StationRef(self::EASTGATE, '01a0-area', 'Eastgate Post'),
            StationSurface::Record,
            static fn (string $slug): bool => true,
        );

        self::assertCount(1, $bands);
        self::assertSame('Watch and presence', $bands[0]->section->label);
    }

    // ---------------------------------------------------------------- fixtures

    /**
     * @param list<FakeStationSections> $contributors
     * @param list<string>              $running
     *
     * @return array<string, list<ContributedStationSection>>
     */
    private function collect(array $contributors, array $running): array
    {
        return new StationSectionService($contributors)->collect(
            [
                new StationRef(self::EASTGATE, '01a0-area', 'Eastgate Post'),
                new StationRef(self::FIG_TREE, '01a0-area', 'Fig Tree Ranger Station'),
            ],
            StationSurface::Record,
            static fn (string $slug): bool => \in_array($slug, $running, true),
        );
    }

    /** @param array<string, list<StationSection>> $byStation */
    private function contributor(string $slug, array $byStation): FakeStationSections
    {
        return new FakeStationSections($slug, $byStation);
    }

    private function section(string $id, string $label): StationSection
    {
        return new StationSection($id, $label, '@Fake/band.html.twig');
    }
}

/**
 * A module's contributor, played by a fixture: the seam is a tag and an
 * interface, so the thing under test is the collector's rule and not any
 * real module's query.
 */
final class FakeStationSections implements StationSectionsInterface
{
    public int $calls = 0;
    public bool $asked = false;
    public ?StationSectionRequest $lastRequest = null;

    /** @param array<string, list<StationSection>> $byStation */
    public function __construct(
        private readonly string $slug,
        private readonly array $byStation,
    ) {
    }

    public function moduleSlug(): string
    {
        return $this->slug;
    }

    public function sectionsFor(StationSectionRequest $request): StationSections
    {
        ++$this->calls;
        $this->asked = true;
        $this->lastRequest = $request;

        return new StationSections($this->byStation);
    }
}
