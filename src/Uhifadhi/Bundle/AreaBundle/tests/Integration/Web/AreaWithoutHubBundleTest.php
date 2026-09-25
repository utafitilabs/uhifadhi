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
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Uhifadhi\Bundle\AreaBundle\Entity\CheckIn;
use Uhifadhi\Bundle\AreaBundle\Entity\CheckInCorrection;
use Uhifadhi\Bundle\AreaBundle\Entity\PersonPosition;
use Uhifadhi\Bundle\AreaBundle\Entity\Station;
use Uhifadhi\Bundle\AreaBundle\Repository\CheckInCorrectionRepository;
use Uhifadhi\Bundle\AreaBundle\Repository\CheckInRepository;
use Uhifadhi\Bundle\AreaBundle\Repository\PersonPositionRepository;
use Uhifadhi\Bundle\AreaBundle\Repository\StationRepository;
use Uhifadhi\Bundle\AreaBundle\Service\CheckInService;
use Uhifadhi\Bundle\AreaBundle\Service\CheckInStatusService;
use Uhifadhi\Bundle\AreaBundle\Service\PresencePublisher;
use Uhifadhi\Bundle\AreaBundle\Service\PresenceService;
use Uhifadhi\Bundle\AreaBundle\Service\PresenceStreamService;
use Uhifadhi\Bundle\AreaBundle\Service\StationService;
use Uhifadhi\Bundle\AtlasBundle\Model\AtlasMap;

/**
 * THE INSTALLATION WITHOUT A HUB BUNDLE. The core suggests
 * symfony/mercure-bundle and never requires it, so a kernel that registers
 * the area bundle and its batteries and NO MercureBundle has to compile,
 * answer every area page, store a handset's write, and draw every plate
 * once — no cookie, no stream, no publish, nothing raised.
 *
 * What is pinned here is the wiring: `area.presence_stream` and
 * `area.presence_publisher` name the hub and the subscriber authorization
 * as references that resolve to null when the hub bundle registered nothing,
 * and both services read null as "no hub".
 */
#[CoversClass(PresenceStreamService::class)]
#[CoversClass(PresencePublisher::class)]
final class AreaWithoutHubBundleTest extends WebTestCase
{
    private const string COOKIE = 'mercureAuthorization';
    private const string NOW = '2026-09-19T07:00:00+03:00';
    private const string CLAIM_REF = '3b0c1f2e-5a44-4a1e-9f0e-2c7b1d9e4a10';

    public function testTheContainerCompilesWithNoHubBundleRegistered(): void
    {
        $this->boot(hubUrl: null);

        $bundles = static::getContainer()->getParameter('kernel.bundles');
        self::assertIsArray($bundles);
        self::assertArrayNotHasKey('MercureBundle', $bundles);
        self::assertFalse(static::getContainer()->has('mercure.hub.default'));
        self::assertInstanceOf(PresenceStreamService::class, static::getContainer()->get('test_public.area.presence_stream'));
    }

    public function testTheOverviewAndTheDashboardAnswerWithNoCookieAndNoStream(): void
    {
        $this->boot(hubUrl: null);
        $this->signIn();
        $area = $this->anArea();

        $this->browser()->request('GET', '/areas/'.$area->getUuidString());
        self::assertSame(Response::HTTP_OK, $this->browser()->getResponse()->getStatusCode());
        self::assertFalse($this->hasCookie(), 'no hub bundle, no subscriber cookie');
        self::assertNull($this->liveStream(), 'no hub bundle, no stream on the plate');

        $this->browser()->request('GET', '/');
        self::assertSame(Response::HTTP_OK, $this->browser()->getResponse()->getStatusCode());
        self::assertFalse($this->hasCookie());
        self::assertNull($this->liveStream());
    }

    public function testTheStreamServiceOffersNoSubscriptionForAnyArea(): void
    {
        $this->boot(hubUrl: null);
        $area = $this->anArea();
        /** @var PresenceStreamService $streams */
        $streams = static::getContainer()->get('test_public.area.presence_stream');

        self::assertNull($streams->forArea(Request::create('/'), $area));
        self::assertNull($streams->forOrganization(Request::create('/')));
    }

    public function testAStoredPingPublishesNothingAndRaisesNothing(): void
    {
        $this->boot(hubUrl: null);
        $area = $this->anArea();
        /** @var StationService $stations */
        $stations = static::getContainer()->get('test_public.area.stations');
        $station = $stations->add($area, 'Eastgate Post', -29.75, -3.2, 'ST-01');
        $person = $this->signInAsPerson();

        $service = $this->service();
        $service->claim($area, $person, [
            'clientRef' => self::CLAIM_REF,
            'occurredAt' => '2026-09-19T06:08:12+03:00',
            'localDate' => '2026-09-19',
            'status' => 'at_post',
            'stationUuid' => $station->getUuidString(),
            'deviceId' => '0f9ca41e',
            'appVersion' => '0.1.0',
            'lat' => -3.2001,
            'lon' => -29.7501,
            'accuracyM' => 8.0,
        ]);

        $service->ping($area, $person, ['positions' => [[
            'clientRef' => 'a71c0000-0000-4000-8000-000000000001',
            'checkinRef' => self::CLAIM_REF,
            'recordedAt' => '2026-09-19T06:50:00+03:00',
            'lat' => -3.2010,
            'lon' => -29.7400,
            'accuracyM' => 8.0,
        ]]]);

        self::assertCount(1, $this->em->getRepository(CheckIn::class)->findAll(), 'the claim is stored');
        self::assertCount(1, $this->em->getRepository(PersonPosition::class)->findAll(), 'the ping is stored');
    }

    /**
     * The write path as the field API wires it, with the publisher built the
     * way the container builds it here: the hub reference resolved to null.
     */
    private function service(): CheckInService
    {
        $checkIns = $this->em->getRepository(CheckIn::class);
        $corrections = $this->em->getRepository(CheckInCorrection::class);
        $positions = $this->em->getRepository(PersonPosition::class);
        $stations = $this->em->getRepository(Station::class);
        self::assertInstanceOf(CheckInRepository::class, $checkIns);
        self::assertInstanceOf(CheckInCorrectionRepository::class, $corrections);
        self::assertInstanceOf(PersonPositionRepository::class, $positions);
        self::assertInstanceOf(StationRepository::class, $stations);
        /** @var CheckInStatusService $statuses */
        $statuses = static::getContainer()->get('test_public.area.checkin_statuses');
        /** @var PresenceService $presence */
        $presence = static::getContainer()->get('test_public.area.presence');

        return new CheckInService(
            $this->em,
            $checkIns,
            $corrections,
            $positions,
            $stations,
            $statuses,
            new PresencePublisher(null, $presence, new MockClock(self::NOW), new NullLogger()),
        );
    }

    private function hasCookie(): bool
    {
        foreach ($this->browser()->getResponse()->headers->getCookies() as $cookie) {
            if (self::COOKIE === $cookie->getName()) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, mixed>|null what the plate was handed under `extra.atlas.live` */
    private function liveStream(): ?array
    {
        $crawler = new Crawler((string) $this->browser()->getResponse()->getContent());
        $extra = $crawler->filter('[data-symfony--ux-leaflet-map--map-extra-value]')->first()
            ->attr('data-symfony--ux-leaflet-map--map-extra-value');
        $decoded = json_decode((string) $extra, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertIsArray($decoded[AtlasMap::EXTRA_KEY]);
        self::assertArrayHasKey('live', $decoded[AtlasMap::EXTRA_KEY]);
        $live = $decoded[AtlasMap::EXTRA_KEY]['live'];
        self::assertTrue(null === $live || \is_array($live));

        /** @var array<string, mixed>|null $live */
        return $live;
    }
}
