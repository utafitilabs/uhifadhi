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

namespace Uhifadhi\Bundle\RegistryBundle\Service;

use Uhifadhi\Bundle\RegistryBundle\RegistryBundle;
use Uhifadhi\Bundle\RegistryBundle\Repository\AreaModuleRepository;

/**
 * PARKING A MODULE CLOSES ITS ROUTES — the decision, in one place, for every
 * module at once.
 *
 * WHY HERE. The registry owns the per-area ledger, so the registry is the only thing
 * that can answer "is this module switched on for this area". A check written
 * into each module is a check each module can forget, and the ones likeliest to
 * forget are the ones written after everybody stopped thinking about it. One
 * gate, no per-module code, and a module written before this existed is closed
 * exactly like one written after.
 *
 * TWO READINGS, IN THIS ORDER.
 *
 *  1. THE MARKER. A route default — `_uhifadhi_module: <slug>` — the module
 *     writes on its own routes. Precise, self-declaring, greppable, and free:
 *     an array lookup on attributes the router has already parsed. It works
 *     wherever the route lives, and it names the slug rather than guessing it
 *     from a URL segment.
 *  2. THE PATH SHAPE, as the safety net. The fleet routes module pages at
 *     `/areas/{uuid}/modules/{slug}/…`, and a module that has not added its
 *     marker line is recognised there — but ONLY when the segment names a
 *     module the catalogue actually has.
 *
 * THE CATALOGUE CHECK IS NOT A DETAIL, it is what makes reading 2 safe at all.
 * The area's own screens wear the same path shape — `/areas/{uuid}/modules` is
 * the grid — and nothing stops an installation mounting a screen of its own
 * under it. A gate that read the shape and nothing else would close every such
 * door. A segment that names no module in the catalogue is not a module here.
 *
 * WHAT IT COSTS. A request with the marker: one indexed row read
 * (`area_module` joined to `module` and the area, `LIMIT 1`), and nothing at
 * all for every request without it. A request without the marker that wears the
 * path shape pays the catalogue read as well, which is the price of the safety
 * net and the reason the marker is the recommended line. Everything else —
 * every asset, every page outside an area — exits on a string comparison. None
 * of it is cached, deliberately: the platform's promise is that switching a
 * module off takes it off the same day, and a cache in front of this reading is
 * exactly how that stops being true.
 *
 * IT KNOWS NO MODULE. Slugs arrive from a route default or a URL, and the
 * catalogue answers whether they mean anything; nothing here recognises one.
 */
final readonly class ModuleRouteGate
{
    /**
     * The fleet's module path: `/areas/{area}/modules/{slug}` and anything under
     * it. The area segment is not validated here — an unparseable one simply
     * finds no row, and validating it would be the router's job twice.
     */
    private const string PATH_SHAPE = '#^/areas/(?<area>[^/]++)/modules/(?<slug>[^/]++)(?:/|$)#';

    public function __construct(
        private AreaModuleRepository $areaModules,
        private ModuleCatalogue $catalogue,
    ) {
    }

    /**
     * IS THIS REQUEST FOR A MODULE THE AREA DOES NOT HAVE?
     *
     * True means "answer as though it were not there". False means every other
     * case, and the list of other cases matters: not a module route, no area to
     * read, an installation whose areas have no uuid, and — the ordinary one —
     * a module that is switched on.
     *
     * @param array<string, mixed> $routeAttributes the request attributes the router filled in
     */
    public function closes(array $routeAttributes, string $path): bool
    {
        $target = $this->targetOf($routeAttributes, $path);
        if (null === $target) {
            return false;
        }

        [$areaUuid, $slug] = $target;

        // Parked and never-taken are one sentence to a URL: the module is not
        // part of this area. A uuid that is no area lands here too, and answers
        // 404 the same way the page behind it would have.
        return true !== $this->areaModules->activeStateForAreaUuid($areaUuid, $slug);
    }

    /**
     * The area uuid and module slug this request is about, or null when it is
     * not about a module at all.
     *
     * @param array<string, mixed> $routeAttributes
     *
     * @return array{string, string}|null
     */
    private function targetOf(array $routeAttributes, string $path): ?array
    {
        $declared = $routeAttributes[RegistryBundle::MODULE_ROUTE_DEFAULT] ?? null;
        if (\is_string($declared) && '' !== $declared) {
            $parameter = $routeAttributes[RegistryBundle::MODULE_ROUTE_AREA_DEFAULT] ?? null;
            $parameter = \is_string($parameter) && '' !== $parameter
                ? $parameter
                : RegistryBundle::DEFAULT_AREA_PARAMETER;

            $areaUuid = $routeAttributes[$parameter] ?? null;

            // A declared module route with no area in it is not area-scoped, and
            // the ledger has nothing to say about it.
            return \is_string($areaUuid) && '' !== $areaUuid
                ? $this->guarded($areaUuid, $declared)
                : null;
        }

        if (1 !== preg_match(self::PATH_SHAPE, $path, $matches)) {
            return null;
        }

        // The segment is a module only if the catalogue has one by that name;
        // otherwise it is somebody else's screen wearing the same shape.
        return null === $this->catalogue->find($matches['slug'])
            ? null
            : $this->guarded($matches['area'], $matches['slug']);
    }

    /**
     * @return array{string, string}|null
     */
    private function guarded(string $areaUuid, string $slug): ?array
    {
        return $this->areaModules->areaIsAddressableByUuid() ? [$areaUuid, $slug] : null;
    }
}
