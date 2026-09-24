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

namespace Uhifadhi\Bundle\RegistryBundle\Tests\Integration\Routing;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Uhifadhi\Bundle\RegistryBundle\Service\AreaModuleService;
use Uhifadhi\Bundle\RegistryBundle\Tests\Integration\Fixtures\RoutedHostKernel;
use Uhifadhi\Bundle\RegistryBundle\Tests\Integration\InstallationTestCase;
use Uhifadhi\Entity\AreaOfInterest;

/**
 * SPEC 12 — PARKING A MODULE CLOSES ITS ROUTES, and closes them as ABSENCE.
 *
 * The hole this fills: an area could park a module on the customize screen, see
 * it leave the sub-nav, and still have every one of its pages answer to anyone
 * who kept the URL. Parking read as a tidier menu rather than a decision.
 *
 * WHY THE REGISTRY AND NOT THE MODULE. The registry owns the per-area ledger, so the
 * registry is the only thing that can answer "is this module switched on here" — and
 * a check each module writes for itself is a check each module can forget. One
 * enforcement point, no per-module code, and a module written before any of this
 * existed is closed exactly like one written after.
 *
 * WHY 404 AND NOT 403. A 403 says "this exists and you may not have it", which
 * is a true sentence about permissions and a false one about parking: a parked
 * module is not withheld from you, it is not part of this area. Absence is what
 * the area's own screens already show — the module sits in the shop, not the
 * sub-nav — and the URL now agrees with them.
 */
final class ParkedModuleRouteTest extends InstallationTestCase
{
    protected static function getKernelClass(): string
    {
        return RoutedHostKernel::class;
    }

    /**
     * The two stand-in modules, installed on the deployment and — because
     * neither declares itself base — parked for every area the seed backfills.
     */
    private function areaWithModules(): AreaOfInterest
    {
        $this->install(['alpha', 'beta']);

        return $this->area();
    }

    private function areaModules(): AreaModuleService
    {
        $service = $this->service('registry.area_modules');
        \assert($service instanceof AreaModuleService);

        return $service;
    }

    private function uuid(AreaOfInterest $area): string
    {
        $uuid = $area->getUuid()?->toRfc4122();
        \assert(null !== $uuid);

        return $uuid;
    }

    private function get(string $path): Response
    {
        $kernel = self::$kernel;
        \assert(null !== $kernel);

        return $kernel->handle(Request::create($path));
    }

    /**
     * THE HOLE, CLOSED. The module is in this deployment and this area has
     * parked it; the URL that used to render its dashboard is now a 404.
     */
    public function testAParkedModulesPageIsGone(): void
    {
        $area = $this->areaWithModules();
        $this->areaModules()->install($area, 'alpha');
        $this->areaModules()->uninstall($area, 'alpha');

        self::assertSame(404, $this->get('/areas/'.$this->uuid($area).'/modules/alpha')->getStatusCode());
    }

    /**
     * AND ONLY WHEN PARKED. An active module is untouched — the gate is not
     * permission, and it adds no verdict of its own to a page that is on.
     */
    public function testAnActiveModulesPageRenders(): void
    {
        $area = $this->areaWithModules();
        $this->areaModules()->install($area, 'alpha');

        $response = $this->get('/areas/'.$this->uuid($area).'/modules/alpha');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('the page rendered', $response->getContent());
    }

    /**
     * NEVER TAKEN READS THE SAME AS PARKED. An area created between two seeds
     * holds no row for the module at all, and its screens already say so: the
     * module sits in the shop and not in the sub-nav. The URL says the same
     * thing rather than quietly disagreeing with the page.
     */
    public function testAModuleTheAreaNeverTookIsGoneToo(): void
    {
        $this->install(['alpha', 'beta']);
        $fresh = $this->area('Created after the seed');

        self::assertSame(404, $this->get('/areas/'.$this->uuid($fresh).'/modules/alpha')->getStatusCode());
    }

    /**
     * A SCREEN THAT IS NOT A MODULE'S, ON THE MODULE PATH SHAPE, IS UNTOUCHED.
     *
     * An installation may mount a screen of its own under `/areas/{uuid}/modules/`.
     * Recognising module routes by their path alone, with nothing to check the
     * segment against, would 404 it whenever the shape matched. The gate reads
     * the segment as a module only when the catalogue has one by that name.
     */
    public function testAScreenThatIsNoModulesOnTheSamePathShapeIsUntouched(): void
    {
        $area = $this->areaWithModules();

        self::assertSame(200, $this->get('/areas/'.$this->uuid($area).'/modules/compare')->getStatusCode());
    }

    /**
     * A ROUTE THAT IS NOT A MODULE'S COSTS NOTHING AND CHANGES NOTHING.
     */
    public function testARouteBelongingToNoModuleIsUntouched(): void
    {
        $area = $this->areaWithModules();

        self::assertSame(200, $this->get('/areas/'.$this->uuid($area))->getStatusCode());
    }

    /**
     * A MODULE THAT DECLARED NOTHING IS STILL CLOSED. The marker is the precise
     * reading; the fleet's path shape is the safety net under a module that has
     * not added its line — or was written before there was a line to add.
     */
    public function testAModuleThatDeclaresNothingIsClosedByItsPath(): void
    {
        $area = $this->areaWithModules();
        $path = '/areas/'.$this->uuid($area).'/modules/beta/log';

        $this->areaModules()->install($area, 'beta');
        self::assertSame(200, $this->get($path)->getStatusCode());

        $this->areaModules()->uninstall($area, 'beta');
        self::assertSame(404, $this->get($path)->getStatusCode());
    }

    /**
     * A DECLARED ROUTE IS CLOSED WHEREVER IT LIVES. This one is nowhere near
     * `/areas/{uuid}/modules/…` and names its own area parameter; the marker is
     * the whole reason the gate can see it.
     */
    public function testADeclaredRouteOffThePathShapeIsClosed(): void
    {
        $area = $this->areaWithModules();
        $path = '/alpha-console/'.$this->uuid($area);

        $this->areaModules()->install($area, 'alpha');
        self::assertSame(200, $this->get($path)->getStatusCode());

        $this->areaModules()->uninstall($area, 'alpha');
        self::assertSame(404, $this->get($path)->getStatusCode());
    }

    /**
     * PARKING IS PER AREA, and so is the closing. One area's decision never
     * reaches another's URLs.
     */
    public function testClosingIsPerArea(): void
    {
        $area = $this->areaWithModules();
        $other = $this->area('The other area');

        $this->areaModules()->install($area, 'alpha');
        $this->areaModules()->install($other, 'alpha');
        $this->areaModules()->uninstall($other, 'alpha');

        self::assertSame(200, $this->get('/areas/'.$this->uuid($area).'/modules/alpha')->getStatusCode());
        self::assertSame(404, $this->get('/areas/'.$this->uuid($other).'/modules/alpha')->getStatusCode());
    }
}
