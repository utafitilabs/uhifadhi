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

use Uhifadhi\Bundle\ShellBundle\Contract\LayoutContract;
use Uhifadhi\Bundle\ShellBundle\Controller\FaviconController;
use Uhifadhi\Bundle\ShellBundle\Controller\SettingsController;
use Uhifadhi\Bundle\ShellBundle\Controller\WelcomeController;
use Uhifadhi\Bundle\ShellBundle\Frame\Controller\ConfigureController;
use Uhifadhi\Bundle\ShellBundle\Frame\Registry\ConfigurationSectionsRegistry;
use Uhifadhi\Bundle\ShellBundle\Frame\Registry\ModuleTabsRegistry;
use Uhifadhi\Bundle\ShellBundle\Frame\Service\ModuleFrameService;
use Uhifadhi\Bundle\ShellBundle\Service\AreaShell;
use Uhifadhi\Bundle\ShellBundle\Service\Installation;
use Uhifadhi\Bundle\ShellBundle\Service\Navigation;
use Uhifadhi\Bundle\ShellBundle\Service\OrgModulesNavigation;
use Uhifadhi\Bundle\ShellBundle\Service\OrgShell;
use Uhifadhi\Bundle\ShellBundle\Service\Scopes;
use Uhifadhi\Bundle\ShellBundle\Service\SettingsNavigation;
use Uhifadhi\Bundle\ShellBundle\Service\SettingsReading;
use Uhifadhi\Bundle\ShellBundle\Service\SettingsSection;
use Uhifadhi\Bundle\ShellBundle\Service\Stylesheets;
use Uhifadhi\Bundle\ShellBundle\Service\Theme;
use Uhifadhi\Bundle\ShellBundle\Service\UserBadgeReader;
use Uhifadhi\Bundle\ShellBundle\ShellBundle;
use Uhifadhi\Bundle\ShellBundle\Twig\ShellExtension;
use Uhifadhi\Bundle\ShellBundle\Twig\ShellRuntime;
use Uhifadhi\Contracts\Settings\ModuleMatrixSourceInterface;
use Uhifadhi\Contracts\Settings\OrganizationIdentitySourceInterface;
use Uhifadhi\Contracts\Shell\ConfigurationSectionsInterface;
use Uhifadhi\Contracts\Shell\ModuleTabsInterface;

/*
 * The bundle's static service wiring.
 *
 * PHP (not YAML) on purpose: a reusable bundle must not force symfony/yaml onto
 * hosts, and FQCN references stay refactor-safe and phpstan-checked. Imported by
 * ShellBundle::loadExtension(), which keeps only the config-DRIVEN
 * definitions.
 *
 * Everything defined here is defined EXPLICITLY — no autowire(), no autoconfigure(),
 * and ids prefixed with the bundle alias — because this bundle is installed by other
 * projects via Composer, which is what Symfony calls a reusable bundle:
 *
 *   "Services should not use autowiring or autoconfiguration. Instead, all
 *    services should be defined explicitly."
 *   "If the bundle defines services, they must be prefixed with the bundle alias."
 *   — https://symfony.com/doc/current/bundles/best_practices.html
 *
 * A NOTE ON TWIG, because this bundle is mostly Twig and the split is not
 * decoration: an EXTENSION is constructed as soon as the `twig` service is
 * built, and the image build does exactly that (asset-map:compile fires the
 * asset-compile event and UX Icons warms its cache off it). An extension
 * holding anything that reads a request or a repository therefore breaks the
 * BUILD, not a page. So the shell ships a thin extension that declares
 * functions and a RUNTIME that is constructed lazily, on the first call — i.e.
 * only when a template is actually rendered. The host learned this the hard
 * way with its sidebar; the shell inherits the lesson, not the bug.
 */
return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    // The frozen manifest, as a service, so a host or a module can ask
    // the container which contract version it is mounting rather than reading
    // a constant off a class it had to guess the name of.
    $services->set('shell.contract', LayoutContract::class);

    /*
     * THE NAV CONTRACT'S COLLECTOR. A tagged iterator, and nothing else: whatever
     * carries the tag contributes, whether that is the host folding areas and
     * permissions into rows or a module adding one platform-wide row.
     *
     * The iterator is lazy and is walked on every render, which is what makes
     * the contract's same-day promise true — switch a contributor off and its rows
     * are gone on the next request, not after a deploy.
     */
    $services->set('shell.navigation', Navigation::class)
        ->args([tagged_iterator(ShellBundle::NAV_TAG)]);

    /*
     * HOW WIDE THE PAGE IS LOOKING. The shell draws the scope control and
     * knows no areas: the slices come from the host, which has the areas,
     * the viewer and the voters, and the current one is resolved off the
     * address.
     */
    $services->set('shell.scopes', Scopes::class)
        ->args([tagged_iterator(ShellBundle::SCOPE_TAG), service('request_stack')]);
    $services->alias(Scopes::class, 'shell.scopes');

    /*
     * AND THE MODULES THAT ANSWER AT ORGANIZATION LEVEL, as rows in
     * Observatory. Tagged as a nav source like any other contributor — the
     * shell collects an interface from the contracts package and names no
     * module doing it.
     */
    $services->set('shell.org_shell', OrgShell::class)
        ->args([
            tagged_iterator(ShellBundle::ORG_PAGES_TAG),
            service('router'),
            service('request_stack'),
            service('shell.scopes'),
        ]);
    $services->alias(OrgShell::class, 'shell.org_shell');

    $services->set('shell.org_modules_navigation', OrgModulesNavigation::class)
        ->args([service('shell.org_shell')])
        ->tag(ShellBundle::NAV_TAG);

    /*
     * THE SHEETS A PAGE LINKS FOR COMPONENTS IT DOES NOT KNOW IT WILL
     * DRAW. A tagged iterator again, and the tag's priority is the load
     * order — a component's vocabulary has to reach the head, because a
     * stylesheet link in the body is not conforming HTML and a component
     * therefore cannot bring its own.
     */
    $services->set('shell.stylesheets', Stylesheets::class)
        ->args([tagged_iterator(ShellBundle::STYLESHEET_TAG)]);

    /*
     * THE AREA CONTRACT'S READER. One source, aliased by the host to the id below —
     * an alias rather than a tagged collection because two things claiming to
     * know where the viewer is, is exactly the disagreement this bundle exists
     * to prevent.
     *
     * nullOnInvalid() is the stand-alone rule written into the container: a
     * fresh installation declares no such source, and it must get pages with
     * no tab strip rather than a container that will not compile.
     */
    $services->set('shell.area_shell', AreaShell::class)
        ->args([service('shell.area_shell_source')->nullOnInvalid()]);

    /*
     * THE TOP BAR'S VIEWER-CARD READER. One optional source, aliased by the
     * host to the id below — an alias rather than a tagged collection for the
     * area contract's reason: two things claiming to know who is signed in is the
     * disagreement this bundle exists to prevent.
     *
     * nullOnInvalid() is the stand-alone rule written into the container: a fresh
     * installation declares no such source, and it must get a top bar with no
     * card rather than a container that will not compile.
     */
    $services->set('shell.user_badge', UserBadgeReader::class)
        ->args([service('shell.user_badge_source')->nullOnInvalid()]);

    // What a visitor who has never chosen a theme gets. Everything else about
    // the theme is the browser's, resolved before the first paint.
    $services->set('shell.theme', Theme::class)
        ->args(['%shell.default_theme%']);

    // WHAT THIS INSTALLATION IS MADE OF, read from composer's runtime API. No
    // arguments: the answer is a property of the vendor directory, not of any
    // configuration, and a welcome screen that reported a list somebody typed
    // would be wrong the first time anybody installed a module.
    $services->set('shell.installation', Installation::class);

    /*
     * THE WELCOME PAGE, as a controller service.
     *
     * The `controller.service_arguments` tag is what lets the route address it
     * by this id: the tag registers the service with the controller resolver's
     * locator, which is how a controller stays a normal, explicitly wired
     * service instead of a public one fished out of the container by class name.
     *
     * REACHABLE ONLY IF THE APPLICATION SAYS SO. Registering this service does
     * not put it at an address — config/routes/welcome.php does that, and
     * nothing here loads that file (see ShellBundle::ROUTES).
     */
    $services->set('shell.controller.welcome', WelcomeController::class)
        ->args([
            service('twig'),
            service('shell.installation'),
            // WHAT THE KERNEL ACTUALLY BOOTS. A core bundle on disk that
            // nothing registers is a directory, and the page may not report it
            // as something this installation has.
            param('kernel.bundles'),
        ])
        ->tag('controller.service_arguments');

    /*
     * THE MODULE FRAME'S TWO COLLECTORS. Tagged iterators, walked on every
     * render: whatever carries the tag contributes, and a module switched off
     * this morning is out of the strip this morning rather than after a deploy.
     *
     * The pair is deliberate and not one registry with two verbs — a module with
     * data places need not have a configure page, and a surface with a configure
     * page need not have data places (the area is exactly that).
     */
    $services->set('shell.frame.module_tabs', ModuleTabsRegistry::class)
        ->args([tagged_iterator(ModuleTabsInterface::TAG)]);

    $services->set('shell.frame.configuration_sections', ConfigurationSectionsRegistry::class)
        ->args([tagged_iterator(ConfigurationSectionsInterface::TAG)]);

    /*
     * WHICH STRIP A PAGE GETS, AND WHERE ITS `Configure` GOES.
     *
     * THE MODULE MARKER ARRIVES AS A STRING, not as a constant read off the
     * registry. The shell requires no registry — a page frame that had to be
     * installed alongside a module ledger would not be a page frame — and
     * importing one to learn the spelling of a route default would be buying a
     * dependency for a word. The default is the marker the platform publishes as
     * RegistryBundle::MODULE_ROUTE_DEFAULT. It is an argument rather than a
     * configuration key because it is a property of the PLATFORM, not of a
     * deployment: nobody installing the shell gets to choose it, and this tree
     * stays as small as its own rules demand.
     */
    $services->set('shell.frame', ModuleFrameService::class)
        ->args([
            service('request_stack'),
            service('router'),
            service('shell.frame.module_tabs'),
            service('shell.frame.configuration_sections'),
            service('shell.area_shell'),
            ConfigureController::AREA_ROUTE,
            ConfigureController::MODULE_ROUTE,
            ModuleFrameService::MODULE_ROUTE_ATTRIBUTE,
            ModuleFrameService::AREA_PARAMETER,
        ]);

    /*
     * THE CONFIGURE PAGE, as a controller service — the `controller.service_arguments`
     * tag is what lets the routes address it by id, keeping it an ordinary,
     * explicitly wired service rather than a public one fished out by class name.
     *
     * REACHABLE ONLY IF THE APPLICATION SAYS SO. config/routes/configure.php puts
     * it at an address, and nothing here loads that file.
     */
    $services->set('shell.controller.configure', ConfigureController::class)
        ->args([
            service('twig'),
            service('shell.frame'),
        ])
        ->tag('controller.service_arguments');

    /*
     * THE SETTINGS SECTION — the reading, the frame, its row in the sidebar,
     * and the one controller that draws all four of its screens.
     *
     * THE READING IS COMPOSED, NOT AUTHORED. What the section can read for
     * itself is composer's runtime metadata and the wordmark it was
     * configured with; areas, people, what runs where and whose installation
     * this is arrive through the contracts, which is why every collaborator
     * below is either a tagged iterator or a locator of optional aliases.
     *
     * TWO OF THEM ARE ALIASES RATHER THAN COLLECTIONS. Which modules run in
     * which areas, and whose organization this is, each have exactly one
     * answer; two things claiming to know either would be a disagreement with
     * no way to settle it. They are looked up through a locator so that an
     * installation where nobody answers gets a screen that says so, rather
     * than a container that refuses to compile.
     */
    $services->set('shell.settings.reading', SettingsReading::class)
        ->args([
            service('shell.installation'),
            tagged_iterator(ShellBundle::SETTINGS_FIGURE_TAG),
            tagged_iterator(ShellBundle::SETTINGS_CHECK_TAG),
            tagged_iterator(ShellBundle::SETTINGS_DECISION_TAG),
            tagged_iterator(ShellBundle::SETTINGS_CHANGE_TAG),
            tagged_iterator(ShellBundle::SETTINGS_STEP_TAG),
            service_locator([
                ModuleMatrixSourceInterface::SERVICE => service(ModuleMatrixSourceInterface::SERVICE)->ignoreOnInvalid(),
                OrganizationIdentitySourceInterface::SERVICE => service(OrganizationIdentitySourceInterface::SERVICE)->ignoreOnInvalid(),
            ]),
            '%shell.brand_name%',
            // WHAT THE KERNEL ACTUALLY BOOTS. A part of the core on disk that
            // nothing registers is a directory, and the page may not report
            // it as something this installation has.
            param('kernel.bundles'),
        ]);

    $services->set('shell.settings.section', SettingsSection::class)
        ->args([
            service('router'),
            service('shell.settings.reading'),
        ]);

    /*
     * ITS ROW IN THE SIDEBAR, in the group that comes last. Tagged by hand,
     * like every other contribution here: this bundle is not autoconfigured.
     * The row disappears on its own where the application has not imported
     * the section's route resource, because the source generates the address
     * and yields nothing when it cannot.
     */
    $services->set('shell.settings.navigation', SettingsNavigation::class)
        ->args([
            service('shell.settings.section'),
            service('request_stack'),
        ])
        ->tag(ShellBundle::NAV_TAG);

    /*
     * THE SECTION'S ONE CONTROLLER. The `controller.service_arguments` tag is
     * what lets the route address it by id; registering it puts it at no
     * address — config/routes/settings.php does that, and nothing here loads
     * that file (see ShellBundle::SETTINGS_ROUTES).
     */
    /*
     * `/favicon.ico`, ANSWERED FROM THE FILE THE DOCUMENT ALREADY LINKS. The
     * path is resolved here rather than in the controller so the controller
     * stays a thing a test can hand any file to — and there is one statement
     * of where the bundle keeps its mark.
     */
    $services->set('shell.controller.favicon', FaviconController::class)
        ->args([\dirname(__DIR__).'/public/favicon.svg'])
        ->tag('controller.service_arguments');

    $services->set('shell.controller.settings', SettingsController::class)
        ->args([
            service('twig'),
            service('shell.settings.section'),
        ])
        ->tag('controller.service_arguments');

    $services->set('shell.twig.extension', ShellExtension::class)
        ->tag('twig.extension');

    $services->set('shell.twig.runtime', ShellRuntime::class)
        ->args([
            service('shell.navigation'),
            service('shell.stylesheets'),
            service('shell.area_shell'),
            service('shell.frame'),
            service('shell.org_shell'),
            service('shell.user_badge'),
            // WHOSE INSTALLATION THIS IS, for the top bar's lockup and the
            // browser title. The section's own reading, not a second one: two
            // services answering "what is this installation called" is the
            // disagreement the single-source contract exists to prevent.
            service('shell.settings.reading'),
            service('shell.theme'),
            service('router'),
            '%shell.brand_name%',
            '%shell.home_route%',
            service('security.token_storage')->nullOnInvalid(),
        ])
        ->tag('twig.runtime');
};
