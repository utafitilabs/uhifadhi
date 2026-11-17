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

use Uhifadhi\Bundle\ShellBundle\Controller\ManualController;

/*
 * THE INSTALLATION'S MANUAL — the page, its pictures and its PDF. Imported by
 * config/routes/settings.php, so an installation with the settings section
 * has these with no line of its own; one without imports this file directly:
 *
 *     # config/routes/shell.yaml (your application)
 *     shell_manual:
 *         resource: '@ShellBundle/config/routes/manual.php'
 *
 * Whether they serve anything is `shell.manual`'s to say: with no folder
 * named, all three answer 404.
 *
 * THE PAGE'S ADDRESS ENDS IN A SLASH, so the manual's own relative links
 * (`img/…`) resolve under it; the router sends `/manual` there.
 *
 * A PICTURE'S NAME IS ONE SEGMENT, whatever it holds: the controller refuses
 * anything that is not a picture's name with a 404, so a bad name and a
 * missing picture read the same.
 */
return static function (RoutingConfigurator $routes): void {
    $routes->add(ManualController::ROUTE, '/manual/')
        ->controller('shell.controller.manual::page')
        ->methods(['GET']);

    $routes->add(ManualController::PICTURE_ROUTE, '/manual/img/{name}')
        ->controller('shell.controller.manual::picture')
        ->requirements(['name' => '[^/]+'])
        ->methods(['GET']);

    $routes->add(ManualController::PDF_ROUTE, '/manual/manual.pdf')
        ->controller('shell.controller.manual::pdf')
        ->methods(['GET']);
};
