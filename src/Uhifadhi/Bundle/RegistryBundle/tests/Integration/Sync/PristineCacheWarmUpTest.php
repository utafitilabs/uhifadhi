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

namespace Uhifadhi\Bundle\RegistryBundle\Tests\Integration\Sync;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Container\ContainerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Uhifadhi\Bundle\RegistryBundle\Tests\Integration\Fixtures\DeployedHostKernel;
use Uhifadhi\Bundle\RegistryBundle\Tests\Integration\Fixtures\HostKernel;
use Uhifadhi\Bundle\RegistryBundle\Tests\Integration\InstallationTestCase;

/**
 * A DEPLOY, AS THE OPERATOR RUNS IT: `registry:sync` and then the cache
 * commands, without debug, on a cache directory that holds nothing.
 *
 * That is the state a production image is built in, and it is the one state the
 * rest of this suite cannot reach: every other specification boots a debug
 * kernel, where doctrine-bundle registers no metadata cache warmer at all, so
 * the rule that warmer enforces is invisible to them.
 *
 * The rule is that NOBODY MAY LOAD ORM METADATA BEFORE IT DOES. It refuses to
 * build its PHP-array metadata cache from a factory somebody already filled,
 * and the refusal is fatal to the command:
 *
 *   "DoctrineMetadataCacheWarmer must load metadata first, check priority of
 *    your warmers."
 *
 * @see vendor/doctrine/doctrine-bundle/src/CacheWarmer/DoctrineMetadataCacheWarmer.php
 *
 * The kernel warms the cache ONCE WHILE IT COMPILES THE CONTAINER, and that
 * pass runs the non-optional warmers only — Doctrine's is optional, so it is
 * not in it. This is why the registry contributes no warmer and the
 * reconciliation is a command of its own, run in its own process: a warmer
 * that read the database would load metadata in a pass Doctrine's warmer is
 * absent from, and poison the pass the command runs next, in the same process.
 * @see vendor/symfony/http-kernel/Kernel.php — `initializeContainer()` warms the cache after a rebuild, enabling the optional warmers only when the cache and build directories differ
 */
final class PristineCacheWarmUpTest extends InstallationTestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function cacheCommands(): iterable
    {
        yield 'cache:warmup' => ['cache:warmup'];
        yield 'cache:clear' => ['cache:clear'];
    }

    /**
     * The cache commands read no database and touch no catalogue: on a
     * pristine prod cache each succeeds, and the catalogue is exactly as
     * `registry:sync` left it — which, before it has run, is empty.
     */
    #[DataProvider('cacheCommands')]
    public function testACacheCommandSucceedsOnAPristineCacheAndReconcilesNothing(string $command): void
    {
        $this->install([]);
        $this->area('Deployed area');

        $kernel = $this->deployed(['sightings' => []]);

        self::assertSame(0, $this->console($kernel, $command));

        self::assertSame(
            [],
            $this->connection($kernel)->fetchFirstColumn('SELECT slug FROM module'),
            'a cache command is not the reconciliation',
        );

        $kernel->shutdown();
    }

    /**
     * THE DEPLOY IN ORDER: `registry:sync` fills the catalogue, then
     * `cache:warmup` — its own process, as it is on a server — builds every
     * cache, Doctrine's metadata cache among them.
     */
    public function testRegistrySyncThenWarmUpIsADeploy(): void
    {
        $this->install([]);
        $this->area('Deployed area');

        $kernel = $this->deployed(['sightings' => []]);
        self::assertSame(0, $this->console($kernel, 'registry:sync'));

        $connection = $this->connection($kernel);
        self::assertSame(
            ['sightings'],
            $connection->fetchFirstColumn('SELECT slug FROM module ORDER BY slug'),
            'the deploy reconciled the catalogue with the installed providers',
        );
        self::assertCount(1, $connection->fetchFirstColumn('SELECT id FROM area_module'), 'and gave the area its row');
        $kernel->shutdown();

        $kernel = $this->deployed(['sightings' => []], pristine: false);
        self::assertSame(0, $this->console($kernel, 'cache:warmup'));
        self::assertFileExists($kernel->getBuildDir().'/doctrine/orm/default_metadata.php', "Doctrine's own warmer did its work");
        $kernel->shutdown();
    }

    private function console(DeployedHostKernel $kernel, string $command): int
    {
        $application = new Application($kernel);
        $application->setAutoExit(false);
        $output = new BufferedOutput();

        $status = $application->run(new ArrayInput(['command' => $command]), $output);
        self::assertSame(0, $status, $output->fetch());

        return $status;
    }

    /**
     * @param array<string, array<string, mixed>> $modules
     */
    private function deployed(array $modules, bool $pristine = true): DeployedHostKernel
    {
        self::ensureKernelShutdown();
        HostKernel::$modules = $modules;

        $kernel = new DeployedHostKernel('prod', false);
        if ($pristine) {
            self::remove($kernel->getCacheDir());
        }
        $kernel->boot();

        return $kernel;
    }

    private function connection(DeployedHostKernel $kernel): Connection
    {
        // `framework.test` publishes a locator over the private services, which
        // is how a specification reaches a connection in a kernel it is
        // driving by hand.
        $container = $kernel->getContainer()->get('test.service_container');
        \assert($container instanceof ContainerInterface);

        $connection = $container->get('doctrine.dbal.default_connection');
        \assert($connection instanceof Connection);

        return $connection;
    }

    private static function remove(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($entries as $entry) {
            \assert($entry instanceof \SplFileInfo);
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }

        rmdir($directory);
    }
}
