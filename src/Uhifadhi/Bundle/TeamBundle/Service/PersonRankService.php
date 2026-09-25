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
use Uhifadhi\Bundle\TeamBundle\Entity\RankHolding;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Bundle\TeamBundle\Repository\RankHoldingRepository;
use Uhifadhi\Bundle\TeamBundle\Repository\RankRepository;
use Uhifadhi\Bundle\TeamBundle\Repository\RankScaleRepository;

/**
 * ONE PERSON'S RANK, as their record reads it and their configure page
 * writes it: the rank held now, the rows it was held before, and the ranks
 * that may be given — nothing at all while the organization does not use
 * ranks.
 */
final readonly class PersonRankService
{
    public function __construct(
        private TeamSettingsService $settings,
        private RankService $ranks,
        private RankScaleRepository $scales,
        private RankRepository $rankRepository,
        private RankHoldingRepository $holdings,
    ) {
    }

    public function usesRanks(): bool
    {
        return $this->settings->current()->usesRanks();
    }

    /** @return list<RankHolding> the ranks held, the one held now first; empty while ranks are off */
    public function historyOf(User $person): array
    {
        return $this->usesRanks() ? $this->holdings->findByPersonNewestFirst($person) : [];
    }

    /**
     * THE RANKS THAT MAY BE GIVEN, scale by scale in seniority order; each run
     * carries its scale's name only when there are several scales.
     *
     * @return list<array{head: ?string, ranks: list<Rank>}>
     */
    public function choices(): array
    {
        $scales = $this->scales->findAllOrdered();
        $several = \count($scales) > 1;
        $runs = [];
        foreach ($scales as $scale) {
            $ranks = $this->rankRepository->findActiveByScale($scale);
            if ([] !== $ranks) {
                $runs[] = ['head' => $several ? $scale->getName() : null, 'ranks' => $ranks];
            }
        }

        return $runs;
    }

    /** The rank with this identifier that may still be given, or null. */
    public function rankFor(string $uuid): ?Rank
    {
        $rank = $this->rankRepository->findOneBy(['uuid' => $uuid, 'retiredAt' => null]);

        return $rank instanceof Rank ? $rank : null;
    }

    public function assign(User $person, ?Rank $rank, \DateTimeImmutable $since, ?User $recordedBy): void
    {
        $this->ranks->assign($person, $rank, $since, $recordedBy);
    }
}
