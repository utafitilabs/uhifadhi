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

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpKernel\CacheWarmer\CacheWarmerAggregate;
use Uhifadhi\Bundle\AreaBundle\AreaBundle;
use Uhifadhi\Bundle\AtlasBundle\AtlasBundle;
use Uhifadhi\Bundle\RegistryBundle\EventListener\RegistrySyncListener;
use Uhifadhi\Bundle\RegistryBundle\RegistryBundle;
use Uhifadhi\Bundle\ShellBundle\ShellBundle;
use Uhifadhi\Bundle\TeamBundle\TeamBundle;
use Uhifadhi\Core\Tests\Application\Kernel;

/**
 * THE CORE AS ONE INSTALLATION.
 *
 * Every bundle here has a suite that boots it in the smallest kernel it can
 * live in, which is the right shape for a specification ABOUT that bundle and
 * the wrong shape for the only question this file asks: do they compile
 * together, in one container, with one Twig, one router and one database?
 *
 * They are released together, so that is not an integration test in the
 * optional sense — it is the product. A bundle that compiles alone and
 * conflicts with its siblings has passed its own suite and broken the package.
 */
final class CoreBootTest extends KernelTestCase
{
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

    public function testEveryCoreBundleCompilesInOneContainer(): void
    {
        $kernel = self::bootKernel();

        foreach ([RegistryBundle::class, ShellBundle::class, AtlasBundle::class, TeamBundle::class, AreaBundle::class] as $bundle) {
            self::assertArrayHasKey(
                substr($bundle, (int) strrpos($bundle, '\\') + 1),
                $kernel->getBundles(),
                $bundle.' must be installed.',
            );
        }
    }

    /**
     * EACH BUNDLE'S CONFIG IS KEYED BY ITS OWN WORD, and the five cannot
     * collide because they are five words. An installation writes
     * config/packages/registry.yaml, shell.yaml, atlas.yaml, team.yaml and
     * area.yaml, one per bundle, exactly as it would if each were its own
     * package.
     */
    public function testEachBundleKeepsItsOwnConfigurationRoot(): void
    {
        $kernel = self::bootKernel();

        $aliases = [];
        foreach (['RegistryBundle', 'ShellBundle', 'AtlasBundle', 'TeamBundle', 'AreaBundle'] as $name) {
            $aliases[$name] = $kernel->getBundle($name)->getContainerExtension()?->getAlias();
        }

        self::assertSame(
            [
                'RegistryBundle' => 'registry',
                'ShellBundle' => 'shell',
                'AtlasBundle' => 'atlas',
                'TeamBundle' => 'team',
                'AreaBundle' => 'area',
            ],
            $aliases,
        );
    }

    /**
     * A DEPLOY IS `doctrine:migrations:migrate` AND THEN `cache:warmup`, and this
     * is the pair of things it does to the five bundles at once: every warmer they
     * contribute runs, and the registry is reconciled with whatever module
     * providers the installation carries.
     *
     * Both halves run here against a database with no registry tables in it —
     * the state a fresh installation is in before its first migration — and the
     * whole point is that neither throws.
     *
     * NO BUNDLE HERE READS THE DATABASE WHILE THE CACHE IS WARMED. That is not a
     * style rule: doctrine-bundle's metadata warmer fails the command outright if
     * anything loaded ORM metadata before it, and the pass the kernel runs while
     * it compiles the container does not include it.
     *
     * @see \Uhifadhi\Bundle\RegistryBundle\Tests\Integration\Sync\PristineCacheWarmUpTest which asserts that on a pristine prod cache, where the warmer in question exists
     */
    public function testADeployWarmsEveryCacheAndReconcilesTheRegistry(): void
    {
        $kernel = self::bootKernel();

        // The state is arranged rather than inherited: this suite shares one
        // database with every other, and "before the first migration" is a
        // precondition, not whatever the previous run happened to leave.
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        \assert($connection instanceof Connection);
        $connection->executeStatement('DROP SCHEMA IF EXISTS public CASCADE');
        $connection->executeStatement('CREATE SCHEMA public');

        $warmer = self::getContainer()->get('cache_warmer');
        \assert($warmer instanceof CacheWarmerAggregate);

        $warmer->enableOptionalWarmers();
        $warmer->warmUp($kernel->getCacheDir(), $kernel->getBuildDir());

        $listener = self::getContainer()->get('registry.sync_listener');
        \assert($listener instanceof RegistrySyncListener);

        @unlink($listener->stampFile());
        $listener->reconcileOnce();

        self::assertFileDoesNotExist(
            $listener->stampFile(),
            'nothing to reconcile before the first migration, and nothing remembered as done',
        );
    }
}
