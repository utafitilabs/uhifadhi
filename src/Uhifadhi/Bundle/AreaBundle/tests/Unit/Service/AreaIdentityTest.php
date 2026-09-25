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

use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\Exception\AreaIdentityException;
use Uhifadhi\Bundle\AreaBundle\Service\AreaIdentity;
use Uhifadhi\Bundle\AreaBundle\Service\PingInterval;

/**
 * THE AREA SETTINGS' ONE WRITE, as far as the ping interval goes: blank is
 * "not set" and reads as the default, below one minute is refused, anything
 * else is the area's number.
 */
#[CoversClass(AreaIdentity::class)]
final class AreaIdentityTest extends TestCase
{
    public function testABlankIntervalIsNotSetAndReadsAsTheDefault(): void
    {
        $area = new AreaOfInterest()->setPingIntervalMinutes(15);

        $this->identity()->update($area, 'Northern Reserve', null, null, null, null);

        self::assertNull($area->getPingIntervalMinutes());
        self::assertSame(PingInterval::DEFAULT_MINUTES, new PingInterval()->for($area));
    }

    public function testAnIntervalIsSaved(): void
    {
        $area = new AreaOfInterest();

        $this->identity()->update($area, 'Northern Reserve', null, null, null, 45);

        self::assertSame(45, $area->getPingIntervalMinutes());
    }

    /** Refused rather than clamped, and nothing on the area moves. */
    public function testBelowOneMinuteIsRefusedAndTheAreaIsUnchanged(): void
    {
        $area = new AreaOfInterest()->setName('Northern Reserve')->setPingIntervalMinutes(20);

        try {
            $this->identity()->update($area, 'Renamed Reserve', null, null, null, 0);
            self::fail('A ping interval of zero was accepted.');
        } catch (AreaIdentityException $refused) {
            self::assertStringContainsString('at least one minute', $refused->getMessage());
        }

        self::assertSame(20, $area->getPingIntervalMinutes());
        self::assertSame('Northern Reserve', $area->getName());
    }

    private function identity(): AreaIdentity
    {
        return new AreaIdentity($this->createStub(EntityManagerInterface::class));
    }
}
