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

namespace Uhifadhi\Bundle\AreaBundle\Tests\Unit\Enum;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Uhifadhi\Bundle\AreaBundle\Enum\CheckInStatusKind;

/**
 * THE FOUR MEANINGS, in the platform's own words: the editor and the roster's
 * card say these and nothing else.
 */
#[CoversClass(CheckInStatusKind::class)]
final class CheckInStatusKindTest extends TestCase
{
    public function testEachKindSaysThePlatformsWord(): void
    {
        self::assertSame(
            ['At a station', 'Working elsewhere', 'Not working', 'Special assignment'],
            array_map(static fn (CheckInStatusKind $kind): string => $kind->label(), CheckInStatusKind::cases()),
        );
    }

    public function testEachKindSaysWhatItMeans(): void
    {
        self::assertSame('On duty at a named station · the only kind that takes a station', CheckInStatusKind::AtPost->meaning());
        self::assertSame('On duty away from a station · outside the park, escorting, in court · the ranger adds a note', CheckInStatusKind::WorkingElsewhere->meaning());
        self::assertSame('Not on duty · unfit, on leave, stood down', CheckInStatusKind::NotWorking->meaning());
        self::assertSame('On duty inside the park, on an assignment away from a station · the ranger adds a note', CheckInStatusKind::Special->meaning());
    }

    public function testOnlyAtAStationTakesAStation(): void
    {
        self::assertSame(
            [true, false, false, false],
            array_map(static fn (CheckInStatusKind $kind): bool => $kind->takesStation(), CheckInStatusKind::cases()),
        );
    }
}
