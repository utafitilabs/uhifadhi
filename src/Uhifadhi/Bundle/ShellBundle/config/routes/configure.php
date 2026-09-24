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

namespace Symfony\Component\Routing\Loader\Configurator;

use Symfony\Component\Routing\Requirement\Requirement;
use Uhifadhi\Bundle\ShellBundle\Frame\Controller\ConfigureController;

/*
 * THE CONFIGURE PAGE'S TWO ADDRESSES — shipped as a resource, loaded by nobody
 * here, exactly like the welcome page's. An application asks for them in one
 * line, in a file it owns:
 *
 *     # config/routes/shell.yaml (your application)
 *     shell_configure:
 *         resource: '@ShellBundle/config/routes/configure.php'
 *
 * An installation that never imports it has no configure page and every module
 * in it keeps whatever it had; nothing here claims a URL the application did
 * not ask it to claim.
 *
 * THE SECTION IS A TRAILING OPTIONAL PARAMETER. Every section is addressed by
 * name — `/areas/{uuid}/configure/widgets`, `/areas/{uuid}/configure/settings`
 * — one address shape for everything a surface is set up with, whether the
 * shell renders the section or it keeps a screen of its own. The bare address
 * is the `Configure` action's way in: it opens on the surface's FIRST section,
 * Widget library by the ruled order, and names none.
 *
 * A surface whose first section keeps an address of its own cannot be drawn at
 * the bare address, so the bare address answers 302 to that screen instead. One
 * rule, both shapes — see ModuleFrameService::bareAddressRedirect().
 *
 * A SECTION WITH A SCREEN OF ITS OWN takes the same shape at its own route —
 * `/areas/{uuid}/configure/modules`, `/areas/{uuid}/configure/zones` — mounted
 * with a priority so the shell's `{section}` parameter never swallows it.
 *
 * THE MODULE ADDRESS WEARS THE PLATFORM'S MODULE PATH SHAPE on purpose:
 * `/areas/{uuid}/modules/{slug}/…` is the shape a parked module is closed by, so
 * a module switched off for an area has no configure page either, with nothing
 * written here to arrange it.
 */
return static function (RoutingConfigurator $routes): void {
    $routes->add(ConfigureController::AREA_ROUTE, '/areas/{uuid}/configure/{section}')
        ->controller(['shell.controller.configure', 'area'])
        ->requirements(['uuid' => Requirement::UUID, 'section' => '[a-z][a-z0-9-]*'])
        ->defaults(['section' => null])
        ->methods(['GET']);

    $routes->add(ConfigureController::MODULE_ROUTE, '/areas/{uuid}/modules/{slug}/configure/{section}')
        ->controller(['shell.controller.configure', 'module'])
        ->requirements(['uuid' => Requirement::UUID, 'slug' => '[a-z]+', 'section' => '[a-z][a-z0-9-]*'])
        ->defaults(['section' => null])
        ->methods(['GET']);
};
