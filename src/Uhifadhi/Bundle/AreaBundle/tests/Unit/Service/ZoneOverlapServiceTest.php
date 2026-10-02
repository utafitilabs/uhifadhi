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
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\Service\ZoneOverlapService;
use Uhifadhi\Bundle\AreaBundle\Settings\CoreSettings;
use Uhifadhi\Contracts\Settings\SettingsReaderInterface;

/**
 * WHEN SHARED GROUND IS A SLIVER AND WHEN IT IS AN OVERLAP.
 *
 * TWO RINGS DRAWN FROM THE SAME SOURCE ALMOST NEVER MEET EXACTLY. A scheme
 * digitised by hand, or exported twice through different tools, leaves metres
 * of shared edge between neighbours that were meant to touch — and refusing
 * the file over that is refusing the file over arithmetic. So a small enough
 * overlap is ACCEPTED: both rings are stored as they arrived, and the ground
 * they share is answered for by the same deterministic tie-break that answers
 * which zone a point on a shared edge is in.
 *
 * SMALL ENOUGH IS TWO THINGS, EITHER OF THEM. A part of a percent of the
 * SMALLER ring, so the rule scales with what is being drawn; or under a square
 * kilometre outright, so a sliver against a tiny zone is still a sliver. The
 * percentage is the area's to set, because how carefully a scheme was drawn is
 * a fact about the installation and not about the product.
 *
 * ANYTHING LARGER IS STILL A REFUSAL, and it is refused with its size, because
 * "overlaps Crater" tells nobody whether the file is wrong or the stored zone
 * is, and "overlaps Crater by 12 km²" does.
 */
#[CoversClass(ZoneOverlapService::class)]
final class ZoneOverlapServiceTest extends TestCase
{
    public function testNothingSetGetsOnePercent(): void
    {
        self::assertSame(1.0, self::ruleReading(null)->toleranceOf(new AreaOfInterest()));
    }

    /** The tolerance in force for the area — Settings › Core's, organization or custom — decides. */
    public function testTheToleranceInForceIsObeyed(): void
    {
        self::assertSame(2.5, self::ruleReading(2.5)->toleranceOf(new AreaOfInterest()));
    }

    /** Under a percent of the smaller ring at the default: a sliver. */
    public function testAFractionOfAPercentOfTheSmallerRingIsASliver(): void
    {
        // 0.4 % of 500 km² is 2 km², which is over the absolute floor and
        // under the percentage — so the percentage is what accepts it.
        self::assertTrue($this->rule()->isSliver(2.0, 500.0, 900.0, 1.0));
    }

    public function testFivePercentOfTheSmallerRingIsAnOverlap(): void
    {
        self::assertFalse($this->rule()->isSliver(25.0, 500.0, 900.0, 1.0));
    }

    /**
     * THE SMALLER RING IS THE ONE THAT DECIDES. Twelve square kilometres is
     * nothing against a big zone and most of a small one, so measuring against
     * the larger of the two would accept an overlap that swallows its
     * neighbour.
     */
    public function testTheRuleMeasuresAgainstTheSmallerRing(): void
    {
        self::assertFalse($this->rule()->isSliver(12.0, 100.0, 5000.0, 1.0));
        self::assertTrue($this->rule()->isSliver(12.0, 5000.0, 5000.0, 1.0));
    }

    /** A square kilometre is a sliver whatever the rings are, so a tiny zone is not held to a tiny fraction. */
    public function testAnythingUnderASquareKilometreIsASliver(): void
    {
        self::assertTrue($this->rule()->isSliver(0.9, 2.0, 2.0, 1.0));
        self::assertFalse($this->rule()->isSliver(1.5, 2.0, 2.0, 1.0));
    }

    /** A tolerance of zero still accepts the absolute floor: nothing here is exact arithmetic. */
    public function testAToleranceOfZeroStillAcceptsTheAbsoluteFloor(): void
    {
        self::assertTrue($this->rule()->isSliver(0.4, 500.0, 900.0, 0.0));
        self::assertFalse($this->rule()->isSliver(2.0, 500.0, 900.0, 0.0));
    }

    private function rule(): ZoneOverlapService
    {
        return self::ruleReading(null);
    }

    private static function ruleReading(?float $tolerance): ZoneOverlapService
    {
        return new ZoneOverlapService(new readonly class($tolerance) implements SettingsReaderInterface {
            public function __construct(private ?float $tolerance)
            {
            }

            public function value(string $key, ?string $areaUuid = null, ?string $departmentUuid = null): ?float
            {
                return CoreSettings::ZONE_OVERLAP_TOLERANCE === $key ? ($this->tolerance ?? ZoneOverlapService::DEFAULT_TOLERANCE_PCT) : null;
            }
        });
    }
}
