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
 * THIS BUNDLE SHIPS ONE CONSOLE COMMAND: `area:presence:rebuild`.
 *
 * It recomputes the facts each check-in row carries from the pings, which a
 * production installation must be able to do without development packages —
 * after a station's point is moved or a zone set is replaced. That is the
 * whole of the exception. Everything else the platform can be told to do is
 * a screen or belongs to devkit, a development-only package whose absence
 * from a deployment is enforced by the dependency graph rather than by a flag
 * somebody has to remember to set. A seeder shipped here would be installed
 * on every deployment and reachable by anybody who can reach a shell.
 *
 * The assertion is about a category rather than about one name: whatever this
 * bundle registers under `area:` is listed, so a second command added next
 * year fails on the same line.
 *
 * The command loader is the console's own index of what an installation can be
 * told to run: the compiler pass builds it from every `console.command` tag it
 * collected, and `getNames()` is the list a `bin/console list` prints.
 *
 * @see vendor/symfony/console/DependencyInjection/AddConsoleCommandPass.php
 */
final class PresenceRebuildIsTheOnlyCommandTest extends IntegrationTestCase
{
    public function testTheBundleContributesOnlyThePresenceRebuild(): void
    {
        $loader = static::getContainer()->get('console.command_loader');
        self::assertInstanceOf(CommandLoaderInterface::class, $loader);

        $mine = array_values(array_filter(
            $loader->getNames(),
            static fn (string $name): bool => str_starts_with($name, 'area:'),
        ));

        self::assertSame(['area:presence:rebuild'], $mine, \sprintf(
            'This bundle registers the presence rebuild and nothing else; it registers [%s]. A seeder belongs in devkit.',
            implode(', ', $mine),
        ));
    }
}
