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

namespace Uhifadhi\Bundle\AtlasBundle\Tests\Unit\Assets;

use PHPUnit\Framework\TestCase;

/**
 * THE PLATE HOLDS A CREDENTIALED EVENTSOURCE OPEN, and nothing else moves a
 * live mark.
 *
 * The controller is read as text, the way the other asset specifications
 * here read it: what is asserted is the four things the stream contract
 * turns on. The browser connects WITH CREDENTIALS, because the page set a
 * subscriber cookie for the topics and the hub authorizes on it; every topic
 * is a `topic` query parameter on the hub's public address; a frame that is
 * not JSON is dropped and never a broken plate; and the connection is closed
 * with the plate, so a detached map does not go on receiving.
 *
 * Rendering the stream against a real hub is NEEDS RENDER-VERIFY; this fixes
 * the wiring so a later edit cannot quietly turn the credentials off.
 */
final class PlateLiveStreamTest extends TestCase
{
    public function testTheConnectionIsACredentialedEventSource(): void
    {
        self::assertStringContainsString(
            'new EventSource(url, { withCredentials: true })',
            self::controllerJs(),
            'withCredentials is what lets the hub read the subscriber cookie the page set',
        );
    }

    public function testEveryTopicIsAQueryParameterOnTheHubsPublicAddress(): void
    {
        self::assertMatchesRegularExpression(
            '/const url = new URL\(stream\.hub\);\s*\n\s*for \(const topic of stream\.topics\) \{\s*\n\s*url\.searchParams\.append\(\'topic\', topic\);/',
            self::controllerJs(),
        );
    }

    public function testAMalformedFrameIsDroppedRatherThanBreakingThePlate(): void
    {
        self::assertMatchesRegularExpression(
            '/onmessage = \(event\) => \{\s*\n\s*let frame;\s*\n\s*try \{\s*\n\s*frame = JSON\.parse\(event\.data\);\s*\n\s*\} catch \(error\) \{\s*\n\s*return;/',
            self::controllerJs(),
        );
    }

    /** A plate given no hub and no topics behaves exactly as before: nothing is opened. */
    public function testWithoutAHubAndTopicsNothingIsOpened(): void
    {
        self::assertMatchesRegularExpression(
            '/if \(!stream\?\.hub \|\| !\(stream\.topics \?\? \[\]\)\.length\) \{\s*\n\s*return;/',
            self::controllerJs(),
        );
    }

    public function testTheStreamIsClosedWithThePlate(): void
    {
        $js = self::controllerJs();

        self::assertMatchesRegularExpression('/disconnect\(\) \{\s*\n\s*this\.unsubscribe\(\);/', $js);
        self::assertStringContainsString('this.stream?.close();', $js);
    }

    private static function controllerJs(): string
    {
        return (string) file_get_contents(\dirname(__DIR__, 3).'/assets/controllers/map_plate_controller.js');
    }
}
