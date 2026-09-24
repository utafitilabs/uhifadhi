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

namespace Uhifadhi\Bundle\TeamBundle\Service;

use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Uhifadhi\Bundle\ShellBundle\Service\Scopes;

/**
 * WHERE SOMEBODY GOES TO POST A PERSON AT A STATION.
 *
 * A POSTING IS MADE IN THE AREA, and the person's record says so and then
 * left the reader to find the page: the owner could not. Naming a page
 * without a door to it is the defect, so the record carries one.
 *
 * WHICH AREA, THOUGH. A posting is made on a station and a station belongs to
 * an area, so the door needs one — and this bundle holds none. The areas the
 * VIEWER may open arrive through the shell's scope source, which is the one
 * place that has the areas, the account and the voters folded together. Where
 * exactly one area is offered the door goes straight to its stations; where
 * several are, it goes to the register, because choosing one on somebody's
 * behalf is choosing where they work.
 *
 * ROUTE-TOLERANT, like every cross-bundle link this bundle draws. The
 * addresses belong to the application, which mounts the area bundle's routes
 * or does not, and a record page that 500'd because somebody unmounted a
 * route would be the worst possible way to learn it. No route, no door.
 *
 * IT ASKS NOTHING ABOUT PERMISSION. Whether the viewer may open the page
 * behind the door is the template's question, asked with the same
 * `is_granted` the page itself is gated on — the rule the sidebar's sources
 * already follow, so that a door never closes in somebody's face.
 */
final readonly class PostingDoorService
{
    /** Where a posting is actually made, in one area. */
    public const string STATIONS_ROUTE = 'area_stations_configure';

    /** Where somebody picks the area first. */
    public const string REGISTER_ROUTE = 'area_index';

    public function __construct(
        private UrlGeneratorInterface $urls,
        private Scopes $scopes,
    ) {
    }

    /**
     * The address, or null where this installation mounts no area pages at
     * all and there is nowhere to send anybody.
     */
    public function url(): ?string
    {
        $areas = array_values(array_filter(
            $this->scopes->available(),
            static fn (object $scope): bool => !$scope->isOrganization(),
        ));

        if (1 === \count($areas)) {
            try {
                return $this->urls->generate(self::STATIONS_ROUTE, ['uuid' => $areas[0]->areaUuid]);
            } catch (RouteNotFoundException) {
                // The stations page is not mounted; the register may still be.
            }
        }

        try {
            return $this->urls->generate(self::REGISTER_ROUTE);
        } catch (RouteNotFoundException) {
            return null;
        }
    }
}
