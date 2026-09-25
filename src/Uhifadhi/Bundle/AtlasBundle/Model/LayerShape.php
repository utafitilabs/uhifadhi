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
 * How a layer's features are drawn, and therefore what its legend swatch looks
 * like.
 *
 * A module states what the geometry MEANS, not how thick the stroke is.
 * Stroke widths, opacities and radii are the plate's, so two modules cannot
 * disagree about what "a line" looks like.
 *
 * THE LIVE SHAPES ARE ONE MARK IN THREE STATES, not three marks. A position
 * whose last ping is younger than two ping intervals is live; older and it is
 * the SAME dot, dimmed and still; a person with no fix at all draws nothing on
 * the ground and is stated in the key instead, which is why the third state
 * exists here at all — it is a legend row for a mark that is deliberately
 * absent. The accent is reserved for the first of them, everywhere.
 */
enum LayerShape: string
{
    /** A stroke with no fill — a track, a route, a river. */
    case Line = 'line';

    /** A filled shape with a stroke — a zone, a block, a catchment. */
    case Fill = 'fill';

    /** A circle marker per feature — a station, a sighting, a sample. */
    case Point = 'point';

    /** Somebody's last known position, and it is current. */
    case Live = 'live';

    /** The same position, older than two ping intervals: shown, not believed. */
    case LiveStale = 'live-stale';

    /** Nobody's position — a key row for a mark the plate draws nowhere. */
    case LiveAbsent = 'live-absent';

    /** The pin a picking plate places — a key row for the one mark the plate draws on a click. */
    case Pin = 'pin';

    /**
     * The live dot's classes for this shape, or null where the shape is not
     * one of the dot's states.
     *
     * The mark is the shell's primitive (`.livedot`), so what varies between
     * the three is a modifier and nothing else — which is the point of them
     * being one enum rather than three drawings.
     */
    public function liveDot(): ?string
    {
        return match ($this) {
            self::Live => 'livedot',
            self::LiveStale => 'livedot stale',
            self::LiveAbsent => 'livedot none',
            default => null,
        };
    }
}
