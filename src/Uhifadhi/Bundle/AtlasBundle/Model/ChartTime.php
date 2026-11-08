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
 * THE INDEX AXIS AS TIME. The labels are ISO dates (`2026-11-01`,
 * `2026-11-09T06:00`) — or, on a decimated run, each point's x is its
 * instant in milliseconds — and the axis spaces them as the calendar does:
 * a missing week is a gap, not a neighbour.
 *
 * A UNIT, AND THE HOUSE'S WORDS FOR IT. A tick is written in the unit's
 * short form and the hover in its long one ({@see TimeUnit}); a caller may
 * state either in date-fns tokens where the house's do not fit. Weeks start
 * on Monday.
 */
final readonly class ChartTime
{
    public function __construct(
        public TimeUnit $unit,
        /** A tick's words in date-fns tokens; null is the unit's. */
        public ?string $format = null,
        /** The hover's words in date-fns tokens; null is the unit's. */
        public ?string $tooltip = null,
    ) {
        if ('' === $format || '' === $tooltip) {
            throw new \InvalidArgumentException('A time axis states a date-fns format or none; an empty one writes nothing.');
        }
    }
}
