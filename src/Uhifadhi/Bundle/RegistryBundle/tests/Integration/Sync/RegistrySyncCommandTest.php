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

use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Uhifadhi\Bundle\RegistryBundle\Tests\Integration\Fixtures\CollectedCacheWarmers;
use Uhifadhi\Bundle\RegistryBundle\Tests\Integration\InstallationTestCase;

/**
 * `registry:sync` — THE MECHANISM IS A COMMAND, TYPED ONCE PER INSTALL AND PER
 * UPGRADE, AFTER THE MIGRATIONS AND BEFORE THE WARM-UP. Not a cache warmer,
 * and not a listener on anything.
 *
 * What it prints is a ledger: added, kept, retired, area rows created. What it
 * refuses is the sequence typed in the wrong order — before the first
 * migration there are no registry tables, and the command exits non-zero
 * naming the step that comes first.
 *
 * Symfony's documented way to drive a command in process is a FrameworkBundle
 * Application built on a booted kernel.
 *
 * @see https://symfony.com/doc/current/console.html#testing-commands
 * @see vendor/symfony/framework-bundle/Console/Application.php
 */
final class RegistrySyncCommandTest extends InstallationTestCase
{
    public function testItReportsWhatItAddedKeptAndRetired(): void
    {
        $this->install(['sightings', 'ferries']);
        $this->area('North');

        $first = $this->reconcile();
        self::assertStringContainsString('added: none', $first, 'install() reconciled already; nothing is new');
        self::assertStringContainsString('kept: sightings, ferries', $first);
        self::assertStringContainsString('retired (rows kept, provider gone): none', $first);
        self::assertStringContainsString('area rows created: 2', $first);
        self::assertStringContainsString('2 module(s) installed', $first);

        // The ferries bundle is removed: its row stays, and the report says so.
        $this->install(['sightings'], freshDatabase: false);
        $second = $this->reconcile();
        self::assertStringContainsString('kept: sightings', $second);
        self::assertStringContainsString('retired (rows kept, provider gone): ferries', $second);
        self::assertStringContainsString('area rows created: 0', $second, 'a second run is idempotent');
        self::assertSame(
            ['ferries', 'sightings'],
            $this->em()->getConnection()->fetchFirstColumn('SELECT slug FROM module ORDER BY slug'),
        );
    }

    public function testBeforeTheFirstMigrationItRefusesAndNamesTheStepThatComesFirst(): void
    {
        $this->install(['sightings']);

        $metadata = $this->em()->getMetadataFactory()->getAllMetadata();
        new SchemaTool($this->em())->dropSchema($metadata);

        $kernel = self::$kernel;
        \assert(null !== $kernel);
        $application = new Application($kernel);
        $application->setAutoExit(false);
        $application->setCatchExceptions(false);
        $output = new BufferedOutput();

        $status = $application->run(new ArrayInput(['command' => 'registry:sync']), $output);

        self::assertSame(1, $status, 'no registry tables: the command fails rather than pretending');
        self::assertStringContainsString('Run doctrine:migrations:migrate first', $output->fetch());
    }

    /**
     * AND IT IS NOT A CACHE WARMER. A warmer that reads the database breaks the
     * cache commands on a pristine prod cache, so the registry contributes none:
     * this asserts the tag list the framework's aggregate receives carries
     * nothing of the registry's.
     *
     * @see PristineCacheWarmUpTest
     */
    public function testTheRegistryContributesNoCacheWarmer(): void
    {
        $this->install([]);

        $collected = self::getContainer()->get(CollectedCacheWarmers::class);
        \assert($collected instanceof CollectedCacheWarmers);

        foreach ($collected->classNames() as $warmer) {
            self::assertStringNotContainsString('Uhifadhi\\', $warmer);
        }
    }
}
