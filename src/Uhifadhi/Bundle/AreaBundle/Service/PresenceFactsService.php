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
use Uhifadhi\Bundle\AreaBundle\Entity\CheckIn;
use Uhifadhi\Bundle\AreaBundle\Entity\PersonPosition;
use Uhifadhi\Bundle\AreaBundle\Repository\CheckInRepository;

/**
 * THE FACTS A WATCH'S ROW CARRIES, KEPT IN STEP WITH WHAT ITS HANDSET SENT.
 *
 * A ping batch, a claim with a position, a back-filled position and a
 * correction each move the facts of the one row they belong to, and each is
 * folded in by the caller in the SAME transaction as the write itself — so a
 * reader sees the ping and the fact it moved together or neither. A fold
 * reads the batch and the row, never the pings before them: the cost of a
 * ping is the same at 07:00 as at 18:00.
 *
 * A CORRECTION RE-MEASURES ITS WATCH. It may name another post, and the
 * nearest the watch came is a distance to a post; so the one watch is
 * recomputed from its own pings — bounded by one watch, and rare.
 *
 * THE RECOMPUTE IS A COMMAND. `area:presence:rebuild` re-derives every row
 * from the kept pings: after a station's point is moved, after a zone set is
 * replaced, or whenever a row is in doubt. A ring widened or narrowed needs
 * nothing: the ring is not a fact of the row.
 */
final readonly class PresenceFactsService
{
    public function __construct(
        private CheckInRepository $checkIns,
    ) {
    }

    /**
     * Fold pings just stored — flushed, so they have ids — into their watches'
     * rows, one statement pair per watch in the batch.
     *
     * @param list<PersonPosition> $pings
     */
    public function recordPings(array $pings): void
    {
        /** @var array<int, array{checkIn: CheckIn, ids: list<int>}> $byWatch */
        $byWatch = [];
        foreach ($pings as $ping) {
            $checkIn = $ping->getCheckIn();
            $id = $ping->getId();
            if (null === $checkIn || null === $checkIn->getId() || null === $id) {
                continue;
            }
            $byWatch[$checkIn->getId()] ??= ['checkIn' => $checkIn, 'ids' => []];
            $byWatch[$checkIn->getId()]['ids'][] = $id;
        }

        foreach ($byWatch as $watch) {
            $this->checkIns->foldPings($watch['checkIn'], $watch['ids']);
        }
    }

    /** Fold the check-in's own position in — at the claim, or when one is back-filled. */
    public function recordClaimFix(CheckIn $checkIn): void
    {
        $this->checkIns->foldClaimFix($checkIn);
    }

    /** Re-measure one watch from its own pings — after a correction names another post. */
    public function remeasure(CheckIn $checkIn): void
    {
        $this->checkIns->rebuildFacts(checkIn: $checkIn);
    }

    /**
     * Recompute every row the filters name from the kept pings.
     *
     * @return int how many check-in rows were recomputed
     */
    public function rebuild(?AreaOfInterest $area = null, ?\DateTimeImmutable $from = null, ?\DateTimeImmutable $until = null): int
    {
        return $this->checkIns->rebuildFacts($area, $from, $until);
    }
}
