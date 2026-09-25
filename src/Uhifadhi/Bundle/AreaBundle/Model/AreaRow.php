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

use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AtlasBundle\Model\Thumbnail;

/**
 * ONE AREA AS THE REGISTER DRAWS IT — a card: the entity's identity, the two
 * facts the area page owns about its operations (how many modules are live, and when
 * it was last touched), and the operational figures a module contributed for it.
 *
 * THE AREA PAGE OWNS THE FRAME, A MODULE OWNS THE CONTENT. The card's face, its
 * identity line, its "modules live" count and its "last check-in" are the area page's;
 * every operational figure on it — the stat cells, the "out right now" chip, the
 * alert count — arrived through the overview contributions and is a value the area page lays
 * out without understanding. That is what lets a card read honestly when a module
 * leaves: its figures simply stop arriving, and no hard-coded cell is left behind.
 *
 * A value object rather than an array so the template reads named things and
 * phpstan can see them; built once per render and thrown away.
 */
final readonly class AreaRow
{
    /**
     * @param list<CardStat> $stats      the operational figures for the four-up grid, in the
     *                                   order the modules' now-tiles arrived — the area page's own
     *                                   "modules live" is prepended by the template, not here
     * @param CardStat|null  $liveNow    the one "right now" figure a card foots with, from a
     *                                   module's live now-tile, or null when nothing is live here
     * @param int            $alertCount how many things are asking for attention here, from the
     *                                   attention contribution — the card's alert flag and the "With
     *                                   alerts" pill both read it
     */
    public function __construct(
        public AreaOfInterest $area,
        public int $areaKm2,
        public int $liveModules,
        public Thumbnail $thumbnail,
        public array $stats = [],
        public ?CardStat $liveNow = null,
        public int $alertCount = 0,
        public ?\DateTimeImmutable $lastActivity = null,
        public ?string $lastActivityLabel = null,
    ) {
    }

    /**
     * Whether anything is actually running here.
     *
     * AN AREA WITH NO MODULES IS NOT BROKEN, it is new — the card says "boundary
     * set, no modules yet" rather than drawing a grid of noughts, because the
     * first thing a fresh installation has is exactly this.
     */
    public function isLive(): bool
    {
        return $this->liveModules > 0;
    }

    /**
     * THE SAME FIGURES, KEYED BY WHAT THEY MEASURE — how a TABLE has to read
     * them, because a table has columns and a card does not.
     *
     * THE REGISTER DRAWS ONE COLUMN PER FIGURE, headed from the first live
     * area, and every other row fills those columns. Read by POSITION that is
     * wrong twice over: an area running fewer modules has fewer figures, so
     * the row either prints its numbers under somebody else's headings or —
     * with strict variables, which is how the product runs — asks for an index
     * that is not there and takes the whole page down with it. Neither is a
     * rendering of the truth, and the truth is that a column is a LABEL: an
     * area that contributes nothing for it has nothing to say there.
     *
     * @return array<string, string> the figure's label => its value
     */
    public function statsByLabel(): array
    {
        $byLabel = [];
        foreach ($this->stats as $stat) {
            $byLabel[$stat->label] = $stat->value;
        }

        return $byLabel;
    }

    /** Whether this area is asking for attention — the card flags it, the pill counts it. */
    public function hasAlerts(): bool
    {
        return $this->alertCount > 0;
    }

    /**
     * A monotonic recency key for the "last activity" sort — the last check-in as
     * a Unix timestamp, or 0 for an area that has done nothing. The register sorts
     * on it descending, so the areas that moved most recently rise and the
     * boundary-only ones settle to the bottom.
     */
    public function activityRank(): int
    {
        return $this->lastActivity?->getTimestamp() ?? 0;
    }
}
