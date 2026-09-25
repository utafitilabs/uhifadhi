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

namespace Uhifadhi\Core\Tests\Core;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\CommandLoader\CommandLoaderInterface;
use Uhifadhi\Core\Tests\Application\Kernel;

/**
 * THE CORE'S CONSOLE SURFACE IS FIVE COMMANDS, AND THIS IS WHERE THAT IS TRUE
 * OR NOT.
 *
 * The rule and its exceptions, stated once for all five bundles at once.
 * Everything the platform can be told to do is a screen, because a command is
 * a door with no page and nobody to explain itself to; the five that are not
 * screens are all things a PRODUCTION installation must be able to run
 * without development packages, which is the whole of the exception:
 *
 *   `registry:sync`             the catalogue brought into step with the
 *                               installed module bundles — the third line of
 *                               every install and every upgrade, after the
 *                               migrations and before the warm-up.
 *   `team:user:create`          the first administrator — the one account an
 *                               installation cannot make through a screen,
 *                               because every screen is behind the sign-in it
 *                               creates.
 *   `team:performance:snapshot` what the figures were — run on the first of
 *                               each period, because a closed period cannot be
 *                               recomputed and every movement on the
 *                               performance page is measured against one.
 *   `area:presence:rebuild`     the facts on the check-in rows recomputed
 *                               from the kept pings — after a station's point
 *                               moves or a zone set is replaced.
 *   `uhifadhi:facts:rebuild`    the facts ledger recomputed over a range of
 *                               months — after the deploy that brings a
 *                               module's facts, and after a rule they depend
 *                               on changes; the schedule never recomputes a
 *                               closed period, so this is what does.
 *
 * Every other command the platform has belongs to devkit, which installs
 * through `require-dev` and is absent from a production build.
 *
 * The assertion is about a CATEGORY rather than about one name: whatever the
 * five bundles register under their own namespaces is listed here, so a seeder
 * added next year fails on this line and is sent to devkit.
 *
 * The command loader is the console's own index of what an installation can be
 * told to run: the compiler pass builds it from every `console.command` tag it
 * collected, and `getNames()` is the list a `bin/console list` prints.
 *
 * @see vendor/symfony/console/DependencyInjection/AddConsoleCommandPass.php
 */
final class OneConsoleCommandTest extends KernelTestCase
{
    /**
     * The five bundles' own command namespaces — one per configuration root —
     * and the platform's, which a command spanning every module is named under.
     */
    private const array NAMESPACES = ['registry', 'shell', 'atlas', 'team', 'area', 'uhifadhi'];

    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        // The framework's debug exception handler is registered while a kernel
        // boots and never popped, which PHPUnit reports as a risky test.
        while (true) {
            $previous = set_exception_handler(static fn () => null);
            restore_exception_handler();
            if (null === $previous) {
                break;
            }
            restore_exception_handler();
        }
    }

    public function testTheOnlyCommandsTheCoreShipsAreTheFiveAProductionInstallationRuns(): void
    {
        self::bootKernel();

        $loader = self::getContainer()->get('console.command_loader');
        self::assertInstanceOf(CommandLoaderInterface::class, $loader);

        $ours = array_values(array_filter(
            $loader->getNames(),
            static function (string $name): bool {
                foreach (self::NAMESPACES as $namespace) {
                    if (str_starts_with($name, $namespace.':')) {
                        return true;
                    }
                }

                return false;
            },
        ));

        sort($ours);

        self::assertSame(['area:presence:rebuild', 'registry:sync', 'team:performance:snapshot', 'team:user:create', 'uhifadhi:facts:rebuild'], $ours, \sprintf(
            'The core ships five commands, all of them things a production installation must run; this one carries [%s]. Everything else belongs to devkit.',
            implode(', ', $ours),
        ));
    }
}
