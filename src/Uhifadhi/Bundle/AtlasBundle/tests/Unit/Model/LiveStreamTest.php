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

use PHPUnit\Framework\TestCase;
use Uhifadhi\Bundle\AtlasBundle\Model\LiveStream;

/**
 * THE TWO FACTS A PLATE NEEDS TO KEEP ITS LIVE MARKS MOVING — where the hub
 * is and which topics to hold open — and nothing else. The atlas knows no
 * area and no topic vocabulary; it carries what the builder states.
 */
final class LiveStreamTest extends TestCase
{
    private const string HUB = 'https://hub.example.test/.well-known/mercure';

    public function testItStatesTheHubAndTheTopicsAsThePlateReadsThem(): void
    {
        $stream = new LiveStream(self::HUB, ['area/a/presence', 'area/b/presence']);

        self::assertSame(
            ['hub' => self::HUB, 'topics' => ['area/a/presence', 'area/b/presence']],
            $stream->toArray(),
        );
    }

    /** A stream to nowhere is not a stream: the deployment that has no hub hands the plate none. */
    public function testAStreamNeedsAHubAddress(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new LiveStream('', ['area/a/presence']);
    }

    public function testAStreamNeedsAtLeastOneTopic(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new LiveStream(self::HUB, []);
    }
}
