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
use Uhifadhi\Bundle\AreaBundle\Entity\Station;
use Uhifadhi\Bundle\AreaBundle\Enum\StationPositionSource;
use Uhifadhi\Bundle\AreaBundle\Service\StationService;
use Uhifadhi\Bundle\AreaBundle\Tests\Integration\IntegrationTestCase;

/**
 * WHERE A STATION'S POINT CAME FROM — surveyed on the ground, or estimated.
 *
 * A point somebody typed on the form or placed on the configure page is a
 * surveyed point; a point the code had to invent for a post nobody has
 * placed yet is an estimate, and the row says which.
 */
#[CoversClass(StationService::class)]
#[CoversClass(Station::class)]
final class StationPositionSourceTest extends IntegrationTestCase
{
    public function testAPostRecordedAtAPointIsSurveyed(): void
    {
        $station = $this->stations()->add($this->anArea(), 'Eastgate Post', -29.75, -3.2);

        self::assertSame(StationPositionSource::Surveyed, $station->getPositionSource());
        self::assertSame('surveyed', $this->storedSource($station));
    }

    public function testACallerThatInventedThePointRecordsItAsEstimated(): void
    {
        $station = $this->stations()->add($this->anArea(), 'Roadside Marker', -29.75, -3.2, positionSource: StationPositionSource::Estimated);

        self::assertSame(StationPositionSource::Estimated, $station->getPositionSource());
        self::assertSame('estimated', $this->storedSource($station));
    }

    /** Placing an estimated post on the map is surveying it. */
    public function testMovingAPostMakesItsPointSurveyed(): void
    {
        $station = $this->stations()->add($this->anArea(), 'Delta Outpost', -29.75, -3.2, positionSource: StationPositionSource::Estimated);

        $this->stations()->moveTo($station, -29.7, -3.25);

        self::assertSame(StationPositionSource::Surveyed, $station->getPositionSource());
        self::assertSame('surveyed', $this->storedSource($station));
    }

    public function testAStationBuiltByHandStartsSurveyed(): void
    {
        self::assertSame(StationPositionSource::Surveyed, new Station()->getPositionSource());
    }

    private function storedSource(Station $station): mixed
    {
        return $this->em->getConnection()->fetchOne('SELECT position_source FROM station WHERE id = ?', [$station->getId()]);
    }

    private function stations(): StationService
    {
        /** @var StationService $service */
        $service = static::getContainer()->get('test_public.area.stations');

        return $service;
    }
}
