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

namespace Uhifadhi\Bundle\AreaBundle\Shell;

use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Uid\Uuid;
use Uhifadhi\Bundle\AreaBundle\Controller\AreaEditController;
use Uhifadhi\Bundle\AreaBundle\Controller\AreaModulesController;
use Uhifadhi\Bundle\AreaBundle\Controller\StationConfigureController;
use Uhifadhi\Bundle\AreaBundle\Controller\ZoneConfigureController;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\Repository\AreaOfInterestRepository;
use Uhifadhi\Bundle\AreaBundle\Repository\ZoneRepository;
use Uhifadhi\Bundle\AreaBundle\Service\AreaRegister;
use Uhifadhi\Bundle\AreaBundle\Service\ZoneOverlapService;
use Uhifadhi\Contracts\Shell\AreaSectionsInterface;
use Uhifadhi\Contracts\Shell\ConfigurationSection;
use Uhifadhi\Contracts\Shell\ConfigurationSectionsInterface;

/**
 * WHAT IS ON AN AREA'S CONFIGURE PAGE — declared through exactly the contract a
 * module declares its own through.
 *
 * THE AREA IS NOT A SPECIAL CASE, and that is the whole reason it goes through
 * here. One `Configure` action, one page, one strip: if the area had its own
 * arrangement, the rule would already have an exception on the day it shipped,
 * and the second exception is always easier than the first.
 *
 * IT RESOLVES THE REQUEST ITSELF, like every other source in the frame. The
 * shell passes nothing — it has a slug, not an area — so the heading is composed
 * here, from the area the viewer is actually configuring.
 *
 * EVERY SECTION ANSWERS AT `/areas/{uuid}/configure/<section>` — the ones the
 * shell renders and the ones with a screen of their own alike — so a person
 * reads one address shape for everything an area is set up with, and a
 * module adding a section of its own lands in the same place.
 *
 * A SECTION THE VIEWER MAY NOT HOLD IS NOT DECLARED. Each one asks, of this
 * area, the pair the screen behind it enforces — and a section the shell
 * renders has no screen of its own, so it asks the pair of what it shows. The
 * shell holds no authorization service; a section left out here is one the
 * frame never draws and its address never serves.
 */
final readonly class AreaConfigurationSections implements ConfigurationSectionsInterface
{
    public function __construct(
        private RequestStack $requests,
        private AreaOfInterestRepository $areas,
        private AreaRegister $register,
        private ZoneRepository $zones,
        private AuthorizationCheckerInterface $authorization,
        /**
         * WHAT OTHER BUNDLES CONFIGURE ABOUT AN AREA. Departments are the
         * first: they belong to the team bundle, and an area naming them
         * would be this bundle depending on one it does not require.
         *
         * @var iterable<AreaSectionsInterface>
         */
        private iterable $contributors = [],
    ) {
    }

    public function slug(): string
    {
        return self::AREA;
    }

    /**
     * The area's own name, to which the shell adds " · configure". Empty when
     * the request names no area — a page the configure route never serves, but
     * a source that answers only on the pages it expects is a source that
     * throws on the one it did not.
     */
    public function heading(): string
    {
        return (string) $this->currentArea()?->getName();
    }

    public function summary(): string
    {
        return 'Everything this area is set up with, in one place: how its dashboard is composed, and the area’s own identity, access, zones and the posts standing on them.';
    }

    public function sections(): array
    {
        $area = $this->currentArea();

        /*
         * A SECTION WITH NO AREA TO BE ABOUT IS WITHHELD rather than rendered
         * over nothing. It can only happen on a request the configure route does
         * not serve, and answering it with an empty record would be worse than
         * answering it with a shorter strip.
         */
        if (null === $area) {
            return [];
        }

        $uuid = ['uuid' => (string) $area->getUuidString()];
        $sections = [];

        // THE COMPOSITION OF THE AREA'S DASHBOARD, which is the area's to read.
        if ($this->authorization->isGranted('areas.read', $area)) {
            $sections[] = ConfigurationSection::page(
                ConfigurationSection::WIDGETS,
                'Widget library',
                '@Area/area/configure/_widgets.html.twig',
            );
        }

        /*
         * MODULES IS A SCREEN — the register of what this area runs, with
         * the switch and the order — and it stands second because what an
         * area runs on is decided before how its ground is divided.
         */
        if ($this->authorization->isGranted(AreaModulesController::COMPOSE, $area)) {
            $sections[] = ConfigurationSection::screen('modules', 'Modules', AreaModulesController::CONFIGURE, $uuid);
        }

        /*
         * ZONES IS A SCREEN, NOT A RENDERED SECTION. A section the shell
         * draws is a template with no request of its own; this one takes an
         * uploaded file, previews it and writes, so it answers at its own
         * address and the strip links there. The frame is identical either
         * way — the shell recognises a section's own route and keeps the
         * strip and the Configure action exactly as they are here.
         */
        if ($this->authorization->isGranted(ZoneConfigureController::READ, $area)) {
            $sections[] = ConfigurationSection::screen('zones', 'Zones', ZoneConfigureController::ROUTE, $uuid);
        }

        /*
         * STATIONS IS A SCREEN TOO, and it sits beside Zones because the
         * two are read together: the ground, then the places on it.
         */
        if ($this->authorization->isGranted(StationConfigureController::READ, $area)) {
            $sections[] = ConfigurationSection::screen('stations', 'Stations', StationConfigureController::ROUTE, $uuid);
        }

        /*
         * CONTRIBUTED SECTIONS STAND HERE — after the area's own, before
         * Area settings, which is last on every configure page in the
         * platform. They are told the area by identifier and by name, so
         * neither bundle learns the other's classes, and each withholds
         * itself.
         */
        foreach ($this->contributors as $contributor) {
            foreach ($contributor->sectionsFor((string) $area->getUuidString(), (string) $area->getName()) as $section) {
                $sections[] = $section;
            }
        }

        // THE AREA'S OWN RECORD, whose one door is the edit screen.
        if ($this->authorization->isGranted(AreaEditController::CONFIGURE, $area)) {
            $sections[] = ConfigurationSection::page(
                ConfigurationSection::SETTINGS,
                'Area settings',
                '@Area/area/configure/_settings.html.twig',
                [
                    'area' => $area,
                    'areaKm2' => $this->register->areaKm2($area),
                    'defaultZoneOverlapTolerance' => ZoneOverlapService::DEFAULT_TOLERANCE_PCT,
                    'zoneCount' => $this->zones->countFor($area),
                ],
            );
        }

        return $sections;
    }

    private function currentArea(): ?AreaOfInterest
    {
        $uuid = $this->requests->getCurrentRequest()?->attributes->get('uuid');

        if (!\is_string($uuid) || !Uuid::isValid($uuid)) {
            return null;
        }

        return $this->areas->findOneBy(['uuid' => Uuid::fromString($uuid)]);
    }
}
