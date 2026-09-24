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

namespace Uhifadhi\Bundle\TeamBundle\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Uhifadhi\Bundle\TeamBundle\Repository\DepartmentRepository;
use Uhifadhi\Bundle\TeamBundle\Service\DepartmentPerformance;
use Uhifadhi\Bundle\TeamBundle\Service\PerformanceHistory;
use Uhifadhi\Bundle\TeamBundle\Service\StaffingFigures;
use Uhifadhi\Bundle\TeamBundle\Service\TeamSectionOverview;

/**
 * WRITE THE PERIOD DOWN BEFORE IT STOPS BEING TRUE.
 *
 * A CLOSED PERIOD CANNOT BE RECOMPUTED. "Positions filled in July" is not a
 * query anybody can write in September: posts are filled and emptied,
 * people move, a module's records are edited. Every movement, sparkline and
 * rank change on the performance page is a comparison against a period that
 * has closed, so this runs once a period and writes down what was true.
 *
 * WHEN IT RUNS. On the first of each period, as a scheduled task, for the
 * period that has just closed — which is the default when no period is
 * named. By hand at any time to correct a run, and after installing a
 * module so its first period is not a hole. The current period is never
 * snapshotted: it is computed live and it is still moving.
 *
 * IT IS IDEMPOTENT. The same period written twice corrects the history
 * rather than doubling it, because the second run is usually the one
 * somebody wanted.
 *
 * WHAT IT WRITES: the staffing figures every department has whatever it
 * attaches, and whatever the installed modules published for the
 * department. A figure nobody published is written as ABSENT rather than
 * skipped, so a sparkline can draw the hole and a delta can say there is
 * nothing to compare with — neither of which is a nought.
 *
 * @see https://symfony.com/doc/current/console.html — #[AsCommand] names the command; a reusable bundle is not autoconfigured, so config/services.php adds the 'console.command' tag by hand
 * @see vendor/symfony/console/DependencyInjection/AddConsoleCommandPass.php — $aliases = $tags[0]['command'] ?? $attribute?->name, which is why the tag carries no name of its own
 */
#[AsCommand(
    name: 'team:performance:snapshot',
    description: 'Write down what each department’s figures were in a period that has closed',
)]
final class PerformanceSnapshotCommand extends Command
{
    public function __construct(
        private readonly DepartmentRepository $departments,
        private readonly StaffingFigures $staffing,
        private readonly DepartmentPerformance $performance,
        private readonly PerformanceHistory $history,
        private readonly TeamSectionOverview $overview,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'period',
                null,
                InputOption::VALUE_REQUIRED,
                'The period to write, as its key — 2026-08. Left out: the month that has just closed',
            )
            ->setHelp(<<<'HELP'
                Run this on the first of each period, as a scheduled task:

                    0 1 1 * *  php bin/console team:performance:snapshot

                It writes the period that has just closed. Run it by hand with
                <info>--period=2026-08</info> to correct one, and after installing a module so
                that module's first period is a figure rather than a hole.

                The period a page is currently showing is computed live and is never
                written here — it is still moving.
                HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $named = $input->getOption('period');
        $period = \is_string($named) && '' !== $named
            ? $named
            : PerformanceHistory::monthKey(new \DateTimeImmutable('first day of last month'));

        $departments = $this->departments->findAllOrdered();
        $figures = 0;

        foreach ($departments as $department) {
            foreach ($this->staffing->of($department) as $key => $value) {
                $this->history->record($department, $period, $key, $value);
                ++$figures;
            }

            // AND WHAT THE MODULES PUBLISHED, under their own published
            // names. A module that arrives next year needs no schema change
            // to have a history: its key is its key.
            foreach ($this->performance->kpisFor($department) as $kpi) {
                $this->history->record($department, $period, $kpi->moduleSlug.'.'.$kpi->key, $kpi->value);
                ++$figures;
            }
        }

        /*
         * AND THE INSTALLATION'S OWN FIVE, which belong to no department:
         * an account with no position is in none, a posting is the area's,
         * and the tiers are the installation's. The Team overview's
         * movements are comparisons against these rows.
         */
        foreach ($this->overview->figures() as $key => $value) {
            $this->history->recordForInstallation($period, $key, $value);
            ++$figures;
        }

        $io->success(\sprintf(
            '%s written: %d %s, %d %s.',
            $period,
            \count($departments),
            1 === \count($departments) ? 'department' : 'departments',
            $figures,
            1 === $figures ? 'figure' : 'figures',
        ));

        return Command::SUCCESS;
    }
}
