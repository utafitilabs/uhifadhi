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
 * Three facts, stated by whoever built the map: the public address of the
 * Mercure hub the browser can reach, the topics to hold open on it, and the
 * query parameter the hub's protocol takes them on — `topic` on Mercure 0.x,
 * `match` on 1.0 ("the bare `match` parameter selects the default `exact`
 * matcher type", https://mercure.rocks/spec). The plate opens one credentialed
 * `EventSource` on the hub with every topic on that parameter, and each frame
 * it receives is one live mark to move, add or take off — the same feature
 * shape {@see LiveMarks} draws at page load.
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
    /** The parameter a Mercure 0.x hub takes a subscription's topics on. */
    public const string TOPIC = 'topic';

    /** The parameter a Mercure 1.0 hub takes them on: the default, exact, matcher. */
    public const string MATCH = 'match';

    /** @var list<string> */
    public array $topics;

    /**
     * @param string       $hub       the hub's PUBLIC address, as the browser reaches it
     * @param list<string> $topics    the topics to hold open, at least one
     * @param string       $parameter the query parameter the hub takes them on ({@see parameterFor()})
     */
    public function __construct(
        public string $hub,
        array $topics,
        public string $parameter = self::TOPIC,
    ) {
        if (!\in_array($parameter, [self::TOPIC, self::MATCH], true)) {
            throw new \InvalidArgumentException(\sprintf('A hub takes its topics on "%s" or "%s", not "%s".', self::TOPIC, self::MATCH, $parameter));
        }
        if ('' === $hub) {
            throw new \InvalidArgumentException('A live stream needs the hub\'s public address; a deployment with no hub states no stream.');
        }
        if ([] === $topics) {
            throw new \InvalidArgumentException('A live stream needs at least one topic to hold open.');
        }

        $this->topics = $topics;
    }

    /**
     * The parameter a hub speaking this protocol version takes topics on: the
     * version as `symfony/mercure` names it (`ProtocolVersion::value`, "0.x"
     * or "1.0"), so the atlas reads a string and requires no Mercure package.
     */
    public static function parameterFor(string $protocolVersion): string
    {
        return str_starts_with($protocolVersion, '0.') ? self::TOPIC : self::MATCH;
    }

    /**
     * The three facts as the plate controller reads them off `extra.atlas.live`.
     *
     * @return array{hub: string, topics: list<string>, parameter: string}
     */
    public function toArray(): array
    {
        return ['hub' => $this->hub, 'topics' => $this->topics, 'parameter' => $this->parameter];
    }
}
