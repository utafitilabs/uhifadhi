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

namespace Uhifadhi\Bundle\AtlasBundle\Model;

/**
 * WHERE A PLATE'S LIVE MARKS KEEP COMING FROM, once the page is drawn.
 *
 * Two facts, stated by whoever built the map: the public address of the
 * Mercure hub the browser can reach, and the topics to hold open on it. The
 * plate opens one credentialed `EventSource` on the hub with every topic as a
 * `topic` query parameter, and each frame it receives is one live mark to
 * move, add or take off — the same feature shape {@see LiveMarks} draws at
 * page load.
 *
 * THE ATLAS KNOWS NOTHING OF WHAT A TOPIC MEANS. An area names its own topic
 * and sets the subscriber cookie that authorizes the browser on it; the atlas
 * carries the two strings to the browser and draws what arrives. That is what
 * keeps this class free of any area, roster or person.
 *
 * NO STREAM IS THE ORDINARY CASE. A deployment with no hub address states no
 * stream, and the plate behaves exactly as a plate with none: the marks stay
 * where the page put them.
 *
 * The public address is what the documentation names the hub's public URL —
 * "`MERCURE_PUBLIC_URL` the publicly available URL (e.g.
 * `https://example.com/.well-known/mercure`)" — and the subscription the
 * browser makes is the documented one: "the subscriber can use a cookie, or an
 * `Authorization` HTTP header" and connects with `withCredentials: true`.
 *
 * @see https://symfony.com/doc/current/mercure.html — "Configuration" and "Authorization"
 * @see assets/controllers/map_plate_controller.js — `subscribe()`
 */
final readonly class LiveStream
{
    /** @var list<string> */
    public array $topics;

    /**
     * @param string       $hub    the hub's PUBLIC address, as the browser reaches it
     * @param list<string> $topics the topics to hold open, at least one
     */
    public function __construct(
        public string $hub,
        array $topics,
    ) {
        if ('' === $hub) {
            throw new \InvalidArgumentException('A live stream needs the hub\'s public address; a deployment with no hub states no stream.');
        }
        if ([] === $topics) {
            throw new \InvalidArgumentException('A live stream needs at least one topic to hold open.');
        }

        $this->topics = $topics;
    }

    /**
     * The two facts as the plate controller reads them off `extra.atlas.live`.
     *
     * @return array{hub: string, topics: list<string>}
     */
    public function toArray(): array
    {
        return ['hub' => $this->hub, 'topics' => $this->topics];
    }
}
