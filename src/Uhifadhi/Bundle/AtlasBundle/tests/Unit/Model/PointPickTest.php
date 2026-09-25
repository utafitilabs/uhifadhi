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

namespace Uhifadhi\Bundle\AtlasBundle\Tests\Unit\Model;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Uhifadhi\Bundle\AtlasBundle\Model\PointPick;

/**
 * WHAT A PAGE STATES TO HAVE ITS PLATE PICK A POINT: the form a click writes
 * into at rest, what that point is called, the names of the two inputs that
 * hold it, and how many decimals are written.
 */
#[CoversClass(PointPick::class)]
final class PointPickTest extends TestCase
{
    public function testThePairIsLatAndLonToFiveDecimalsUnlessStated(): void
    {
        self::assertSame(
            ['form' => 'add', 'name' => '', 'latitude' => 'lat', 'longitude' => 'lon', 'precision' => 5],
            new PointPick('add')->toArray(),
        );
        self::assertSame(
            ['form' => 'where', 'name' => 'the sighting', 'latitude' => 'y', 'longitude' => 'x', 'precision' => 6],
            new PointPick('where', 'the sighting', latitude: 'y', longitude: 'x', precision: 6)->toArray(),
        );
    }

    /** A form nobody named cannot be written into, and a point written to no decimals is a town. */
    public function testAPickWithNoFormOrNoPrecisionIsRefused(): void
    {
        foreach ([static fn () => new PointPick(''), static fn () => new PointPick('add', precision: 0)] as $pick) {
            try {
                $pick();
                self::fail('The pick was accepted.');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }
}
