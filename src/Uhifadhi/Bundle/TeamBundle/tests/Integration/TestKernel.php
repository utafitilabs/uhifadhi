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

namespace Uhifadhi\Bundle\TeamBundle\Tests\Integration;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\DependencyInjection\Argument\TaggedIteratorArgument;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Symfony\UX\Chartjs\ChartjsBundle;
use Symfony\UX\Icons\UXIconsBundle;
use Symfony\UX\StimulusBundle\StimulusBundle;
use Uhifadhi\Bundle\AtlasBundle\AtlasBundle;
use Uhifadhi\Bundle\RegistryBundle\RegistryBundle;
use Uhifadhi\Bundle\ShellBundle\ShellBundle;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Bundle\TeamBundle\Security\ApiTokenAuthenticator;
use Uhifadhi\Bundle\TeamBundle\TeamBundle;
use Uhifadhi\Bundle\TeamBundle\Tests\Integration\Fixtures\DeclaringConcernSource;
use Uhifadhi\Bundle\TeamBundle\Tests\Integration\Fixtures\DeclaringModuleProvider;
use Uhifadhi\Bundle\TeamBundle\Tests\Integration\Fixtures\DevkitContentCollector;
use Uhifadhi\Bundle\TeamBundle\Tests\Integration\Fixtures\FakeModuleProvider;
use Uhifadhi\Bundle\TeamBundle\Tests\Integration\Fixtures\FakePeopleFacet;
use Uhifadhi\Bundle\TeamBundle\Tests\Integration\Fixtures\FakePersonPostings;
use Uhifadhi\Bundle\TeamBundle\Tests\Integration\Fixtures\FakeRecordCells;
use Uhifadhi\Bundle\TeamBundle\Tests\Integration\Fixtures\FakeStationDirectory;
use Uhifadhi\Bundle\TeamBundle\Tests\Integration\Fixtures\FakeStationPlates;
use Uhifadhi\Bundle\TeamBundle\Tests\Integration\Fixtures\FakeTopicProvider;
use Uhifadhi\Bundle\TeamBundle\Tests\Integration\Fixtures\GroundConcernSource;
use Uhifadhi\Bundle\TeamBundle\Tests\Integration\Fixtures\GuardedController;
use Uhifadhi\Bundle\TeamBundle\Tests\Integration\Fixtures\RosterKpiProvider;
use Uhifadhi\Bundle\TeamBundle\Tests\Integration\Fixtures\ShellPageController;
use Uhifadhi\Bundle\TeamBundle\Tests\Integration\Fixtures\SilentModuleProvider;
use Uhifadhi\Bundle\TeamBundle\Tests\Integration\Fixtures\SurveyKpiProvider;
use Uhifadhi\Contracts\Access\ConcernSourceInterface;
use Uhifadhi\Contracts\Area\StationDirectoryInterface;
use Uhifadhi\Contracts\People\PeopleFacetProviderInterface;
use Uhifadhi\Contracts\People\PersonPostingProviderInterface;
use Uhifadhi\Contracts\People\PersonRecordCellProviderInterface;
use Uhifadhi\Contracts\People\StationPlateProviderInterface;
use Uhifadhi\Contracts\Performance\PerformanceTopicProviderInterface;

/**
 * The smallest host this bundle can live in: framework + twig + doctrine +
 * security + the shell the sign-in screen renders through, talking to a REAL
 * database (UHIFADHI_TEST_DATABASE_URL, see phpunit.dist.xml).
 *
 * THE SECURITY CONFIG HERE MIRRORS THE FILE THE SKELETON SHIPS. What this
 * kernel writes under `security:` is what an installation gets in its own
 * config/packages/security.yaml — the password hashers, the entity provider,
 * the user checker, the web firewall pointing at this bundle's routes, the two
 * `/api` firewalls and the access ladder. The two are kept in step
 * deliberately: a test kernel that invented its own firewall would prove the
 * bundle works in a shape no installation has.
 */
final class TestKernel extends Kernel
{
    use CheckoutTempDirTrait;
    use MicroKernelTrait;

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new TwigBundle();
        yield new StimulusBundle();
        yield new UXIconsBundle();
        yield new DoctrineBundle();
        yield new SecurityBundle();
        yield new RegistryBundle();
        yield new ShellBundle();
        // THE CHARTS A TOPIC'S RECORD DRAWS. The performance pages state a
        // chart through the contracts and the atlas draws it, so a kernel
        // without these two renders a record with a hole where its charts go —
        // which is exactly the failure a suite has to be able to see.
        yield new ChartjsBundle();
        yield new AtlasBundle();
        yield new TeamBundle();
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
            // A form login needs a session and a CSRF token manager, exactly as
            // a real host has them.
            'session' => ['storage_factory_id' => 'session.storage.factory.mock_file'],
            'csrf_protection' => ['enabled' => true],
            // asset() has to exist, because both the shell's document and this
            // module's own page link a stylesheet with it. AssetMapper is a dev
            // dependency of this bundle and takes over path resolution here, so
            // the hrefs come out content-digested exactly as they do in a real
            // installation — which is why the assertions match a stem and not a
            // literal filename.
            'assets' => true,
            'asset_mapper' => [
                'paths' => [__DIR__.'/Fixtures/app/assets' => ''],
            ],
        ]);

        $container->extension('security', [
            'password_hashers' => [
                'Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface' => [
                    'algorithm' => 'auto',
                    // Test-only cost floor, the documented Symfony practice.
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
                /*
                 * WHERE A FIELD CLIENT SIGNS IN, deliberately firewall-free: a
                 * handset whose token has expired still holds it and still
                 * sends it, and that stale header must never be what stops
                 * somebody signing in again. The endpoint checks the
                 * credentials itself.
                 */
                'api_auth' => [
                    'pattern' => '^/api/auth/token$',
                    'security' => false,
                ],
                /*
                 * THE MACHINE DOOR: bearer tokens, no session, no form, no
                 * remembering. Stateless is not an optimisation — a client
                 * syncs in bursts of hundreds after hours offline, and a
                 * session per burst would be a lie about a conversation that is
                 * not happening. The entry point is what makes "no token at
                 * all" a 401 rather than a 403.
                 */
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
                    // Exactly as the README tells an installation to write it: a
                    // deactivated account is refused at the door, with a reason.
                    'user_checker' => 'team.user_checker',
                    'form_login' => [
                        'login_path' => 'team_login',
                        'check_path' => 'team_login',
                        'enable_csrf' => true,
                        'default_target_path' => '/',
                    ],
                    /*
                     * BLUNTING THE CREDENTIAL-STUFFING SURFACE — five attempts
                     * a minute per address and identifier, counted by the
                     * FIREWALL rather than by anything this bundle wrote. It is
                     * the installation's security file that carries this line;
                     * it is written here so the behaviour is proved against the
                     * shape an installation actually has.
                     *
                     * @see https://symfony.com/doc/current/security.html#limiting-login-attempts
                     */
                    'login_throttling' => ['max_attempts' => 5],
                    'logout' => [
                        'path' => 'team_logout',
                        'target' => 'team_login',
                    ],
                    // Impersonation, as the installation's own security.yaml has it.
                    'switch_user' => true,
                    // A rule the ladder below admits on a remembered token is
                    // only honestly exercised by a firewall that can issue one.
                    'remember_me' => [
                        'secret' => '%kernel.secret%',
                        'lifetime' => 604800,
                        'always_remember_me' => false,
                    ],
                ],
            ],
            // THE TIER LADDER, and only it. A tier is a coarse standing an
            // installation's own rules may name; the granular permissions a
            // position grants are decided by a voter, never by a role.
            'role_hierarchy' => [
                'ROLE_ADMIN' => ['ROLE_USER'],
                'ROLE_SUPER_ADMIN' => ['ROLE_ADMIN', 'ROLE_ALLOWED_TO_SWITCH'],
            ],
            // DEFAULT-CLOSED: the paths a stranger has to reach are named, and
            // the catch-all shuts everything else. `/_guarded` needs no rule of
            // its own — the catch-all is what guards it, which is the point.
            'access_control' => [
                ['path' => '^/login', 'roles' => 'PUBLIC_ACCESS'],
                ['path' => '^/reset-password', 'roles' => 'PUBLIC_ACCESS'],
                ['path' => '^/invite/', 'roles' => 'PUBLIC_ACCESS'],
                ['path' => '^/api/auth/token$', 'roles' => 'PUBLIC_ACCESS'],
                ['path' => '^/api', 'roles' => 'ROLE_USER'],
                ['path' => '^/', 'roles' => 'ROLE_USER'],
            ],
        ]);

        $container->extension('doctrine', [
            'dbal' => ['url' => '%env(UHIFADHI_TEST_DATABASE_URL)%'],
            'orm' => [
                // The skeleton's own choice, mirrored so the bundle's SQL is
                // exercised against the column names it will actually meet.
                'naming_strategy' => 'doctrine.orm.naming_strategy.underscore',
                // NO resolve_target_entities FOR THE USER CONTRACT HERE,
                // DELIBERATELY. The bundle prepends it, and this kernel is the
                // proof: the shell keeps a widget layout per PERSON and points
                // at the contract to do it, so if the prepend ever stopped
                // happening the schema would stop before it reached a single
                // team_ table and every test in this suite would say so at once.
                //
                // THE AREA CONTRACT IS THE HOST'S TO ANSWER, so this kernel — a
                // host, minimally — answers it, exactly as a real installation
                // does through AreaBundle. A department carries a
                // nullable area, so its metadata cannot be built until the
                // platform's AreaInterface (the contracts) resolves to a concrete entity. This bundle never
                // resolves it itself; it only points at it.
                'resolve_target_entities' => [
                    \Uhifadhi\Contracts\Entity\AreaInterface::class => Fixtures\Area\HostArea::class,
                ],
                'mappings' => [
                    'TeamTestArea' => [
                        'type' => 'attribute',
                        'dir' => __DIR__.'/Fixtures/Area',
                        'prefix' => 'Uhifadhi\\Bundle\\TeamBundle\\Tests\\Integration\\Fixtures\\Area',
                        'is_bundle' => false,
                    ],
                ],
            ],
        ]);

        // No extra twig paths: every template this bundle renders is its own,
        // reached through the @Team namespace the bundle registers.

        $container->extension('ux_icons', [
            'icon_dir' => __DIR__.'/Fixtures/icons',
            'ignore_not_found' => true,
        ]);

        // A module standing in for every installed module bundle: it DECLARES a
        // permission, which the catalogue must fold in beside the core seven.
        $container->services()
            ->set(DeclaringModuleProvider::class)
            ->tag('uhifadhi.module');

        // The same module saying what there is to have a permission ABOUT.
        // Without a module-owned concern in the kernel, the third question a
        // check asks — does the placement cover the department — has nothing
        // to be about, and only two of the three could be exercised.
        $container->services()
            ->set(DeclaringConcernSource::class)
            ->tag(ConcernSourceInterface::TAG);

        // And the GROUND's concerns, which belong to the area bundle this
        // one must be testable without. A person's record draws a door to
        // where a posting is made, and that door names the area's pairs; a
        // kernel that declared none of them would close the door for reasons
        // that have nothing to do with what is being tested.
        $container->services()
            ->set(GroundConcernSource::class)
            ->tag(ConcernSourceInterface::TAG);

        // And one that declares NOTHING, which is what most modules do. The
        // matrix has to draw it rather than skip it, so the catalogue has to
        // know it is there.
        $container->services()
            ->set(SilentModuleProvider::class)
            ->tag('uhifadhi.module');

        // TWO MODULES THAT REPORT A FIGURE, tagged by hand exactly as a module
        // bundle tags its own (a reusable bundle is not autoconfigured). One
        // belongs to each of the providers above, so the department lens can be
        // asked the question the KPI seam exists for: a provider is read only when
        // the department attaches its module, and the other module's figure stays
        // off the page rather than going to zero.
        $container->services()
            ->set(SurveyKpiProvider::class)
            ->tag('uhifadhi.department_kpi');
        $container->services()
            ->set(RosterKpiProvider::class)
            ->tag('uhifadhi.department_kpi');

        /*
         * TWO MODULES PUBLISHING A TOPIC, which is the module side of the
         * performance seam. Registered always; the collector drops the one
         * whose module this installation does not carry, which is the
         * behaviour under test.
         */
        $container->services()
            ->set('fake.module.patrols', FakeModuleProvider::class)
            ->args(['patrols'])
            ->tag('uhifadhi.module');
        $container->services()
            ->set('fake.module.incidents', FakeModuleProvider::class)
            ->args(['incidents'])
            ->tag('uhifadhi.module');

        $container->services()
            ->set('fake.topic.patrols', FakeTopicProvider::class)
            ->args(['patrols'])
            ->tag(PerformanceTopicProviderInterface::TAG);
        $container->services()
            ->set('fake.topic.incidents', FakeTopicProvider::class)
            ->args(['incidents'])
            ->tag(PerformanceTopicProviderInterface::TAG);

        /*
         * AND WHOEVER OWNS THE GROUND, so the postings board has a station to
         * read. This kernel installs no area package on purpose — Team must
         * hold a person without one — so the seam is answered by a fixture,
         * exactly as an installation answers it with its own.
         */
        $container->services()
            ->set('fake.station_directory', FakeStationDirectory::class)
            ->tag(StationDirectoryInterface::TAG);
        $container->services()
            ->set('fake.person_postings', FakePersonPostings::class)
            ->tag(PersonPostingProviderInterface::TAG);
        // A MODULE'S DROPDOWN ON THE PEOPLE REGISTER, from the seam a module tags.
        $container->services()
            ->set('fake.people_facet', FakePeopleFacet::class)
            ->tag(PeopleFacetProviderInterface::TAG);
        $container->services()
            ->set('fake.station_plates', FakeStationPlates::class)
            ->tag(StationPlateProviderInterface::TAG);
        // A MODULE'S CARD ON A PERSON'S RECORD, from the seam a module tags.
        $container->services()
            ->set('fake.record_cells', FakeRecordCells::class)
            ->tag(PersonRecordCellProviderInterface::TAG);

        // The thing behind the firewall (see configureRoutes).
        $container->services()->set(GuardedController::class)->public();

        // A page in the shell's frame that is NOT this bundle's, so the sidebar
        // suite can ask what a viewer sees from somewhere else.
        $container->services()->set(ShellPageController::class)
            ->args([new Reference('twig')])
            ->public();

        // The matrix renders through the real Twig, with the bundle's own
        // namespace prepended — a template test that stubbed either would
        // prove nothing about what a page draws.
        $container->services()->alias('test.twig', 'twig')->public();

        // WHERE THE LIMITERS COUNT. A suite that has to start with a full
        // budget needs the pool the limiters actually write to, not a copy.
        $container->services()->alias('test_public.rate_limiter_pool', 'cache.rate_limiter')->public();

        // The framework's own hasher, made reachable: a suite proving a stored
        // password verifies has to use the same service the firewall does.
        $container->services()->alias('test_public.hasher', 'security.user_password_hasher')->public();

        // DEVKIT, STANDING IN. devkit installs through require-dev and is no
        // dependency of the core, so the side of the devkit contracts that
        // COLLECTS is a fixture here: the same tagged iterator devkit builds,
        // over the same tag string this bundle writes by hand. It orders the
        // content providers and seeds them.
        $container->services()
            ->set('test_public.devkit_content', DevkitContentCollector::class)
            ->args([new TaggedIteratorArgument('uhifadhi.devkit.content_provider')])
            ->public();

        // Public aliases so a test can hold the bundle's private services.
        foreach ([
            \Uhifadhi\Bundle\TeamBundle\ArgumentResolver\AreaValueResolver::class => 'team.area_value_resolver',
            \Uhifadhi\Bundle\TeamBundle\Repository\UserRepository::class => \Uhifadhi\Bundle\TeamBundle\Repository\UserRepository::class,
            \Uhifadhi\Bundle\TeamBundle\Repository\PositionRepository::class => \Uhifadhi\Bundle\TeamBundle\Repository\PositionRepository::class,
            \Uhifadhi\Bundle\TeamBundle\Repository\DepartmentScopeChangeRepository::class => \Uhifadhi\Bundle\TeamBundle\Repository\DepartmentScopeChangeRepository::class,
            \Uhifadhi\Bundle\TeamBundle\Repository\ApiTokenRepository::class => \Uhifadhi\Bundle\TeamBundle\Repository\ApiTokenRepository::class,
            \Uhifadhi\Bundle\TeamBundle\Service\ApiTokenManager::class => 'team.api_token.manager',
            \Uhifadhi\Bundle\TeamBundle\Service\SuperAdminInvariant::class => 'team.super_admin_invariant',
            \Uhifadhi\Bundle\TeamBundle\Service\TeamOverview::class => 'team.overview',
            \Uhifadhi\Bundle\TeamBundle\Service\UserService::class => 'team.accounts',
            \Uhifadhi\Bundle\TeamBundle\Service\PositionService::class => 'team.positions',
            \Uhifadhi\Bundle\TeamBundle\Service\DepartmentService::class => 'team.departments',
            \Uhifadhi\Bundle\TeamBundle\Service\PasswordResetService::class => 'team.password_reset',
            \Uhifadhi\Bundle\TeamBundle\Service\TeamSettingsService::class => 'team.settings',
            \Uhifadhi\Bundle\TeamBundle\Service\RankService::class => 'team.ranks',
            \Uhifadhi\Bundle\TeamBundle\Repository\RankRepository::class => \Uhifadhi\Bundle\TeamBundle\Repository\RankRepository::class,
            \Uhifadhi\Bundle\TeamBundle\Repository\RankHoldingRepository::class => \Uhifadhi\Bundle\TeamBundle\Repository\RankHoldingRepository::class,
            \Uhifadhi\Bundle\TeamBundle\Repository\TeamSettingsRepository::class => \Uhifadhi\Bundle\TeamBundle\Repository\TeamSettingsRepository::class,
            \Uhifadhi\Bundle\ShellBundle\Widget\Registry\WidgetSurfaceRegistry::class => 'shell.widget.surfaces',
            \Uhifadhi\Bundle\TeamBundle\Shell\DepartmentAreaNavChildren::class => 'team.area_nav_children',
            \Uhifadhi\Bundle\TeamBundle\Settings\PeopleFigure::class => 'team.settings.figure',
            \Uhifadhi\Bundle\TeamBundle\Settings\PositionFigure::class => 'team.settings.position_figure',
            \Uhifadhi\Bundle\TeamBundle\Settings\TeamSteps::class => 'team.settings.steps',
            \Uhifadhi\Bundle\TeamBundle\Service\PerformanceHistory::class => 'team.performance_history',
            \Uhifadhi\Bundle\TeamBundle\Service\PerformanceTopics::class => 'team.performance_topics',
            \Uhifadhi\Bundle\TeamBundle\Service\PositionVacancy::class => 'team.position_vacancy',
            \Uhifadhi\Bundle\TeamBundle\Service\StaffingFigures::class => 'team.staffing_figures',
            \Uhifadhi\Bundle\TeamBundle\Service\DepartmentMembership::class => 'team.department_membership',
            \Uhifadhi\Bundle\TeamBundle\Access\ConcernCatalogue::class => 'team.access.catalogue',
            \Uhifadhi\Bundle\TeamBundle\Security\GrantVoter::class => 'team.access.voter',
            \Uhifadhi\Bundle\TeamBundle\Repository\DepartmentRepository::class => \Uhifadhi\Bundle\TeamBundle\Repository\DepartmentRepository::class,
            \Uhifadhi\Bundle\TeamBundle\Repository\DepartmentGoalRepository::class => \Uhifadhi\Bundle\TeamBundle\Repository\DepartmentGoalRepository::class,
            \Uhifadhi\Bundle\TeamBundle\Service\DepartmentDirectory::class => 'team.department_directory',
            // The registry's own write path, for the suites that ask what a
            // department can be asked about.
            'registry.area_modules' => 'registry.area_modules',
        ] as $class => $serviceId) {
            $container->services()->alias('test_public.'.$class, $serviceId)->public();
        }
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        // Mounted exactly as the recipe's config/routes/team.yaml mounts it.
        $routes->import('@TeamBundle/Controller/', 'attribute');

        // Something behind the firewall, so "an anonymous visitor is sent to
        // /login" is a fact this suite can assert rather than assume.
        $routes->add('guarded', '/_guarded')
            ->controller(GuardedController::class);

        // The same thing behind the API firewall, so "a bearer token reaches
        // what a session reaches" is a fact this suite can assert.
        $routes->add('api_guarded', '/api/_guarded')
            ->controller(GuardedController::class);

        // Somewhere else in the shell, open to anybody, so the sidebar suite can
        // ask what an anonymous visitor and a colleague without team.manage see.
        $routes->add('elsewhere', '/_elsewhere')
            ->controller(ShellPageController::class);

        // The front door every installation has (the skeleton points `/` at the
        // shell's welcome page). It exists here because the firewall's
        // default_target_path sends a fresh sign-in to it, and a redirect to
        // nowhere would make the suite prove nothing.
        /*
         * THE AREA PAGES A REAL INSTALLATION HAS, stood in for. The person's
         * record carries a door to where a posting is MADE, and a posting is
         * made in the area — this bundle mounts no such address, so a suite
         * without these two could only ever prove the door is absent.
         */
        $routes->add('area_index', '/areas')->controller(ShellPageController::class);
        $routes->add('area_stations_configure', '/areas/{uuid}/configure/stations')
            ->controller(ShellPageController::class);

        $routes->add('home', '/')->controller(GuardedController::class);
    }

    /**
     * THE STAND-IN HOST'S PROJECT DIRECTORY — a Flex-installed application's
     * asset side and nothing else. The shell's document renders the importmap
     * of whatever application it is installed in, so a suite that renders any
     * page through the page frame needs an application that has one. Pointing
     * the kernel at a fixture is how it gets one without this bundle growing an
     * importmap of its own, which a shipped bundle has no business carrying.
     */
    public function getProjectDir(): string
    {
        return __DIR__.'/Fixtures/app';
    }

    public function getCacheDir(): string
    {
        return $this->checkoutTempDir('team/cache');
    }

    public function getLogDir(): string
    {
        return $this->checkoutTempDir('team/log');
    }
}
