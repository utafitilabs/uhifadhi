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

namespace Uhifadhi\Core\Tests\Core;

use PHPUnit\Framework\TestCase;

/**
 * ONE PACKAGE ANSWERS FOR EVERY IMPORT NAME THE CORE PUBLISHES, for exactly the
 * reason it answers for every controller ({@see ControllerPackageTest}).
 *
 * Flex reads a package's `assets/package.json` under
 * `<vendor-dir>/<the composer package's name>`
 * ({@see vendor/symfony/flex/src/PackageJsonSynchronizer.php} —
 * `resolvePackageJson()` builds that one path and looks nowhere else). The
 * bundles here are names this repository `replace`s: none of them has an install
 * path, so none of their manifests is ever opened. The core ships as ONE
 * composer package, and the root manifest is therefore the only file whose
 * `symfony.importmap` block reaches an installation's `importmap.php`.
 *
 * WHAT WOULD BREAK WITHOUT THIS TEST: a bundle declares its module in its own
 * manifest, every review reads that as published, the install writes nothing,
 * and the first thing anybody hears is a browser console on somebody else's
 * machine saying `Failed to resolve module specifier`. A bundle's own block is
 * still right — after a split it is the installed package — so both files carry
 * the name and this test is what keeps them one list.
 */
final class ImportmapPackageTest extends TestCase
{
    private const string ROOT = __DIR__.'/../..';

    /** The bundles that publish importmap modules. */
    private const array BUNDLES = ['ShellBundle', 'AtlasBundle'];

    /**
     * Every module a bundle declares is declared by the root manifest too, and
     * the two point at the same file.
     */
    public function testEveryModuleABundlePublishesIsPublishedByTheInstalledPackage(): void
    {
        $root = self::importmap(self::ROOT);

        foreach (self::BUNDLES as $bundle) {
            $directory = 'src/Uhifadhi/Bundle/'.$bundle;

            foreach (self::importmap(self::ROOT.'/'.$directory) as $specifier => $entry) {
                self::assertArrayHasKey(
                    $specifier,
                    $root,
                    $specifier.' is declared by '.$bundle.' and by no manifest Flex opens, so no installation gets it.',
                );

                self::assertIsString($entry);
                self::assertSame(
                    str_replace('path:%PACKAGE%/', 'path:%PACKAGE%/../'.$directory.'/assets/', $entry),
                    $root[$specifier],
                    $specifier.' points at a different file in the root manifest than in '.$bundle.'.',
                );
            }
        }
    }

    /**
     * And the root manifest publishes nothing no bundle ships: every entry is
     * a file of the core, or a third-party package a bundle declares.
     */
    public function testEveryModuleTheInstalledPackagePublishesIsAFileTheCoreShips(): void
    {
        $declared = [];
        foreach (self::BUNDLES as $bundle) {
            $declared += self::importmap(self::ROOT.'/src/Uhifadhi/Bundle/'.$bundle);
        }

        foreach (self::importmap(self::ROOT) as $specifier => $entry) {
            self::assertIsString($entry);
            if (!str_starts_with($entry, 'path:')) {
                self::assertArrayHasKey($specifier, $declared, $specifier.' is a package no bundle declares.');

                continue;
            }
            self::assertStringStartsWith('path:%PACKAGE%/', $entry, $specifier.' is not a path into this repository.');

            self::assertFileExists(
                self::ROOT.'/assets/'.substr($entry, \strlen('path:%PACKAGE%/')),
                'the entry "'.$specifier.'" points at no file.',
            );
        }
    }

    /**
     * The names are BARE SPECIFIERS, which is what lets a module move
     * underneath one: only the right-hand side of the entry changes, and no
     * importer in any repository does.
     */
    public function testEveryPublishedNameIsABareSpecifier(): void
    {
        foreach (self::importmap(self::ROOT) as $specifier => $entry) {
            if (\is_string($entry) && str_starts_with($entry, 'path:')) {
                self::assertMatchesRegularExpression('#^uhifadhi/[a-z][a-z0-9-]*$#', $specifier);
            }
        }
    }

    /**
     * A THIRD-PARTY PACKAGE IS PINNED, to the version the design was drawn
     * with: Flex runs `importmap:require <name>@<constraint>`, and a range
     * would let two installations draw the same chart with two libraries.
     */
    public function testEveryThirdPartyPackageIsPinnedToOneVersion(): void
    {
        foreach (self::importmap(self::ROOT) as $specifier => $entry) {
            self::assertIsString($entry);
            if (!str_starts_with($entry, 'path:')) {
                self::assertMatchesRegularExpression('#^\d+\.\d+\.\d+$#', $entry, $specifier.' is not pinned.');
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function importmap(string $directory): array
    {
        $path = $directory.'/assets/package.json';
        self::assertFileExists($path);

        $manifest = json_decode((string) file_get_contents($path), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($manifest);
        self::assertIsArray($manifest['symfony'] ?? null);

        $importmap = $manifest['symfony']['importmap'] ?? [];
        self::assertIsArray($importmap);

        /** @var array<string, mixed> $importmap */
        return $importmap;
    }
}
