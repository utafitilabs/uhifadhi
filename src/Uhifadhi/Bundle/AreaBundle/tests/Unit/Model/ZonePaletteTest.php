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

use PHPUnit\Framework\TestCase;
use Uhifadhi\Bundle\AreaBundle\Model\ZonePalette;
use Uhifadhi\Contracts\Atlas\PlatePalette;

/**
 * ELEVEN ZONES, ELEVEN MARKS.
 *
 * THE COLOUR IS THE ZONE: on a plate a zone has no label of its own, so the
 * hue is the only thing tying a ring on the map to a row in the key and a
 * swatch on its card. Two zones drawing alike is therefore not a cosmetic
 * problem — it is a map that answers "which zone is this" with two names.
 *
 * Kilimani Crater has ELEVEN, which is why this is the number the test names: the
 * register wrapped at nine before the ring was ruled, and the tenth and
 * eleventh zones came out wearing the first and second zones' colours.
 */
final class ZonePaletteTest extends TestCase
{
    /** @return list<int> */
    private static function set(int $zones): array
    {
        $cats = [];
        for ($position = 0; $position < $zones; ++$position) {
            $cats[] = ZonePalette::catFor($position);
        }

        return $cats;
    }

    public function testASetOfElevenZonesGetsElevenDistinctCategories(): void
    {
        $cats = self::set(11);

        self::assertCount(11, array_unique($cats), 'Two zones share a mark, and a plate cannot say which is which.');
        self::assertSame([1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11], $cats);
    }

    /** And every one of them resolves to its own token on the plate. */
    public function testEachOfTheElevenPaintsWithItsOwnToken(): void
    {
        $tokens = array_map(PlatePalette::category(...), self::set(11));

        self::assertCount(11, array_unique($tokens));
    }

    /**
     * THE WHOLE SET IS DISTINCT, right up to the end of the ring — eighteen
     * positions, eighteen categories.
     */
    public function testTheSetRunsToEighteenBeforeItRepeats(): void
    {
        self::assertCount(PlatePalette::CATEGORIES, array_unique(self::set(PlatePalette::CATEGORIES)));
    }

    /**
     * AND THEN IT WRAPS, rather than running out. A nineteenth zone starts
     * the ring again: two zones alike at opposite ends of a plate is a
     * smaller problem than a zone with no colour at all, and an area with
     * nineteen zones is a design conversation, not a palette one.
     */
    public function testThePositionAfterTheRingStartsAgainAtOne(): void
    {
        self::assertSame(1, ZonePalette::catFor(PlatePalette::CATEGORIES));
        self::assertSame(2, ZonePalette::catFor(PlatePalette::CATEGORIES + 1));
    }
}
