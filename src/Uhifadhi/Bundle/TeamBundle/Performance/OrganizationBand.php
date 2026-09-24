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

use Uhifadhi\Contracts\Kpi\FigurePeriod;
use Uhifadhi\Contracts\Performance\PerformanceScope;
use Uhifadhi\Contracts\Performance\PerformanceTopicProviderInterface;

/**
 * THE SIX FIGURES EVERY SCREEN IN THIS SECTION OPENS WITH.
 *
 * ONE BAND, DRAWN ON EVERY SCREEN, AND THE SAME SIX EVERY TIME. It is
 * the answer to "how is the organization doing" — asked once, printed
 * identically above the Overview and above every topic's record — so a
 * reader moving between screens is never re-reading a different six.
 *
 * RULED: PICKED BY KEY FROM THE HOST'S OWN THREE TOPICS, and a module
 * never changes it. A band assembled from whatever topics an
 * installation happened to install would be a different band per
 * installation, and the sentence under it ("every figure here is
 * comparable") would stop being true the day somebody switched a
 * module on.
 *
 * THE SIX, AND WHOSE EACH IS:
 *
 *   Positions filled     staffing.filled      Staffing
 *   People               staffing.people      Staffing
 *   Goals declared       goals.declared       Goals
 *   Needs attention      attention.raised     Attention
 *   Records this period  attention.records    Attention
 *   Measuring            attention.measuring  Attention
 *
 * A FIGURE ITS TOPIC DOES NOT PUBLISH IS NOT A NOUGHT. The slot keeps
 * its name and says it has no figure, because a band that quietly
 * dropped one would be five figures wide on one installation and six
 * on another, and nobody could tell which.
 */
final readonly class OrganizationBand
{
    /** The six, in the order the design reads them, and the label each wears here. */
    public const array FIGURES = [
        'staffing.filled' => 'Positions filled',
        'staffing.people' => 'People',
        'goals.declared' => 'Goals declared',
        'attention.raised' => 'Needs attention',
        'attention.records' => 'Records this period',
        'attention.measuring' => 'Measuring',
    ];

    /**
     * @param list<PerformanceTopicProviderInterface> $topics
     *
     * @return list<array{label: string, figure: string, caption: string, delta: string, tone: string}>
     */
    public function build(array $topics, PerformanceScope $scope, FigurePeriod $period): array
    {
        $published = [];
        foreach ($topics as $topic) {
            // ONLY THE HOST'S. A module publishing a figure under one of
            // these keys would be a module editing the organization's
            // own band, which is the one thing this band is not.
            if (PerformanceTopicProviderInterface::HOST !== $topic->moduleSlug()) {
                continue;
            }

            foreach ($topic->kpis($scope, $period) as $kpi) {
                $published[$kpi->key] = $kpi;
            }
        }

        $band = [];
        foreach (self::FIGURES as $key => $label) {
            $kpi = $published[$key] ?? null;

            $band[] = [
                'label' => $label,
                'figure' => null === $kpi || !$kpi->isKnown() ? '' : Figures::figure((float) $kpi->value),
                'caption' => $kpi->caption ?? '',
                'delta' => null === $kpi ? '' : Figures::delta($kpi->delta),
                'tone' => null === $kpi ? '' : Figures::tone($kpi->delta, $kpi->polarity),
            ];
        }

        return $band;
    }
}
