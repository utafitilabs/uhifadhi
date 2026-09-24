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

namespace Uhifadhi\Bundle\ShellBundle\Tests\Integration\Fixtures;

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Uhifadhi\Bundle\ShellBundle\Model\AreaTab;
use Uhifadhi\Bundle\ShellBundle\Model\NavSection;
use Uhifadhi\Bundle\ShellBundle\ShellBundle;
use Uhifadhi\Bundle\ShellBundle\Tests\Integration\TestKernel;
use Uhifadhi\Contracts\Shell\UserBadge;

/**
 * A STAND-IN HOST — and the specification's main claim, not a testing
 * convenience.
 *
 * This kernel is an application that implements the shell's contracts and has
 * nothing else at all: no areas, no modules, no registry, no database, no user. If
 * the shell can be driven to a complete page by THIS, then the contracts are real
 * contracts rather than a polite name for reaching into the application, and the
 * "standalone" in the shell's charter is a fact about the code.
 *
 * Everything a test wants to vary is a static, set in the test body and reset
 * between tests. The fixture services read them at render time, which is also
 * how a real source behaves — read live, never cached (see the navigation contract's
 * same-day promise).
 */
class HostKernel extends TestKernel
{
    /** @var array<string, NavSection> */
    public static array $navSources = [];

    /**
     * The sheets a package's COMPONENTS need — what a page cannot link
     * for itself because it does not know it is about to draw one.
     *
     * @var list<string>
     */
    public static array $stylesheets = [];

    /** @var list<AreaTab> */
    public static array $areaTabs = [];

    /**
     * Mirror the area's tabs into the sidebar's location tree as well, so a
     * test can assert the strip and the branch cannot disagree — the host keeps
     * two hand-written copies today.
     */
    public static bool $mirrorAreaTabsIntoNav = false;

    /**
     * What the viewer is inside, in words — the middle segment of the page
     * title. The area contract answers it, because it is the same question the tabs
     * answer, asked for the title bar instead of the strip.
     */
    public static ?string $place = 'Test Area';

    /**
     * Who the top bar names, or null for an anonymous request. Null by default,
     * and that is the honest default: the stand-in host has no team, and a shell
     * that always had a viewer would make "a top bar with no card" impossible to
     * assert. A test about the card sets one.
     */
    public static ?UserBadge $userBadge = null;

    /**
     * Flashes the host has pending, seeded into a real session by
     * {@see \Uhifadhi\Bundle\ShellBundle\Tests\Integration\ContractTestCase} before a render.
     *
     * EMPTY BY DEFAULT, and that is the honest default: a host usually has
     * nothing to say, and a stand-in host that always had a message pending
     * would make "an unfilled region leaves nothing behind" impossible to
     * assert. A test that is about flashes seeds one.
     *
     * @var list<array{string, string}> label, message
     */
    public static array $flashes = [];

    public static function reset(): void
    {
        self::$navSources = [];
        self::$stylesheets = [];
        self::$areaTabs = [];
        self::$mirrorAreaTabsIntoNav = false;
        self::$place = 'Test Area';
        self::$flashes = [];
        self::$userBadge = null;
        FixtureOrgModule::reset();
        FixtureScopeSource::reset();
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        parent::configureContainer($container);

        // The fixture pages live under their own namespace so that no test can
        // accidentally assert against a template the shell ships.
        $container->extension('twig', [
            'paths' => [__DIR__.'/templates' => 'fixtures'],
        ]);

        $services = $container->services();

        // Written out by hand, tag and all — which is exactly what a real
        // contributing bundle has to do, since a reusable bundle's services are
        // not autoconfigured. If this fixture needed autowiring to work, the
        // contract would not work for the bundles it exists for.
        $services->set(FixtureNavigationSource::class)
            ->tag(ShellBundle::NAV_TAG)
            ->public();

        $services->set(FixtureStylesheetSource::class)
            ->tag(ShellBundle::STYLESHEET_TAG)
            ->public();

        // A MODULE THAT ANSWERS AT ORGANIZATION LEVEL, tagged by hand as a
        // real module bundle has to tag it. The shell mounts the page set,
        // draws the Observatory row and supplies the scope control; nothing
        // module-shaped reaches the shell but an interface.
        $services->set(FixtureOrgController::class)
            ->public();

        $services->set(FixtureOrgModule::class)
            ->tag(ShellBundle::ORG_PAGES_TAG)
            ->public();

        // And what the viewer may look at, which is the host's answer because
        // the shell has neither the areas nor the voters.
        $services->set(FixtureScopeSource::class)
            ->tag(ShellBundle::SCOPE_TAG)
            ->public();

        $services->set(FixtureAreaShellSource::class)
            ->public();

        $services->set(FixtureUserBadgeSource::class)
            ->public();

        // The host tells the shell which implementation answers the area contract,
        // by aliasing the id the shell looks for. An ALIAS, not a tagged
        // collection and not a config key: two things claiming to know an
        // area's tabs is the disagreement this bundle exists to prevent, and a
        // config key would put a class name in YAML where nothing checks it.
        $services->alias('shell.area_shell_source', FixtureAreaShellSource::class);

        // And who the top bar names — the same alias shape, for the same reason:
        // one source, not a collection, because two answers to "who is signed
        // in" is the disagreement the registry prevents.
        $services->alias('shell.user_badge_source', FixtureUserBadgeSource::class);

        $services->alias('test.shell.navigation', 'shell.navigation')->public();
        $services->alias('test.shell.scopes', 'shell.scopes')->public();
        $services->alias('test.shell.area_shell', 'shell.area_shell')->public();
        $services->alias('test.shell.user_badge', 'shell.user_badge')->public();
        $services->alias('test.shell.contract', 'shell.contract')->public();
        $services->alias('test.shell.theme', 'shell.theme')->public();
    }

    /**
     * THE ADDRESSES THE APPLICATION MOUNTS. A module contributes route NAMES
     * and the host mounts them — which is why the sidebar generates a url
     * rather than printing a path, and why a page whose route nobody mounted
     * is skipped rather than drawn as a link to a 404. Both states are
     * asserted, so both are mounted here and one is deliberately left out.
     */
    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->add('fixture_org_overview', '/sightings')
            ->controller(FixtureOrgController::class)
            ->methods(['GET']);

        $routes->add('fixture_org_today', '/sightings/today')
            ->controller(FixtureOrgController::class)
            ->methods(['GET']);
    }
}
