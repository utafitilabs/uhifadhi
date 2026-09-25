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

namespace Uhifadhi\Bundle\RegistryBundle\Command;

use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Uhifadhi\Bundle\RegistryBundle\Service\FactRebuildService;
use Uhifadhi\Contracts\Facts\FactPeriod;

/**
 * `uhifadhi:facts:rebuild` — RECOMPUTE THE FACTS LEDGER OVER A RANGE OF MONTHS.
 *
 * WHEN IT RUNS. Once after the deploy that introduces a module's facts, to
 * fill the months before it; and after a rule the figures depend on
 * changes — a zone redrawn, a width changed — for the months it should
 * apply to. The schedule never recomputes a closed period, so this is the
 * only thing that does.
 *
 * IT IS IDEMPOTENT: each figure is filed with an upsert, so a second run
 * replaces the rows of the first.
 *
 * @see https://symfony.com/doc/current/console.html — #[AsCommand] names the command; a reusable bundle is not autoconfigured, so config/services.php adds the 'console.command' tag by hand
 * @see vendor/symfony/console/DependencyInjection/AddConsoleCommandPass.php — the tag carries no name of its own; the attribute's is read
 */
#[AsCommand(
    name: 'uhifadhi:facts:rebuild',
    description: 'Recompute the facts the modules file on the ledger, for a range of months',
)]
final class FactsRebuildCommand extends Command
{
    public function __construct(
        private readonly FactRebuildService $facts,
        private readonly ClockInterface $clock,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('module', null, InputOption::VALUE_REQUIRED, 'One module’s figures only, by its slug')
            ->addOption('subject', null, InputOption::VALUE_REQUIRED, 'One subject only, by its uuid')
            ->addOption('from', null, InputOption::VALUE_REQUIRED, 'The first month, as 2026-07 or a day in it. Left out: the month open now')
            ->addOption('until', null, InputOption::VALUE_REQUIRED, 'The last month, included, as 2026-09 or a day in it. Left out: the month open now')
            ->setHelp(<<<'HELP'
                Every month from <info>--from</info> to <info>--until</info> is recomputed for every module that
                files facts, and so are the quarters and years the range touches for the
                figures a quarter does not add up:

                    php bin/console uhifadhi:facts:rebuild --from=2026-01 --until=2026-09
                    php bin/console uhifadhi:facts:rebuild --module=<slug> --from=2026-09

                Run it after the deploy that brings a module's facts, and after a rule the
                figures depend on changes. The worker's schedule recomputes the periods open
                now and never a closed one; this is how a closed one is corrected.
                HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $now = $this->clock->now();

        try {
            $from = $this->month($input->getOption('from'), $now);
            $until = $this->month($input->getOption('until'), $now);
            $months = FactPeriod::monthsBetween($from, $until);

            $module = $input->getOption('module');
            $subject = $input->getOption('subject');

            $written = $this->facts->rebuild(
                $months,
                \is_string($module) && '' !== $module ? $module : null,
                \is_string($subject) && '' !== $subject ? $subject : null,
                static function (FactPeriod $period, int $filed) use ($io): void {
                    $io->writeln(\sprintf('  %-8s %d %s', $period->key, $filed, 1 === $filed ? 'figure' : 'figures'));
                },
            );
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->success(\sprintf(
            '%s to %s rebuilt: %d %s filed.',
            $months[0]->key,
            $months[\count($months) - 1]->key,
            $written,
            1 === $written ? 'figure' : 'figures',
        ));

        return Command::SUCCESS;
    }

    /** A month key, or any day in the month, or — left out — the instant given. */
    private function month(mixed $option, \DateTimeImmutable $default): \DateTimeImmutable
    {
        if (!\is_string($option) || '' === $option) {
            return $default;
        }

        if (1 === preg_match('/^\d{4}-\d{2}$/', $option)) {
            return FactPeriod::fromKey($option)->from;
        }

        if (1 === preg_match('/^\d{4}-\d{2}-\d{2}$/', $option)) {
            $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $option);
            if (false !== $day) {
                return $day;
            }
        }

        throw new \InvalidArgumentException(\sprintf('"%s" is not a month: write 2026-07, or a day such as 2026-07-15.', $option));
    }
}
