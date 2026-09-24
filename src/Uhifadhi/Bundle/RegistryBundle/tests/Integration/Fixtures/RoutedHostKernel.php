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
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Uhifadhi\Bundle\RegistryBundle\RegistryBundle;

/**
 * THE HOST, NOW WITH ROUTES — the five shapes the route gate has to tell apart,
 * every one of them ending at the same always-200 page.
 *
 * The registry draws nothing and never will; these routes belong to the stand-in
 * host and to two stand-in modules, exactly as real ones do. What the suite
 * gets out of them is the only honest test of a kernel listener: a real request
 * through a real router.
 *
 * | route | what it stands for |
 * |---|---|
 * | `alpha_dashboard` | a module route that DECLARES itself, on the fleet's path shape |
 * | `alpha_console` | a module route that declares itself somewhere else entirely, naming its own area parameter |
 * | `beta_log` | a module route that declares nothing and is recognised by its path alone |
 * | `host_customize` | the AREA's own screen, on the same path shape — the door you unpark from, which the gate must never close |
 * | `host_area` | an area page with no module in it at all |
 */
final class RoutedHostKernel extends HostKernel
{
    protected function configureContainer(ContainerConfigurator $container): void
    {
        parent::configureContainer($container);

        $container->services()
            ->set(GatedPage::class)
            ->public();
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $page = GatedPage::class;

        // Declared, and on the fleet's path shape: the ordinary case.
        $routes->add('alpha_dashboard', '/areas/{uuid}/modules/alpha')
            ->controller($page)
            ->defaults([RegistryBundle::MODULE_ROUTE_DEFAULT => 'alpha']);

        // Declared, and nowhere near the path shape — the marker is what makes
        // this gateable at all, and it names the parameter carrying the area.
        $routes->add('alpha_console', '/alpha-console/{place}')
            ->controller($page)
            ->defaults([
                RegistryBundle::MODULE_ROUTE_DEFAULT => 'alpha',
                RegistryBundle::MODULE_ROUTE_AREA_DEFAULT => 'place',
            ]);

        // Declares nothing. A module that forgot, or one written before the
        // gate existed; the path shape is the safety net under it.
        $routes->add('beta_log', '/areas/{uuid}/modules/beta/log')->controller($page);

        // An installation's OWN screen, wearing the same path shape. "compare"
        // is not a module in anybody's catalogue, and the gate must leave it
        // alone: a segment is a module only when the catalogue says so.
        $routes->add('host_compare', '/areas/{uuid}/modules/compare')->controller($page);

        // Not a module route by either reading.
        $routes->add('host_area', '/areas/{uuid}')->controller($page);
    }

    /**
     * A container of its own. {@see HostKernel} keys its cache on the installed
     * modules alone, which is right for a kernel with no routes and wrong the
     * moment a second kernel class shares the same installed set.
     */
    public function getCacheDir(): string
    {
        return parent::getCacheDir().'/routed';
    }
}
