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

namespace Uhifadhi\Bundle\AreaBundle\Model;

/**
 * WHAT ONE WATCH REPORTED, as its check-in row holds it.
 *
 * Facts, never verdicts: how many pings and when the last arrived, the
 * newest fix and how far it lies from the watch's post, and the nearest any
 * fix came to that post. Whether that is "verified" is judged by whoever
 * reads it, against the ring as it stands at the moment of reading.
 *
 * The coordinates are read with `ST_X`/`ST_Y`, not through GeoJSON, so a fix
 * keeps every digit the handset sent.
 */
final readonly class WatchFacts
{
    public function __construct(
        public int $pings = 0,
        public ?\DateTimeImmutable $lastPingAt = null,
        public ?float $latitude = null,
        public ?float $longitude = null,
        public ?\DateTimeImmutable $lastFixAt = null,
        public ?float $lastFixAccuracyM = null,
        public ?int $lastFixBatteryPct = null,
        /** Metres from the newest fix to the watch's post. */
        public ?float $lastFixM = null,
        /** The nearest any fix of the watch came to its post, in metres. */
        public ?float $closestM = null,
    ) {
    }

    /** Whether the watch reported any position at all — a ping or the check-in's own. */
    public function hasFix(): bool
    {
        return null !== $this->latitude && null !== $this->longitude && null !== $this->lastFixAt;
    }
}
