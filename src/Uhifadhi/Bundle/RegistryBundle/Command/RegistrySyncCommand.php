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

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Uhifadhi\Bundle\RegistryBundle\Service\RegistrySyncService;

/**
 * `registry:sync` — RECONCILE THE CATALOGUE WITH THE INSTALLED MODULES.
 *
 * The one step of an install or an upgrade that is the registry's own:
 *
 *     bin/console cache:clear --no-warmup
 *     bin/console doctrine:migrations:migrate
 *     bin/console registry:sync
 *     bin/console cache:warmup
 *
 * It runs {@see RegistrySyncService} and says what that did — the modules
 * added to the catalogue, the ones kept and refreshed, the ones whose provider
 * is gone and whose rows stay — so a deploy log reads as a ledger rather than a
 * silence. It writes nothing itself: the rules of a reconciliation are the
 * service's, and a command with a second copy of them would drift.
 *
 * BEFORE THE FIRST MIGRATION IT REFUSES, AND SAYS WHAT TO RUN. The registry's
 * tables may not exist yet; the command exits non-zero and names
 * `doctrine:migrations:migrate` as the step that comes first, so an operator
 * who typed the sequence in the wrong order is told rather than left with a
 * catalogue that silently stayed empty.
 *
 * `#[AsCommand]` carries the name and the description:
 *
 *   "You can also use #[AsCommand] to add a description, usage examples, and
 *    longer help text for the command"
 *
 *   @see https://symfony.com/doc/current/console.html
 *
 * The compiler pass reads that attribute off the class whether or not the
 * service was autoconfigured, and registers the command lazily under the name
 * it finds — which is why the service definition carries a bare tag and
 * nothing else:
 *
 *   `$aliases = $tags[0]['command'] ?? $attribute?->name ?? '';`
 *   @see vendor/symfony/console/DependencyInjection/AddConsoleCommandPass.php — registerCommand()
 */
#[AsCommand(
    name: 'registry:sync',
    description: 'Reconcile the module catalogue with the installed module bundles — run after doctrine:migrations:migrate, before cache:warmup',
)]
final class RegistrySyncCommand extends Command
{
    public function __construct(private readonly RegistrySyncService $sync)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setHelp(<<<'HELP'
            Bring the module catalogue into step with the module bundles this
            installation has installed. Run it once after every install and every
            upgrade, after the migrations and before the cache is warmed:

              <info>bin/console doctrine:migrations:migrate</info>
              <info>bin/console %command.name%</info>
              <info>bin/console cache:warmup</info>

            It is idempotent and create-only for the per-area rows: an area's
            on/off choices and ordering are never revisited, and the rows of a
            module whose bundle was removed are named and left in place.

            Before the first migration the registry's tables do not exist, and
            the command exits non-zero and says so.
            HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $result = $this->sync->sync();

        if ($result->skipped) {
            $io->error([
                'The registry tables are not there yet.',
                'Run doctrine:migrations:migrate first, then registry:sync.',
            ]);

            return Command::FAILURE;
        }

        $io->listing([
            \sprintf('added: %s', self::named($result->added)),
            \sprintf('kept: %s', self::named($result->kept)),
            \sprintf('retired (rows kept, provider gone): %s', self::named($result->retired)),
            \sprintf('area rows created: %d', $result->areaAssignments),
        ]);

        $io->success(\sprintf('Registry in step: %d module(s) installed.', $result->modules()));

        return Command::SUCCESS;
    }

    /**
     * @param list<string> $slugs
     */
    private static function named(array $slugs): string
    {
        return [] === $slugs ? 'none' : implode(', ', $slugs);
    }
}
