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

use Uhifadhi\Bundle\ShellBundle\Service\SettingsSection;

/*
 * THE SETTINGS SECTION'S ONE ADDRESS — shipped as a resource, loaded by nobody
 * here, exactly like the welcome page's and the configure page's. An
 * application asks for it in one line, in a file it owns:
 *
 *     # config/routes/shell.yaml (your application)
 *     shell_settings:
 *         resource: '@ShellBundle/config/routes/settings.php'
 *
 * An installation that never imports it has no settings section and no row in
 * the sidebar for one — the navigation source generates this route and yields
 * nothing when it cannot.
 *
 * THE SCREEN IS A TRAILING OPTIONAL PARAMETER, which is what makes the ruled
 * order visible in the URL: the section's FIRST screen is the bare address and
 * every other screen hangs one segment below it. `/settings` is the overview
 * and `/settings/installation` is what is installed, and the router generates
 * the bare form on its own whenever no screen is named. The same shape a
 * configure page's sections wear, for the same reason.
 *
 * ONE ROUTE, NOT FOUR, because the set of screens is declared once — in
 * Uhifadhi\Contracts\Settings\SettingsTab — and a route per screen would be a
 * second list to keep in step with it. The requirement below is deliberately
 * the shape of a segment rather than an enumeration of the four for the same
 * reason; a segment that names no screen is refused by the controller, with a
 * 404 that says which.
 */
/*
 * AND THE INSTALLATION'S MANUAL, imported from here so that every
 * installation that has the settings section has the manual's addresses too,
 * with no routing line of its own (#24). They serve nothing until
 * `shell.manual` names a folder. See config/routes/manual.php.
 */
return static function (RoutingConfigurator $routes): void {
    $routes->import('manual.php');

    $routes->add(SettingsSection::ROUTE, '/settings/{'.SettingsSection::PARAMETER.'}')
        ->controller('shell.controller.settings')
        ->requirements([SettingsSection::PARAMETER => '[a-z][a-z0-9-]*'])
        ->defaults([SettingsSection::PARAMETER => null])
        ->methods(['GET']);
};
