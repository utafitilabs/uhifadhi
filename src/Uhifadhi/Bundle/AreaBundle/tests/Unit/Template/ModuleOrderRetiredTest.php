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

namespace Uhifadhi\Bundle\AreaBundle\Tests\Unit\Template;

use PHPUnit\Framework\TestCase;

/**
 * AN AREA'S RUNNING MODULES MOVES BY THE SHELL'S REORDER CONTROL, AND NO Area TEMPLATE NAMES
 * THE BUNDLE'S OWN CONTROLLER ANY MORE.
 *
 * `uhifadhi--shell-bundle--reorder` drags a row by its grip with a visible
 * slot where it will land, steps it with up and down carets and the arrow
 * keys, and announces the move; an area's Configure › Modules wears it.
 *
 * THE RETIRED CONTROLLER IS KEPT FOR ONE RELEASE AS A NO-OP. An
 * installation's `assets/controllers.json` still enables it, and a controller
 * file that is gone is a render-time fatal on every page; so the file stays,
 * does nothing, and keeps its `assets/package.json` entries — switched off
 * for new installations — until it is removed.
 */
final class ModuleOrderRetiredTest extends TestCase
{
    private const string RETIRED = 'uhifadhi--area-bundle--module-order';

    public function testNoTemplateNamesTheRetiredController(): void
    {
        $templates = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(\dirname(__DIR__, 3).'/templates', \FilesystemIterator::SKIP_DOTS));

        foreach ($templates as $file) {
            if (!$file instanceof \SplFileInfo || 'twig' !== $file->getExtension()) {
                continue;
            }

            self::assertStringNotContainsString(
                self::RETIRED,
                (string) file_get_contents($file->getPathname()),
                \sprintf('%s names the retired controller; the shell\'s reorder control moves the rows now.', $file->getFilename()),
            );
        }
    }

    /** The retired controller is still on disk and does nothing at all. */
    public function testTheRetiredControllerIsANoOp(): void
    {
        $file = \dirname(__DIR__, 3).'/assets/controllers/module_order_controller.js';
        self::assertFileExists($file);

        $source = (string) file_get_contents($file);
        self::assertStringContainsString('DEPRECATED, AND DOES NOTHING', $source);
        self::assertStringContainsString('uhifadhi--shell-bundle--reorder', $source, 'the docblock names the replacement');

        $code = (string) preg_replace('~/\*.*?\*/~s', '', $source);
        self::assertStringContainsString('export default class extends Controller {}', $code);
        self::assertStringNotContainsString('static targets', $code);
        self::assertStringNotContainsString('addEventListener', $code);
    }

    /** Both manifests still publish it, switched off for a new installation. */
    public function testTheRetiredControllerIsStillPublishedAndSwitchedOff(): void
    {
        foreach ([
            \dirname(__DIR__, 3).'/assets/package.json' => 'controllers/module_order_controller.js',
            \dirname(__DIR__, 7).'/assets/package.json' => '../src/Uhifadhi/Bundle/AreaBundle/assets/controllers/module_order_controller.js',
        ] as $manifest => $main) {
            $package = json_decode((string) file_get_contents($manifest), true, 512, \JSON_THROW_ON_ERROR);
            self::assertIsArray($package);
            self::assertIsArray($package['symfony'] ?? null);
            self::assertIsArray($package['symfony']['controllers'] ?? null);
            $entry = $package['symfony']['controllers']['module-order'] ?? null;

            self::assertIsArray($entry, $manifest.' still declares module-order');
            self::assertSame($main, $entry['main'] ?? null);
            self::assertSame(self::RETIRED, $entry['name'] ?? null);
            self::assertFalse($entry['enabled'] ?? null, $manifest.' no longer enables it for a new installation');
        }
    }
}
