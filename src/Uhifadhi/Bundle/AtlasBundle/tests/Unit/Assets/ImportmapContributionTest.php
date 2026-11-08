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

namespace Uhifadhi\Bundle\AtlasBundle\Tests\Unit\Assets;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\UX\Chartjs\Builder\ChartBuilder as UxChartBuilder;
use Uhifadhi\Bundle\AtlasBundle\AtlasBundle;
use Uhifadhi\Bundle\AtlasBundle\Chart\ChartBuilder;
use Uhifadhi\Bundle\AtlasBundle\Tests\Integration\Asset\AssetContractTest;
use Uhifadhi\Bundle\AtlasBundle\Tests\Unit\Chart\DoorStatement;
use Uhifadhi\Bundle\AtlasBundle\Twig\ChartRuntime;
use Uhifadhi\Bundle\AtlasBundle\Twig\MapPlateRuntime;

/**
 * NOBODY TYPES THIS BUNDLE'S IMPORTMAP ENTRIES BY HAND.
 *
 * "A bundle cannot contribute an importmap entry" is true of AssetMapper and is
 * not the whole story, because the thing that writes an application's
 * importmap.php on install is not AssetMapper, it is Flex.
 *
 * Flex's PackageJsonSynchronizer reads a package's assets/package.json and, when
 * the host has an importmap.php, runs `importmap:require` once per entry of the
 * `symfony.importmap` block — including `path:` entries pointing back into the
 * package's own files (PackageJsonSynchronizer::resolveImportMapPackages and
 * ::updateImportMap). That is exactly the three lines, written by machine.
 *
 * It reads that file ONLY if the composer package declares the keyword
 * `symfony-ux` (::resolvePackageJson). Without the keyword everything installs
 * and nothing is written — no error, just a blank map on every page that draws
 * one. So the keyword is asserted here rather than trusted to survive the next
 * edit of composer.json.
 *
 * What this file pins is the contribution as a contract: the keyword, the
 * package name Flex keys by, the three bare specifiers (which are the public
 * names every importer in the product types), and that each one points at a file
 * this bundle actually ships.
 *
 * AND THE CHART PACKAGES CHART.JS DOES NOT CARRY, as a UX package ships a
 * third-party one: by name and version in the same block, which Flex hands
 * to `importmap:require` (symfony/ux-chartjs's own assets/package.json
 * declares `"chart.js": "^3.4.1 || ^4.0"` that way). "When an application
 * installs your package, Symfony Flex runs the importmap:require command to
 * add each importmap entry to the importmap.php file of the application" —
 * and the list mirrors `peerDependencies`, as the same page asks.
 *
 * @see https://symfony.com/doc/current/frontend/create_ux_bundle.html — `symfony.importmap`, version constraints and `path:%PACKAGE%/…`; "the list of packages in importmap should be the same as the ones in peerDependencies"
 * @see vendor/symfony/ux-chartjs/assets/package.json — `symfony.importmap.chart.js`
 * @see https://symfony.com/doc/current/frontend/asset_mapper.html — "The importmap:require command will add both the main package and its dependencies"; a subpath is required by its exact path
 */
final class ImportmapContributionTest extends TestCase
{
    /**
     * The three shared modules, as `specifier => file under assets/`.
     *
     * The specifiers are the same list AssetContractTest resolves against a real
     * AssetMapper; that file owns the logical paths, this one owns what Flex is
     * told to write, and testTheContributionCoversEverySharedModule below keeps
     * the two from drifting apart.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function sharedModules(): iterable
    {
        yield 'uhifadhi/basemaps' => ['uhifadhi/basemaps', 'basemaps.js'];
        yield 'uhifadhi/boundary' => ['uhifadhi/boundary', 'boundary.js'];
        yield 'uhifadhi/map-chrome' => ['uhifadhi/map-chrome', 'chrome.js'];
    }

    /**
     * THE CHART PACKAGES, pinned to the versions the design was drawn with:
     * the date adapter and date-fns under it, the five extension packages,
     * the libraries two of them import, and Chart.js's helpers, which every
     * extension imports by that subpath.
     */
    private const array CHART_PACKAGES = [
        'chartjs-adapter-date-fns' => '3.0.0',
        'date-fns' => '2.30.0',
        'chartjs-chart-matrix' => '3.1.0',
        'chartjs-chart-treemap' => '4.2.2',
        'chartjs-chart-sankey' => '0.19.1',
        '@sgratzl/chartjs-chart-boxplot' => '4.4.5',
        '@sgratzl/boxplots' => '2.0.0',
        'chartjs-chart-funnel' => '4.2.5',
        'chroma-js' => '3.2.0',
        'chart.js/helpers' => '4.5.1',
    ];

    public function testTheChartPackagesAreDeclaredByNameAndPinnedVersion(): void
    {
        $packages = array_filter(self::importmap(), static fn (mixed $entry): bool => \is_string($entry) && !str_starts_with($entry, 'path:'));

        self::assertSame(self::CHART_PACKAGES, $packages);
        self::assertSame(array_diff_key(self::CHART_PACKAGES, ['chart.js/helpers' => true]), self::assetPackage()['peerDependencies'] ?? null);
    }

    /**
     * Every package the builder names for a catalogue card — the parity
     * fixtures cover every kind and the time axis — is one Flex writes, and
     * one the plate knows how to load.
     */
    public function testEveryPackageAStatementCanNeedIsDeclaredAndLoadedByThePlate(): void
    {
        $builder = new ChartBuilder(new UxChartBuilder());
        $plate = (string) file_get_contents(self::root().'/assets/controllers/chart_plate_controller.js');
        $named = [];
        foreach (glob(self::root().'/tests/Unit/Chart/Fixtures/door/*.json') ?: [] as $file) {
            $fixture = json_decode((string) file_get_contents($file), true, 512, \JSON_THROW_ON_ERROR);
            self::assertIsArray($fixture);
            self::assertIsArray($fixture['statement'] ?? null);
            $named = [...$named, ...$builder->packages(DoorStatement::chart($fixture['statement']))];
        }

        self::assertSame(['chartjs-adapter-date-fns', 'chartjs-chart-matrix', 'chartjs-chart-treemap', 'chartjs-chart-sankey', '@sgratzl/chartjs-chart-boxplot', 'chartjs-chart-funnel'], self::sorted(array_values(array_unique($named))));
        foreach (array_unique($named) as $package) {
            self::assertArrayHasKey($package, self::CHART_PACKAGES);
            self::assertStringContainsString(\sprintf("'%s': () => import('%s')", $package, $package), $plate);
        }
    }

    /**
     * @param list<string> $packages
     *
     * @return list<string>
     */
    private static function sorted(array $packages): array
    {
        $order = array_flip(array_keys(self::CHART_PACKAGES));
        usort($packages, static fn (string $a, string $b): int => ($order[$a] ?? 99) <=> ($order[$b] ?? 99));

        return $packages;
    }

    /**
     * THE ONE KEYWORD THAT IS NOT DECORATION. Flex opens assets/package.json only
     * for a package that declares it; drop it and the importmap block below is a
     * file nobody reads.
     */
    public function testThePackageIsMarkedAsAUxPackageOrFlexWillNotLookInside(): void
    {
        $keywords = self::composer()['keywords'] ?? null;

        self::assertIsArray($keywords);
        self::assertContains('symfony-ux', $keywords);
    }

    /**
     * The npm-side name is the composer name with an '@' — which is also the
     * AssetMapper namespace this bundle prepends, so the two cannot disagree.
     */
    public function testTheAssetPackageIsNamedTheWayFlexWillKeyIt(): void
    {
        $composer = self::composer();
        self::assertIsString($composer['name'] ?? null);

        self::assertSame('@'.$composer['name'], AtlasBundle::ASSET_NAMESPACE);
        self::assertSame(AtlasBundle::ASSET_NAMESPACE, self::assetPackage()['name'] ?? null);
    }

    /**
     * THE THREE LINES, DECLARED. `path:%PACKAGE%/…` is the form Flex resolves —
     * %PACKAGE% becomes the directory holding assets/package.json, so the entry
     * names a real file in this bundle whatever the host's vendor layout is. It
     * is the same form symfony/stimulus-bundle ships its loader with.
     */
    #[DataProvider('sharedModules')]
    public function testEverySharedModuleIsDeclaredAsAPathEntryIntoThisBundle(string $specifier, string $file): void
    {
        $importmap = self::importmap();

        self::assertArrayHasKey($specifier, $importmap, \sprintf('%s is not declared, so Flex writes nothing and every map importing it is blank.', $specifier));
        self::assertSame('path:%PACKAGE%/'.$file, $importmap[$specifier]);
        self::assertFileExists(self::root().'/assets/'.$file);
    }

    /**
     * Nothing else, and nothing missing. An entry here is a line written into
     * every host on the planet, so the block is the whole list rather than a
     * superset of it — and the list is the one AssetContractTest proves resolves.
     */
    public function testTheContributionCoversEverySharedModule(): void
    {
        $specifiers = [];
        $logicalPaths = [];
        foreach (self::sharedModules() as [$specifier, $file]) {
            $specifiers[] = $specifier;
            $logicalPaths[$specifier] = AtlasBundle::ASSET_NAMESPACE.'/'.$file;
        }

        self::assertSame($specifiers, array_keys(array_filter(self::importmap(), static fn (mixed $entry): bool => \is_string($entry) && str_starts_with($entry, 'path:'))));

        $resolved = [];
        foreach (AssetContractTest::sharedModules() as $specifier => [$logicalPath]) {
            $resolved[$specifier] = $logicalPath;
        }

        self::assertSame($resolved, $logicalPaths, 'The specifiers Flex writes and the logical paths AssetMapper resolves are one list; they have drifted.');
    }

    /**
     * THE ONE CONTROLLER, DECLARED. A Stimulus controller is not an importmap
     * entry: StimulusBundle reads it from the same assets/package.json, under
     * `symfony.controllers`, and an installation enables it in its own
     * controllers.json.
     *
     * The `name` is explicit and asserted, because the identifier StimulusBundle
     * would DERIVE is the package key plus the controller name — and the core
     * ships as one composer package whose assets/package.json aggregates every
     * bundle's controllers, so the derived identifier would say
     * "uhifadhi--uhifadhi--map-plate". Every plate on the platform carries the
     * name below instead, written by render_map().
     */
    public function testThePlateControllerIsDeclaredUnderTheNameEveryPlateCarries(): void
    {
        $symfony = self::assetPackage()['symfony'] ?? null;
        self::assertIsArray($symfony);
        $controllers = $symfony['controllers'] ?? null;
        self::assertIsArray($controllers);

        // TWO PLATES, TWO CONTROLLERS. The chart plate's resolves a series'
        // category where the chart is drawn, because a canvas does not
        // resolve a custom property the way an element does.
        self::assertSame(['map-plate', 'chart-plate'], array_keys($controllers));

        foreach (['map-plate' => MapPlateRuntime::CONTROLLER, 'chart-plate' => ChartRuntime::CONTROLLER] as $key => $name) {
            $plate = $controllers[$key];
            self::assertIsArray($plate);
            self::assertSame($name, $plate['name'] ?? null);
            self::assertTrue($plate['enabled'] ?? false);
            self::assertIsString($plate['main'] ?? null);
            self::assertFileExists(self::root().'/assets/'.$plate['main']);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function importmap(): array
    {
        $symfony = self::assetPackage()['symfony'] ?? null;
        self::assertIsArray($symfony);
        $importmap = $symfony['importmap'] ?? null;
        self::assertIsArray($importmap);

        /** @var array<string, mixed> $importmap */
        return $importmap;
    }

    /**
     * @return array<string, mixed>
     */
    private static function assetPackage(): array
    {
        return self::json(self::root().'/assets/package.json');
    }

    /**
     * @return array<string, mixed>
     */
    private static function composer(): array
    {
        return self::json(self::root().'/composer.json');
    }

    /**
     * @return array<string, mixed>
     */
    private static function json(string $path): array
    {
        self::assertFileExists($path);
        $decoded = json_decode((string) file_get_contents($path), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    private static function root(): string
    {
        return \dirname(__DIR__, 3);
    }
}
