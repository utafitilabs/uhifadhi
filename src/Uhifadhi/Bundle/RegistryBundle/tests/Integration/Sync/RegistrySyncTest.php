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

namespace Uhifadhi\Bundle\RegistryBundle\Tests\Integration\Sync;

use Uhifadhi\Bundle\RegistryBundle\Service\AreaModuleService;
use Uhifadhi\Bundle\RegistryBundle\Tests\Integration\InstallationTestCase;

/**
 * THE REGISTRY SYNC IS CREATE-ONLY, AND THAT IS A PRODUCTION PROMISE.
 *
 * This runs on every deploy. Everything it does, it does to a database with
 * real areas in it, configured by real admins, and there is no undo. So the
 * rule that matters more than any other here is what it does NOT touch:
 *
 *   the catalogue row is the MODULE'S — the provider owns its name, category
 *   and provenance, and the sync refreshes them;
 *   the per-area row is the ADMIN'S — the sync creates it if it is missing and
 *   never revisits it again.
 *
 * An admin who switched a module off is not overruled by a deploy. A module
 * that a deploy reorders does not reshuffle every area's sub-nav. Both of those
 * are one-line mistakes to make and neither is visible until somebody's screen
 * changes overnight.
 *
 * The sync is what `registry:sync` runs; these specifications call the service
 * directly, because they are about what it does and reports rather than about
 * the command that types it.
 */
final class RegistrySyncTest extends InstallationTestCase
{
    private function areaModules(): AreaModuleService
    {
        $service = $this->service('registry.area_modules');
        \assert($service instanceof AreaModuleService);

        return $service;
    }

    /**
     * ZERO MODULES IS A SUCCESSFUL SYNC. A fresh installation, one registry, no
     * modules: the sync has to run and report nothing rather than fail on an
     * empty iterator.
     */
    public function testSyncingAnInstallationWithNoModulesSucceedsAndWritesNothing(): void
    {
        $this->install([]);
        $area = $this->area();

        $result = $this->sync();

        self::assertSame([], $this->areaModules()->allFor($area));
        self::assertSame(0, $result->modules());
        self::assertSame(0, $result->areaAssignments);
        self::assertFalse($result->skipped);
    }

    public function testItIsIdempotent(): void
    {
        $this->install(['sightings', 'ferries']);
        $area = $this->area();
        $this->reconcile();

        $before = \count($this->areaModules()->allFor($area));
        $this->reconcile();

        self::assertSame($before, \count($this->areaModules()->allFor($area)), 'a second run adds nothing');
    }

    /**
     * THE DEPLOY LESSON, IN ONE TEST. An admin parked a base module and turned
     * on an installable one — two deliberate decisions, both the opposite of
     * what the sync would have written. The next deploy must leave both alone.
     */
    public function testADeployNeverOverrulesAnAdminsPerAreaChoices(): void
    {
        $this->install(['ferries' => ['base' => true], 'sightings' => []]);
        $area = $this->area();
        $this->reconcile();

        $this->areaModules()->uninstall($area, 'ferries'); // parked a CORE module, on purpose
        $this->areaModules()->install($area, 'sightings');  // switched on an INSTALLABLE one

        $this->reconcile();

        self::assertFalse($this->areaModules()->isActive($area, 'ferries'), 'a base module the admin parked stays parked');
        self::assertTrue($this->areaModules()->isActive($area, 'sightings'), 'a module the admin switched on stays on');
    }

    /**
     * The area's own ordering is the admin's too. A newly installed module gets
     * a position; it does not renumber the sub-nav somebody arranged.
     */
    public function testADeployNeverReordersAnAreasSubNav(): void
    {
        $this->install(['sightings', 'ferries']);
        $area = $this->area();
        $this->reconcile();
        foreach (['sightings', 'ferries'] as $slug) {
            $this->areaModules()->install($area, $slug);
        }
        $this->areaModules()->reorder($area, ['ferries', 'sightings']);

        $this->install(['sightings', 'ferries', 'tides'], freshDatabase: false);

        self::assertSame(
            ['ferries', 'sightings'],
            array_map(
                static fn (object $areaModule): ?string => $areaModule->getModule()?->getSlug(),
                $this->areaModules()->activeFor($area),
            ),
            'the new module joins parked, and the arranged order is untouched',
        );
    }

    /**
     * Every area gets backfilled, including the ones created between deploys —
     * that is the half of the sync that is not create-only-shaped, and it is
     * why it exists at all.
     */
    public function testANewModuleReachesEveryExistingArea(): void
    {
        $this->install(['sightings']);
        $north = $this->area('North');
        $south = $this->area('South');
        $this->reconcile();

        $this->install(['sightings', 'tides'], freshDatabase: false);

        foreach ([$north, $south] as $area) {
            self::assertContains('tides', array_map(
                static fn (object $areaModule): ?string => $areaModule->getModule()?->getSlug(),
                $this->areaModules()->allFor($area),
            ));
        }
    }

    /**
     * AN UNINSTALLED MODULE'S ROWS ARE LEFT ALONE, not deleted. The sync stops
     * offering the module (see ModuleCatalogueTest); it does not go around
     * removing history, because a bundle removed by mistake and reinstalled
     * next morning has to find the area exactly as it left it — and because a
     * deploy step that deletes data on a missing dependency is a step nobody
     * should run on production.
     */
    public function testAnUninstalledModulesHistoryIsNotDeleted(): void
    {
        $this->install(['sightings', 'ferries']);
        $area = $this->area();
        $this->reconcile();
        $this->areaModules()->install($area, 'ferries');

        $this->install(['sightings'], freshDatabase: false);
        $this->install(['sightings', 'ferries'], freshDatabase: false);

        self::assertTrue(
            $this->areaModules()->isActive($area, 'ferries'),
            'reinstalling a bundle finds the area as it left it',
        );
    }

    /**
     * The sync reports what it did, and the counts are what a deploy log needs
     * to tell "nothing changed" from "nothing ran".
     */
    public function testTheSyncReportsWhatItReconciled(): void
    {
        $this->install(['sightings', 'ferries']);
        $this->area('North');
        $this->area('South');

        // install() reconciled once already, before the areas existed: the
        // modules are kept from that run, and the area rows are this one's.
        $result = $this->sync();

        self::assertSame([], $result->added);
        self::assertSame(['sightings', 'ferries'], $result->kept);
        self::assertSame([], $result->retired);
        self::assertSame(4, $result->areaAssignments, 'two modules × two areas, all new');

        self::assertSame(0, $this->sync()->areaAssignments, 'a second run backfills nothing');

        $this->install(['sightings'], freshDatabase: false);
        self::assertSame(['ferries'], $this->sync()->retired, 'a module whose bundle is gone is named, and its row stays');
    }
}
