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

use Symfony\Component\Routing\RouterInterface;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\Model\ModuleSettingsDoor;
use Uhifadhi\Bundle\ShellBundle\Frame\Controller\ConfigureController;
use Uhifadhi\Bundle\ShellBundle\Frame\Registry\ConfigurationSectionsRegistry;
use Uhifadhi\Contracts\Shell\ConfigurationSection;

/**
 * WHERE A MODULE'S OWN SETTINGS ARE, asked of the same declaration the
 * shell draws its configure page from.
 *
 * A MODULE THAT DECLARES SECTIONS HAS A CONFIGURE PAGE, and the door goes
 * there — the shell's module configure address, which the shell answers for
 * every declared surface. A module that declares none has no page, and the
 * register says "no settings" rather than drawing a door onto a 404. Nothing
 * here knows a module: the slug is the catalogue's and the sections are the
 * module's.
 *
 * THE ADDRESS IS THE APPLICATION'S. The configure routes are a resource an
 * installation mounts; one that has not mounted them has no configure page
 * for anybody, and every row says "no settings" — a door that does not open
 * is worse than none.
 */
final readonly class ModuleSettingsDoors
{
    public function __construct(
        private ConfigurationSectionsRegistry $sections,
        private RouterInterface $router,
    ) {
    }

    public function doorFor(string $slug, AreaOfInterest $area): ?ModuleSettingsDoor
    {
        if (!$this->sections->has($slug) || null === $this->router->getRouteCollection()->get(ConfigureController::MODULE_ROUTE)) {
            return null;
        }

        return new ModuleSettingsDoor(
            $this->router->generate(ConfigureController::MODULE_ROUTE, ['uuid' => $area->getUuidString(), 'slug' => $slug]),
            array_map(
                static fn (ConfigurationSection $section): string => $section->label,
                $this->sections->sections($slug),
            ),
        );
    }
}
