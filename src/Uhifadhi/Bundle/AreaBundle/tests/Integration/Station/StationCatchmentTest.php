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

namespace Uhifadhi\Bundle\AreaBundle\Tests\Integration\Station;

use PHPUnit\Framework\Attributes\CoversClass;
use Uhifadhi\Bundle\AreaBundle\Service\StationService;
use Uhifadhi\Bundle\AreaBundle\Tests\Integration\IntegrationTestCase;

/**
 * WHAT "INSIDE THIS POST" MEANS — the ring a check-in claiming this post is
 * judged against.
 *
 * THE READ SIDE HAS BEEN THERE ALL ALONG: a check-in inside the ring derives
 * as verified and one outside it as unverified. Nothing SET the ring, so
 * every post had none, every day claimed at one derived unverified, and the
 * Live tab read "verified at a station 0 of 13" with fifteen people standing at
 * theirs. A read with no write is a feature that looks broken.
 *
 * NULL STAYS A REAL STATE. A post with no ring has no inside: the handset
 * says so rather than picking a radius of its own, and a day claimed there
 * derives as unverified rather than as wrong. What changed is that a post
 * being CREATED gets the design's default, because somebody making it is
 * looking at the field with the default written beside it.
 *
 * THAT THE FIELD API HANDS THE RING OVER is the core's own specification
 * (tests/Core/FieldAreasEndpointTest), where the API is wired.
 */
#[CoversClass(StationService::class)]
final class StationCatchmentTest extends IntegrationTestCase
{
    /** A new post opens with the design's ring rather than with none. */
    public function testANewPostOpensWithTheDefaultRing(): void
    {
        $station = $this->stations()->add($this->anArea(), 'Eastgate Post', -29.75, -3.2);

        self::assertSame(StationService::DEFAULT_CATCHMENT_M, $station->getCatchmentM());
        self::assertSame(1500, StationService::DEFAULT_CATCHMENT_M, 'the design says 1.5 km');
    }

    /** And a caller that states one gets the one it stated. */
    public function testAPostMayOpenWithARingOfItsOwn(): void
    {
        $station = $this->stations()->add($this->anArea(), 'Outer Marker', -29.75, -3.2, catchmentM: 600);

        self::assertSame(600, $station->getCatchmentM());
    }

    /** A post may be created with none at all, and that is a state. */
    public function testAPostMayOpenWithNoRing(): void
    {
        $station = $this->stations()->add($this->anArea(), 'Roadside Marker', -29.75, -3.2, catchmentM: null);

        self::assertNull($station->getCatchmentM());
    }

    public function testTheRingIsChangedThroughTheService(): void
    {
        $station = $this->stations()->add($this->anArea(), 'Fig Tree Ranger Station', -29.7, -3.2);

        $this->stations()->setCatchment($station, 1000);

        self::assertSame(1000, $station->getCatchmentM());
    }

    /**
     * EMPTY CLEARS IT, and so does a radius nobody can stand in: a zero or a
     * negative is not a small ring, it is not a ring.
     */
    public function testARadiusNobodyCanStandInIsNoRingAtAll(): void
    {
        $station = $this->stations()->add($this->anArea(), 'Delta Outpost', -29.7, -3.2);

        $this->stations()->setCatchment($station, null);
        self::assertNull($station->getCatchmentM());

        $this->stations()->setCatchment($station, 900);
        $this->stations()->setCatchment($station, 0);
        self::assertNull($station->getCatchmentM());

        $this->stations()->setCatchment($station, 900);
        $this->stations()->setCatchment($station, -5);
        self::assertNull($station->getCatchmentM());
    }

    private function stations(): StationService
    {
        /** @var StationService $service */
        $service = static::getContainer()->get('test_public.area.stations');

        return $service;
    }
}
