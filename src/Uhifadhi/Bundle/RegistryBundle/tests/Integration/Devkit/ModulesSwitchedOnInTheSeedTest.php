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

namespace Uhifadhi\Bundle\RegistryBundle\Tests\Integration\Devkit;

use Uhifadhi\Bundle\RegistryBundle\Devkit\ModulesContentProvider;
use Uhifadhi\Bundle\RegistryBundle\Repository\AreaModuleRepository;
use Uhifadhi\Bundle\RegistryBundle\Service\AreaModuleService;
use Uhifadhi\Bundle\RegistryBundle\Service\ModuleCatalogue;
use Uhifadhi\Bundle\RegistryBundle\Tests\Integration\InstallationTestCase;

/**
 * A SEEDED INSTALLATION SHOWS WHAT IT SEEDED. The modules' seed writes
 * patrols and incidents into the seed areas; a module parked in an area hides
 * its pages there, so the seed switches every catalogued module on in every
 * area it holds — after the areas, and idempotently.
 */
final class ModulesSwitchedOnInTheSeedTest extends InstallationTestCase
{
    public function testEveryCataloguedModuleRunsInEveryArea(): void
    {
        $this->install(['sightings', 'ferries']);
        $this->area('North');
        $this->area('South');

        $this->provider()->load();
        $this->provider()->load();

        self::assertSame(
            [['North', 'ferries', true], ['North', 'sightings', true], ['South', 'ferries', true], ['South', 'sightings', true]],
            array_map(
                static fn (array $row): array => [$row['name'], $row['slug'], (bool) $row['active']],
                $this->em()->getConnection()->fetchAllAssociative(
                    'SELECT a.name, m.slug, am.active FROM area_module am JOIN module m ON m.id = am.module_id JOIN fixture_area_of_interest a ON a.id = am.aoi_id ORDER BY a.name, m.slug',
                ),
            ),
            'one row per area and module, every one running, and a second seed adds none',
        );
    }

    public function testItRunsAfterTheAreas(): void
    {
        self::assertSame(['area'], $this->provider()->dependsOn());
    }

    private function provider(): ModulesContentProvider
    {
        $areaModules = self::getContainer()->get(AreaModuleRepository::class);
        $catalogue = self::getContainer()->get('registry.catalogue');
        $switch = self::getContainer()->get('registry.area_modules');
        \assert($areaModules instanceof AreaModuleRepository && $catalogue instanceof ModuleCatalogue && $switch instanceof AreaModuleService);

        return new ModulesContentProvider($areaModules, $catalogue, $switch);
    }
}
