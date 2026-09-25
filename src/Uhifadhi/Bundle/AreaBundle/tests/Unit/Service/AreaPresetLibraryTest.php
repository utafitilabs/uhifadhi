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

use PHPUnit\Framework\TestCase;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\Model\AreaPresetRow;
use Uhifadhi\Bundle\AreaBundle\Model\AreaRow;
use Uhifadhi\Bundle\AreaBundle\Service\AreaPresetLibrary;
use Uhifadhi\Bundle\AtlasBundle\Model\Thumbnail;

/**
 * HOW THE ATTENTION BOARD AND THE FLAGSHIP SORT AREAS — the pure grouping the
 * library does over already-enriched rows.
 *
 * These are the decisions each denser layout leans on: which areas are asking for
 * the operator, which are running steady, which are not yet live, and which single
 * area is the flagship. They are static and pure, so they are pinned here without
 * a kernel; the enrichment that reaches the overview contributions and the page that
 * renders the five are proved in the web suite.
 */
final class AreaPresetLibraryTest extends TestCase
{
    private function row(string $name, int $liveModules, int $alertCount, int $activityAgo = 60): AreaPresetRow
    {
        $area = new AreaOfInterest()->setName($name);

        $lastActivity = $activityAgo > 0 ? new \DateTimeImmutable("-{$activityAgo} seconds") : null;

        return new AreaPresetRow(new AreaRow(
            area: $area,
            areaKm2: 1000,
            liveModules: $liveModules,
            thumbnail: Thumbnail::neutral(),
            alertCount: $alertCount,
            lastActivity: $lastActivity,
        ));
    }

    public function testNeedsAttentionIsEveryAreaWithAnOpenItem(): void
    {
        $rows = [$this->row('A', 3, 2), $this->row('B', 3, 0), $this->row('C', 0, 0)];

        $needs = AreaPresetLibrary::needsAttention($rows);

        self::assertCount(1, $needs);
        self::assertSame('A', $needs[0]->row->area->getName());
    }

    public function testRunningSteadyIsLiveAndQuiet(): void
    {
        $rows = [$this->row('A', 3, 2), $this->row('B', 3, 0), $this->row('C', 0, 0)];

        $steady = AreaPresetLibrary::runningSteady($rows);

        self::assertCount(1, $steady);
        self::assertSame('B', $steady[0]->row->area->getName());
    }

    public function testAwaitingSetupIsEveryAreaWithNoModuleLive(): void
    {
        $rows = [$this->row('A', 3, 2), $this->row('C', 0, 0), $this->row('D', 0, 0)];

        $awaiting = AreaPresetLibrary::awaitingSetup($rows);

        self::assertCount(2, $awaiting);
    }

    /** The flagship is the most recently active LIVE area. */
    public function testTheFlagshipIsTheMostRecentlyActiveLiveArea(): void
    {
        $stale = $this->row('Stale', 5, 0, activityAgo: 9000);
        $fresh = $this->row('Fresh', 2, 1, activityAgo: 30);
        $quiet = $this->row('Quiet', 0, 0);

        $flagship = AreaPresetLibrary::flagship([$stale, $fresh, $quiet]);

        self::assertNotNull($flagship);
        self::assertSame('Fresh', $flagship->row->area->getName());
    }

    /** Nothing live means no flagship — the portfolio read has no hero to feature. */
    public function testThereIsNoFlagshipWhenNothingIsLive(): void
    {
        self::assertNull(AreaPresetLibrary::flagship([$this->row('A', 0, 0), $this->row('B', 0, 0)]));
    }

    public function testTheRestIsEveryAreaButTheFlagship(): void
    {
        $a = $this->row('A', 3, 0, activityAgo: 30);
        $b = $this->row('B', 0, 0);
        $flagship = AreaPresetLibrary::flagship([$a, $b]);

        $rest = AreaPresetLibrary::rest([$a, $b], $flagship);

        self::assertCount(1, $rest);
        self::assertSame('B', $rest[0]->row->area->getName());
    }

    /** With no flagship, the rest is everything — the strip carries the lot. */
    public function testTheRestIsEverythingWhenThereIsNoFlagship(): void
    {
        $rows = [$this->row('A', 0, 0), $this->row('B', 0, 0)];

        self::assertCount(2, AreaPresetLibrary::rest($rows, null));
    }
}
