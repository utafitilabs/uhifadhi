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
use Uhifadhi\Bundle\AreaBundle\Controller\StationsController;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\Entity\Station;
use Uhifadhi\Bundle\AreaBundle\Enum\PostingSource;
use Uhifadhi\Bundle\AreaBundle\Service\PostingService;
use Uhifadhi\Bundle\AreaBundle\Service\StationService;
use Uhifadhi\Bundle\AreaBundle\Tests\Integration\Web\Fixtures\HostUser;

/**
 * THE STATIONS OVERVIEW'S PLATE AND ITS EMPTY STATE, OVER REAL HTTP. The rest
 * of the hub is StationsHubTest's.
 */
#[CoversClass(StationsController::class)]
final class StationsTabTest extends WebTestCase
{
    /**
     * THE PLATE IS A FIXED HEIGHT ON A TAB, stated by the page rather than
     * left to the atlas's default: a card in a stack that sized itself to the
     * viewport would be a different card on every screen, and the design
     * draws this one at 420 with its key below it.
     */
    public function testThePlateIsDrawnAtTheHeightTheDesignFixes(): void
    {
        $this->boot();
        $this->signIn();
        [$area] = $this->aStaffedPost();

        self::assertStringNotContainsString('--map-plate-height:', $this->body($this->tab($area)));
        // THE ZONES ARE THE GROUND UNDER THE STATIONS here, and keep their names off the imagery.
        self::assertStringContainsString('&quot;labels&quot;:false', $this->body($this->tab($area)), 'The zones layer declines its labels on the stations plate.');
    }

    /** An area with no post says what a station is rather than drawing an empty table. */
    public function testAnAreaWithNoPostSaysSoAndPointsAtConfigure(): void
    {
        $this->boot();
        $this->signIn();

        $body = $this->body($this->tab($this->anArea()));

        self::assertStringContainsString('No station in this area yet', $body);
        self::assertStringContainsString('Configure stations', $body);
    }

    // ---------------------------------------------------------------- fixtures

    private function tab(AreaOfInterest $area): string
    {
        return '/areas/'.$area->getUuidString().'/stations';
    }

    private function body(string $url): string
    {
        $this->browser()->request('GET', $url);

        return (string) $this->browser()->getResponse()->getContent();
    }

    /** @return array{0: AreaOfInterest, 1: Station} */
    private function aStaffedPost(): array
    {
        $area = $this->anArea();
        $this->aZone($area, 'Western Sector', self::A_WEST_HALF);
        $station = $this->stations()->add($area, 'Eastgate Post', -29.75, -3.2);

        $lead = $this->postings()->post($station, $this->aPerson('J.', 'Mollel'), PostingSource::WrittenHere);
        $this->postings()->appointLeader($lead);
        $this->postings()->post($station, $this->aPerson('T.', 'Ndosi'), PostingSource::FromTheirPage);

        return [$area, $station];
    }

    private function aPerson(string $first, string $last): HostUser
    {
        $person = new HostUser()->named($first, $last);
        $this->em->persist($person);
        $this->em->flush();

        return $person;
    }

    private function stations(): StationService
    {
        /** @var StationService $service */
        $service = static::getContainer()->get('test_public.area.stations');

        return $service;
    }

    private function postings(): PostingService
    {
        /** @var PostingService $service */
        $service = static::getContainer()->get('test_public.area.postings');

        return $service;
    }
}
