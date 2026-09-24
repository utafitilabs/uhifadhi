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

use Psr\Container\ContainerInterface;
use Uhifadhi\Bundle\RegistryBundle\Access\RegistryConcerns;
use Uhifadhi\Bundle\RegistryBundle\EventListener\ParkedModuleListener;
use Uhifadhi\Bundle\RegistryBundle\EventListener\RegistrySyncListener;
use Uhifadhi\Bundle\RegistryBundle\RegistryBundle;
use Uhifadhi\Bundle\RegistryBundle\Repository\AreaModuleRepository;
use Uhifadhi\Bundle\RegistryBundle\Repository\ModuleRepository;
use Uhifadhi\Bundle\RegistryBundle\Service\AreaModuleLedger;
use Uhifadhi\Bundle\RegistryBundle\Service\AreaModuleService;
use Uhifadhi\Bundle\RegistryBundle\Service\ModuleCatalogue;
use Uhifadhi\Bundle\RegistryBundle\Service\ModuleEntryRouteResolver;
use Uhifadhi\Bundle\RegistryBundle\Service\ModulePermissionCatalogue;
use Uhifadhi\Bundle\RegistryBundle\Service\ModuleRouteGate;
use Uhifadhi\Bundle\RegistryBundle\Service\ProviderCatalogueMapper;
use Uhifadhi\Bundle\RegistryBundle\Service\RegistrySyncService;
use Uhifadhi\Bundle\RegistryBundle\Settings\CatalogueFigure;
use Uhifadhi\Bundle\RegistryBundle\Version\DependencyOrderComparator;
use Uhifadhi\Contracts\Access\ConcernSourceInterface;
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
 *   registry.permissions           the permissions installed modules declare
 *   registry.sync                  the create-only reconciliation itself
 *   registry.sync_listener         the deploy hook that runs it, at the end of a console command
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

    $services->set('registry.permissions', ModulePermissionCatalogue::class)
        ->args([$providers]);

    /*
     * THE RECONCILIATION, AND ITS DEPLOY HOOK further down. The core ships no
     * console command; the catalogue is brought into step with the installed
     * providers once per build, by the listener at the end of this file.
     *
     * Every tag there is written out by hand: nothing here is autoconfigured, so
     * neither the listener tags nor the service-subscriber tag that gives the
     * listener its lazy locator is applied for us.
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

    $services->set('registry.sync_listener', RegistrySyncListener::class)
        // THE SUBSCRIBER IS TAGGED BY HAND because this bundle autoconfigures
        // nothing:
        //   "The container detects service subscribers via autoconfiguration.
        //    If you disabled it, add the `container.service_subscriber` tag to
        //    the definition of the service that implements
        //    ServiceSubscriberInterface"
        //   "when the container finds a service implementing
        //    ServiceSubscriberInterface, it creates a locator with the
        //    subscribed services and injects it into the constructor argument
        //    type-hinted with Psr\Container\ContainerInterface"
        // @see https://symfony.com/doc/current/service_container/service_subscribers_locators.html
        // ResolveServiceSubscribersPass swaps a Psr ContainerInterface reference
        // for the subscriber's own locator; any other id would inject the real
        // container. @see vendor/symfony/dependency-injection/Compiler/ResolveServiceSubscribersPass.php
        ->args([
            service(ContainerInterface::class),
            '%kernel.cache_dir%/uhifadhi/registry-sync.stamp',
        ])
        ->tag('container.service_subscriber')
        // THE END OF A CONSOLE COMMAND IS THE WHOLE OF THE HOOK. A deploy
        // migrates and then warms the cache up, and the registry is in step by
        // the time the second command returns; a request is served without it.
        //
        // `kernel.event_listener` AND A CONSOLE EVENT: the tag's name says
        // kernel, but what it registers is a listener on the application's one
        // `event_dispatcher` — the same dispatcher the console application is
        // given — and the pass that reads the tag treats `event` as an opaque
        // string, so a console event name is as good as a kernel one.
        // @see https://symfony.com/doc/current/reference/dic_tags.html#kernel-event-listener
        // @see https://symfony.com/doc/current/components/console/events.html#the-consoleevents-terminate-event
        // @see vendor/symfony/event-dispatcher/DependencyInjection/RegisterListenersPass.php
        // @see vendor/symfony/console/ConsoleEvents.php — `const TERMINATE = 'console.terminate'`
        ->tag('kernel.event_listener', ['event' => 'console.terminate', 'method' => 'onConsoleTerminate']);
};
