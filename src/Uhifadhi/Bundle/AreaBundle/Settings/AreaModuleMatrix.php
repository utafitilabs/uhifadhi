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

namespace Uhifadhi\Bundle\AreaBundle\Settings;

use Uhifadhi\Bundle\AreaBundle\Repository\AreaOfInterestRepository;
use Uhifadhi\Bundle\AreaBundle\Repository\ZoneRepository;
use Uhifadhi\Bundle\RegistryBundle\Service\AreaModuleLedger;
use Uhifadhi\Bundle\RegistryBundle\Service\ModuleCatalogue;
use Uhifadhi\Contracts\Settings\AreaRun;
use Uhifadhi\Contracts\Settings\ModuleColumn;
use Uhifadhi\Contracts\Settings\ModuleMatrix;
use Uhifadhi\Contracts\Settings\ModuleMatrixSourceInterface;

/**
 * WHAT RUNS WHERE — assembled here because this is the one place with both
 * halves.
 *
 * The catalogue knows which modules exist and the ledger knows which of them
 * an area has switched on, but only this bundle has the AREAS, and the table
 * is one row per area. That is also why the contract is an alias rather than
 * a collected list: there is one answer, and two things claiming to know it
 * would be a disagreement with nothing to settle it.
 *
 * AN AREA THAT RUNS NOTHING KEEPS ITS ROW. It contributes to no figure and
 * appears in no queue anywhere else in the product — it has no module to
 * contribute through — so the only place a registered, empty area can be seen
 * at all is this table. Filtering it out would hide exactly the thing the
 * table is read for.
 *
 * ZONES ARE COUNTED BECAUSE THE TABLE DRAWS THEM, and they are this bundle's
 * own: an area with modules on and no zones is a different state from one
 * with neither, and an operator setting an installation up reads both columns
 * together.
 *
 * ONE QUERY PER AREA AND NOT ONE PER CELL. The ledger answers for a whole
 * area at once, which is what keeps a four-area installation at four reads
 * rather than at four times the catalogue.
 */
final readonly class AreaModuleMatrix implements ModuleMatrixSourceInterface
{
    public function __construct(
        private AreaOfInterestRepository $areas,
        private ZoneRepository $zones,
        private ModuleCatalogue $catalogue,
        private AreaModuleLedger $ledger,
    ) {
    }

    public function moduleMatrix(): ModuleMatrix
    {
        $columns = [];
        foreach ($this->catalogue->all() as $module) {
            $slug = $module->getSlug();
            if (null === $slug) {
                continue;
            }

            // WHAT IT SAYS IT IS, IN ITS OWN WORDS: the one line the module
            // declares, else what it declares it reads from. Only the module's
            // own words may be printed here; a sentence typed about somebody
            // else's package is wrong the release after it was written.
            $said = trim($module->getDescription() ?? '');
            if ('' === $said) {
                $said = trim($module->getDataSource());
            }

            $columns[] = new ModuleColumn(
                $slug,
                $module->getName() ?? $slug,
                description: '' === $said ? null : $said,
            );
        }

        $rows = [];
        foreach ($this->areas->findAllOrdered() as $area) {
            $running = [];
            foreach ($columns as $column) {
                $running[$column->slug] = false;
            }

            foreach ($this->ledger->for($area)['installed'] as $installed) {
                // A MODULE THE AREA HAS BUT THE CATALOGUE NO LONGER OFFERS is
                // not a column, so it is not a cell either: the table's shape
                // is the catalogue's, and a stale ledger row is the
                // catalogue's problem to reconcile rather than this table's
                // to grow a column for.
                if (\array_key_exists($installed['slug'], $running)) {
                    $running[$installed['slug']] = true;
                }
            }

            $rows[] = new AreaRun((string) $area->getName(), $running, $this->zones->countFor($area));
        }

        return new ModuleMatrix($columns, $rows);
    }
}
