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

namespace Uhifadhi\Bundle\AreaBundle\Tests\Integration\Web;

use PHPUnit\Framework\Attributes\CoversClass;
use Uhifadhi\Bundle\AreaBundle\Entity\Station;
use Uhifadhi\Bundle\AreaBundle\People\AreaPersonPostings;
use Uhifadhi\Bundle\AreaBundle\People\AreaStationPlates;
use Uhifadhi\Bundle\AreaBundle\Service\StationService;
use Uhifadhi\Contracts\People\StationPlateProviderInterface;

/**
 * THE GROUND AROUND A STATION, DRAWN FOR A PERSON'S RECORD — the area answers
 * the seam with the station's own plate, and answers nothing for a station
 * that is not its own.
 */
#[CoversClass(AreaStationPlates::class)]
#[CoversClass(AreaPersonPostings::class)]
final class StationPlateTest extends WebTestCase
{
    public function testTheAreaDrawsThePlateForItsOwnStation(): void
    {
        $this->boot();
        $station = $this->aStation();

        $plate = $this->plates()->plateFor((string) $station->getUuidString());

        self::assertNotNull($plate);
        self::assertStringContainsString('stplate', $plate->html, 'The design\'s box, drawn.');
        self::assertStringContainsString('Eastgate Post and the ground around it', $plate->html);
        self::assertMatchesRegularExpression('/data-controller="[^"]*map/', $plate->html, 'A real map, not a picture of one.');
        self::assertStringNotContainsString('map-legend', $plate->html, 'A thumbnail beside the record carries no legend; the station record has it.');
    }

    public function testAStationThatIsNotThisAreasGetsNoPlate(): void
    {
        $this->boot();
        self::assertNull($this->plates()->plateFor('019a0000-0000-7000-8000-0000000000aa'));
        self::assertNull($this->plates()->plateFor('not-a-uuid'));
    }

    /**
     * THE PLATE IS THE STATION'S GROUND, so somebody who may not read the
     * area's stations gets no plate at all — no markup handed over.
     */
    public function testSomebodyWhoMayNotReadTheStationsGetsNoPlate(): void
    {
        $this->boot(['areas.read']);
        $station = $this->aStation();

        self::assertNull($this->plates()->plateFor((string) $station->getUuidString()));
    }

    private function plates(): StationPlateProviderInterface
    {
        $provider = static::getContainer()->get('test_public.area.station_plates');
        self::assertInstanceOf(StationPlateProviderInterface::class, $provider);

        return $provider;
    }

    private function aStation(): Station
    {
        /** @var StationService $service */
        $service = static::getContainer()->get('test_public.area.stations');

        return $service->add($this->anArea(), 'Eastgate Post', -29.75, -3.2, 'ST-01');
    }
}
