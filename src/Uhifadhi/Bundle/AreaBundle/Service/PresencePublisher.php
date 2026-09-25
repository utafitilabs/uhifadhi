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

use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Uhifadhi\Bundle\AtlasBundle\Model\LiveMarks;
use Uhifadhi\Contracts\Area\LivePositionsInterface;

/**
 * ONE PERSON'S MARK ON THE WIRE, the moment their handset spoke.
 *
 * The store write is the record; this is a copy of ONE mark of it, published
 * to the area's private Mercure topic the instant a claim, a check-out or a
 * batch of pings is stored, so every plate drawing that area moves the mark
 * without polling. The write never depends on it: {@see CheckInService}
 * flushes first and asks this second, in its own guarded pass.
 *
 * THE WIRE CARRIES THE MARK AND NOTHING MORE. The frame is the very feature
 * the plate draws at page load — {@see LiveMarks::frame()}: the person's key,
 * the point, the name the mark's title shows, the initials it prints, the
 * age, whether it is stale and what makes it so. No email, no station, no
 * claim reference, no battery: a subscriber holding the topic sees exactly
 * what a viewer of the plate already sees. Somebody who left the ground is
 * {@see LiveMarks::gone()}, the same key with nothing to draw.
 *
 * ONE DERIVATION. The position on the wire is read through the same
 * {@see LivePositionsInterface} the plate reads, at the clock's instant, so
 * a mark cannot be verified on the wire and unverified on the page.
 *
 * THE HANDSET NEVER WAITS ON THE HUB. A deployment may have left the hub's
 * address unset, and a configured hub may be down; neither is the ping's
 * problem. An address-less hub publishes nothing at all, and a failing one
 * is logged and swallowed — a lost frame is never a lost ping.
 *
 * The update is the documented private one: "Mercure also allows dispatching
 * updates only to authorized clients. To do so, mark the update as private
 * by setting the third parameter of the `Update` constructor to `true`".
 *
 * @see https://symfony.com/doc/current/mercure.html — "Publishing" and "Authorization"
 * @see vendor/symfony/mercure/src/Hub.php — `publish()` posts topic, data and `private`
 * @see vendor/symfony/mercure/src/Update.php — `__construct($topics, $data, $private)`
 */
final readonly class PresencePublisher
{
    /**
     * THE TOPIC, SPELT ONCE. An area's presence stream is
     * `area/{areaUuid}/presence`; the page that draws the area's plate sets
     * the subscriber cookie for it ({@see PresenceStreamService}) and this
     * publishes to it, and neither spells it again.
     */
    private const string TOPIC = 'area/%s/presence';

    public function __construct(
        private HubInterface $hub,
        private LivePositionsInterface $positions,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public static function topicFor(string $areaUuid): string
    {
        return \sprintf(self::TOPIC, $areaUuid);
    }

    /**
     * Publish one person's current mark in one area — where they are now, or
     * that they are gone. A no-op where the deployment configured no hub
     * address; never throws.
     */
    public function publish(string $areaUuid, string $personUuid): void
    {
        if ('' === $this->hub->getPublicUrl()) {
            return;
        }

        try {
            $this->hub->publish(new Update(
                self::topicFor($areaUuid),
                self::frame($areaUuid, $personUuid),
                private: true,
            ));
        } catch (\Throwable $e) {
            $this->logger->error('area: live presence publish failed', ['area' => $areaUuid, 'exception' => $e]);
        }
    }

    /** The one mark, as the plate draws it, or the same key with nothing to draw. */
    private function frame(string $areaUuid, string $personUuid): string
    {
        $presence = $this->positions->liveIn($areaUuid, $this->clock->now());

        $frame = LiveMarks::gone($personUuid);
        foreach ($presence->positions as $position) {
            if ($position->personUuid === $personUuid) {
                $frame = LiveMarks::frame($presence, $position);
                break;
            }
        }

        return json_encode($frame, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
    }
}
