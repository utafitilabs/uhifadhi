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

namespace Uhifadhi\Core\Tests\Application;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Bundle\BundleInterface;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Uhifadhi\Bundle\ShellBundle\ShellBundle;
use Uhifadhi\Bundle\TeamBundle\Access\ConcernCatalogue;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Bundle\TeamBundle\Security\ApiTokenAuthenticator;
use Uhifadhi\Contracts\Roster\WatchProviderInterface;
use Uhifadhi\Core\Tests\Core\FakeRoster;

/**
 * THE THROWAWAY APPLICATION the core's own functional tests run inside.
 *
 * It exists so a specification can ask what a real installation does — every
 * core bundle in one kernel, one database, one router — without any repository
 * outside this one being involved. It is export-ignored: nobody installs it.
 *
 * It sits at the monorepo root rather than inside one bundle because the
 * bundles here are released together and their integration IS the product.
 *
 * NOT FINAL, for one reason: the core carries no module bundle, and a
 * specification about what the registry does with one — `registry:sync`
 * against the real migrations — stands a module up by extending this kernel
 * with a tagged provider ({@see \Uhifadhi\Core\Tests\Core\Fixtures\ModuleCarryingKernel}).
 */
class Kernel extends BaseKernel
{
    use CheckoutTempDirTrait;
    use MicroKernelTrait;

    public function registerBundles(): iterable
    {
        /** @var array<class-string<BundleInterface>, array<string, bool>> $contents */
        $contents = require __DIR__.'/config/bundles.php';

        foreach ($contents as $class => $envs) {
            if ($envs[$this->environment] ?? $envs['all'] ?? false) {
                yield new $class();
            }
        }
    }

    public function getProjectDir(): string
    {
        return __DIR__;
    }

    public function getCacheDir(): string
    {
        return $this->checkoutTempDir('application/cache/'.$this->environment);
    }

    public function getLogDir(): string
    {
        return $this->checkoutTempDir('application/log');
    }

    /**
     * Where this application's OWN versions go. A checkout has no such
     * directory in it, so one is made: a registered path that is not there is
     * a path the migration finder throws on.
     *
     * @see vendor/doctrine/migrations/src/Finder/Finder.php — `getRealPath()`
     */
    public function installationMigrationsDir(): string
    {
        $dir = $this->checkoutTempDir('application/migrations');

        if (!is_dir($dir) && !mkdir($dir, 0o777, true) && !is_dir($dir)) {
            throw new \RuntimeException(\sprintf('the throwaway application could not make "%s"', $dir));
        }

        return $dir;
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'secret' => 'test',
            'test' => true,
            'router' => ['utf8' => true],
            'http_method_override' => false,
            'handle_all_throwables' => true,
            'php_errors' => ['log' => true],
            'session' => ['storage_factory_id' => 'session.storage.factory.mock_file'],
            // THE ASSET SIDE A FLEX-INSTALLED APPLICATION HAS. The shell's
            // document links its own stylesheet through asset() and renders
            // the application's importmap, so a specification that renders a
            // PAGE rather than an API document needs both. Flex writes exactly
            // this pair — an `assets/` directory mapped at the root and an
            // `importmap.php` beside it — and this application is an
            // installation.
            'assets' => [],
            'asset_mapper' => ['paths' => [__DIR__.'/assets' => '']],
        ]);

        // WHAT A DEPLOYMENT SETS, AND WHY IT IS HERE. With on-demand fetching
        // on, a name no file answers to is fetched from a remote API and
        // cached, so a missing glyph stays invisible until the deployment that
        // has no outbound network draws a blank square. An installation turns
        // it off, and this application is an installation.
        //
        // @see https://symfony.com/bundles/ux-icons/current/index.html#icons-on-demand
        $container->extension('ux_icons', [
            'iconify' => ['on_demand' => false],
        ]);

        // WHICH RENDERER DRAWS THE MAPS. The atlas is built on UX Map and its
        // Leaflet bridge, and UX Map draws nothing at all until a renderer is
        // named — an installation writes this line, and this application is an
        // installation.
        //
        // @see https://symfony.com/bundles/ux-map/current/index.html#configuration
        $container->extension('ux_map', [
            'renderer' => 'leaflet://default',
        ]);

        /*
         * THE MACHINE API'S CONFIGURATION, as an installation receives it.
         *
         * The first four keys are verbatim what API Platform's own Flex recipe
         * writes into `config/packages/api_platform.yaml` — title, version, and
         * the two `defaults` — so the resources here are read under the settings
         * a real installation runs.
         *
         * `formats` IS THE ONE LINE THE RECIPE DOES NOT WRITE, and the field
         * contract needs it. API Platform's default first format is JSON-LD,
         * which answers with `@context` and `@id` members; a field client parses
         * the documented keys and nothing else, so JSON is the only format this
         * URL space offers and content negotiation has nothing else to pick.
         *
         * `mapping.paths` IS DELIBERATELY UNSET. A bundle's own `ApiResource`
         * directory is discovered from `kernel.bundles_metadata`, so neither the
         * core nor an installation names TeamBundle or AreaBundle anywhere —
         * and naming anything here would DISABLE the project-dir defaults an
         * installation's own resources rely on.
         *
         * @see https://api-platform.com/docs/symfony/#configuration
         * @see https://github.com/symfony/recipes/tree/main/api-platform/core/4.0 — the recipe's own config/packages/api_platform.yaml
         * @see vendor/api-platform/core/src/Symfony/Bundle/DependencyInjection/ApiPlatformExtension.php — `getBundlesResourcesPaths()`
         */
        $container->extension('api_platform', [
            'title' => 'Uhifadhi core test API',
            'version' => '1.0.0',
            'formats' => ['json' => ['application/json']],
            /*
             * NARROWED WITH `formats`, AND IT HAS TO BE. `error_formats`
             * defaults to JSON-LD FIRST and is a SEPARATE setting, so narrowing
             * only `formats` leaves API Platform rendering every refusal in a
             * format whose serializer is no longer registered — the serializer
             * throws INSIDE the exception handler and a 406 comes out as a 500.
             * Both lists are therefore narrowed together, always.
             *
             * @see vendor/api-platform/core/src/Symfony/Bundle/DependencyInjection/Configuration.php — the error_formats default
             */
            'error_formats' => ['json' => ['application/problem+json', 'application/json']],
            'defaults' => [
                'stateless' => true,
                'cache_headers' => ['vary' => ['Content-Type', 'Authorization', 'Origin']],
            ],
        ]);

        // THE TWO SETTINGS THAT DECIDE WHAT THE DDL LOOKS LIKE, copied from the
        // file the skeleton ships as `config/packages/doctrine.yaml`, because
        // the migrations in this repository are generated here and applied
        // there. Under the default naming strategy `areaUuid` would be a column
        // called `areaUuid`; under `underscore` it is `area_uuid`. Under the
        // default identity preference an `integer` primary key is a `SERIAL`;
        // under `identity` it is `GENERATED BY DEFAULT AS IDENTITY`. Either
        // difference is a permanent diff on a real installation.
        //
        // @see https://symfony.com/doc/current/doctrine.html
        $container->extension('doctrine', [
            'dbal' => ['url' => '%env(UHIFADHI_TEST_DATABASE_URL)%'],
            'orm' => [
                'controller_resolver' => ['auto_mapping' => false],
                'naming_strategy' => 'doctrine.orm.naming_strategy.underscore',
                'identity_generation_preferences' => [
                    PostgreSQLPlatform::class => 'identity',
                ],
            ],
        ]);

        // THE ONE LINE EVERY INSTALLATION HAS AND THE CORE DOES NOT SHIP: its
        // own migrations namespace, mapped in `config/packages/doctrine_migrations.yaml`
        // to a `migrations/` directory in the project. It is here because a
        // specification about where a generated version LANDS cannot be asked
        // of an application that has nowhere of its own to put one.
        //
        // @see https://symfony.com/bundles/DoctrineMigrationsBundle/current/index.html
        $container->extension('doctrine_migrations', [
            'migrations_paths' => [
                'DoctrineMigrations' => $this->installationMigrationsDir(),
            ],
        ]);

        /*
         * THE HUB THE AREA BUNDLE REQUIRES, with no address: this application
         * is the installation that configured none (the documented empty
         * `MERCURE_URL`), so the live-presence publisher publishes nothing,
         * no page sets a subscriber cookie, and every plate reads as the page
         * drew it. The bundle is registered because the services are injected
         * straight; the address is what a deployment adds.
         *
         * @see https://symfony.com/doc/current/mercure.html — "Configuration"
         */
        $container->extension('mercure', [
            'hubs' => [
                'default' => [
                    'url' => '',
                    'public_url' => '',
                    'jwt' => ['secret' => 'test-mercure-jwt-secret-at-least-256-bits-long'],
                ],
            ],
        ]);

        // The host provides monolog's `logger`; this application provides a NullLogger.
        $container->services()->set('logger', NullLogger::class);

        // A PRIVATE SERVICE A SPECIFICATION HAS TO REACH. Nothing in an
        // application references the devkit declarations — devkit does, and it
        // is not installed here — so the container compiles them away. The same
        // `test_public.` alias every bundle's own test kernel uses keeps this
        // one, and keeps it as the one the bundle actually defines rather than a
        // second copy built by hand.
        $container->services()
            ->alias('test_public.team.devkit.content', 'team.devkit.content')
            ->public();

        // WHAT THIS INSTALLATION SAYS THERE IS TO HAVE A PERMISSION ABOUT.
        // The build tests that hold routes, doors and declarations together
        // read the catalogue an installation actually compiles, rather than
        // a list assembled in the test — which would only ever agree with
        // itself.
        $container->services()
            ->alias('test_public.'.ConcernCatalogue::class, 'team.access.catalogue')
            ->public();

        // The ground's own demo content, for the same reason: a specification
        // that seeds the shipped organization has to reach the providers an
        // installation's devkit would run, not copies of them.
        $container->services()
            ->alias('test_public.area.devkit.areas', 'area.devkit.areas')
            ->public()
            ->alias('test_public.area.devkit.zones', 'area.devkit.zones')
            ->public()
            ->alias('test_public.area.devkit.stations', 'area.devkit.stations')
            ->public()
            ->alias('test_public.area.postings', 'area.postings')
            ->public()
            ->alias('test_public.area.stations', 'area.stations')
            ->public()
            ->alias('test_public.area.checkin_statuses', 'area.checkin_statuses')
            ->public()
            ->alias('test_public.area.checkins', 'area.checkins')
            ->public();

        // The credential a field client carries. The field-API specifications
        // mint a real token through it, so their requests cross the same
        // authenticator an installation's do rather than a logged-in session.
        $container->services()
            ->alias('test_public.team.api_token.manager', 'team.api_token.manager')
            ->public();

        /*
         * THE ROSTER MODULE AN INSTALLATION WOULD HAVE, played by a fixture
         * and TAGGED BY HAND as a reusable bundle's own contributor is — a
         * fixture relying on autoconfiguration would prove a wiring no
         * installation uses.
         *
         * It is here rather than in the area bundle's own kernel because the
         * endpoint that reads it exists only where api-platform and security
         * are both installed, which is this application and nowhere else.
         */
        $container->services()
            ->set(FakeRoster::class)
            ->tag(WatchProviderInterface::TAG);

        // The security file an installation gets from the skeleton, as this
        // throwaway application's own: the hashers, the entity provider over
        // the account TeamBundle owns, the checker that refuses a deactivated
        // one, the web firewall on the sign-in routes, and the two /api
        // firewalls the field client meets.
        $container->extension('security', [
            'password_hashers' => [
                PasswordAuthenticatedUserInterface::class => [
                    'algorithm' => 'auto',
                    'cost' => 4,
                    'time_cost' => 3,
                    'memory_cost' => 10,
                ],
            ],
            'providers' => [
                'team_user_provider' => [
                    'entity' => ['class' => User::class, 'property' => 'email'],
                ],
            ],
            'firewalls' => [
                'api_auth' => [
                    'pattern' => '^/api/auth/token$',
                    'security' => false,
                ],
                'api' => [
                    'pattern' => '^/api',
                    'stateless' => true,
                    'provider' => 'team_user_provider',
                    'user_checker' => 'team.user_checker',
                    'custom_authenticators' => [ApiTokenAuthenticator::class],
                    'entry_point' => ApiTokenAuthenticator::class,
                ],
                'main' => [
                    'lazy' => true,
                    'provider' => 'team_user_provider',
                    'user_checker' => 'team.user_checker',
                    'form_login' => [
                        'login_path' => 'team_login',
                        'check_path' => 'team_login',
                        'enable_csrf' => true,
                        'default_target_path' => '/',
                    ],
                    'logout' => ['path' => 'team_logout', 'target' => 'team_login'],
                    'remember_me' => ['secret' => '%kernel.secret%', 'lifetime' => 604800],
                ],
            ],
            'role_hierarchy' => [
                'ROLE_ADMIN' => ['ROLE_USER'],
                'ROLE_SUPER_ADMIN' => ['ROLE_ADMIN', 'ROLE_ALLOWED_TO_SWITCH'],
            ],
            'access_control' => [
                ['path' => '^/up$', 'roles' => 'PUBLIC_ACCESS'],
                ['path' => '^/login', 'roles' => 'PUBLIC_ACCESS'],
                ['path' => '^/reset-password', 'roles' => 'PUBLIC_ACCESS'],
                ['path' => '^/invite/', 'roles' => 'PUBLIC_ACCESS'],
                ['path' => '^/api/auth/token$', 'roles' => 'PUBLIC_ACCESS'],
                ['path' => '^/api', 'roles' => 'ROLE_USER'],
                ['path' => '^/', 'roles' => 'ROLE_USER'],
            ],
        ]);
    }

    /** The liveness answer: a status and nothing else, out of the container alone. */
    public function liveness(): Response
    {
        return new Response('', Response::HTTP_NO_CONTENT);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        // The shell ships its welcome route as a RESOURCE it never loads; an
        // application imports it, or does not, and owns the address either way.
        // This one does, because a throwaway app with no route at all cannot
        // answer whether the core serves a page.
        $routes->import(ShellBundle::ROUTES);

        // The configure page, the same way: a resource the shell ships and an
        // application asks for. Every module and the area reach their one
        // configuration entry through it.
        $routes->import(ShellBundle::CONFIGURE_ROUTES);

        // THE LIVENESS ROUTE, which an installation puts behind the proxy's
        // healthcheck and this application owns for the same reason: it answers
        // out of the container alone, with no database, no session and no
        // template, so a probe for it is a probe of the process and of nothing
        // else. A kernel's own method is the documented controller for a route an
        // application declares in code.
        //
        // @see vendor/symfony/framework-bundle/Kernel/MicroKernelTrait.php — `loadRoutes()` rewrites a [$this, 'method'] controller to the kernel service
        $routes->add('liveness', '/up')->controller([$this, 'liveness'])->methods(['GET']);

        /*
         * THE ONE `/api` ENTRY POINT, mounted exactly as API Platform's Flex
         * recipe mounts it in `config/routes/api_platform.yaml`:
         *
         *     api_platform:
         *         resource: .
         *         type: api_platform
         *         prefix: /api
         *
         * Every resource class in a registered bundle's `ApiResource` directory
         * reaches its address through this import and through nothing else, so
         * neither the core's two field resources nor a module's are named here.
         *
         * @see https://github.com/symfony/recipes/tree/main/api-platform/core/4.0 — the recipe's own config/routes/api_platform.yaml
         */
        $routes->import('.', 'api_platform')->prefix('/api');

        // Every screen TeamBundle draws, mounted where an installation's own
        // config/routes/team.yaml mounts it.
        $routes->import('@TeamBundle/Controller/', 'attribute');

        // The same for the area screens: the register, an area's overview, its
        // zones and the pages that compose it.
        $routes->import('@AreaBundle/Controller/', 'attribute');
    }
}
