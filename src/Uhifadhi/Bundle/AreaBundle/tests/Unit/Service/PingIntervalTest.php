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
use Uhifadhi\Bundle\AreaBundle\Settings\CoreSettings;
use Uhifadhi\Contracts\Settings\SettingsReaderInterface;

/**
 * HOW OFTEN AN AREA'S HANDSETS REPORT, AND WHEN A SILENT ONE GOES STALE —
 * Settings › Core's two Presence settings, set by Super Admins and Admins for
 * the organization and customised per area. One reading, for the handset, the
 * presence derivation and any module that counts from it.
 */
#[CoversClass(PingInterval::class)]
final class PingIntervalTest extends TestCase
{
    public function testNothingSetRunsAtHalfAnHour(): void
    {
        self::assertSame(30, PingInterval::DEFAULT_MINUTES);
        self::assertSame(30, new PingInterval(self::reader([]))->for(self::area()));
    }

    public function testTheValueInForceForTheAreaIsObeyed(): void
    {
        self::assertSame(12, new PingInterval(self::reader([CoreSettings::PING_INTERVAL => 12]))->for(self::area()));
    }

    /** Zero is no interval: a phone given it would never ping or never stop. */
    public function testZeroAndBelowReadAsTheDefault(): void
    {
        self::assertSame(30, new PingInterval(self::reader([CoreSettings::PING_INTERVAL => 0]))->for(self::area()));
    }

    /** STALE-AFTER LEFT UNSET KEEPS THE TWO-INTERVAL RULE: the reading is null, and presence applies the rule. */
    public function testStaleAfterUnsetIsNullAndSetIsItsMinutes(): void
    {
        self::assertNull(new PingInterval(self::reader([]))->staleAfterFor(self::area()));
        self::assertSame(45, new PingInterval(self::reader([CoreSettings::STALE_AFTER => 45]))->staleAfterFor(self::area()));
    }

    private static function area(): AreaOfInterest
    {
        return new AreaOfInterest();
    }

    /**
     * @param array<string, int> $values
     */
    private static function reader(array $values): SettingsReaderInterface
    {
        return new readonly class($values) implements SettingsReaderInterface {
            /** @param array<string, int> $values */
            public function __construct(private array $values)
            {
            }

            public function value(string $key, ?string $areaUuid = null, ?string $departmentUuid = null): ?int
            {
                return $this->values[$key] ?? match ($key) {
                    CoreSettings::PING_INTERVAL => 30,
                    default => null,
                };
            }
        };
    }
}
