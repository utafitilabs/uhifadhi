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

namespace Uhifadhi\Bundle\TeamBundle\EventListener;

use Uhifadhi\Bundle\RegistryBundle\Event\ModuleInstalledEvent;
use Uhifadhi\Bundle\TeamBundle\Repository\DepartmentRepository;
use Uhifadhi\Bundle\TeamBundle\Service\DepartmentPerformance;
use Uhifadhi\Bundle\TeamBundle\Service\PerformanceHistory;

/**
 * A MODULE THAT ARRIVES IN SEPTEMBER CAN ANSWER FOR JULY — AND NOBODY ASKS IT.
 *
 * The snapshot runs once a period and writes what the installed modules
 * published. A module switched on afterwards was not installed when any of
 * those runs happened, so its first periods are holes: the page would show
 * a module with no past for a year, and every movement of its figures
 * would read "no history yet" long after the module had one.
 *
 * SO INSTALLING A MODULE ASKS IT ABOUT THE PERIODS THAT HAVE ALREADY
 * CLOSED. Its own records are already there — a module is installed on an
 * area that has been working for years — and the KPI seam takes the
 * instant to answer for, which is exactly what makes a past period a
 * question it can be asked.
 *
 * WHAT IT WRITES IS WHAT THE MODULE SAYS, AND NOTHING ELSE. A provider
 * that ignores the instant it is handed will answer every period with
 * today's figure; that is the module's bug and the contract says so in
 * plain words. This listener invents nothing and fills no gap: where a
 * module answers with nothing, the period stays a hole.
 *
 * IT IS NOT THE SNAPSHOT. The command remains the only scheduled writer
 * and the only thing that walks every department for every figure; this
 * asks one newly-present module about the recent past, so the page it
 * appears on has something to compare against.
 *
 * @see https://symfony.com/doc/current/event_dispatcher.html — a listener is a service tagged 'kernel.event_listener' with the event and the method
 * @see vendor/symfony/event-dispatcher/DependencyInjection/RegisterListenersPass.php — the tag attributes this listener is registered with in config/services.php
 */
final readonly class ModuleHistoryListener
{
    /**
     * HOW FAR BACK A NEW MODULE IS ASKED.
     *
     * The six the sparkline draws, and no further: a year of back-filling
     * on the click that installs a module is a click that appears to hang,
     * and the periods beyond the run are not drawn anywhere.
     */
    public const int PERIODS = 6;

    public function __construct(
        private DepartmentRepository $departments,
        private DepartmentPerformance $performance,
        private PerformanceHistory $history,
    ) {
    }

    public function onModuleInstalled(ModuleInstalledEvent $event): void
    {
        $departments = $this->departments->findAllActiveOrdered();
        if ([] === $departments) {
            return;
        }

        $closed = new \DateTimeImmutable('first day of last month');

        foreach (PerformanceHistory::monthsEndingAt($closed, self::PERIODS) as $month) {
            // THE FIGURE AS IT WAS IN THAT MONTH, asked for the middle of
            // it: a provider reading "the period containing this instant"
            // cannot mistake the first second of a month for the last of
            // the one before.
            $asked = new \DateTimeImmutable($month.'-15 12:00:00');

            foreach ($departments as $department) {
                foreach ($this->performance->kpisFor($department, $asked) as $kpi) {
                    if ($kpi->moduleSlug !== $event->slug) {
                        continue;
                    }

                    $this->history->record($department, $month, $kpi->moduleSlug.'.'.$kpi->key, $kpi->value);
                }
            }
        }
    }
}
