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

namespace Uhifadhi\Bundle\RegistryBundle;

use Doctrine\Migrations\Version\Comparator;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use Uhifadhi\Bundle\RegistryBundle\DependencyInjection\Compiler\InstallationMigrationsPathFirstPass;
use Uhifadhi\Bundle\RegistryBundle\DependencyInjection\RegistryConfiguration;
use Uhifadhi\Contracts\Facts\FactProviderInterface;
use Uhifadhi\Contracts\ModuleProviderInterface;

/**
 * THE REGISTRY — the runtime every uhifadhi module registers with.
 *
 * The skeleton is the application, the registry carries the modules, and the
 * shell is what you see. This bundle is the carrying: the module catalogue, the
 * per-area record of what is switched on, the permissions modules declare, and
 * the sync that keeps the catalogue in step with what is installed.
 *
 * IT RENDERS NOTHING. No templates, no controllers, no routes — the visible
 * surface is the shell's job. The registry answers questions in data and
 * services, and anything that draws a module grid reads it. See docs/boundaries.md
 * for why the grid is not here.
 *
 * IT KNOWS NO MODULE BY NAME — not one, not even the pinned hub every
 * installation has. A module is whatever tagged itself, and everything the
 * registry treats specially (pinned, base) is a flag the provider declares,
 * never a slug the runtime recognises. A test sweeps the shipped source for that
 * property, and the sweep is why this paragraph names nothing either.
 *
 * THIS CLASS IS THE PLUG: the bundle registers, its config is keyed under
 * "registry:", its entity directory is mapped, and it autoconfigures the module
 * tag. The runtime itself lives in Service/ and Repository/.
 */
final class RegistryBundle extends AbstractBundle
{
    /**
     * The tag every module provider carries. Published as a constant because
     * the registry is the end that COLLECTS it — a module bundle writes the string
     * by hand in its own extension (it is not autoconfigured), and a host or a
     * test that wants to stand in for the collector should not retype it.
     */
    public const string MODULE_TAG = 'uhifadhi.module';

    /**
     * THE ROUTE MARKER. A route default a module writes on its own routes —
     * `_uhifadhi_module: <its slug>` — which is how the registry recognises a
     * request as belonging to a module and closes it where the area has parked
     * that module. Published for the same reason the tag is: the registry is the
     * end that READS it, and the end that writes it should not retype a string.
     *
     * A route that carries it is read precisely; a route that does not is still
     * caught by the fleet's `/areas/{uuid}/modules/{slug}/…` path shape when the
     * segment names a module in the catalogue. See docs/guarantees.md.
     */
    public const string MODULE_ROUTE_DEFAULT = '_uhifadhi_module';

    /**
     * Which route parameter carries the area's uuid, for a module route that
     * does not call it `uuid`. Optional; the fleet's convention is the default.
     */
    public const string MODULE_ROUTE_AREA_DEFAULT = '_uhifadhi_module_area';

    /** The parameter a module route carries its area's uuid in, unless it says otherwise. */
    public const string DEFAULT_AREA_PARAMETER = 'uuid';

    /** Config lives under "registry:", not the class-derived "registry_bundle:". */
    protected string $extensionAlias = 'registry';

    /**
     * THE BUNDLE CLASS SITS AT THE PACKAGE ROOT, beside this bundle's own
     * composer.json, because after a split the package root IS the bundle
     * root.
     *
     * AbstractBundle assumes otherwise. Its default "assume the modern
     * directory structure" answer is `dirname($file, 2)`, which is right for a
     * bundle whose class lives in src/ and two directories too high for one
     * whose class lives at the root — templates/ and public/ would be looked
     * for outside the package.
     *
     * @see vendor/symfony/http-kernel/Bundle/AbstractBundle.php
     */
    public function getPath(): string
    {
        return __DIR__;
    }

    public function configure(DefinitionConfigurator $definition): void
    {
        RegistryConfiguration::define($definition->rootNode());
    }

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        // THE MODULE REGISTRY, AT ITS COLLECTING END. Every ModuleProviderInterface gets
        // the tag, so a module the host itself defines is collected exactly like
        // a module bundle's. This is autoconfiguration, which only fires for
        // autoconfigured services — a reusable bundle's own services are not, so
        // a module BUNDLE still writes the tag by hand. Both ends meet here.
        //
        // The autoconfiguration lives with the collector, not in the
        // application's Kernel, so a fresh installation plus this bundle is
        // already a working registry.
        $container->registerForAutoconfiguration(ModuleProviderInterface::class)
            ->addTag(self::MODULE_TAG);

        // THE FACTS SEAM, collected the same way: a provider the application
        // defines is tagged for it, a module bundle tags its own by hand.
        $container->registerForAutoconfiguration(FactProviderInterface::class)
            ->addTag(FactProviderInterface::TAG);

        // WHERE A FLAGLESS `migrations:diff` LANDS. The registry already owns
        // how doctrine/migrations behaves across a whole installation — it
        // replaces the comparator below — and this is the other half of it: the
        // installation's own directory is put ahead of every directory a
        // package ships, so the version an installation generates for its own
        // entities is never written into vendor/.
        $container->addCompilerPass(new InstallationMigrationsPathFirstPass());
    }

    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        // Zero-config persistence: the registry maps its own entities, so a host
        // never writes a doctrine mappings block for the catalogue tables.
        //
        // PREPENDED, LIKE EVERY BLOCK THIS METHOD WRITES — `prependExtensionConfig()`
        // on the builder, the form the docs and symfony/ux-chartjs write — so this
        // config goes first and an installation that maps these classes itself
        // wins.
        //
        // @see https://symfony.com/doc/current/bundles/prepend_extension.html
        // @see vendor/symfony/ux-chartjs/src/DependencyInjection/ChartjsExtension.php:57
        if ($builder->hasExtension('doctrine')) {
            $builder->prependExtensionConfig('doctrine', [
                'orm' => [
                    'mappings' => [
                        'Registry' => [
                            'type' => 'attribute',
                            'dir' => __DIR__.'/Entity',
                            'prefix' => 'Uhifadhi\\Bundle\\RegistryBundle\\Entity',
                            'is_bundle' => false,
                        ],
                    ],
                ],
            ]);
        }

        // THE TABLES ARRIVE WITH THE CODE. The catalogue and the per-area ledger
        // are the registry's, so their DDL ships here too, under the bundle's
        // own namespace — the shape the migrations bundle documents for a
        // bundle-shipped history:
        //
        // > migrations_paths:
        // >     'SomeBundle\Migrations': '@SomeBundle/Migrations'
        //
        // @see https://symfony.com/bundles/DoctrineMigrationsBundle/current/index.html
        // @see vendor/doctrine/doctrine-migrations-bundle/src/DependencyInjection/DoctrineMigrationsExtension.php
        //
        // Guarded: an application may install this bundle without the migrations
        // bundle in its kernel, and there it simply has no history to run.
        if (!$builder->hasExtension('doctrine_migrations')) {
            return;
        }

        $builder->prependExtensionConfig('doctrine_migrations', [
            'migrations_paths' => [
                'Uhifadhi\\Bundle\\RegistryBundle\\Migrations' => __DIR__.'/migrations',
            ],

            // WHY THE CORE REPLACES A DOCTRINE SERVICE, AND ONLY THIS ONE. A
            // version's identity is its full class name, so the shipped
            // comparator orders migrations by NAMESPACE — which, with a
            // namespace per package, is not the order the foreign keys need.
            // The replacement orders by the Composer dependency graph.
            // @see Version/DependencyOrderComparator.php
            'services' => [
                Comparator::class => 'registry.migration_comparator',
            ],
        ]);
    }

    /**
     * @param array<string, mixed> $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        // Static service wiring lives in a PHP config file (see config/services.php
        // for why PHP, not YAML). loadExtension keeps only the config-DRIVEN bits.
        $container->import('config/services.php');

        // The category an unplaced module falls back to. A provider naming a
        // category the deployment does not have is coerced rather than trusted,
        // and this is what it is coerced TO — "operations" by default, because
        // this is an operations platform and an unplaced module is far likelier
        // to be somebody's daily work than a reading of the ecosystem.
        $builder->setParameter(
            'registry.default_category',
            \is_string($config['default_category'] ?? null) ? $config['default_category'] : 'operations',
        );

        // Dev-only tooling (seeders, fixtures) hangs off this flag, so a
        // production installation never grows a command that writes invented
        // catalogue rows. Nothing claims it yet — the switch exists so the first
        // thing that needs it has somewhere to hang.
        $builder->setParameter('registry.dev_tools', true === ($config['dev_tools'] ?? false));
    }
}
