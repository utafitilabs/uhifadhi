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
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;
use Uhifadhi\Bundle\AreaBundle\Controller\AreaController;
use Uhifadhi\Bundle\AreaBundle\Controller\OrgDashboardController;
use Uhifadhi\Bundle\AreaBundle\Service\PresencePublisher;
use Uhifadhi\Bundle\AreaBundle\Service\PresenceStreamService;
use Uhifadhi\Bundle\AreaBundle\Tests\Integration\Web\Fixtures\FakeRankLadder;
use Uhifadhi\Bundle\AtlasBundle\Model\AtlasMap;

/**
 * THE PAGE THAT DRAWS A LIVE PLATE LETS THE BROWSER SUBSCRIBE, over real HTTP.
 *
 * Two facts leave the server together or not at all: the subscriber cookie
 * scoped to the area's presence topic, and the hub address plus topic in the
 * plate's payload. Both ask the same pair the page asks (`areas.read` on the
 * area), so somebody who may not open the page holds no cookie for it. A
 * deployment with no hub address sets no cookie and hands the plate no
 * stream, and the page renders exactly as it does today.
 */
#[CoversClass(AreaController::class)]
#[CoversClass(OrgDashboardController::class)]
#[CoversClass(PresenceStreamService::class)]
final class AreaOverviewLiveStreamTest extends WebTestCase
{
    private const string COOKIE = 'mercureAuthorization';

    /** The control room's grant follows the one topic every position goes out on. */
    public function testTheControlRoomFollowsTheAreasEveryoneTopicAndThePlateStreamsIt(): void
    {
        $this->boot([...self::ALL_AREA_PERMISSIONS, 'locations.read']);
        $this->signIn();
        $area = $this->anArea();
        $topic = PresencePublisher::topicFor((string) $area->getUuidString()).'/all';

        $this->browser()->request('GET', '/areas/'.$area->getUuidString());

        self::assertSame(Response::HTTP_OK, $this->browser()->getResponse()->getStatusCode());
        self::assertSame([$topic], $this->subscribedTopics());
        self::assertSame(['hub' => WebKernel::HUB_URL, 'topics' => [$topic]], $this->liveStream());
    }

    /**
     * A RANKED PERSON FOLLOWS THEIR OWN PLACE'S TOPIC and nothing else: every
     * position of somebody junior goes out on it, and a peer's or a senior's
     * never does. The cookie is the hub's own authorization, so this is the
     * guarantee, not the page's good manners.
     */
    public function testARankedPersonFollowsOnlyTheirOwnPlacesTopic(): void
    {
        $this->boot();
        $area = $this->anArea();
        $person = $this->signInAsPerson();
        $this->ladder()->place((string) $person->getUuidString(), 5);

        $this->browser()->request('GET', '/areas/'.$area->getUuidString());

        $topic = PresencePublisher::topicFor((string) $area->getUuidString()).'/for/5';
        self::assertSame(Response::HTTP_OK, $this->browser()->getResponse()->getStatusCode());
        self::assertSame([$topic], $this->subscribedTopics());
        self::assertSame(['hub' => WebKernel::HUB_URL, 'topics' => [$topic]], $this->liveStream());
    }

    /** A person without a rank sees nobody: the page answers, and there is nothing to follow. */
    public function testAPersonWithoutARankFollowsNothing(): void
    {
        $this->boot();
        $area = $this->anArea();
        $this->signInAsPerson();

        $this->browser()->request('GET', '/areas/'.$area->getUuidString());

        self::assertSame(Response::HTTP_OK, $this->browser()->getResponse()->getStatusCode());
        self::assertNull($this->cookie(), 'no rank, no grant: no topic to authorize');
        self::assertNull($this->liveStream());
    }

    /** A signed-in principal that is not a person record has no rank either. */
    public function testAViewerWhoIsNoPersonFollowsNothingWithoutTheGrant(): void
    {
        $this->boot();
        $this->signIn();
        $area = $this->anArea();

        $this->browser()->request('GET', '/areas/'.$area->getUuidString());

        self::assertSame(Response::HTTP_OK, $this->browser()->getResponse()->getStatusCode());
        self::assertNull($this->cookie());
    }

    public function testWithoutAHubThePageSetsNoCookieAndThePlateStreamsNothing(): void
    {
        $this->boot(hubUrl: '');
        $this->signIn();
        $area = $this->anArea();

        $this->browser()->request('GET', '/areas/'.$area->getUuidString());

        self::assertSame(Response::HTTP_OK, $this->browser()->getResponse()->getStatusCode());
        self::assertNull($this->cookie(), 'nothing to authorize a subscriber against while the hub has no address');
        self::assertNull($this->liveStream());
    }

    /**
     * The recipe's placeholder hub, or any hub on another origin: the page
     * answers, sets no cookie and streams nothing. A subscriber cookie is
     * scoped to the hub's domain, and a domain that is not this page's is
     * one this page cannot authorize anybody against.
     */
    public function testAHubOnAnotherOriginGetsNoCookieAndNoStreamAndThePageStillAnswers(): void
    {
        $this->boot(hubUrl: 'https://example.com/.well-known/mercure');
        $this->signIn();
        $area = $this->anArea();

        $this->browser()->request('GET', '/areas/'.$area->getUuidString());

        self::assertSame(Response::HTTP_OK, $this->browser()->getResponse()->getStatusCode());
        self::assertNull($this->cookie(), 'a hub on another origin is not ours to authorize a subscriber against');
        self::assertNull($this->liveStream());
    }

    /** The pair the page asks is the pair the stream asks: no `areas.read`, no page and no cookie. */
    public function testSomebodyWithoutTheReadPairGetsNeitherThePageNorACookie(): void
    {
        $this->boot(['zones.read']);
        $this->signIn();
        $area = $this->anArea();

        $this->browser()->request('GET', '/areas/'.$area->getUuidString());

        self::assertSame(Response::HTTP_FORBIDDEN, $this->browser()->getResponse()->getStatusCode());
        self::assertNull($this->cookie());
    }

    /** The dashboard draws every area, so the control room follows every area's everyone topic in one cookie. */
    public function testTheDashboardSubscribesTheControlRoomToEveryAreasEveryoneTopic(): void
    {
        $this->boot([...self::ALL_AREA_PERMISSIONS, 'locations.read']);
        $this->signIn();
        $one = $this->anArea('Northern Conservation Reserve');
        $two = $this->anArea('Southern Conservation Reserve');
        $topics = [
            PresencePublisher::topicFor((string) $one->getUuidString()).'/all',
            PresencePublisher::topicFor((string) $two->getUuidString()).'/all',
        ];

        $this->browser()->request('GET', '/');

        self::assertSame(Response::HTTP_OK, $this->browser()->getResponse()->getStatusCode());
        self::assertSame($topics, $this->subscribedTopics());
        self::assertSame(['hub' => WebKernel::HUB_URL, 'topics' => $topics], $this->liveStream());
    }

    /** And a ranked person follows their place in every area, one topic each. */
    public function testTheDashboardSubscribesARankedPersonToTheirPlaceInEveryArea(): void
    {
        $this->boot();
        $one = $this->anArea('Northern Conservation Reserve');
        $two = $this->anArea('Southern Conservation Reserve');
        $person = $this->signInAsPerson();
        $this->ladder()->place((string) $person->getUuidString(), 3);

        $this->browser()->request('GET', '/');

        self::assertSame([
            PresencePublisher::topicFor((string) $one->getUuidString()).'/for/3',
            PresencePublisher::topicFor((string) $two->getUuidString()).'/for/3',
        ], $this->subscribedTopics());
    }

    private function ladder(): FakeRankLadder
    {
        $ladder = static::getContainer()->get(FakeRankLadder::class);
        self::assertInstanceOf(FakeRankLadder::class, $ladder);

        return $ladder;
    }

    private function cookie(): ?Cookie
    {
        foreach ($this->browser()->getResponse()->headers->getCookies() as $cookie) {
            if (self::COOKIE === $cookie->getName()) {
                return $cookie;
            }
        }

        return null;
    }

    /**
     * The topics the cookie's JWT lets the browser subscribe to — read out of
     * the token's own claims, because a cookie for the wrong topic is a cookie
     * the hub refuses.
     *
     * @return list<string>
     */
    private function subscribedTopics(): array
    {
        $cookie = $this->cookie();
        self::assertNotNull($cookie, 'the subscriber cookie is on the response');

        $parts = explode('.', (string) $cookie->getValue());
        self::assertCount(3, $parts, 'a JWT');
        $claims = json_decode((string) base64_decode(strtr($parts[1], '-_', '+/'), true), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($claims);
        self::assertIsArray($claims['mercure']);
        self::assertIsArray($claims['mercure']['subscribe']);

        $topics = [];
        foreach ($claims['mercure']['subscribe'] as $topic) {
            self::assertIsString($topic);
            $topics[] = $topic;
        }

        return $topics;
    }

    /**
     * What the plate was handed: `extra.atlas.live` on the map element.
     *
     * @return array<string, mixed>|null
     */
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
