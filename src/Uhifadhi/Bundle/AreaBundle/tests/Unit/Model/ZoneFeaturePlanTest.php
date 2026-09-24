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

namespace Uhifadhi\Bundle\AreaBundle\Tests\Unit\Model;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Uhifadhi\Bundle\AreaBundle\Model\ZoneFeaturePlan;

/**
 * A FLAGGED FEATURE'S REASON IS ONE LINE.
 *
 * THE LIST IS READ BY SCANNING IT. Eleven rows in a narrow column answer one
 * question — which of these is not coming in, and why — and a reason that
 * wraps to three lines turns the scan into reading. So every sentence the plan
 * can print is held to the length of the longest the design drew: "name
 * already here — rename it in the file, or edit the zone".
 *
 * THE AREA IS THE PAGE, so no reason names it. "Falls outside the boundary of
 * Northern Conservation Reserve — a zone subdivides its area" spends three
 * lines restating the heading the reader is looking at; "outside the area
 * boundary" says the same thing to somebody who can already see which area
 * they are configuring.
 *
 * A LENGTH IS A TEST, NOT AN EYEBALL. Wrapping was found by rendering, and the
 * only way it stays found is a number a new sentence has to pass.
 */
#[CoversClass(ZoneFeaturePlan::class)]
final class ZoneFeaturePlanTest extends TestCase
{
    /**
     * The length of the longest reason the settled design draws — "name
     * already here — rename it in the file, or edit the zone", which this
     * bundle prints verbatim. Nothing may exceed the sentence the design
     * itself was measured with.
     */
    private const int LONGEST = 59;

    /** @return \Generator<string, array{ZoneFeaturePlan}> */
    public static function reasons(): \Generator
    {
        $feature = ZoneFeaturePlan::arriving('Crater North', '{}', 121);

        yield 'name taken' => [$feature->nameAlreadyHere()];
        yield 'name twice' => [$feature->nameUsedTwiceInTheFile()];
        yield 'overlaps a zone' => [$feature->overlapsZone('Crater', 41)];
        yield 'overlaps a zone, unmeasured' => [$feature->overlapsZone('Crater', null)];
        yield 'overlaps a feature' => [$feature->overlapsFeatureInTheFile('Angata Salei', 3)];
        yield 'overlaps a long-named feature' => [$feature->overlapsFeatureInTheFile('Short-grass Plains', 1234)];
        yield 'no geometry' => [$feature->noGeometry()];
        yield 'not a polygon' => [$feature->notAPolygon()];
    }

    #[DataProvider('reasons')]
    public function testEveryReasonFitsOnOneLine(ZoneFeaturePlan $flagged): void
    {
        self::assertLessThanOrEqual(self::LONGEST, mb_strlen($flagged->why()), \sprintf(
            '"%s" is %d characters and wraps; the design\'s longest reason is %d.',
            $flagged->why(),
            mb_strlen($flagged->why()),
            self::LONGEST,
        ));
    }

    /** A reason names a zone, a feature, or nothing — never the area being configured. */
    #[DataProvider('reasons')]
    public function testNoReasonNamesTheAreaThePageIsAbout(ZoneFeaturePlan $flagged): void
    {
        self::assertStringNotContainsString('boundary of', $flagged->why());
        self::assertStringNotContainsString('subdivides', $flagged->why());
    }

    /**
     * A QUALIFIER IS HELD TO THE SAME LINE, and it is a qualifier: the feature
     * carrying it is still arriving, because a zone may lie outside the area's
     * gazetted boundary.
     */
    public function testTheBoundaryQualifierIsOneLineAndDoesNotFlagTheFeature(): void
    {
        $beyond = ZoneFeaturePlan::arriving('Border Sector', '{}', 318)->extendingBeyondTheBoundary(1234);

        self::assertSame('extends 1,234 km² beyond the boundary', $beyond->note);
        self::assertLessThanOrEqual(self::LONGEST, mb_strlen($beyond->note));
        self::assertTrue($beyond->isArriving());
        self::assertSame('', $beyond->why());
    }

    public function testAFlaggedFeatureIsNotArrivingAndAnUnflaggedOneIs(): void
    {
        $feature = ZoneFeaturePlan::arriving('Crater North', '{}', 121);

        self::assertTrue($feature->isArriving());
        self::assertFalse($feature->nameAlreadyHere()->isArriving());
    }
}
