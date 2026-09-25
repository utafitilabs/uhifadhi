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
 * THE STATIONS SECTION PICKS ITS POINT THROUGH THE ATLAS, AND EXTENDS NOTHING.
 *
 * The plate picks a point into a form because the area states
 * `AtlasMap::pickPoint()`; the controls that arm it for another form wear the
 * atlas's `data-atlas-pick` attributes. No Area template names the area's
 * own picker controller any more.
 *
 * THAT CONTROLLER IS KEPT FOR ONE RELEASE AS A NO-OP. An installation's
 * `assets/controllers.json` still enables it, and a controller file that is
 * gone is a render-time fatal on every page; so the file stays, does nothing,
 * and keeps its `assets/package.json` entry until it is removed.
 */
final class StationPickTest extends TestCase
{
    private const string RETIRED = 'uhifadhi--area-bundle--station-point';

    /** No Area template names the retired picker, as a controller, a target or an action. */
    public function testNoAreaTemplateNamesTheRetiredPicker(): void
    {
        $templates = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(\dirname(__DIR__, 3).'/templates', \FilesystemIterator::SKIP_DOTS));

        foreach ($templates as $file) {
            if (!$file instanceof \SplFileInfo || 'twig' !== $file->getExtension()) {
                continue;
            }

            self::assertStringNotContainsString(
                self::RETIRED,
                (string) file_get_contents($file->getPathname()),
                \sprintf('%s names the retired station picker; the atlas picks the point now.', $file->getFilename()),
            );
        }
    }

    /**
     * THE CONTROLS ARM THE ATLAS'S PLATE: each names its form, its mode and
     * the point it is about, and the add form's note is the one the plate
     * tells what it wrote.
     */
    public function testTheArmingControlsWearTheAtlasAttributes(): void
    {
        $template = self::template();

        self::assertStringContainsString('data-atlas-pick="station-add"', $template);
        self::assertStringContainsString('data-atlas-pick="move-{{ row.uuid }}"', $template);
        self::assertStringContainsString('data-atlas-pick-mode="add"', $template);
        self::assertStringContainsString('data-atlas-pick-mode="move"', $template);
        self::assertSame(2, substr_count($template, 'data-atlas-pick-name='));
        self::assertStringContainsString('data-atlas-pick-note', $template);
    }

    /** The caption is the atlas's, drawn under the plate; the section does not draw a second one. */
    public function testTheCaptionIsTheAtlasesAndNotTheSections(): void
    {
        self::assertStringNotContainsString('class="pickcap"', self::template());
    }

    /** The retired controller is still on disk, still published, and does nothing at all. */
    public function testTheRetiredControllerIsANoOpThatIsStillPublished(): void
    {
        $file = \dirname(__DIR__, 3).'/assets/controllers/station_point_controller.js';
        self::assertFileExists($file);

        $code = (string) preg_replace('~/\*.*?\*/~s', '', (string) file_get_contents($file));
        self::assertStringContainsString('export default class extends Controller', $code);
        self::assertStringNotContainsString('static targets', $code);
        self::assertStringNotContainsString('addEventListener', $code);
        self::assertStringNotContainsString('console', $code);

        self::assertStringContainsString(
            '"main": "controllers/station_point_controller.js"',
            (string) file_get_contents(\dirname(__DIR__, 3).'/assets/package.json'),
        );
    }

    private static function template(): string
    {
        return (string) file_get_contents(\dirname(__DIR__, 3).'/templates/station/configure.html.twig');
    }
}
