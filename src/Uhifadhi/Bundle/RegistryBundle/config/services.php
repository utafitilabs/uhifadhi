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

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Symfony\Component\Console\Application;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\DoctrineDbalStore;
use Uhifadhi\Bundle\RegistryBundle\Access\RegistryConcerns;
use Uhifadhi\Bundle\RegistryBundle\Command\FactsRebuildCommand;
use Uhifadhi\Bundle\RegistryBundle\Command\RegistrySyncCommand;
use Uhifadhi\Bundle\RegistryBundle\EventListener\ParkedModuleListener;
use Uhifadhi\Bundle\RegistryBundle\EventListener\QueueTableSchemaListener;
use Uhifadhi\Bundle\RegistryBundle\Facts\FactProviders;
use Uhifadhi\Bundle\RegistryBundle\Facts\FactReader;
use Uhifadhi\Bundle\RegistryBundle\Message\RecomputeOpenFacts;
use Uhifadhi\Bundle\RegistryBundle\MessageHandler\RecomputeOpenFactsHandler;
use Uhifadhi\Bundle\RegistryBundle\RegistryBundle;
use Uhifadhi\Bundle\RegistryBundle\Repository\AreaModuleRepository;
use Uhifadhi\Bundle\RegistryBundle\Repository\FigureFactRepository;
use Uhifadhi\Bundle\RegistryBundle\Repository\ModuleRepository;
use Uhifadhi\Bundle\RegistryBundle\Service\AreaModuleLedger;
use Uhifadhi\Bundle\RegistryBundle\Service\AreaModuleService;
use Uhifadhi\Bundle\RegistryBundle\Service\FactRebuildService;
use Uhifadhi\Bundle\RegistryBundle\Service\ModuleCatalogue;
use Uhifadhi\Bundle\RegistryBundle\Service\ModuleEntryRouteResolver;
use Uhifadhi\Bundle\RegistryBundle\Service\ModuleRouteGate;
use Uhifadhi\Bundle\RegistryBundle\Service\ProviderCatalogueMapper;
use Uhifadhi\Bundle\RegistryBundle\Service\RegistrySyncService;
use Uhifadhi\Bundle\RegistryBundle\Settings\CatalogueFigure;
use Uhifadhi\Bundle\RegistryBundle\Version\DependencyOrderComparator;
use Uhifadhi\Contracts\Access\ConcernSourceInterface;
use Uhifadhi\Contracts\Facts\FactProviderInterface;
use Uhifadhi\Contracts\Facts\FactReaderInterface;
use Uhifadhi\Contracts\Settings\SettingsFigureSourceInterface;

/*
 * The bundle's static service wiring.
 *
 * PHP (not YAML) on purpose: a reusable bundle must not force symfony/yaml onto
 * hosts, and FQCN references stay refactor-safe and phpstan-checked. Imported by
 * RegistryBundle::loadExtension(), which keeps only the config-DRIVEN
 * definitions.
 *
 * Everything below is defined EXPLICITLY — no autowire(), no autoconfigure(),
 * and ids prefixed with the bundle alias — because this bundle is installed by
 * other projects via Composer, which is what Symfony calls a reusable bundle:
 *
 *   "Services should not use autowiring or autoconfiguration. Instead, all
 *    services should be defined explicitly."
 *   "If the bundle defines services, they must be prefixed with the bundle alias."
 *   — https://symfony.com/doc/current/bundles/best_practices.html
 *
 * The ids are the published surface. They are private, as a reusable bundle's
 * should be; a host that wants one aliases it, and the specification suite does
 * exactly that.
 *
 *   registry.catalogue             what modules this deployment has
 *   registry.provider_mapper       provider -> catalogue row (category coercion)
 *   registry.area_modules          per-area install state: install, uninstall, order
 *   registry.area_module_ledger    what an area has and what it does not
 *   registry.entry_routes          where a module's tile links
 *   registry.module_route_gate     is this request for a module the area parked?
 *   registry.parked_module_listener  the gate, applied to every incoming request
 *   registry.sync                  the create-only reconciliation itself
 *   registry.command.sync          `registry:sync`, the command that runs it and reports
 *   registry.facts.providers       every module that computes facts, and the figures they declared
 *   registry.facts.reader          the facts ledger, read (aliased from FactReaderInterface)
 *   registry.facts.rebuild         asks the modules for their figures and files them
 *   registry.facts.recompute_handler  the worker's side of the schedule
 *   registry.command.facts_rebuild `uhifadhi:facts:rebuild`, the operator's recompute over a range
 */
return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    /*
     * THE COLLECTING END OF THE REGISTRY, four times over. Each of these reads the
     * providers live, from the container, in registration order — which is what
     * makes uninstalling a bundle take its module, its route and its declared
     * permissions with it on the next request rather than on the next deploy.
     */
    $providers = tagged_iterator(RegistryBundle::MODULE_TAG);

    /*
     * Repositories keep FQCN ids — the one place the bundle-alias prefix cannot
     * be used: ServiceRepositoryCompilerPass keys its locator by SERVICE ID over
     * findTaggedServiceIds(), while ContainerRepositoryFactory looks a repository
     * up by CLASS NAME; tagged-id lookup never sees aliases.
     *
     * @see vendor/doctrine/doctrine-bundle/src/DependencyInjection/Compiler/ServiceRepositoryCompilerPass.php
     */
    $services->set(ModuleRepository::class)
        ->args([service('doctrine')])
        ->tag('doctrine.repository_service');

    $services->set(AreaModuleRepository::class)
        ->args([service('doctrine')])
        ->tag('doctrine.repository_service');

    /*
     * THE FACTS LEDGER. Figures over growing sets, computed by the worker on
     * a schedule and read by pages as stored numbers. The table is written
     * by SQL (an upsert) through its repository; the reader is the one
     * service a page or a module asks, published under the contract's
     * interface so a module type-hints the contract and never this bundle.
     */
    $services->set(FigureFactRepository::class)
        ->args([service('doctrine')])
        ->tag('doctrine.repository_service');

    $services->set('registry.facts.providers', FactProviders::class)
        ->args([tagged_iterator(FactProviderInterface::TAG)]);

    $services->set('registry.facts.reader', FactReader::class)
        ->args([service(FigureFactRepository::class), service('registry.facts.providers')]);
    $services->alias(FactReaderInterface::class, 'registry.facts.reader');

    /*
     * THE ONE WRITER OF THE LEDGER, and the worker's handler that runs it
     * for the periods open now. "Now" is the framework's `clock` service.
     *
     * The handler is tagged by hand with the message it handles:
     *   "If autoconfiguration is disabled, manually register handlers using
     *    the messenger.message_handler tag with the handles attribute"
     *   — https://symfony.com/doc/current/messenger.html#manually-configuring-handlers
     */
    $services->set('registry.facts.rebuild', FactRebuildService::class)
        ->args([
            service('registry.facts.providers'),
            service(FigureFactRepository::class),
            service('clock'),
        ]);

    $services->set('registry.facts.recompute_handler', RecomputeOpenFactsHandler::class)
        ->args([service('registry.facts.rebuild')])
        ->tag('messenger.message_handler', ['handles' => RecomputeOpenFacts::class]);

    $services->set('registry.provider_mapper', ProviderCatalogueMapper::class)
        ->args([param('registry.default_category')]);

    $services->set('registry.catalogue', ModuleCatalogue::class)
        ->args([service(ModuleRepository::class), $providers]);

    $services->set('registry.area_modules', AreaModuleService::class)
        ->args([
            service('doctrine.orm.entity_manager'),
            service(AreaModuleRepository::class),
            service('registry.catalogue'),
            service('event_dispatcher'),
        ]);

    $services->set('registry.area_module_ledger', AreaModuleLedger::class)
        ->args([service('registry.catalogue'), service(AreaModuleRepository::class)]);

    /*
     * THE ONE CONCERN THE CATALOGUE OWNS: which modules an area runs. Tagged
     * by hand, with the CONTRACT's constant, because a reusable bundle is not
     * autoconfigured and this bundle names no sibling bundle.
     */
    $services->set('registry.access.concerns', RegistryConcerns::class)
        ->tag(ConcernSourceInterface::TAG);

    /*
     * HOW MANY MODULES THIS INSTALLATION RUNS, for the settings section's
     * figure row. The section can read the vendor directory and see
     * packages; what a MODULE is, is this runtime's definition, so the
     * count is answered here.
     *
     * TAGGED BY HAND, and the tag is the CONTRACT's constant rather than the
     * shell's: this bundle names no sibling bundle, and the contracts package
     * is the one place both ends can read the spelling from.
     */
    $services->set('registry.settings.figure', CatalogueFigure::class)
        ->args([service('registry.catalogue')])
        ->tag(SettingsFigureSourceInterface::TAG);

    $services->set('registry.entry_routes', ModuleEntryRouteResolver::class)
        ->args([$providers]);

    /*
     * THE ROUTE GATE, and the listener that is its only caller. Parking a
     * module for an area closes that module's routes there — the registry owns the
     * ledger, so the registry is where the question is answered, once, for every
     * module at the same time.
     *
     * Priority 8 puts the listener after Symfony's RouterListener (32), whose
     * work — the route's defaults, on the request — is what the gate reads, and
     * well before any controller runs.
     */
    $services->set('registry.module_route_gate', ModuleRouteGate::class)
        ->args([service(AreaModuleRepository::class), service('registry.catalogue')]);

    $services->set('registry.parked_module_listener', ParkedModuleListener::class)
        ->args([service('registry.module_route_gate')])
        ->tag('kernel.event_listener', ['event' => 'kernel.request', 'priority' => 8]);

    /*
     * THE RECONCILIATION, AND THE COMMAND THAT RUNS IT further down. The
     * catalogue is brought into step with the installed providers by
     * `registry:sync`, typed once per install and per upgrade, after the
     * migrations and before the warm-up.
     */
    $services->set('registry.sync', RegistrySyncService::class)
        ->args([
            service('doctrine.orm.entity_manager'),
            service(ModuleRepository::class),
            service(AreaModuleRepository::class),
            service('registry.provider_mapper'),
            $providers,
        ]);

    $services->alias(RegistrySyncService::class, 'registry.sync');

    /*
     * WHAT DECIDES THE ORDER OF EVERY MIGRATION IN AN INSTALLATION. It belongs
     * to the registry because the registry is the one core bundle that requires
     * doctrine/doctrine-migrations-bundle, and because an ordering across
     * namespaces is a property of the installation rather than of any one
     * bundle's tables. The bundle class hands this id to
     * `doctrine_migrations.services`; the class itself says what the order is.
     *
     * It takes the migrations configuration because that is where a namespace
     * is mapped to a directory, and a directory is what says which package a
     * version came from. The named constructor reads the installed set from
     * Composer at RUNTIME, so nothing about a particular vendor directory is
     * baked into a compiled container.
     */
    $services->set('registry.migration_comparator', DependencyOrderComparator::class)
        ->factory([DependencyOrderComparator::class, 'fromComposer'])
        ->args([service('doctrine.migrations.configuration')]);

    /*
     * `registry:sync` — THE ONE COMMAND THIS BUNDLE SHIPS. An install and an
     * upgrade both end in the same four lines, and this is the third of them:
     * clear, migrate, sync, warm up.
     *
     *   "If you can't use PHP attributes, register the command as a service and
     *    tag it with the console.command tag."
     *   — https://symfony.com/doc/current/console.html#registering-the-command
     *
     * A BARE TAG, because the name and the description are on the class: the
     * compiler pass reads #[AsCommand] whether or not anything was
     * autoconfigured, and registers the service lazily under the name it finds.
     * @see vendor/symfony/console/DependencyInjection/AddConsoleCommandPass.php — registerCommand()
     *
     * GUARDED ON THE COMPONENT, as FrameworkBundle guards the file that carries
     * every one of its commands (FrameworkExtension::hasConsole() is
     * class_exists(Application::class)). A container compiled where there is
     * no console must not carry a service whose class it cannot load.
     * @see vendor/symfony/framework-bundle/DependencyInjection/FrameworkExtension.php
     */
    if (class_exists(Application::class)) {
        $services->set('registry.command.sync', RegistrySyncCommand::class)
            ->args([service('registry.sync')])
            ->tag('console.command');

        $services->set('registry.command.facts_rebuild', FactsRebuildCommand::class)
            ->args([service('registry.facts.rebuild'), service('clock')])
            ->tag('console.command');
    }
    /*
     * THE `default` SCHEDULE'S STATE AND LOCK, AS ROWS IN THE INSTALLATION'S
     * DATABASE, so they outlive a redeploy's fresh `var/cache` and a second
     * worker container shares them. Both are registered here, so an
     * installation configures nothing; StatefulDefaultSchedulePass hands them
     * to the schedule.
     *
     * The state is a cache pool of the registry's own on the framework's
     * Doctrine DBAL adapter — a child of `cache.adapter.doctrine_dbal` tagged
     * `cache.pool`, which is what a `framework.cache.pools` entry with that
     * adapter compiles to — on the default connection (the adapter's
     * `cache.default_doctrine_dbal_provider`). The namespace is fixed, not
     * derived from the container, so a recompiled container reads the same
     * rows. https://symfony.com/doc/current/cache.html#creating-custom-namespaced-pools
     * https://symfony.com/doc/current/components/cache/adapters/doctrine_dbal_adapter.html
     * @see vendor/symfony/framework-bundle/Resources/config/cache.php — `cache.adapter.doctrine_dbal`
     * @see vendor/symfony/cache/Adapter/DoctrineDbalAdapter.php — the `db_table` option
     * @see vendor/symfony/cache/DependencyInjection/CachePoolPass.php — the provider and namespace a `cache.pool` tag sets
     *
     * The lock is a factory of the registry's own over the Doctrine DBAL
     * store, tagged `lock.store` as the framework tags its stores.
     * https://symfony.com/doc/current/lock.html
     * https://symfony.com/doc/current/components/lock.html#doctrinedbalstore
     * @see vendor/symfony/lock/Store/DoctrineDbalStore.php
     *
     * Both tables are the registry's migration (Version20260925220000); the
     * schema listeners DoctrineBundle registers for DBAL cache adapters and
     * lock stores put them in the schema a diff compares.
     * @see vendor/doctrine/doctrine-bundle/src/DependencyInjection/Compiler/CacheSchemaSubscriberPass.php
     * @see vendor/symfony/doctrine-bridge/SchemaListener/LockStoreSchemaListener.php
     */
    $services->set('registry.schedule.state')
        ->parent('cache.adapter.doctrine_dbal')
        // `index_3`: a child definition replaces a parent's argument by this
        // key; a bare 3 would be appended after the parent's five.
        // @see vendor/symfony/dependency-injection/Compiler/ResolveChildDefinitionsPass.php
        ->arg('index_3', ['db_table' => 'registry_schedule_state'])
        ->tag('cache.pool', ['namespace' => 'registry.schedule']);

    $services->set('registry.schedule.lock_store', DoctrineDbalStore::class)
        ->args([service('doctrine.dbal.default_connection'), ['db_table' => 'registry_schedule_lock']])
        ->tag('lock.store');

    $services->set('registry.schedule.lock_factory', LockFactory::class)
        ->args([service('registry.schedule.lock_store')]);
    /*
     * THE QUEUE'S TABLE, DECLARED TO THE SCHEMA TOOL whether or not the
     * installation configures a Doctrine transport — the registry's migration
     * creates it, so the registry says it is there.
     * @see EventListener/QueueTableSchemaListener.php
     */
    $services->set('registry.queue_table_schema_listener', QueueTableSchemaListener::class)
        ->args([service('doctrine.dbal.default_connection')])
        ->tag('doctrine.event_listener', ['event' => 'postGenerateSchema']);
};
