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

namespace Uhifadhi\Bundle\RegistryBundle\Tests\Integration\Fixtures;

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Uhifadhi\Bundle\RegistryBundle\RegistryBundle;
use Uhifadhi\Bundle\RegistryBundle\Tests\Integration\TestKernel;

/**
 * A HOST, MINIMALLY: the registry, plus the three things a real installation
 * contributes — an area entity, the resolution of the registry's area interface to
 * it, and whichever modules happen to be installed.
 *
 * INSTALLING A MODULE IS BOOTING A DIFFERENT KERNEL, and that is not a testing
 * trick: it is what installing a module bundle actually is. A specification
 * about uninstalling therefore boots a kernel without that provider, which is
 * the only honest way to assert what an uninstall does.
 *
 * The aliases below are also the registry's PUBLISHED SERVICE IDS. The bundle's
 * services are private, as a reusable bundle's should be, so a public alias is
 * the sanctioned way to observe exactly the ones a specification is about — and
 * the extraction has to land on these names (config/services.php lists the same
 * set).
 *
 * NOT FINAL: {@see RoutedHostKernel} is this host with routes on it, which is
 * what the route-gate specification needs and what no other specification
 * should have to carry.
 */
class HostKernel extends TestKernel
{
    /**
     * The installed modules, as slug => provider overrides. Set before booting.
     *
     * @var array<string, array<string, mixed>>
     */
    public static array $modules = [];

    public function registerBundles(): iterable
    {
        yield from parent::registerBundles();
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        parent::configureContainer($container);

        $container->extension('doctrine', [
            'orm' => [
                'resolve_target_entities' => [
                    \Uhifadhi\Contracts\Entity\AreaInterface::class => \Uhifadhi\Entity\AreaOfInterest::class,
                ],
                'mappings' => [
                    'TestHost' => [
                        'type' => 'attribute',
                        'dir' => __DIR__.'/Uhifadhi/Entity',
                        'prefix' => 'Uhifadhi\\Entity',
                        'is_bundle' => false,
                    ],
                ],
            ],
        ]);

        $services = $container->services();

        // Every installed module, tagged by hand — a module bundle's services
        // are never autoconfigured, so this is the entrance the real ones use.
        //
        // ONLY THE SLUG CROSSES INTO THE CONTAINER. A specification dials a
        // provider with real objects, and a service
        // definition argument can only hold scalars, parameters and references —
        // so the overrides stay here, on this class, and the provider reads them
        // by slug at call time. That is also the more honest fixture: a real
        // module's answers are code it runs, not values baked into a compiled
        // container, and reading them live is what makes uninstalling a bundle
        // observable at all.
        foreach (array_keys(self::$modules) as $slug) {
            $services->set('test.module.'.$slug, SpecModuleProvider::class)
                ->args([$slug])
                ->tag(RegistryBundle::MODULE_TAG);
        }

        foreach ([
            'registry.catalogue',
            'registry.provider_mapper',
            'registry.area_modules',
            'registry.area_module_ledger',
            'registry.entry_routes',
            'registry.sync',
            'registry.sync_listener',
        ] as $id) {
            $services->alias('test.'.$id, $id)->public();
        }
    }

    /**
     * One compiled container per installed set — otherwise the second boot in a
     * test would reuse the first one's container and quietly re-answer the
     * previous question.
     */
    public function getCacheDir(): string
    {
        return $this->checkoutTempDir(
            'cache/installation/'.substr(hash('xxh128', serialize(self::$modules)), 0, 12),
        );
    }
}
