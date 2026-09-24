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

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Core\Tests\Core\Fixtures\ModuleCarryingKernel;

/**
 * `registry:sync` AS AN INSTALLER RUNS IT, against the core's own migrations:
 * an empty database, `doctrine:migrations:migrate`, then the command.
 *
 * Three facts, in the order an operator meets them. Typed too early, before
 * the migrations, it exits non-zero and names the step that comes first.
 * Typed in its place, it fills the catalogue from the installed module bundles
 * and gives every area its rows. Typed again, it changes nothing and says so.
 *
 * @see \Uhifadhi\Bundle\RegistryBundle\Tests\Integration\Sync\RegistrySyncCommandTest for what the report names, module by module
 */
final class RegistrySyncCommandTest extends MigrationsTestCase
{
    protected static function getKernelClass(): string
    {
        return ModuleCarryingKernel::class;
    }

    public function testBeforeTheFirstMigrationItRefusesAndNamesTheStepThatComesFirst(): void
    {
        [$status, $text] = $this->registrySync();

        self::assertSame(1, $status, 'no registry tables: the command fails rather than pretending');
        self::assertStringContainsString('Run doctrine:migrations:migrate first', $text);
        self::assertNotContains('module', $this->tableNames(), 'and it created nothing on the way');
    }

    public function testAfterTheMigrationsItFillsTheCatalogueAndIsIdempotent(): void
    {
        $this->console('doctrine:migrations:migrate', ['--no-interaction' => true, 'version' => 'latest']);

        // One area, made the way the product makes one, so the command has a
        // row to give it.
        $em = self::getContainer()->get('doctrine.orm.entity_manager');
        \assert($em instanceof EntityManagerInterface);
        $em->persist(new AreaOfInterest()->setName('Fixture area'));
        $em->flush();
        $em->clear();

        [$status, $text] = $this->registrySync();

        self::assertSame(0, $status, $text);
        self::assertStringContainsString('added: sightings', $text);
        self::assertStringContainsString('area rows created: 1', $text);
        self::assertSame(['sightings'], $this->connection->fetchFirstColumn('SELECT slug FROM module'));
        self::assertSame(1, $this->connection->fetchOne('SELECT count(*) FROM area_module'));

        [$status, $text] = $this->registrySync();

        self::assertSame(0, $status, $text);
        self::assertStringContainsString('added: none', $text);
        self::assertStringContainsString('kept: sightings', $text);
        self::assertStringContainsString('area rows created: 0', $text);
        self::assertSame(['sightings'], $this->connection->fetchFirstColumn('SELECT slug FROM module'), 'a second run adds nothing');
        self::assertSame(1, $this->connection->fetchOne('SELECT count(*) FROM area_module'));
    }

    /**
     * The command's own exit code, which {@see MigrationsTestCase::console()}
     * asserts is zero and this specification is partly about it not being.
     *
     * @return array{int, string}
     */
    private function registrySync(): array
    {
        $kernel = self::$kernel;
        self::assertNotNull($kernel);

        $application = new Application($kernel);
        $application->setAutoExit(false);
        $application->setCatchExceptions(false);

        $output = new BufferedOutput();
        $status = $application->run(new ArrayInput(['command' => 'registry:sync']), $output);

        return [$status, $output->fetch()];
    }
}
