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

namespace Uhifadhi\Bundle\TeamBundle\Service;

use Uhifadhi\Bundle\TeamBundle\Entity\Rank;
use Uhifadhi\Bundle\TeamBundle\Entity\RankScale;
use Uhifadhi\Bundle\TeamBundle\Model\RankBand;
use Uhifadhi\Bundle\TeamBundle\Model\RankQuery;
use Uhifadhi\Bundle\TeamBundle\Model\RankRow;
use Uhifadhi\Bundle\TeamBundle\Repository\RankHoldingRepository;
use Uhifadhi\Bundle\TeamBundle\Repository\RankRepository;
use Uhifadhi\Bundle\TeamBundle\Repository\RankScaleRepository;

/**
 * THE RANKS REGISTER, READ — every rank in use, scale by scale in seniority
 * order, with the number of people holding it now.
 *
 * THE ORDER NUMBER IS THE RANK'S PLACE ON ITS SCALE, not its row on the page:
 * a search that leaves the third rank alone still reads it as 3.
 */
final readonly class RankBoard
{
    public function __construct(
        private RankScaleRepository $scales,
        private RankRepository $ranks,
        private RankHoldingRepository $holdings,
    ) {
    }

    /** @return list<RankBand> the bands the query leaves, empty ones dropped */
    public function bands(RankQuery $query): array
    {
        $holders = $this->holdings->countCurrentByRank();
        $bands = [];

        foreach ($this->scales->findAllOrdered() as $scale) {
            if (null !== $query->scale && $query->scale !== $scale->getUuidString()) {
                continue;
            }

            $rows = [];
            $order = 0;
            foreach ($this->ranks->findActiveByScale($scale) as $rank) {
                ++$order;
                if (!self::matches($rank, $query->q)) {
                    continue;
                }
                $rows[] = new RankRow($rank, $order, $holders[(int) $rank->getId()] ?? 0);
            }

            if ([] === $rows) {
                continue;
            }

            $bands[] = new RankBand(
                $scale,
                $rows,
                \count($rows),
                array_sum(array_map(static fn (RankRow $row): int => $row->holders, $rows)),
            );
        }

        return $bands;
    }

    /** @return array<string, int> ranks in use per scale, keyed by the scale's uuid — the chips count the whole set */
    public function countByScale(): array
    {
        $counts = [];
        foreach ($this->scales->findAllOrdered() as $scale) {
            $counts[(string) $scale->getUuidString()] = \count($this->ranks->findActiveByScale($scale));
        }

        return $counts;
    }

    /** @return list<RankScale> */
    public function scales(): array
    {
        return $this->scales->findAllOrdered();
    }

    private static function matches(Rank $rank, ?string $q): bool
    {
        if (null === $q) {
            return true;
        }

        return str_contains(mb_strtolower($rank->getName()), mb_strtolower($q))
            || str_contains(mb_strtolower($rank->getShortCode()), mb_strtolower($q));
    }
}
