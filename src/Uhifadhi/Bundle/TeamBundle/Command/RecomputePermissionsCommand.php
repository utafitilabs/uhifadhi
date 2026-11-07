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
use Uhifadhi\Bundle\TeamBundle\Enum\PermissionRunTriggerEnum;
use Uhifadhi\Bundle\TeamBundle\Permissions\PermissionRunService;

/**
 * THE DEPLOY'S RUN. After a deploy the positions, the catalogue and the
 * voters may all be new, so the deploy asks for a run; the worker answers it.
 * `--now` runs it in this process instead and says what it filed.
 */
#[AsCommand(name: 'team:permissions:recompute', description: 'Ask for a permissions run (the worker answers it), or run one now with --now.')]
final class RecomputePermissionsCommand extends Command
{
    public function __construct(
        private readonly PermissionRunService $runs,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('now', null, InputOption::VALUE_NONE, 'Run it in this process rather than in the worker.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $run = $this->runs->requestRun(PermissionRunTriggerEnum::Deploy, null);

        if (true !== $input->getOption('now')) {
            $io->success(\sprintf('Permissions run %d asked for; the worker answers it.', (int) $run->getId()));

            return Command::SUCCESS;
        }

        $done = $this->runs->handle($run->getId()) ?? $run;
        $io->success(\sprintf(
            'Permissions run %d: %d people, %d permissions, %d places, %d questions in %d shapes, %d answers, %d findings, %d drift, %d ms.',
            (int) $done->getId(),
            $done->getCount('people'),
            \count($done->getPairs()),
            \count($done->getPlaces()),
            $done->getCount('questions'),
            $done->getCount('shapes'),
            $done->getCount('answers'),
            $done->getCount('findings'),
            $done->getCount('drift'),
            $done->getDurationMs() ?? 0,
        ));

        return Command::SUCCESS;
    }
}
