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

namespace Uhifadhi\Bundle\RegistryBundle\Tests\Integration;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Uhifadhi\Bundle\RegistryBundle\Service\RegistrySyncResult;
use Uhifadhi\Bundle\RegistryBundle\Service\RegistrySyncService;
use Uhifadhi\Bundle\RegistryBundle\Tests\Integration\Fixtures\HostKernel;
use Uhifadhi\Entity\AreaOfInterest;

/**
 * Shared plumbing for the specifications that need a database and a set of
 * installed modules.
 *
 * The database is the fundi cluster's `uhifadhi_core_test` (port 5434). The
 * registry genuinely owns tables — the catalogue and the per-area install
 * record are its data, not the host's — so there is no honest version of this
 * suite that avoids a connection.
 */
abstract class InstallationTestCase extends RegistryKernelTestCase
{
    protected static function getKernelClass(): string
    {
        return HostKernel::class;
    }

    protected function setUp(): void
    {
        parent::setUp();

        HostKernel::$modules = [];
    }

    protected function tearDown(): void
    {
        HostKernel::$modules = [];

        parent::tearDown();
    }

    /**
     * INSTALL THESE MODULES AND RECONCILE — the whole install path, in one line:
     * boot an installation carrying exactly these module bundles, give it a
     * schema, and reconcile the way a deploy does.
     *
     * Called a second time in one test, it is an UNINSTALL as well as an
     * install: the modules not named are the ones whose bundles were removed,
     * and the database survives the reboot exactly as a real one does.
     *
     * @param array<string, array<string, mixed>>|list<string> $modules slug => provider overrides, or bare slugs
     */
    protected function install(array $modules, bool $freshDatabase = true): void
    {
        $normalised = [];
        foreach ($modules as $key => $value) {
            if (\is_int($key)) {
                \assert(\is_string($value));
                $normalised[$value] = [];

                continue;
            }
            \assert(\is_array($value));
            $normalised[$key] = $value;
        }

        self::ensureKernelShutdown();
        HostKernel::$modules = $normalised;
        self::bootKernel();

        if ($freshDatabase) {
            $metadata = $this->em()->getMetadataFactory()->getAllMetadata();
            $tool = new SchemaTool($this->em());
            $tool->dropSchema($metadata);
            $tool->createSchema($metadata);
        }

        $this->reconcile();
    }

    /**
     * Reconcile the registry the way an operator does: `registry:sync`, typed
     * into the console of the booted installation. Symfony's documented way to
     * drive a command in process is a FrameworkBundle Application built on the
     * kernel.
     *
     * @see https://symfony.com/doc/current/console.html#testing-commands
     * @see vendor/symfony/framework-bundle/Console/Application.php
     */
    protected function reconcile(): string
    {
        $kernel = self::$kernel;
        \assert(null !== $kernel);

        $application = new Application($kernel);
        $application->setAutoExit(false);
        $application->setCatchExceptions(false);

        $output = new BufferedOutput();
        $status = $application->run(new ArrayInput(['command' => 'registry:sync']), $output);
        $text = $output->fetch();

        self::assertSame(0, $status, 'registry:sync failed:'.\PHP_EOL.$text);

        $this->em()->clear();

        return $text;
    }

    /**
     * The reconciliation itself, called directly — for the specifications that
     * are about what it REPORTS rather than about the hook that triggers it.
     */
    protected function sync(): RegistrySyncResult
    {
        $sync = self::getContainer()->get('test.registry.sync');
        \assert($sync instanceof RegistrySyncService);

        $result = $sync->sync();
        $this->em()->clear();

        return $result;
    }

    protected function em(): EntityManagerInterface
    {
        $em = self::getContainer()->get('doctrine.orm.entity_manager');
        \assert($em instanceof EntityManagerInterface);

        return $em;
    }

    protected function area(string $name = 'Test area'): AreaOfInterest
    {
        $area = new AreaOfInterest()->setName($name);
        $this->em()->persist($area);
        $this->em()->flush();

        return $area;
    }

    protected function service(string $id): object
    {
        return self::getContainer()->get('test.'.$id);
    }
}
