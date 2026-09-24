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
use Uhifadhi\Bundle\AreaBundle\Model\AreaRow;
use Uhifadhi\Bundle\AreaBundle\Model\CardStat;
use Uhifadhi\Bundle\AreaBundle\Overview\NowTile;
use Uhifadhi\Bundle\AreaBundle\Repository\AreaOfInterestRepository;
use Uhifadhi\Bundle\RegistryBundle\Repository\AreaModuleRepository;

/**
 * WHAT THE REGISTER KNOWS ABOUT EACH AREA — read once, here, rather than
 * assembled in a template.
 *
 * THE AREA PAGE'S OWN FACTS, AND THE MODULES' FIGURES, GATHERED IN ONE PLACE. The
 * the area page owns an area's size (ST_Area on the spheroid, the way a surveyor measures
 * it rather than by multiplying degrees), how many modules it has switched on
 * (the registry's ledger, so switching one off changes the register the same day),
 * and its boundary's face. Every operational figure on a card — the stat cells,
 * the "out right now" chip, the alert flag, the last check-in — is a MODULE's,
 * gathered through the overview contributions {@see AreaOverview} already owns. This
 * service knows what a patrol is no more than the overview does: it lays out
 * contributed values and never names one.
 *
 * ASKED PER AREA, AT ONE MOMENT. `$now` is handed in so every card on the wall
 * is measured against the same clock — two areas' "6 min ago" mean the same
 * thing — and so the register is testable at a fixed time.
 */
final readonly class AreaRegister
{
    /** How many operational figures a card's four-up grid shows beside "modules live". */
    private const int STAT_CELLS = 3;

    /**
     * How far back "last activity" looks. A card's recency is a recent-operations
     * fact, not an archaeology of the area: an area nobody has touched in this
     * window reads as "no recent activity" rather than dredging up a move from
     * two years ago as though it were news.
     */
    private const string ACTIVITY_WINDOW = '-90 days';

    public function __construct(
        private AreaOfInterestRepository $areas,
        private AreaModuleRepository $areaModules,
        private AreaOverview $overview,
        private AreaThumbnailer $thumbnailer,
    ) {
    }

    /**
     * Every area, as the register lists them — BY NAME, because the register is a
     * list a person reads and insertion order is an implementation detail nobody
     * outside the database can see. The client re-sorts by activity on load; the
     * server order is the stable fallback for a viewer with no scripting.
     *
     * @return list<AreaRow>
     */
    public function rows(\DateTimeImmutable $now): array
    {
        $since = $now->modify(self::ACTIVITY_WINDOW);

        $rows = [];
        foreach ($this->areas->findBy([], ['name' => 'ASC']) as $area) {
            $rows[] = $this->cardFor($area, $now, $since);
        }

        return $rows;
    }

    private function cardFor(AreaOfInterest $area, \DateTimeImmutable $now, \DateTimeImmutable $since): AreaRow
    {
        // The now-tiles the overview already gathers, split the way a card reads
        // them: the standing figures fill the grid, the one "right now" figure
        // foots the card. Both come from installed modules; the area page names none.
        $standing = [];
        $liveNow = null;
        foreach ($this->overview->nowTilesFor($area, $now) as $tile) {
            if ($tile->live) {
                $liveNow ??= self::stat($tile);
            } elseif (\count($standing) < self::STAT_CELLS) {
                $standing[] = self::stat($tile);
            }
        }

        $lastActivity = $this->overview->latestActivityFor($area, $since, $now);

        return new AreaRow(
            area: $area,
            areaKm2: $this->areaKm2($area),
            liveModules: \count($this->areaModules->activeForArea($area)),
            thumbnail: $this->thumbnailer->forArea($area),
            stats: $standing,
            liveNow: $liveNow,
            alertCount: \count($this->overview->attentionFor($area, $now)),
            lastActivity: $lastActivity,
            lastActivityLabel: null === $lastActivity ? null : self::ago($lastActivity, $now),
        );
    }

    /**
     * What the register's pills count.
     *
     * ONLY THE ONES THAT CAN MOVE. Live and awaiting-setup are read from the
     * registry's ledger and alerts from the attention contribution, so all three change with
     * the installation rather than being furniture stuck at a constant. A filter
     * that can only ever return everything or nothing teaches somebody the
     * control is broken, so the register draws none such.
     *
     * @param list<AreaRow> $rows
     *
     * @return array{all: int, live: int, alerts: int, setup: int}
     */
    public function counts(array $rows): array
    {
        $live = 0;
        $alerts = 0;
        foreach ($rows as $row) {
            if ($row->isLive()) {
                ++$live;
            }
            if ($row->hasAlerts()) {
                ++$alerts;
            }
        }

        return ['all' => \count($rows), 'live' => $live, 'alerts' => $alerts, 'setup' => \count($rows) - $live];
    }

    /** The area's size on the spheroid, rounded the way the design prints it. */
    public function areaKm2(AreaOfInterest $area): int
    {
        $id = $area->getId();
        if (null === $id) {
            return 0;
        }

        return (int) round($this->areas->stAreaKm2(['id' => $id]));
    }

    /**
     * WHERE THE AREA IS, as a band states it: "3.2°S 29.5°W".
     *
     * HEMISPHERES, NEVER SIGNS. A minus in front of a latitude is a fact
     * about a coordinate system; south is a fact about the place, and it is
     * the one a person reading a band is after.
     */
    public function centroid(AreaOfInterest $area): ?string
    {
        $id = $area->getId();
        $point = null === $id ? null : $this->areas->stCentroid($id);

        if (null === $point) {
            return null;
        }

        [$lat, $lon] = $point;

        return \sprintf(
            '%.1f°%s %.1f°%s',
            abs($lat),
            $lat < 0 ? 'S' : 'N',
            abs($lon),
            $lon < 0 ? 'W' : 'E',
        );
    }

    /** A now-tile as the card reads it: the value with its unit, and its label. */
    private static function stat(NowTile $tile): CardStat
    {
        return new CardStat($tile->value.($tile->unit ?? ''), $tile->label);
    }

    /**
     * How long ago, said the plain way a card foots with — "just now", "6 min
     * ago", "3 h ago", "2 d ago". Coarse on purpose: a card states recency, not a
     * stopwatch, and the exact instant rides the `<time datetime>` for a machine.
     */
    private static function ago(\DateTimeImmutable $at, \DateTimeImmutable $now): string
    {
        $seconds = max(0, $now->getTimestamp() - $at->getTimestamp());

        return match (true) {
            $seconds < 60 => 'just now',
            $seconds < 3600 => intdiv($seconds, 60).' min ago',
            $seconds < 86400 => intdiv($seconds, 3600).' h ago',
            default => intdiv($seconds, 86400).' d ago',
        };
    }
}
