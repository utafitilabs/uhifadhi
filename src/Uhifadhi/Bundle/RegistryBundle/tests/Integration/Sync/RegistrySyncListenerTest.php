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
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;
use Uhifadhi\Bundle\RegistryBundle\EventListener\RegistrySyncListener;
use Uhifadhi\Bundle\RegistryBundle\Service\AreaModuleService;
use Uhifadhi\Bundle\RegistryBundle\Tests\Integration\Fixtures\CollectedCacheWarmers;
use Uhifadhi\Bundle\RegistryBundle\Tests\Integration\Fixtures\RoutedHostKernel;
use Uhifadhi\Bundle\RegistryBundle\Tests\Integration\InstallationTestCase;

/**
 * THE MECHANISM: A LISTENER ON THE END OF A CONSOLE COMMAND, WHICH IS THE DEPLOY
 * PATH AND THE ONLY PATH. NOT A COMMAND OF ITS OWN, AND NOT A CACHE WARMER.
 *
 * Reconciling the registry with the installed module providers is a
 * once-per-deploy job, and the core ships no console command for it — devkit
 * owns commands. So it hangs off `console.terminate`: a deploy runs
 * `doctrine:migrations:migrate` and then `cache:warmup`, and each of those is a
 * console command finishing in the build the deploy has just made. The first of
 * them reconciles, the stamp file in the cache directory records it, and the
 * second finds the work done.
 *
 * A WEB REQUEST RECONCILES NOTHING. The registry's connection is the deploy's to
 * open: a request that arrives before the first migration — a proxy's liveness
 * probe on a DB-free route among them — must reach its controller without the
 * registry asking the database anything.
 *
 * IT IS NOT A CACHE WARMER, AND CANNOT BE ONE: a warmer that reads a database
 * breaks the cache commands on a pristine prod cache. The kernel's own warm-up
 * pass, run while it compiles the container, skips the optional warmers —
 * doctrine-bundle's metadata warmer among them — and that warmer then refuses to
 * build its cache from a metadata factory somebody already filled.
 *
 * @see PristineCacheWarmUpTest the deploy that pins it
 *
 * The half that is easy to get wrong is the FRESH INSTALL. A console command can
 * be run before the first migration, so the reconciliation meets a database with
 * no registry tables in it — and it must neither break the command it is
 * attached to nor remember that nothing was done as if something had been.
 */
final class RegistrySyncListenerTest extends InstallationTestCase
{
    /**
     * The host with routes on it: what a request does is only observable through
     * a router that has something to match, and a listener on `kernel.request`
     * never runs for a request that matches nothing.
     */
    protected static function getKernelClass(): string
    {
        return RoutedHostKernel::class;
    }

    private function listener(): RegistrySyncListener
    {
        $listener = $this->service('registry.sync_listener');
        \assert($listener instanceof RegistrySyncListener);

        return $listener;
    }

    /**
     * THE DEPLOY PATH, AND NOTHING ELSE. `console.terminate` is the one event it
     * is on, which is what makes `doctrine:migrations:migrate` followed by
     * `cache:warmup` the whole of the operator's instructions.
     *
     * `console.terminate` and not `console.command`: the command a deploy ends
     * with is `cache:warmup`, and reconciling before it executes would load ORM
     * metadata into the very pass that must not find any.
     */
    public function testTheEndOfAConsoleCommandIsTheOnlyThingItIsWiredTo(): void
    {
        $this->install([]);

        $dispatcher = self::getContainer()->get('event_dispatcher');
        \assert($dispatcher instanceof EventDispatcherInterface);

        $listener = $this->listener();

        self::assertContains(
            [$listener, 'onConsoleTerminate'],
            $dispatcher->getListeners('console.terminate'),
        );

        foreach ($dispatcher->getListeners('kernel.request') as $registered) {
            $subscriber = \is_array($registered) ? $registered[0] : $registered;
            self::assertNotSame($listener, $subscriber, 'a request reconciles nothing');
        }
    }

    /**
     * A WEB REQUEST NEITHER RECONCILES NOR OPENS THE CONNECTION, in the state
     * where opening it is the visible failure: the registry tables are not there
     * yet, and the page asked for is one the registry has no part in — the shape
     * every installation's liveness route has, which a proxy probes and which
     * reads no database.
     *
     * A connection opened here is a healthcheck that depends on a database it
     * never reads, and a reconciliation attempted here is one attempted on every
     * request until the first migration runs.
     */
    public function testAWebRequestNeitherReconcilesNorOpensTheConnection(): void
    {
        $this->install(['sightings']);

        $metadata = $this->em()->getMetadataFactory()->getAllMetadata();
        new SchemaTool($this->em())->dropSchema($metadata);

        @unlink($this->listener()->stampFile());

        $connection = $this->em()->getConnection();
        $connection->close();

        $kernel = self::$kernel;
        \assert(null !== $kernel);
        $response = $kernel->handle(Request::create('/areas/'.Uuid::v7()->toRfc4122()));

        self::assertSame(200, $response->getStatusCode(), 'the page a request came for was answered');

        self::assertFalse($connection->isConnected(), 'the request opened the registry a connection');
        self::assertFileDoesNotExist($this->listener()->stampFile());
    }

    /**
     * AND IT IS NOT A CACHE WARMER. A warmer that reads the database breaks the
     * cache commands on a pristine prod cache, so the registry contributes none:
     * this asserts the tag list the framework's aggregate receives carries
     * nothing of the registry's.
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

    public function testItReconcilesTheRegistry(): void
    {
        $this->install(['sightings']);
        $area = $this->area();

        // A new build: the area was configured after the last deploy, and this
        // is the deploy that gives it its rows.
        $this->reconcile();

        $this->em()->clear();
        $service = $this->service('registry.area_modules');
        \assert($service instanceof AreaModuleService);

        self::assertContains('sightings', array_map(
            static fn (object $areaModule): ?string => $areaModule->getModule()?->getSlug(),
            $service->allFor($area),
        ));
    }

    /**
     * ONCE PER BUILD, AND THE STAMP IS WHAT SAYS SO. A deploy is two commands —
     * migrate, then warm up — and the question they answer between them is asked
     * once: the first of them reconciles and the second finds the work done, as
     * does every command an operator runs afterwards in the same build. The proof
     * is a row removed by hand that a second call does not put back.
     */
    /**
     * A STAMP BELONGS TO ONE CONTAINER BUILD. `cache:clear` is itself a console
     * command: at its own end it reconciles with the container it booted with —
     * the one from BEFORE the clear, which knows nothing of a module installed a
     * moment earlier — and stamps. If that stamp counted for the next, rebuilt
     * container, the new module never entered the catalogue until somebody
     * deleted the file by hand. So the stamp is named after the build, and a
     * build finds only its own.
     */
    public function testAStampFromAnotherBuildDoesNotStopThisBuildReconciling(): void
    {
        $this->install(['sightings']);
        $listener = $this->listener();

        $buildId = self::getContainer()->getParameter('container.build_id');
        self::assertIsString($buildId);
        self::assertStringContainsString('registry-sync.'.$buildId.'.stamp', $listener->stampFile(), 'the stamp is named after this build');

        // Another build stamped, as cache:clear does with the container it booted with.
        @unlink($listener->stampFile());
        touch(\dirname($listener->stampFile()).'/registry-sync.0ldbu1ld.stamp');
        $this->em()->getConnection()->executeStatement('DELETE FROM area_module');
        $this->em()->getConnection()->executeStatement('DELETE FROM module');

        $listener->reconcileOnce();

        self::assertSame(
            ['sightings'],
            $this->em()->getConnection()->fetchFirstColumn('SELECT slug FROM module'),
            'another build\'s stamp does not count; this build reconciles once',
        );
        self::assertFileExists($listener->stampFile());
    }

    public function testAReconciledBuildIsNotReconciledAgain(): void
    {
        $this->install(['sightings']);

        self::assertFileExists($this->listener()->stampFile());

        $this->em()->getConnection()->executeStatement('DELETE FROM area_module');
        $this->em()->getConnection()->executeStatement('DELETE FROM module');

        $this->listener()->reconcileOnce();

        self::assertSame(
            [],
            $this->em()->getConnection()->fetchFirstColumn('SELECT slug FROM module'),
            'this build was reconciled already; the listener asked nothing',
        );
    }

    /**
     * THE FRESH-INSTALL GUARD. No tables yet — a console command run before the
     * first migration still has to work, and the reconciliation says so rather
     * than exploding.
     *
     * AND IT MUST NOT BE REMEMBERED AS DONE: the operator's next step is the
     * first migration, and the command that ends it is the one that fills the
     * catalogue. A stamp left behind here would leave an installation with an
     * empty catalogue until its second deploy.
     */
    public function testReconcilingBeforeTheFirstMigrationIsNotRememberedAsDone(): void
    {
        $this->install(['sightings']);

        $metadata = $this->em()->getMetadataFactory()->getAllMetadata();
        $tool = new SchemaTool($this->em());
        $tool->dropSchema($metadata);

        @unlink($this->listener()->stampFile());
        $this->listener()->reconcileOnce();

        self::assertFileDoesNotExist($this->listener()->stampFile());
        self::assertTrue($this->sync()->skipped, 'no registry tables, nothing to reconcile');

        // …and the migration's turn comes: the tables appear, and the command
        // that applied them reconciles as it ends.
        $tool->createSchema($metadata);
        $this->listener()->reconcileOnce();

        self::assertSame(
            ['sightings'],
            $this->em()->getConnection()->fetchFirstColumn('SELECT slug FROM module'),
        );
    }
}
