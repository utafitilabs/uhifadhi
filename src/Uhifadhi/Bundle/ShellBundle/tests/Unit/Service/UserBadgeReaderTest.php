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

namespace Uhifadhi\Bundle\ShellBundle\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Uhifadhi\Bundle\ShellBundle\Service\UserBadgeReader;
use Uhifadhi\Contracts\Shell\UserBadge;
use Uhifadhi\Contracts\Shell\UserBadgeSourceInterface;

/**
 * THE READER, ON ITS OWN. The integration side proves the frame draws the card;
 * this proves the stand-alone rule — a shell with no source registered is a working
 * shell, not a broken one.
 */
final class UserBadgeReaderTest extends TestCase
{
    public function testWithNoSourceThereIsNoCard(): void
    {
        self::assertNull(new UserBadgeReader()->badge(), 'A fresh installation declares no source and gets no card, not an error.');
    }

    public function testItHandsBackWhateverTheSourceComposed(): void
    {
        $badge = new UserBadge('N. Kileo', 'NK', 'UCA · operator');

        $reader = new UserBadgeReader(new class($badge) implements UserBadgeSourceInterface {
            public function __construct(private readonly UserBadge $badge)
            {
            }

            public function badge(): UserBadge
            {
                return $this->badge;
            }
        });

        self::assertSame($badge, $reader->badge());
    }

    public function testASourceThatKnowsNoViewerYieldsNoCard(): void
    {
        $reader = new UserBadgeReader(new class implements UserBadgeSourceInterface {
            public function badge(): ?UserBadge
            {
                return null;
            }
        });

        self::assertNull($reader->badge(), 'An anonymous request names nobody, even with a source registered.');
    }
}
