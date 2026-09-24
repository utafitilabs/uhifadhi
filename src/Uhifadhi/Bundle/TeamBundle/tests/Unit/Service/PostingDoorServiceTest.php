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

namespace Uhifadhi\Bundle\TeamBundle\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Uhifadhi\Bundle\ShellBundle\Service\Scopes;
use Uhifadhi\Bundle\TeamBundle\Service\PostingDoorService;
use Uhifadhi\Contracts\Shell\Scope;
use Uhifadhi\Contracts\Shell\ScopeSourceInterface;

/**
 * WHERE THE PERSON'S RECORD SENDS SOMEBODY TO MAKE A POSTING.
 *
 * WHICH AREA IS THE WHOLE QUESTION. A posting is made on a station and a
 * station belongs to an area, so the door needs one — and this bundle holds
 * no areas. Where the viewer may open exactly one, the door goes straight to
 * its stations; where they may open several, it goes to the register, because
 * choosing one on somebody's behalf is choosing where they work.
 */
#[CoversClass(PostingDoorService::class)]
final class PostingDoorServiceTest extends TestCase
{
    /** One area to choose from is no choice, so the door skips the register. */
    public function testOneAreaSendsSomebodyStraightToItsStations(): void
    {
        $door = $this->door(['01a0c006-bfb6-752d-bc76-5e816ddeaa93' => 'Northern Reserve']);

        self::assertSame('/areas/01a0c006-bfb6-752d-bc76-5e816ddeaa93/configure/stations', $door->url());
    }

    /** Several, and the register is where they say which. */
    public function testSeveralAreasSendSomebodyToTheRegisterToChoose(): void
    {
        $door = $this->door([
            '01a0c006-bfb6-752d-bc76-5e816ddeaa93' => 'Northern Reserve',
            '01a0c006-bfb7-78cc-9a9f-54bd5ebfa376' => 'Southern Reserve',
        ]);

        self::assertSame('/areas', $door->url());
    }

    /**
     * THE ORGANIZATION IS NOT AN AREA. It is the first row of every scope
     * control, and a door that counted it would send somebody who may open
     * one area to the register instead of to their own stations.
     */
    public function testTheOrganizationRowIsNotCountedAsAnArea(): void
    {
        $door = $this->door(['01a0c006-bfb6-752d-bc76-5e816ddeaa93' => 'Northern Reserve'], organization: true);

        self::assertSame('/areas/01a0c006-bfb6-752d-bc76-5e816ddeaa93/configure/stations', $door->url());
    }

    /** No areas at all, and the register is still somewhere to start. */
    public function testWithNoAreasTheRegisterIsStillTheAnswer(): void
    {
        self::assertSame('/areas', $this->door([])->url());
    }

    /**
     * AND WHERE THE APPLICATION MOUNTS NO AREA PAGES AT ALL there is nowhere
     * to send anybody, so there is no door — never a link to a 404.
     */
    public function testWithNoAreaPagesMountedThereIsNoDoor(): void
    {
        self::assertNull($this->door([], mounted: false)->url());
    }

    /**
     * ONE UNMOUNTED ADDRESS DOES NOT TAKE THE OTHER DOWN. An installation
     * that kept the register and dropped the stations screen still has
     * somewhere to send somebody.
     */
    public function testAMissingStationsPageFallsBackToTheRegister(): void
    {
        $door = $this->door(['01a0c006-bfb6-752d-bc76-5e816ddeaa93' => 'Northern Reserve'], stations: false);

        self::assertSame('/areas', $door->url());
    }

    /** @param array<string, string> $areas uuid => name */
    private function door(array $areas, bool $organization = false, bool $mounted = true, bool $stations = true): PostingDoorService
    {
        $routes = new RouteCollection();
        if ($mounted) {
            $routes->add(PostingDoorService::REGISTER_ROUTE, new Route('/areas'));
            if ($stations) {
                $routes->add(PostingDoorService::STATIONS_ROUTE, new Route('/areas/{uuid}/configure/stations'));
            }
        }

        $scopes = [];
        if ($organization) {
            $scopes[] = Scope::organization();
        }
        foreach ($areas as $uuid => $name) {
            $scopes[] = Scope::area($uuid, $name);
        }

        return new PostingDoorService(
            new UrlGenerator($routes, new RequestContext()),
            new Scopes([new class($scopes) implements ScopeSourceInterface {
                /** @param list<Scope> $scopes */
                public function __construct(private readonly array $scopes)
                {
                }

                public function scopes(): iterable
                {
                    yield from $this->scopes;
                }
            }], new RequestStack()),
        );
    }
}
