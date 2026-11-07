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

namespace Uhifadhi\Bundle\AreaBundle\Enum;

/**
 * THE THINGS THAT CAN HAPPEN TO A ZONE SET, and the whole of them.
 *
 * THE HISTORY IS LINES, NOT GEOMETRY. No superseded ring is kept anywhere, so
 * an entry cannot be undone and is not meant to be: it answers "what happened
 * to this set, and who", and an earlier scheme is seen again only by importing
 * its file again.
 *
 * A REFUSED FILE IS AN EVENT TOO. Nothing changed, which is exactly why it is
 * worth a line: somebody tried, and the next person needs to know the attempt
 * was made and why it did not take.
 *
 * The backing value is what the column stores, so it is a stable word and not
 * the case name.
 */
enum ZoneEventKind: string
{
    case Imported = 'imported';
    case Renamed = 'renamed';
    case RingReplaced = 'ring_replaced';
    case Removed = 'removed';
    case Cleared = 'cleared';
    case Refused = 'refused';

    /** What the log's kind chip calls a line of this kind. */
    public function label(): string
    {
        return match ($this) {
            self::Imported => 'Imported',
            self::Renamed => 'Renamed',
            self::RingReplaced => 'Boundary replaced',
            self::Removed => 'Removed',
            self::Cleared => 'Cleared',
            self::Refused => 'Refused',
        };
    }

    /** Whether the line is about something that did not happen — the log's one red dot. */
    public function isRefusal(): bool
    {
        return self::Refused === $this;
    }
}
