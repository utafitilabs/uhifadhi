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

namespace Uhifadhi\Bundle\AreaBundle\Service;

use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\Model\ModuleRegisterRow;
use Uhifadhi\Bundle\RegistryBundle\Entity\Module;
use Uhifadhi\Bundle\RegistryBundle\Service\AreaModuleService;
use Uhifadhi\Bundle\RegistryBundle\Service\ModuleCatalogue;
use Uhifadhi\Bundle\RegistryBundle\Service\ModuleEntryRouteResolver;
use Uhifadhi\Bundle\ShellBundle\Model\ModuleCard;
use Uhifadhi\Bundle\ShellBundle\Model\ModuleGroup;

/**
 * AN AREA'S COMPOSITION, READ — what is switched on here, what is parked, and
 * where each tile goes.
 *
 * THE READ MODEL ONLY. Every write goes straight to the registry's
 * {@see AreaModuleService} from the controller and never through this class: the
 * invariants — a pinned module cannot be parked, position 0 is the pinned one's
 * — live in the registry because the registry owns the table, and a second writer in
 * this bundle would eventually disagree with the first.
 *
 * THE CATALOGUE IS THE REGISTRY'S TOO, and it is an INTERSECTION rather than a
 * table: a `module` row whose bundle has been uninstalled keeps its data and
 * leaves the catalogue. That is why "parked" is derived here from
 * catalogue-minus-active rather than read off a column — an area does not have
 * a module parked when the module is not installed on the machine at all.
 *
 * THE GRID SHOWS WHAT IS ON, AND NOTHING ELSE. A tile for a parked module would
 * be a card that opens onto a page the area has switched off; parked things live
 * in the shop, which is the screen for changing your mind about them.
 *
 * A TILE WITH NOWHERE TO GO IS NOT A LINK. A module declares an entry ROUTE, not
 * a URL, and the application decides whether to mount it — so generating one can
 * fail, and a card whose route is unmounted is inert rather than a 404 waiting
 * to happen. Same tolerance as {@see \Uhifadhi\Bundle\AreaBundle\Shell\AreaShellSource}.
 */
final readonly class AreaComposition
{
    public function __construct(
        private ModuleCatalogue $catalogue,
        private AreaModuleService $areaModules,
        private ModuleEntryRouteResolver $entryRoutes,
        private RouterInterface $router,
        private ModuleSettingsDoors $doors,
    ) {
    }

    /**
     * THE GRID, GROUPED THE WAY THE CATALOGUE FILES THINGS. The headings are the
     * registry's own category labels, not a list written here: a category added to
     * the platform appears without this file changing.
     *
     * @return list<ModuleGroup>
     */
    public function gridFor(AreaOfInterest $area): array
    {
        $groups = [];
        foreach ($this->activeModulesOf($area) as $module) {
            $label = $module->getCategory()->label();
            $groups[$label] ??= new ModuleGroup($label);
            $groups[$label]->cards[] = new ModuleCard(
                slug: (string) $module->getSlug(),
                title: (string) $module->getName(),
                status: $module->getStatus()->value,
                source: $module->getDataSource(),
                url: $this->entryUrlFor($module, $area),
            );
        }

        return array_values($groups);
    }

    /**
     * THE ACTIVE MODULES AS ENTRY LINKS — the same set and order {@see gridFor}
     * draws, flattened for the sidebar's location tree: each is a name, its entry
     * url (null and therefore inert where the route is unmounted, the same
     * tolerance the grid keeps) and the slug the tree prints as the row's
     * identity-dot class. One method so the grid and the sidebar branch cannot
     * list a different set of modules than each other.
     *
     * @return list<ModuleCard>
     */
    public function moduleLinksFor(AreaOfInterest $area): array
    {
        $links = [];
        foreach ($this->activeModulesOf($area) as $module) {
            $links[] = new ModuleCard(
                slug: (string) $module->getSlug(),
                title: (string) $module->getName(),
                status: $module->getStatus()->value,
                source: $module->getDataSource(),
                url: $this->entryUrlFor($module, $area),
            );
        }

        return $links;
    }

    /**
     * WHEN THIS AREA TOOK EACH MODULE ON, by slug.
     *
     * A row written before the date was recorded has none, and says so on
     * the page: a date nobody wrote down cannot be recovered, and an
     * invented one would be read as a fact.
     *
     * @return array<string, \DateTimeImmutable>
     */
    public function installedAtBySlug(AreaOfInterest $area): array
    {
        $dates = [];
        foreach ($this->areaModules->activeFor($area) as $assignment) {
            $slug = $assignment->getModule()?->getSlug();
            $at = $assignment->getInstalledAt();
            if (\is_string($slug) && null !== $at) {
                $dates[$slug] = $at;
            }
        }

        return $dates;
    }

    /**
     * WHAT THIS AREA HAS SWITCHED ON *AND* STILL HAS INSTALLED, in the area's own
     * order.
     *
     * THE INTERSECTION IS THE WHOLE POINT, and getting it wrong is a bug that
     * cannot be cleared from the product. The registry's catalogue is deliberately
     * `rows AND registered providers`, so removing a bundle removes the
     * capability WITHOUT deleting anybody's data — the `area_module` row
     * survives, ready for the day the bundle comes back. Read straight from the
     * rows, this screen would keep drawing a tile for a module the machine no
     * longer has: a card that can never open, and one the shop could not even
     * offer to park, because the shop cannot see what the catalogue cannot.
     *
     * So the ledger is filtered THROUGH the catalogue, exactly as the parked side
     * is derived from it. Both halves of the screen then agree about what a
     * module is, which is the only way the two can add up.
     *
     * @return list<Module>
     */
    private function activeModulesOf(AreaOfInterest $area): array
    {
        $installed = [];
        foreach ($this->catalogue->all() as $module) {
            $installed[(string) $module->getSlug()] = true;
        }

        $active = [];
        foreach ($this->areaModules->activeFor($area) as $assignment) {
            $module = $assignment->getModule();
            if (null !== $module && isset($installed[(string) $module->getSlug()])) {
                $active[] = $module;
            }
        }

        return $active;
    }

    /**
     * THE MODULES REGISTER — every catalogued module as this area holds it,
     * running rows first in the area's own order, parked rows after in the
     * catalogue's.
     *
     * TWO KINDS OF PARKED ROW, AND THEY ARE DELIBERATELY INDISTINGUISHABLE: a
     * module this area switched OFF, and one it has never had a row for at
     * all. Both are switched on the same way and by the same slug, so telling
     * them apart would be a distinction that changes nothing a person can do.
     *
     * @return list<ModuleRegisterRow>
     */
    public function registerFor(AreaOfInterest $area): array
    {
        $rows = [];
        $position = 0;
        foreach ($this->activeModulesOf($area) as $module) {
            $rows[(string) $module->getSlug()] = $this->row($module, $area, ++$position);
        }

        foreach ($this->catalogue->all() as $module) {
            $slug = (string) $module->getSlug();
            if (!isset($rows[$slug])) {
                $rows[$slug] = $this->row($module, $area, null);
            }
        }

        return array_values($rows);
    }

    /** How many of the catalogue's modules this area has parked — the grid's count. */
    public function parkedCountFor(AreaOfInterest $area): int
    {
        return \count($this->catalogue->all()) - \count($this->activeModulesOf($area));
    }

    private function row(Module $module, AreaOfInterest $area, ?int $position): ModuleRegisterRow
    {
        return new ModuleRegisterRow(
            slug: (string) $module->getSlug(),
            name: (string) $module->getName(),
            description: $module->getDescription(),
            running: null !== $position,
            pinned: $module->isPinned(),
            position: $position,
            settings: $this->doors->doorFor((string) $module->getSlug(), $area),
        );
    }

    private function entryUrlFor(Module $module, AreaOfInterest $area): ?string
    {
        $route = $this->entryRoutes->entryRouteFor((string) $module->getSlug());
        if (null === $route || null === $this->router->getRouteCollection()->get($route)) {
            return null;
        }

        return $this->router->generate(
            $route,
            ['uuid' => $area->getUuidString()],
            UrlGeneratorInterface::ABSOLUTE_PATH,
        );
    }
}
