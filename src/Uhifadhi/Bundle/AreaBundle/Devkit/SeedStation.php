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

namespace Uhifadhi\Bundle\AreaBundle\Devkit;

/**
 * ONE POST OF THE SEED GROUND — its name, its code, where it stands, and the two
 * facts its page prints beside the map.
 *
 * A ROW, NOT A SERVICE. It is read only by {@see StationContentProvider}, holds
 * no behaviour and is never persisted; the station it describes is written
 * through {@see \Uhifadhi\Bundle\AreaBundle\Service\StationService} like any
 * other.
 *
 * WHERE IT STANDS IS A POINT ON THE GROUND ITSELF, longitude then latitude, as
 * everything here is: each reserve has posts of its own — see {@see SeedArea}.
 */
final readonly class SeedStation
{
    public function __construct(
        public string $name,
        public string $code,
        public float $longitude,
        public float $latitude,
        public int $elevationM,
        public string $locality,
        /**
         * HOW MANY PEOPLE WORK OUT OF IT, the first of them its leader.
         *
         * POSTS ARE NOT THE SAME SIZE. A main gate holds five and a
         * roadside marker holds nobody, and a seed that staffed every post
         * with the same two made every screen that ranks, groups or
         * compares posts draw a flat line — which is the one thing those
         * screens exist not to draw. Zero is a post nobody works out of,
         * which is a state, not a gap.
         */
        public int $posted = 2,
        /**
         * WHAT "INSIDE THIS POST" MEANS, in metres — and posts differ here
         * too. A main gate's ground is wide and a roadside marker's is a
         * lay-by; a seed that gave every post the same ring would make the
         * verified/unverified reading look like a setting rather than a
         * fact about the place.
         *
         * The design's own numbers: 1.5 km at the gates and the big posts,
         * 1 km at the smaller ones.
         */
        public int $catchmentM = 1500,
    ) {
    }
}
