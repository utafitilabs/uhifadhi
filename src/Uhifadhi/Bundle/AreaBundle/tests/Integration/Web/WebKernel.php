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

namespace Uhifadhi\Bundle\AreaBundle\Tests\Integration\Web;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\MercureBundle\MercureBundle;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Symfony\UX\Icons\UXIconsBundle;
use Symfony\UX\Map\UXMapBundle;
use Symfony\UX\StimulusBundle\StimulusBundle;
use Twig\Environment;
use Uhifadhi\Bundle\AreaBundle\AreaBundle;
use Uhifadhi\Bundle\AreaBundle\Overview\OrgOverviewContributorInterface;
use Uhifadhi\Bundle\AreaBundle\Overview\OverviewContributorInterface;
use Uhifadhi\Bundle\AreaBundle\Tests\Integration\CheckoutTempDirTrait;
use Uhifadhi\Bundle\AreaBundle\Tests\Integration\Fixtures\ChattyFigureProvider;
use Uhifadhi\Bundle\AreaBundle\Tests\Integration\Web\Fixtures\HostDirectory;
use Uhifadhi\Bundle\AreaBundle\Tests\Integration\Web\Fixtures\HostUser;
use Uhifadhi\Bundle\AreaBundle\Tests\Integration\Web\Fixtures\OrgModule;
use Uhifadhi\Bundle\AreaBundle\Tests\Integration\Web\Fixtures\PatrolsModuleTabs;
use Uhifadhi\Bundle\AreaBundle\Tests\Integration\Web\Fixtures\SignedInPerson;
use Uhifadhi\Bundle\AtlasBundle\AtlasBundle;
use Uhifadhi\Bundle\RegistryBundle\RegistryBundle;
use Uhifadhi\Bundle\ShellBundle\ShellBundle;
use Uhifadhi\Contracts\Area\StationSectionsInterface;
use Uhifadhi\Contracts\Entity\UserInterface;
use Uhifadhi\Contracts\Kpi\ZoneFigureProviderInterface;
use Uhifadhi\Contracts\People\PersonDirectoryProviderInterface;
use Uhifadhi\Contracts\Shell\AreaNavChildrenInterface;
use Uhifadhi\Contracts\Shell\AreaSectionsInterface;
use UtafitiLabs\PostGISBundle\UtafitiLabsPostGISBundle;

/**
 * AN INSTALLATION WITH SCREENS — the same minimal kernel as
 * {@see \Uhifadhi\Bundle\AreaBundle\Tests\Integration\TestKernel}, plus the three things a
 * page needs: twig to render in, security to be gated by, and the shell to be
 * framed by.
 *
 * THE SHELL IS HERE BUT IT IS A `suggest`, NOT A `require`, and that asymmetry
 * is the point. These screens render in the shell's frame when an installation
 * has one and render unframed when it does not, so the suite has to be able to
 * boot BOTH — this kernel is the framed half, and
 * {@see \Uhifadhi\Bundle\AreaBundle\Tests\Integration\TestKernel} is still the bare one.
 *
 * THE FIREWALL IS REAL. The permissions these screens are gated on are answered
 * by TeamBundle's voter in a real installation, and team is not a
 * dependency of this one. So the suite ships its own voter over the same
 * permission strings: what is being tested here is that the screens ASK, not
 * what somebody else answers.
 */
final class WebKernel extends Kernel
{
    /*
     * MicroKernelTrait, not a hand-rolled Kernel: it is what tags the `kernel`
     * service as a route loader and points framework.router at
     * `kernel::loadRoutes`. Without it configureRoutes() below is never called
     * and every route in this suite silently does not exist.
     */
    use CheckoutTempDirTrait;
    use MicroKernelTrait;
    /** The instant this suite's pages are read at, unless a test says otherwise. */
    public const string CLOCK = '2026-09-19 11:42:00';

    /**
     * THE HUB, AS A DEPLOYMENT CONFIGURES IT. Its address and secret are what
     * an installation supplies; the suite supplies them literally so the
     * pages set their subscriber cookie and hand their plates a stream.
     * Nothing in this suite publishes, so the address is never reached. A
     * test standing for the deployment that configured none passes ''; one
     * standing for the installation that carries no hub bundle at all
     * passes null, and this kernel then registers no MercureBundle.
     */
    public const string HUB_URL = 'http://localhost:3000/.well-known/mercure';

    /** @var list<string> */
    public array $grants = [];

    /**
     * @param list<string> $grants    what the viewer holds
     * @param int          $attention how many items the stand-in module
     *                                raises — two by default, and as many as
     *                                a test proving a card is BOUNDED needs
     * @param int          $figures   how many headline figures the stand-in module
     *                                publishes — one by default, four where a suite
     *                                proves a full row leaves no slot for the
     *                                organization's own filler
     * @param string       $clock     WHEN THIS KERNEL THINKS IT IS. Pinned, and a
     *                                suite may move it: which month a page is
     *                                about is read from the clock now, so a
     *                                month boundary is a thing a test can
     *                                stand on
     * @param string|null  $hubUrl    the hub's address, '' for the deployment
     *                                that configured none, or null for the
     *                                installation without the hub bundle
     */
    public function __construct(array $grants = [], private int $attention = 2, private string $clock = self::CLOCK, private int $figures = 1, private ?string $hubUrl = self::HUB_URL)
    {
        $this->grants = $grants;
        // The cache is keyed by what the viewer holds AND by what the
        // stand-in contributes: two kernels with different grants or
        // different fixtures must not share a compiled container. The clock
        // joins them for the same reason — a container built at one instant
        // must not answer for another — and so does the hub's address.
        parent::__construct('test'.md5(implode(',', $grants).'|'.$attention.'|'.$this->clock.'|'.$this->figures.'|'.($this->hubUrl ?? 'no-hub-bundle')), true);
    }

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new TwigBundle();
        yield new SecurityBundle();
        yield new DoctrineBundle();
        yield new UtafitiLabsPostGISBundle();
        yield new RegistryBundle();
        // The shell's frame draws its icons with ux_icon(); it is a hard
        // requirement of the shell, so an installation that has the shell has it.
        yield new UXIconsBundle();
        yield new StimulusBundle();
        // UX Map and its Leaflet bridge: the atlas's plate is built on them,
        // and every area screen that draws a map renders through them.
        yield new UXMapBundle();
        yield new ShellBundle();
        // The atlas: these pages link its map sheet by the constant it
        // publishes and render their plates through it, so an installation that
        // draws an area's boundary has it and so does this kernel.
        yield new AtlasBundle();
        // The live-presence wire's hub, where the installation carries it: the
        // area bundle suggests it, and every area page answers without it.
        if (null !== $this->hubUrl) {
            yield new MercureBundle();
        }
        yield new AreaBundle();
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'secret' => 'test',
            'test' => true,
            'http_method_override' => false,
            'php_errors' => ['log' => true],
            'session' => ['storage_factory_id' => 'session.storage.factory.mock_file'],
            // The module shop WRITES, so every control on it carries a token
            // and the screen refuses a post without one.
            'csrf_protection' => true,
            // The shell's document links its own stylesheet through asset(), and
            // renders the application's importmap. AssetMapper takes over path
            // resolution here, so the hrefs come out content-digested exactly as
            // they do in a real installation — which is why the assertions match
            // a stem rather than a literal filename.
            'assets' => [],
            'asset_mapper' => [
                'paths' => [__DIR__.'/Fixtures/app/assets' => ''],
            ],
        ]);

        /*
         * THE ICONS ARE LOCAL, and they come from the shell: `shell:` is an
         * icon SET the shell registers, and a set is answered only from its own
         * directory. So this kernel keeps an empty icon directory of its own —
         * an application always has one — and on-demand fetching is off, which
         * is what makes the suite prove that the screens render rather than
         * that the machine has internet.
         */
        $container->extension('ux_icons', [
            'icon_dir' => __DIR__.'/icons',
            'iconify' => ['on_demand' => false],
        ]);

        // STRICT: a template that reads a variable the controller did not pass
        // fails here rather than rendering a blank cell in an installation.
        $container->extension('twig', [
            'strict_variables' => true,
            // THE STAND-IN MODULE'S OWN TEMPLATE NAMESPACE. A contributed
            // cell is rendered from its own bundle's namespace, so the
            // fixture needs one of its own or it would be testing the area's
            // template loader rather than the contract.
            'paths' => [__DIR__.'/Fixtures/templates' => 'fixtures'],
        ]);
        $container->extension('ux_map', ['renderer' => 'leaflet://default']);

        $container->extension('doctrine', [
            'dbal' => ['url' => '%env(UHIFADHI_TEST_DATABASE_URL)%'],
            'orm' => [
                'naming_strategy' => 'doctrine.orm.naming_strategy.underscore',
                /*
                 * THE ONE LINE AN INSTALLATION WRITES, WRITTEN HERE. The shell
                 * keeps a widget layout per person and points it at the
                 * contracts' UserInterface without resolving it; whoever owns
                 * the account class states the resolution, and this bundle is
                 * not that package. So this kernel plays the installation's
                 * end of it with {@see HostUser}, and nothing here depends on
                 * a sibling bundle to build a schema.
                 */
                'resolve_target_entities' => [
                    UserInterface::class => HostUser::class,
                ],
                'mappings' => [
                    'AreaTestHost' => [
                        'type' => 'attribute',
                        'dir' => __DIR__.'/Fixtures',
                        'prefix' => 'Uhifadhi\\Bundle\\AreaBundle\\Tests\\Integration\\Web\\Fixtures',
                        'is_bundle' => false,
                    ],
                ],
            ],
        ]);

        $container->extension('security', [
            'providers' => ['in_memory' => ['memory' => ['users' => ['ranger' => ['password' => 'x', 'roles' => ['ROLE_USER']]]]]],
            'firewalls' => ['main' => ['pattern' => '^/', 'security' => false]],
        ]);

        /*
         * THE HUB — one hub named "default", its address and the secret the
         * subscriber cookie is signed with. The documented minimum:
         *
         *     mercure:
         *         hubs:
         *             default:
         *                 url: '%env(MERCURE_URL)%'
         *                 public_url: '%env(MERCURE_PUBLIC_URL)%'
         *                 jwt:
         *                     secret: '%env(MERCURE_JWT_SECRET)%'
         *
         * @see https://symfony.com/doc/current/mercure.html — "Configuration"
         * @see vendor/symfony/mercure-bundle/src/DependencyInjection/MercureExtension.php
         */
        if (null !== $this->hubUrl) {
            $container->extension('mercure', [
                'hubs' => [
                    'default' => [
                        'url' => $this->hubUrl,
                        'public_url' => $this->hubUrl,
                        'jwt' => ['secret' => 'test-mercure-jwt-secret-at-least-256-bits-long'],
                    ],
                ],
            ]);
        }

        $services = $container->services();

        // The host provides monolog's `logger`; this kernel provides a NullLogger.
        $services->set('logger', NullLogger::class);

        /*
         * A CATALOGUE WITH BUNDLES BEHIND IT. The registry's catalogue is the
         * intersection of `module` rows and REGISTERED PROVIDERS, so rows alone
         * would read as an empty catalogue. These stand in for module bundles
         * this one must never depend on — see InstallableModule.
         *
         * `patrols` carries an entry route this kernel actually serves, so its
         * tile is a link; `forest-loss` carries none, so its tile is inert. Both
         * halves of that rule are asserted.
         */
        /*
         * A MODULE'S OWN DATA PLACES, STOOD IN FOR — what fills the sidebar's
         * fourth rung. Tagged by hand, exactly as a reusable module bundle must.
         */
        $services->set(PatrolsModuleTabs::class)
            ->tag('uhifadhi.module_tabs');

        /*
         * A MODULE'S OWN CONFIGURE SECTIONS, STOOD IN FOR — what puts a
         * `Configure` door on that module's row of the modules register.
         * Tagged by hand, as a reusable module bundle must.
         */
        $services->set(PatrolsConfigurationSections::class)
            ->tag('uhifadhi.configuration_sections');

        foreach ([
            ['patrols', 'Patrols', 'pressure', 'live', 'GPS field tracks', 'test_module_entry'],
            ['incidents', 'Incidents', 'pressure', 'live', 'field reports', null],
            ['forest-loss', 'Forest loss', 'flux', 'template', 'Hansen GFC', null],
        ] as $i => $module) {
            $services->set('test.module.'.$module[0], InstallableModule::class)
                ->args($module)
                ->tag('uhifadhi.module');
        }

        /*
         * A MODULE'S LAYERS ON THE AREA PAGE'S PLATE, STOOD IN FOR. The overview
         * gathers map layers from every module switched on in the area, through
         * the `uhifadhi.map.layer` contribution — patrol tracks, incident points — and
         * this bundle must depend on none of them. These fakes contribute over
         * the same contribution so the suite can prove the area page draws a contributed
         * layer's geometry and its legend group where its module is on, and
         * leaves both out where it is off.
         */
        $services->set('test.map_layers.patrols', FakeMapLayers::class)
            ->args(['patrols', 'Patrols'])
            ->tag('uhifadhi.map.layer');
        $services->set('test.map_layers.incidents', FakeMapLayers::class)
            ->args(['incidents', 'Incidents'])
            ->tag('uhifadhi.map.layer');

        /*
         * A MODULE'S REGISTER-CARD FIGURES, STOOD IN FOR. The overview contributions the
         * register card reads its operational figures through — now-tiles (its
         * stat cells and "out right now" chip), attention (its alert flag) and
         * pulse (its "last check-in") — are the same contributions the overview draws
         * from, and this bundle depends on no real module. These fakes contribute
         * over those contributions for `patrols` so the suite can prove the area page lays out
         * a contributed figure on a card where its module is on, and shows the
         * boundary-only card where it is off.
         */
        $services->set('test.now_tiles.patrols', FakeNowTiles::class)
            ->args(['patrols'])
            ->tag('uhifadhi.overview.now_tile');
        /*
         * AND ONE MORE FIGURE FROM A SECOND MODULE, contributed only where
         * `incidents` is switched on — so an installation's two live areas
         * are UNALIKE, which is the shape the register's operational columns
         * have to survive and the shape that broke it.
         */
        $services->set('test.now_tiles.incidents', FakeSecondNowTile::class)
            ->args(['incidents'])
            ->tag('uhifadhi.overview.now_tile');
        $services->set('test.attention.patrols', FakeAttention::class)
            ->args(['patrols', $this->attention])
            ->tag('uhifadhi.overview.attention');
        $services->set('test.pulse.patrols', FakePulse::class)
            ->args(['patrols'])
            ->tag('uhifadhi.overview.pulse');

        /*
         * THE INSTALLATION'S PEOPLE DIRECTORY, played by the stand-in — the
         * seam the stations section's chooser reads. Tagged by hand, as every
         * seam in this platform is.
         */
        /*
         * A MODULE PUTTING A CARD ON THE OVERVIEW, tagged the way a real one
         * tags itself. Registered always; the catalogue only asks it where
         * the area has 'patrols' switched on, which is the behaviour under
         * test.
         */
        $services->set(FakeOverviewWidgets::class)
            ->args(['patrols'])
            ->tag(OverviewContributorInterface::TAG);

        /*
         * A SECOND MODULE CONTRIBUTING A CELL, so the suite can say which
         * order two contributions come in — tag order is not it.
         */
        $services->set('fake.overview_widgets.incidents', FakeOverviewWidgets::class)
            ->args(['incidents'])
            ->tag(OverviewContributorInterface::TAG);

        /*
         * AND A MODULE ANSWERING AT ORGANIZATION LEVEL, tagged by hand as a
         * real module bundle has to tag it. Without one the dashboard is
         * rendered with nothing but the host's own cells, which proves the
         * host and nothing about the seam the page exists for.
         */
        $services->set(FakeOrgWidgets::class)
            ->args(['patrols', $this->figures])
            ->tag(OrgOverviewContributorInterface::TAG);

        /*
         * A BUNDLE CONTRIBUTING A SECTION TO THE AREA'S CONFIGURE STRIP,
         * tagged as the team bundle tags Departments.
         */
        $services->set(FakeAreaSections::class)
            ->tag(AreaSectionsInterface::TAG);

        /*
         * A MODULE PUTTING A BAND ON A POST — the seam the roster's watch
         * arrives through. Its slug is the module this harness installs, so
         * the ledger decides whether it is asked at all.
         */
        $services->set(FakeStationSections::class)
            ->tag(StationSectionsInterface::TAG);

        /*
         * A TALKATIVE MODULE'S ZONE FIGURES — four about one zone, each
         * with the kind of caption a real module writes. The zone
         * record's band took every one of them and wrapped to four
         * rows; a fixture publishing one figure could not have seen it.
         */
        $services->set(ChattyFigureProvider::class)
            ->tag(ZoneFigureProviderInterface::TAG);

        /*
         * A BUNDLE UNFOLDING ONE OF THE AREA'S SCREENS IN THE TREE, tagged
         * as the team bundle tags the departments under an area.
         */
        $services->set(FakeAreaNavChildren::class)
            ->args([new Reference('request_stack')])
            ->tag(AreaNavChildrenInterface::TAG);

        $services->set(HostDirectory::class)
            ->args([new Reference('doctrine.orm.entity_manager')])
            ->tag(PersonDirectoryProviderInterface::TAG);

        // The suite's own voter, standing in for TeamBundle's. It answers
        // the same permission strings, which is the whole of what these screens
        // depend on.
        /*
         * THE SUITE'S OWN SIGN-IN, standing in for a firewall this kernel
         * deliberately leaves unsecured — see SignedInPerson. Public, because the
         * test says whose requests these are.
         */
        $services->set(SignedInPerson::class)
            ->args([new Reference('doctrine'), new Reference('security.token_storage')])
            ->tag('kernel.event_listener', ['event' => 'kernel.request', 'method' => 'onKernelRequest', 'priority' => 9])
            ->public();

        $services->set(GrantedPermissions::class)
            ->args([$this->grants])
            ->tag('security.voter')
            ->public();

        // And the `door()` helper the templates ask with, standing in for
        // TeamBundle's extension for the same reason the voter does.
        $services->set(DoorFunction::class)
            ->args([new Reference('security.authorization_checker')])
            ->tag('twig.extension');

        $services->alias('test_public.area.shell_source', 'area.shell_source')->public();
        $services->alias('test_public.area.scopes', 'area.scopes')->public();

        /*
         * A MODULE THAT ANSWERS AT ORGANIZATION LEVEL, tagged by hand as a
         * real module bundle has to tag it. Its pages wear the shell's org
         * frame, and the control in that frame is filled by this bundle's
         * own scope source with nothing wired by a host — which is the whole
         * of what the default is for.
         */
        $services->set(OrgModule::class)
            ->tag(ShellBundle::ORG_PAGES_TAG)
            ->public();
        $services->alias('test_public.area.stations', 'area.stations')->public();
        $services->alias('test_public.area.station_plates', 'area.station_plates')->public();
        $services->alias('test_public.area.postings', 'area.postings')->public();
        $services->alias('test_public.area.zones', 'area.zones')->public();
        $services->alias('test_public.area.map', 'area.map')->public();
        $services->alias('test_public.area.map_payload', 'area.map_payload')->public();
        /* The live-presence pair, reached by the suite proving the installation without a hub bundle. */
        $services->alias('test_public.area.presence', 'area.presence')->public();
        $services->alias('test_public.area.presence_stream', 'area.presence_stream')->public();
        $services->alias('test_public.area.checkin_statuses', 'area.checkin_statuses')->public();
        // The ledger's writer, so a test can arrange an area's composition the
        // same way the screen does rather than inserting rows behind it.
        $services->alias('test_public.registry.area_modules', 'registry.area_modules')->public();
        $services->alias('test_public.area.navigation', 'area.navigation')->public();
        // WHAT THIS BUNDLE TELLS THE SETTINGS SECTION — the one reading with
        // real queries in it, and the one every other contribution derives from.
        $services->alias('test_public.area.settings.module_matrix', 'area.settings.module_matrix')->public();
        $services->alias('test_public.area.org_catalogue', 'area.org_catalogue')->public();
        $services->alias('test_public.area.zone_list', 'area.zone_list')->public();
        $services->alias('test_public.atlas.periods', 'atlas.periods')->public();

        /*
         * WHAT TIME THIS INSTALLATION THINKS IT IS. Pinned, because pages
         * here caption the period they are about and that period is read
         * from the clock: a suite on the wall clock asserts a different
         * month every thirty days.
         */
        $services->set('clock', MockClock::class)
            ->args([$this->clock])
            ->public();
        $services->alias('test_public.event_dispatcher', 'event_dispatcher')->public();
        $services->alias('test_public.token_storage', 'security.token_storage')->public();
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        /*
         * WHAT THE RECIPE MOUNTS. An installation imports these from
         * config/routes/area.yaml and may prefix or remove them; the suite
         * mounts them where the recipe does, so a url asserted here is the url
         * an installation serves.
         */
        $routes->import(
            ['path' => \dirname(__DIR__, 3).'/Controller/', 'namespace' => 'Uhifadhi\Bundle\AreaBundle\Controller'],
            'attribute',
        );

        /*
         * THE SHELL'S CONFIGURE PAGE, mounted as an installation mounts it. The
         * area's one configuration entry leads here, so a suite that did not
         * mount it would be asserting a `Configure` action that goes nowhere.
         */
        $routes->import(ShellBundle::CONFIGURE_ROUTES);

        /*
         * THE SHELL'S SETTINGS SECTION, mounted as an installation mounts it.
         * Its what-runs-where matrix is this bundle's contribution, so the
         * suite reads it on the page that prints it.
         */
        $routes->import(ShellBundle::SETTINGS_ROUTES);

        /*
         * A MODULE'S OWN PAGE, STOOD IN FOR. A tile links where the registry's entry
         * resolver names a route the application actually mounted; in a real
         * installation that is the patrol module's dashboard. This suite mounts
         * one route with that shape so the linked and the inert tile can both be
         * asserted without depending on a module bundle.
         */
        $routes->add('test_module_entry', '/areas/{uuid}/modules/patrols')
            ->controller('kernel::moduleEntry');

        // THE CONTRIBUTED CONFIGURE SECTION'S OWN SCREEN, at the address shape
        // every area configure section wears. A section that borrowed the
        // area's front door would make the frame read the overview as a
        // configure page and light the Configure action there.
        $routes->add('test_contributed_section', '/areas/{uuid}/configure/contributed')
            ->controller('kernel::moduleEntry');

        // The module's second data place, so the fourth rung of the tree has
        // more than one rung to be.
        $routes->add('test_module_list', '/areas/{uuid}/modules/patrols/patrols')
            ->controller('kernel::moduleEntry');

        // AND THE STAND-IN MODULE'S ORGANIZATION-LEVEL SCREENS, mounted by
        // the application as a real one mounts them: the module contributes
        // route NAMES and the host decides the addresses.
        $routes->add('test_org_overview', '/sightings')->controller('kernel::orgPage');
        $routes->add('test_org_today', '/sightings/today')->controller('kernel::orgPage');
    }

    public function orgPage(Environment $twig): Response
    {
        return new Response($twig->render('@fixtures/org_page.html.twig'));
    }

    public function moduleEntry(): Response
    {
        return new Response('a module page');
    }

    /**
     * THE STAND-IN HOST'S PROJECT DIRECTORY — a Flex-installed application's
     * asset side and nothing else. The shell's document renders the importmap
     * of whatever application it is installed in, so a suite that renders a
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
        return $this->checkoutTempDir('area/web-cache/'.$this->getEnvironment());
    }

    public function getLogDir(): string
    {
        return $this->checkoutTempDir('area/web-log');
    }
}
