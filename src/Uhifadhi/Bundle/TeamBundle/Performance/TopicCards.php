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

namespace Uhifadhi\Bundle\TeamBundle\Performance;

use Uhifadhi\Bundle\AtlasBundle\Model\SparkSize;
use Uhifadhi\Contracts\Kpi\FigurePeriod;
use Uhifadhi\Contracts\Performance\PerformanceScope;
use Uhifadhi\Contracts\Performance\PerformanceTopicProviderInterface;
use Uhifadhi\Contracts\Performance\TopicLedgerInterface;
use Uhifadhi\Contracts\Performance\TopicMovementInterface;

/**
 * THE OVERVIEW'S TOP ROW: one card a topic, and where its headline
 * figure moved.
 *
 * THE TOPIC'S OWN FIRST FIGURE, the same rule the overview's matrix
 * uses for its columns — a topic decided which of its five leads, and
 * the host does not get a second opinion about somebody else's figures.
 *
 * A TOPIC WITH NO FIGURE YET IS STILL A CARD. Dropping it would make
 * the row shorter on a quiet month, and a reader cannot tell a short
 * row from a missing topic; the card says "no figure yet" in words.
 */
final readonly class TopicCards
{
    /**
     * THE OVERVIEW'S STRIP — one card a topic, FOUR of them.
     *
     * A figure row is four to a row (ruled), and this one had five: on a
     * small laptop the fifth wrapped and the strip read as four plates and
     * an orphan. What gives way is not decided here — a topic whose reading
     * is a LEDGER rather than one moving number says so itself
     * ({@see TopicLedgerInterface}), because it knows what kind of reading
     * it has and this class is not allowed to know which topic is which.
     *
     * IT IS NOT A TRUNCATION. The topic keeps its row in the sidebar, its
     * card on the Topics register and its own record; what it declines is a
     * slot on the one strip that asks every topic for a single number.
     *
     * @param list<PerformanceTopicProviderInterface> $topics in the page's order
     * @param (\Closure(string): ?string)|null        $urlFor the way into one topic, by its key
     *
     * @return list<TopicCard>
     */
    public function strip(
        array $topics,
        PerformanceScope $scope,
        FigurePeriod $period,
        ?\Closure $urlFor = null,
    ): array {
        return $this->build(
            array_values(array_filter(
                $topics,
                static fn (PerformanceTopicProviderInterface $topic): bool => !$topic instanceof TopicLedgerInterface,
            )),
            $scope,
            $period,
            $urlFor,
        );
    }

    /**
     * EVERY TOPIC, one card each — the Topics register's own list, where a
     * ledger belongs like any other.
     *
     * @param list<PerformanceTopicProviderInterface> $topics in the page's order
     * @param (\Closure(string): ?string)|null        $urlFor the way into one topic, by its key
     *
     * @return list<TopicCard>
     */
    public function build(
        array $topics,
        PerformanceScope $scope,
        FigurePeriod $period,
        ?\Closure $urlFor = null,
    ): array {
        $cards = [];
        foreach ($topics as $topic) {
            $kpi = $topic->kpis($scope, $period)[0] ?? null;
            $byModule = PerformanceTopicProviderInterface::HOST !== $topic->moduleSlug();
            $url = null === $urlFor ? null : $urlFor($topic->key());
            // A TOPIC THAT CANNOT WRITE A SENTENCE IS NOT ASKED, and one
            // with nothing worth saying answers null.
            $movement = $topic instanceof TopicMovementInterface ? $topic->movement($scope, $period) : null;

            if (null === $kpi) {
                $cards[] = new TopicCard(
                    key: $topic->key(),
                    title: $topic->title(),
                    label: '',
                    figure: '',
                    word: 'publishes no figure',
                    byModule: $byModule,
                    url: $url,
                    movement: $movement,
                );

                continue;
            }

            $cards[] = new TopicCard(
                key: $topic->key(),
                title: $topic->title(),
                label: $kpi->label,
                figure: null === $kpi->value ? '' : Figures::figure($kpi->value),
                unit: $kpi->unit,
                delta: Figures::delta($kpi->delta),
                deltaTone: Figures::tone($kpi->delta, $kpi->polarity),
                spark: Figures::line($kpi->history, Figures::sparkTone($kpi->delta, $kpi->polarity), SparkSize::Card),
                // A FIGURE NOBODY PUBLISHED SAYS SO IN WORDS. A blank
                // card reads as a nought, and a nought is a measurement.
                word: null === $kpi->value ? 'no figure yet' : '',
                caption: $kpi->caption,
                byModule: $byModule,
                url: $url,
                movement: $movement,
            );
        }

        return $cards;
    }
}
