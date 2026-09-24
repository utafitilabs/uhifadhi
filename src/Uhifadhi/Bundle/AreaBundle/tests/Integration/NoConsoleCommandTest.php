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

namespace Uhifadhi\Bundle\AreaBundle\Tests\Integration;

use Symfony\Component\Console\CommandLoader\CommandLoaderInterface;

/**
 * THE CORE'S CONSOLE COMMANDS LIVE ELSEWHERE, AND THIS BUNDLE SHIPS NONE.
 *
 * The exceptions are exact: the registry's `registry:sync`, which brings the
 * catalogue into step after the migrations, and the team bundle's
 * `team:user:create` and `team:performance:snapshot` — all three things a
 * production installation must run without development packages. Nothing
 * else. Everything the platform can otherwise be told to do is something
 * devkit owns, and devkit is a development-only package whose absence from a
 * deployment is enforced by the dependency graph rather than by a flag somebody
 * has to remember to set. A seeder shipped here would be installed on every
 * deployment and reachable by anybody who can reach a shell.
 *
 * The assertion is about a category rather than about one name: nothing this
 * bundle registers carries `console.command`. A second seeder added next year
 * fails on the same line.
 *
 * The command loader is the console's own index of what an installation can be
 * told to run: the compiler pass builds it from every `console.command` tag it
 * collected, and `getNames()` is the list a `bin/console list` prints.
 *
 * @see vendor/symfony/console/DependencyInjection/AddConsoleCommandPass.php
 */
final class NoConsoleCommandTest extends IntegrationTestCase
{
    public function testTheBundleContributesNoConsoleCommand(): void
    {
        $loader = static::getContainer()->get('console.command_loader');
        self::assertInstanceOf(CommandLoaderInterface::class, $loader);

        $mine = array_values(array_filter(
            $loader->getNames(),
            static fn (string $name): bool => str_starts_with($name, 'area:'),
        ));

        self::assertSame([], $mine, \sprintf(
            'The core\'s commands are the registry\'s and the team bundle\'s; this bundle registers [%s]. A seeder belongs in devkit.',
            implode(', ', $mine),
        ));
    }
}
