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

namespace Uhifadhi\Bundle\AreaBundle\Service;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mercure\Authorization;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\Model\PresenceSubscription;
use Uhifadhi\Bundle\AreaBundle\Repository\AreaOfInterestRepository;
use Uhifadhi\Bundle\AtlasBundle\Model\LiveStream;

/**
 * WHAT A PAGE NEEDS TO LET ITS PLATE KEEP THE LIVE MARKS MOVING: the two
 * facts the plate carries to the browser, and the cookie that lets the
 * browser subscribe.
 *
 * SAME-ORIGIN SUBSCRIBER AUTHORIZATION. The page and the hub are served from
 * one origin, so a cookie scoped to the areas' presence topics is all the
 * browser's EventSource needs; the page sets it on its own response and the
 * plate connects with credentials. The documented mechanism: "To provide
 * this JWT, the subscriber can use a cookie, or an `Authorization` HTTP
 * header" and "MercureBundle provides a convenient service, `Authorization`,
 * to do so" — used here through {@see Authorization::createCookie()}, so the
 * page decides where the cookie goes.
 *
 * THE COOKIE AND THE LAYER ASK THE SAME PAIR. Live positions are drawn under
 * `areas.read` — the pair the organization dashboard enforces to draw
 * everybody and the pair an area's overview enforces to open — so a topic is
 * offered only for an area the viewer holds that pair on. An organization-wide
 * page subscribes to every area the viewer may read, in ONE cookie, because
 * the hub reads one.
 *
 * NOTHING TO AUTHORIZE A SUBSCRIBER AGAINST WHILE THE HUB HAS NO ADDRESS: a
 * deployment that configured none gets no cookie and a plate with no stream,
 * which is the plate exactly as the page drew it.
 *
 * @see https://symfony.com/doc/current/mercure.html — "Authorization", "Programmatically Setting The Cookie"
 * @see vendor/symfony/mercure/src/Authorization.php — `createCookie(Request, array $grants)`
 * @see vendor/symfony/mercure-bundle/src/DependencyInjection/MercureExtension.php — registers `Authorization::class`
 */
final readonly class PresenceStreamService
{
    /**
     * THE PAIR A LIVE POSITION IS READ UNDER — the same one the dashboard and
     * the area overview enforce to draw the plate the marks are on.
     */
    public const string PAIR = 'areas.read';

    public function __construct(
        private HubInterface $hub,
        private Authorization $authorization,
        private AuthorizationCheckerInterface $checker,
        private AreaOfInterestRepository $areas,
    ) {
    }

    /** The subscription for one area's plate, or null where there is nothing to subscribe to. */
    public function forArea(Request $request, AreaOfInterest $area): ?PresenceSubscription
    {
        return $this->open($request, [$area]);
    }

    /** The subscription for the organization's plate: every area the viewer may read. */
    public function forOrganization(Request $request): ?PresenceSubscription
    {
        return $this->open($request, $this->areas->findAllOrdered());
    }

    /**
     * @param iterable<AreaOfInterest> $areas
     */
    private function open(Request $request, iterable $areas): ?PresenceSubscription
    {
        $hub = $this->hub->getPublicUrl();
        if ('' === $hub) {
            return null;
        }

        $topics = [];
        foreach ($areas as $area) {
            if ($this->checker->isGranted(self::PAIR, $area)) {
                $topics[] = PresencePublisher::topicFor((string) $area->getUuidString());
            }
        }
        if ([] === $topics) {
            return null;
        }

        return new PresenceSubscription(
            new LiveStream($hub, $topics),
            $this->authorization->createCookie($request, $topics),
        );
    }
}
