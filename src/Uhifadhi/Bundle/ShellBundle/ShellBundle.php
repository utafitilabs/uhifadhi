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

namespace Uhifadhi\Bundle\ShellBundle;

use Symfony\Component\AssetMapper\AssetMapperInterface;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use Uhifadhi\Bundle\ShellBundle\DependencyInjection\ShellConfiguration;
use Uhifadhi\Bundle\ShellBundle\Widget\Registry\WidgetSurfaceInterface;

/**
 * The SHELL — the visible shell every uhifadhi page grows into.
 *
 * An application registers this bundle and every module renders into it. This
 * is what you see: the document, the shell (sidebar + top bar), the page frame
 * (breadcrumbs, page head, actions, tabs, flashes, body), the navigation contracts
 * and the theme. A module writes a page by filling this bundle's blocks; it
 * writes no shell of its own, and it copies no `<div class="page">`.
 *
 * IT IS A CONTRACT, NOT A CONVENTION. The block names, the registry interfaces and
 * the theme tokens are a versioned, test-enforced API — Symfony-extension-point
 * grade. Renaming a block is a breaking change and has to be made as one: the
 * manifest is frozen in a test that fails if the list moves at all. This exists
 * because the alternative was in production — every module re-declaring
 * `{% block content %}<div class="page">…` by copy, so that a change to the
 * frame reached the modules that happened to be remembered.
 *
 * IT REMEMBERS ONE THING, AND IT IS FURNITURE. Which widgets a person adopted
 * on which surface is the same kind of fact as which theme they chose — about
 * the frame, not about a domain — and every module with a dashboard would
 * otherwise reinvent the storage for it. That machinery lives under Widget/ and
 * nowhere else; tests/Unit/BoundaryTest.php fails the build if an entity appears
 * anywhere outside it.
 *
 * IT KNOWS NO MODULE BY NAME — the same rule the registry holds, for the same
 * reason and enforced by the same kind of sweep. A module's row in the sidebar
 * and its card in the grid arrive as DATA, through the contracts in src/Contract;
 * the shell renders whatever it is handed and recognises no slug.
 *
 * IT SHIPS ZERO WIDGETS, the way it names zero modules. A module with a
 * dashboard DECLARES a surface — a catalogue of widgets, their groups, and the
 * whole layouts a person may adopt; this bundle COLLECTS the declarations,
 * STORES what each person adopted, and RESOLVES the two into the layout a page
 * renders. A widget of its own would be the shell breaking its own boundary.
 */
final class ShellBundle extends AbstractBundle
{
    /**
     * The shell's stylesheet, as a host and every module must link it.
     *
     * Published as a constant for the reason every module's own is: the
     * path is written in more than one place (the shell links it; a module base
     * extends the shell; a shared surface renders a contributor's sheet beside
     * it), and a path typed twice is a path that eventually differs.
     *
     * Under AssetMapper a bundle's public/ directory is exposed automatically
     * as `bundles/<lowercased bundle name>/`, content-versioned, with no
     * assets:install step.
     *
     * @see https://symfony.com/doc/current/frontend/asset_mapper.html
     */
    public const string STYLESHEET = 'bundles/shell/shell.css';

    /**
     * The tab icon: the full masterbrand mark, as one SVG at every size.
     *
     * Shipped rather than left to the host because a document that declares no
     * icon is a document every browser asks `/favicon.ico` for, and a fresh
     * installation answered that with a 404 in its first minute. A deployment with a
     * brand of its own replaces the link through the head's sockets.
     */
    public const string FAVICON = 'bundles/shell/favicon.svg';

    /**
     * THE WIDGET LIBRARY'S OWN SHEET. It is written in the shell's tokens and
     * defines none of its own, so an installation that restyles the shell
     * restyles this with it. Stated as a constant because the page that renders
     * the library has to name it, and nobody should retype a vendor path.
     */
    public const string WIDGET_STYLESHEET = 'bundles/shell/widget.css';

    /**
     * THE SHELL'S ROUTES, as the one line an application writes to accept them.
     *
     * The shell ships one route — the welcome page's — as a RESOURCE this
     * bundle never loads. An application imports it in its own
     * config/routes/shell.yaml and owns the decision:
     *
     *     shell:
     *         resource: '@ShellBundle/config/routes/welcome.php'
     *
     * Published as a constant for the reason the stylesheet's path is: this
     * string is written in the recipe, in the skeleton, in docs/boundaries.md and in
     * every installation, and a path typed twice is a path that eventually
     * differs.
     */
    public const string ROUTES = '@ShellBundle/config/routes/welcome.php';

    /**
     * THE CONFIGURE PAGE'S ADDRESSES, shipped the same way and imported the same
     * way:
     *
     *     shell_configure:
     *         resource: '@ShellBundle/config/routes/configure.php'
     *
     * A SECOND RESOURCE RATHER THAN A SECOND ROUTE IN THE FIRST, because the two
     * are separate decisions: an installation may want the welcome page and no
     * configure page (nothing configurable yet), or the configure page and its
     * own front door at `/`. One file per decision keeps both reversible in one
     * line.
     */
    public const string CONFIGURE_ROUTES = '@ShellBundle/config/routes/configure.php';

    /**
     * THE SETTINGS SECTION'S ADDRESS, shipped the same way and imported the
     * same way:
     *
     *     shell_settings:
     *         resource: '@ShellBundle/config/routes/settings.php'
     *
     * A THIRD RESOURCE FOR A THIRD DECISION, by the same argument the
     * configure page's is a second one: an installation may want the section
     * and not the welcome page — the ordinary case now that `/` belongs to
     * the organization dashboard — or neither. One file per decision keeps
     * each of them reversible in one line, and the sidebar's Settings group
     * simply does not appear where the line is missing.
     */
    public const string SETTINGS_ROUTES = '@ShellBundle/config/routes/settings.php';

    /**
     * `/favicon.ico`, shipped the same way and imported the same way:
     *
     *     shell_favicon:
     *         resource: '@ShellBundle/config/routes/favicon.php'
     *
     * A FOURTH RESOURCE FOR A FOURTH DECISION. An installation serving its
     * own icon from the web server, or from a CDN in front of it, wants this
     * address left alone, and deleting one import is how it says so.
     */
    public const string FAVICON_ROUTES = '@ShellBundle/config/routes/favicon.php';

    /**
     * The AssetMapper namespace this bundle's assets/ directory is mapped to.
     *
     * It is the npm-style form of the composer package name, and it has to be:
     * Flex keys assets/controllers.json by '@'.<composer package name>, and
     * StimulusBundle resolves that key back to this directory. A different name
     * here is a controller the host cannot find.
     */
    public const string ASSET_NAMESPACE = '@uhifadhi/shell-bundle';

    /**
     * The prefix every one of this bundle's Stimulus controllers is addressed
     * by in a template — StimulusBundle's own normalisation of the namespace
     * above ('@' dropped, '/' and '_' to '-'), so `theme` is reached as
     * `uhifadhi--shell-bundle--theme`.
     */
    public const string CONTROLLER_PREFIX = 'uhifadhi--shell-bundle--';

    /**
     * The tag a bundle carries to contribute a NAV SECTION to the shell.
     *
     * Published as a constant because the shell is the end that COLLECTS it: a
     * contributor writes the string by hand in its own extension (a reusable
     * bundle's services are not autoconfigured), and a host or a test standing
     * in for the collector should not retype it. The shell renders whatever
     * arrives and reads no slug off it — see src/Contract.
     */
    public const string NAV_TAG = 'shell.nav_section';

    /**
     * WHAT A PACKAGE TAGS TO HAVE ITS COMPONENT'S SHEET IN EVERY HEAD.
     *
     * The same string as {@see StylesheetSourceInterface::TAG}, written
     * out for the same reason the nav tag is: this file is read by an
     * installation that may not have the contract class loaded.
     */
    public const string STYLESHEET_TAG = 'shell.stylesheet';

    /**
     * WHAT A HOST TAGS TO FILL THE SCOPE CONTROL — the slices this viewer
     * may look at, on an organization-level page.
     *
     * The shell draws the control and knows no areas: it holds no domain and
     * no authorization service, so the list comes from the one place that has
     * both. Same reasoning, and same spelling discipline, as the nav tag.
     */
    public const string SCOPE_TAG = 'shell.scope_source';

    /**
     * WHAT A MODULE TAGS TO ANSWER AT ORGANIZATION LEVEL — its own page set,
     * which the shell mounts in Observatory after Performance.
     *
     * A module writes no sidebar item, no tab strip and no scope control:
     * it says which screens it has one scope wider and the shell draws the
     * chrome, exactly as it does for an area's tabs.
     */
    public const string ORG_PAGES_TAG = 'shell.org_pages';

    /**
     * WHAT A BUNDLE TAGS TO PUT A FIGURE ON THE SETTINGS OVERVIEW'S ROW.
     *
     * The row states how big this installation is and no single package
     * knows: areas belong to one bundle, people to another, what is kept to a
     * module. Same spelling discipline as the nav tag, and the same reason —
     * a contributor writes the string by hand in its own extension.
     */
    public const string SETTINGS_FIGURE_TAG = 'shell.settings_figure';

    /** WHAT A BUNDLE TAGS TO ANSWER ONE OF THE INSTALLATION SCREEN'S HEALTH QUESTIONS. */
    public const string SETTINGS_CHECK_TAG = 'shell.settings_check';

    /** WHAT A BUNDLE TAGS TO SAY THE INSTALLATION NEEDS A DECISION. */
    public const string SETTINGS_DECISION_TAG = 'shell.settings_decision';

    /** WHAT A BUNDLE TAGS TO REPORT A CHANGE TO THE INSTALLATION. */
    public const string SETTINGS_CHANGE_TAG = 'shell.settings_change';

    /**
     * WHAT A BUNDLE TAGS TO PUT A STEP ON THE SETTINGS OVERVIEW'S CHECKLIST.
     *
     * Adding an area is one bundle's step and composing a position another's,
     * and a module with a step of its own publishes it the day it is
     * installed. A hardcoded list would go stale the first time the product
     * grew one more.
     */
    public const string SETTINGS_STEP_TAG = 'shell.settings_step';

    /** Config lives under "shell:", not the class-derived "uhifadhi_shell:". */
    protected string $extensionAlias = 'shell';

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
        ShellConfiguration::define($definition->rootNode());
    }

    /**
     * EVERY GLYPH THE CORE DRAWS, SHIPPED WITH IT AND RESOLVED FROM DISK.
     *
     * A deployment turns ux-icons' on-demand fetching off, so a name no file
     * answers to is a blank square rather than a round trip to a remote API.
     * The core's own chrome may depend on neither, so every glyph it draws —
     * the sidebar's collapse chevron, the tree's caret, the theme toggle, the
     * catalogue's lens marker and the whole drawn vocabulary the shell, the
     * team screens and the area screens share — is a file in one directory
     * here, reached under `shell:`.
     *
     * ONE PREFIX, AND IT IS THE ONLY ONE THE CORE CLAIMS. An icon set maps a
     * prefix to a single `path`, and a prefix so mapped is answered ONLY from
     * that path: the lookup never falls back to an application's `icon_dir`.
     * Registering a second prefix here would therefore take that word away from
     * every installation and every module that installs into one. Each package
     * answers for its own alias and no other — this one for `shell:`, a module
     * for the alias it ships, an application for whatever it keeps in
     * `assets/icons/`.
     *
     * @see https://symfony.com/bundles/ux-icons/current/index.html#full-configuration
     * @see https://symfony.com/bundles/ux-icons/current/index.html#importing-icons
     * @see vendor/symfony/ux-icons/src/Registry/LocalSvgIconRegistry.php
     */
    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        if ($builder->hasExtension('ux_icons')) {
            $builder->prependExtensionConfig('ux_icons', [
                'icon_sets' => [
                    'shell' => ['path' => __DIR__.'/assets/icons/shell'],
                ],
            ]);
        }

        // THE FURNITURE'S BEHAVIOUR, shipped with the furniture.
        //
        // The shell's own controls — the theme toggle, the sidebar's collapse,
        // the tree's carets — were markup here and behaviour in the host the
        // shell was extracted from, so on a fresh installation every one of them was
        // dead. A bundle contributes no importmap entry, but it does contribute
        // an AssetMapper path and a `symfony.controllers` block in
        // assets/package.json, which is how every symfony/ux package ships a
        // Stimulus controller (see TurboExtension::prepend). Flex writes the
        // host's assets/controllers.json on install; nothing is built.
        //
        // The bundle's public/ dir needs none of this: AssetMapper exposes it
        // as `bundles/shell/` on its own.
        if ($builder->hasExtension('framework') && interface_exists(AssetMapperInterface::class)) {
            // PREPENDED, THE SHAPE EVERY symfony/ux BUNDLE WRITES — and the one form
            // every block in this method takes, `prependExtensionConfig()` on the
            // builder, so this path goes FIRST and an installation's own framework
            // config wins: \"any other settings done explicitly inside the config/*
            // files would override these prepended settings\".
            //
            // @see https://symfony.com/doc/current/bundles/prepend_extension.html
            // @see https://symfony.com/doc/current/frontend/create_ux_bundle.html
            // @see vendor/symfony/ux-map/src/UXMapBundle.php:117
            // @see vendor/symfony/ux-chartjs/src/DependencyInjection/ChartjsExtension.php:58
            $builder->prependExtensionConfig('framework', [
                'asset_mapper' => [
                    'paths' => [
                        __DIR__.'/assets' => self::ASSET_NAMESPACE,
                    ],
                ],
            ]);
        }

        // Zero-config persistence for the one thing the shell remembers: the
        // dashboard layouts under Widget/. An installation never writes a
        // doctrine mappings block for widget_* tables.
        //
        // PREPENDED, LIKE EVERY BLOCK THIS METHOD WRITES — `prependExtensionConfig()`
        // on the builder, the form the docs and symfony/ux-map write — so this
        // config goes first and an installation that maps these classes itself
        // wins.
        //
        // @see https://symfony.com/doc/current/bundles/prepend_extension.html
        // @see vendor/symfony/ux-map/src/UXMapBundle.php:117
        if ($builder->hasExtension('doctrine')) {
            $builder->prependExtensionConfig('doctrine', [
                'orm' => [
                    'mappings' => [
                        'ShellWidget' => [
                            'type' => 'attribute',
                            'dir' => __DIR__.'/Widget/Entity',
                            'prefix' => 'Uhifadhi\\Bundle\\ShellBundle\\Widget\\Entity',
                            'is_bundle' => false,
                        ],
                    ],
                ],
            ]);
        }

        // THE TABLES ARRIVE WITH THE CODE. A person's widget layout and the
        // presets they saved are this bundle's, so their DDL ships here too,
        // under the bundle's own namespace — the shape the migrations bundle
        // documents for a bundle-shipped history:
        //
        // > migrations_paths:
        // >     'SomeBundle\Migrations': '@SomeBundle/Migrations'
        //
        // @see https://symfony.com/bundles/DoctrineMigrationsBundle/current/index.html
        // @see vendor/doctrine/doctrine-migrations-bundle/src/DependencyInjection/DoctrineMigrationsExtension.php
        //
        // Guarded: an application may install this bundle without the migrations
        // bundle in its kernel, and there it simply has no history to run.
        if ($builder->hasExtension('doctrine_migrations')) {
            $builder->prependExtensionConfig('doctrine_migrations', [
                'migrations_paths' => [
                    'Uhifadhi\\Bundle\\ShellBundle\\Migrations' => __DIR__.'/migrations',
                ],
            ]);
        }
    }

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        /*
         * An installation's own widget surfaces are autoconfigured, which saves
         * an application from tagging by hand. A module shipped as a reusable
         * bundle is NOT autoconfigured and still tags explicitly — see
         * WidgetSurfaceInterface.
         */
        $container->registerForAutoconfiguration(WidgetSurfaceInterface::class)
            ->addTag(WidgetSurfaceInterface::TAG);
    }

    /**
     * @param array<string, mixed> $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        // Static service wiring lives in a PHP config file (see config/services.php
        // for why PHP, not YAML). loadExtension keeps only the config-DRIVEN bits.
        $container->import('config/services.php');
        $container->import('config/widgets.php');

        // The wordmark beside the brand tile, and where the tile links. Neither
        // can be hardcoded: the shell is installed BY an application and does
        // not know its route names, and a deployment is entitled to its own
        // name over the shell of its own installation.
        $builder->setParameter(
            'shell.brand_name',
            \is_string($config['brand_name'] ?? null) ? $config['brand_name'] : 'Uhifadhi',
        );
        $builder->setParameter(
            'shell.home_route',
            \is_string($config['home_route'] ?? null) ? $config['home_route'] : 'organization_dashboard',
        );

        // Which theme a visitor who has never chosen one gets. Light by default
        // because the platform's own default is light and a shell that opened
        // dark on a ranger's midday screen would be a worse first frame than a
        // wrong preference.
        $builder->setParameter(
            'shell.default_theme',
            \is_string($config['default_theme'] ?? null) ? $config['default_theme'] : 'light',
        );
    }
}
