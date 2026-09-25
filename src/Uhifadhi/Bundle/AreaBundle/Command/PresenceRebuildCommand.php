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

namespace Uhifadhi\Bundle\AreaBundle\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Uhifadhi\Bundle\AreaBundle\Repository\AreaOfInterestRepository;
use Uhifadhi\Bundle\AreaBundle\Service\PresenceFactsService;

/**
 * RECOMPUTE THE FACTS ON THE CHECK-IN ROWS FROM THE KEPT PINGS.
 *
 * Every ping folds itself into its watch's row as it arrives; this re-derives
 * those rows from the pings themselves, which are kept. Run it after a
 * station's point is moved or an area's zones are replaced — the distances and
 * the zone of a fix are measured against both — and whenever a row is in
 * doubt. A ring widened or narrowed needs no run: the ring is read when a
 * page reads.
 *
 * IT IS IDEMPOTENT. The same pings give the same rows, so a second run is the
 * same run, and a run can be narrowed to one area and a window of the
 * rangers' own days.
 *
 * @see https://symfony.com/doc/current/console.html — #[AsCommand] names the command; a reusable bundle is not autoconfigured, so config/services.php adds the 'console.command' tag by hand
 * @see vendor/symfony/console/DependencyInjection/AddConsoleCommandPass.php — the tag carries no name of its own; the attribute's is used
 */
#[AsCommand(
    name: 'area:presence:rebuild',
    description: 'Recompute the facts every check-in row carries from the pings its watch sent',
)]
final class PresenceRebuildCommand extends Command
{
    public function __construct(
        private readonly AreaOfInterestRepository $areas,
        private readonly PresenceFactsService $facts,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('area', null, InputOption::VALUE_REQUIRED, 'Only this area, by its uuid')
            ->addOption('from', null, InputOption::VALUE_REQUIRED, 'Only watches of this day or later, as 2026-09-01')
            ->addOption('until', null, InputOption::VALUE_REQUIRED, 'Only watches of this day or earlier, as 2026-09-30')
            ->setHelp(<<<'HELP'
                Every ping writes its facts onto its check-in's row as it arrives. This
                recomputes those rows from the pings, which are kept:

                    php bin/console area:presence:rebuild
                    php bin/console area:presence:rebuild --area=<uuid> --from=2026-09-01 --until=2026-09-30

                Run it after moving a station's point or replacing an area's zones.
                A changed ring needs nothing: verified and unverified are judged when
                a page reads, against the ring as it stands then.
                HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $area = null;
        $areaUuid = $input->getOption('area');
        if (\is_string($areaUuid) && '' !== $areaUuid) {
            $area = $this->areas->findOneBy(['uuid' => $areaUuid]);
            if (null === $area) {
                $io->error(\sprintf('No area has the uuid %s.', $areaUuid));

                return Command::FAILURE;
            }
        }

        try {
            $from = self::day($input->getOption('from'));
            $until = self::day($input->getOption('until'));
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::INVALID;
        }

        $rows = $this->facts->rebuild($area, $from, $until);

        $io->success(\sprintf('%d check-ins recomputed from their pings.', $rows));

        return Command::SUCCESS;
    }

    private static function day(mixed $value): ?\DateTimeImmutable
    {
        if (!\is_string($value) || '' === $value) {
            return null;
        }

        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (false === $day || $day->format('Y-m-d') !== $value) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a day; write it as 2026-09-01.', $value));
        }

        return $day;
    }
}
