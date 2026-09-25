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
use Uhifadhi\Bundle\AreaBundle\Service\PingInterval;

/**
 * HOW OFTEN AN AREA'S HANDSETS REPORT — the area's number, or half an hour
 * where it set none. One reading, for the handset, the presence derivation and
 * any module that counts from it.
 */
#[CoversClass(PingInterval::class)]
final class PingIntervalTest extends TestCase
{
    public function testAnAreaThatSetsNoneRunsAtHalfAnHour(): void
    {
        self::assertSame(30, PingInterval::DEFAULT_MINUTES);
        self::assertSame(30, new PingInterval()->for(new AreaOfInterest()));
    }

    public function testAnAreaThatSetsOneIsObeyed(): void
    {
        self::assertSame(12, new PingInterval()->for(new AreaOfInterest()->setPingIntervalMinutes(12)));
    }

    /** Zero is no interval: a phone given it would never ping or never stop. */
    public function testZeroAndBelowReadAsTheDefault(): void
    {
        self::assertSame(30, new PingInterval()->for(new AreaOfInterest()->setPingIntervalMinutes(0)));
        self::assertSame(30, new PingInterval()->for(new AreaOfInterest()->setPingIntervalMinutes(-5)));
    }
}
