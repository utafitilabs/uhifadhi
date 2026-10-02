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

use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\Settings\CoreSettings;
use Uhifadhi\Contracts\Settings\SettingsReaderInterface;

/**
 * WHEN SHARED GROUND BETWEEN TWO ZONES IS A SLIVER, AND WHEN IT IS AN OVERLAP.
 *
 * ZONES OF ONE AREA DO NOT SHARE INTERIOR — that is still the rule, and a real
 * overlap is still refused. What changed is where the line is: two rings
 * digitised by hand, or exported twice through different tools, share metres
 * of edge that were meant to touch, and a scheme refused over that is a scheme
 * refused over arithmetic rather than over geography.
 *
 * SMALL ENOUGH IS EITHER OF TWO THINGS. A fraction of the SMALLER ring, so the
 * rule scales with what is being drawn and a big zone cannot quietly swallow a
 * small one; or under a square kilometre outright, so a sliver against a tiny
 * zone is still a sliver. The percentage belongs to the AREA, because how
 * carefully a scheme was drawn is a fact about an installation's survey and not
 * about this product; the absolute floor belongs here, because the arithmetic
 * is the same everywhere.
 *
 * AN ACCEPTED SLIVER IS STORED AS IT ARRIVED. Neither ring is clipped: what
 * they share is answered for by the deterministic tie-break that already
 * answers which zone a point on a shared edge is in — lowest name, then lowest
 * id — so the question "which zone is this in?" has one stable answer without
 * anybody editing geometry to produce it.
 *
 * A RULE WHOSE ONE COLLABORATOR IS THE SETTINGS READER — the tolerance in
 * force for the area, from Settings › Core — so it is unit-testable without a
 * kernel and the same sentence decides for an import, for a redrawn ring and
 * for anything that writes a zone later.
 */
final readonly class ZoneOverlapService
{
    public function __construct(private SettingsReaderInterface $settings)
    {
    }

    /**
     * WHAT AN AREA GETS WHEN IT HAS NOT SAID. One percent of the smaller ring
     * is below anything a surveyor draws on purpose and above what two passes
     * over the same edge produce.
     */
    public const float DEFAULT_TOLERANCE_PCT = 1.0;

    /**
     * The most an installation may set. Ten percent of a zone is a tenth of
     * somewhere, which is a decision about the ground rather than about
     * drawing precision; past that the answer is to fix the scheme.
     */
    public const float MAX_TOLERANCE_PCT = 10.0;

    /**
     * SHARED GROUND THIS SMALL IS NEVER AN OVERLAP, whatever the rings are and
     * whatever the area set. Two rings of two square kilometres each cannot be
     * held to a hundredth of one.
     */
    public const float FLOOR_KM2 = 1.0;

    /** The tolerance in force for this area (Settings › Core), in percent. */
    public function toleranceOf(AreaOfInterest $area): float
    {
        $set = $this->settings->value(CoreSettings::ZONE_OVERLAP_TOLERANCE, $area->getUuidString());

        return \is_int($set) || \is_float($set) ? (float) $set : self::DEFAULT_TOLERANCE_PCT;
    }

    /**
     * @param float $overlapKm2   the ground the two rings share
     * @param float $firstKm2     one ring's own size
     * @param float $secondKm2    the other ring's
     * @param float $tolerancePct the area's setting, as a percentage
     */
    public function isSliver(float $overlapKm2, float $firstKm2, float $secondKm2, float $tolerancePct): bool
    {
        if ($overlapKm2 < self::FLOOR_KM2) {
            return true;
        }

        $smaller = min($firstKm2, $secondKm2);

        return $smaller > 0.0 && $overlapKm2 < $smaller * $tolerancePct / 100.0;
    }
}
