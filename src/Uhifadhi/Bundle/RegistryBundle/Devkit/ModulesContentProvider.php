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

namespace Uhifadhi\Bundle\RegistryBundle\Devkit;

use Uhifadhi\Bundle\RegistryBundle\Repository\AreaModuleRepository;
use Uhifadhi\Bundle\RegistryBundle\Service\AreaModuleService;
use Uhifadhi\Bundle\RegistryBundle\Service\ModuleCatalogue;
use Uhifadhi\Contracts\Devkit\ContentProviderInterface;

/**
 * A SEEDED INSTALLATION SHOWS WHAT IT SEEDED.
 *
 * Each module's own seed writes its records into the seed areas. A module that is parked in an area hides its pages there, and every
 * area the seed made was made after the deploy's `registry:sync`, so without
 * this the seed's content sat behind modules nobody had switched on. This
 * switches every catalogued module on in every area, through the same service
 * the area's Modules section uses — idempotent, so a second seed adds nothing.
 *
 * AFTER THE AREAS, before nothing: it names no module, so it cannot depend on
 * one, and a module's content does not need its switch to be written.
 *
 * IT IS COLLECTED, NOT RUN — devkit installs through `require-dev`, so in a
 * production build nothing asks this anything.
 */
final readonly class ModulesContentProvider implements ContentProviderInterface
{
    public function __construct(
        private AreaModuleRepository $areaModules,
        private ModuleCatalogue $catalogue,
        private AreaModuleService $switch,
    ) {
    }

    public function key(): string
    {
        return 'modules';
    }

    public function label(): string
    {
        return 'Modules switched on';
    }

    public function description(): string
    {
        return 'Every installed module running in every seed area, so the seed content has its pages.';
    }

    public function dependsOn(): array
    {
        return ['area'];
    }

    public function load(): void
    {
        $modules = $this->catalogue->all();
        foreach ($this->areaModules->everyArea() as $area) {
            foreach ($modules as $module) {
                $this->switch->install($area, (string) $module->getSlug());
            }
        }
    }
}
