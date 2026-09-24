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

namespace Uhifadhi\Bundle\RegistryBundle\EventListener;

use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ContainerBagInterface;
use Symfony\Contracts\Service\ServiceSubscriberInterface;
use Uhifadhi\Bundle\RegistryBundle\Service\RegistrySyncService;

/**
 * THE ONCE-PER-DEPLOY HOOK. There is no command to reconcile the registry with —
 * devkit owns every command the platform has bar the team bundle's first
 * administrator — so reconciling it with the installed module providers hangs
 * off the moment a deploy is made of: a console command has finished. A deploy
 * runs `doctrine:migrations:migrate` and then `cache:warmup`, so the first of
 * them reconciles the build and the second finds the work done.
 *
 * A REQUEST DOES NOTHING HERE, AND THAT IS THE POINT. The registry's connection
 * is the deploy's to open: an installation's liveness route reads no database, a
 * proxy probes it before anything is migrated, and a listener on `kernel.request`
 * would answer that probe out of a connection to tables that do not exist yet.
 * The catalogue is filled by the command that migrated them, in the same deploy,
 * before a request arrives at all.
 *
 * WHY NOT A CACHE WARMER, WHICH IS WHAT THIS LOOKS LIKE. Because a warmer that
 * reads the database breaks the cache commands themselves on a pristine prod
 * cache. The kernel warms the cache once while it compiles the container, and
 * that pass runs the NON-OPTIONAL warmers only:
 *
 *   "if ($cacheDir !== $buildDir) { $cacheWarmer->enableOptionalWarmers(); }"
 *
 *   @see vendor/symfony/http-kernel/Kernel.php — `initializeContainer()`
 *
 * doctrine-bundle's metadata warmer is optional, so it is absent from that pass
 * — and it refuses to build its PHP-array cache from a metadata factory
 * somebody else has already filled, which is fatal to the command that follows
 * in the same process:
 *
 *   "DoctrineMetadataCacheWarmer must load metadata first, check priority of
 *    your warmers."
 *   @see vendor/doctrine/doctrine-bundle/src/CacheWarmer/DoctrineMetadataCacheWarmer.php
 *
 * A priority cannot order two warmers that never run in the same pass, and no
 * reconciliation of a database can avoid loading the metadata of the entities it
 * writes. So the work leaves the warmer chain, and the framework's own rule —
 * warmers fill caches from static configuration, nothing else — is kept:
 *
 *   "A cache warmer processes and dumps the contents of a certain cache."
 *   @see https://symfony.com/doc/current/reference/dic_tags.html#kernel-cache-warmer
 *
 * `console.terminate` and not `console.command`, because the command a deploy
 * ends with IS `cache:warmup`: reconciling before it executes would poison
 * exactly the pass this listener exists to protect, while reconciling after it
 * has warmed the metadata cache is free of that. So migrating and warming up
 * remains the whole of the operator's instructions, and the registry is in step
 * by the time the last command returns.
 *
 * The stamp file is what makes this once-per-deploy rather than once-per-command:
 * it lives in the cache directory, which a deploy replaces, so the two commands
 * of one deploy reconcile once between them and every command an operator runs
 * afterwards in that build reconciles not at all. It is claimed atomically, so
 * two processes running together reconcile once.
 */
final class RegistrySyncListener implements ServiceSubscriberInterface
{
    /**
     * @param string $stampPath where the stamp lives; the file itself is named after the container build, see {@see stampFile()}
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly string $stampPath,
    ) {
    }

    /**
     * THE STAMP BELONGS TO ONE CONTAINER BUILD. `cache:clear` is a console
     * command too: at its own end this listener runs with the container the
     * command booted with — the one from BEFORE the clear, which knows nothing
     * of a module installed a moment earlier — and stamps. Named after the
     * build, that stamp is nobody else's: the rebuilt container that the next
     * command boots finds no stamp of its own and reconciles, with the module
     * in it. A stamp shared across builds left a freshly installed module out
     * of the catalogue until somebody deleted the file by hand.
     *
     * `container.build_id` is written into every compiled container by the
     * dumper; it changes whenever the container is rebuilt.
     */
    public function stampFile(): string
    {
        $parameters = $this->container->get(ContainerBagInterface::class);
        \assert($parameters instanceof ContainerBagInterface);
        $build = $parameters->has('container.build_id') ? $parameters->get('container.build_id') : 'build';
        \assert(\is_string($build));

        return \dirname($this->stampPath).'/registry-sync.'.$build.'.stamp';
    }

    /**
     * The event object is not taken, and symfony/console is not a dependency of
     * this bundle: the registry has no command of its own, so it names no class
     * of that component. A tag is a string, and an installation without a
     * console simply never fires this one.
     */
    public function onConsoleTerminate(): void
    {
        $this->reconcileOnce();
    }

    /**
     * Reconcile unless this build already has been.
     *
     * WHAT IT DECLINES TO DO RATHER THAN FATAL, and why the stamp is removed
     * again in two cases: a console command can be run BEFORE the first
     * migration, so this meets a database with no registry tables in it — or, in
     * a build container, no database at all. Neither may break the command it is
     * attached to, and neither may be remembered as done: a skipped
     * reconciliation leaves no stamp, so the command that applies the first
     * migration is the one that fills the catalogue.
     */
    public function reconcileOnce(): void
    {
        $stampFile = $this->stampFile();
        if (is_file($stampFile)) {
            return;
        }

        $directory = \dirname($stampFile);
        if (!is_dir($directory) && !mkdir($directory, 0o777, true) && !is_dir($directory)) {
            return;
        }

        // 'x' fails if the file is there: the process that creates it is the one
        // that reconciles, and the others go on with the command they are in.
        $claim = @fopen($stampFile, 'x');
        if (false === $claim) {
            return;
        }
        fclose($claim);

        try {
            $sync = $this->container->get(RegistrySyncService::class);
            \assert($sync instanceof RegistrySyncService);

            if ($sync->sync()->skipped) {
                @unlink($stampFile);
            }
        } catch (\Throwable) {
            @unlink($stampFile);
        }
    }

    public static function getSubscribedServices(): array
    {
        // Fetched through the locator rather than injected, so that a console
        // command builds no entity manager for a reconciliation this build has
        // already had.
        return [
            RegistrySyncService::class,
            ContainerBagInterface::class,
        ];
    }
}
