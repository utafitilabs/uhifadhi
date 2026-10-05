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

namespace Uhifadhi\Bundle\TeamBundle\Shell;

use Uhifadhi\Bundle\TeamBundle\Access\Door;
use Uhifadhi\Bundle\TeamBundle\Controller\PermissionsController;
use Uhifadhi\Bundle\TeamBundle\Controller\PositionController;
use Uhifadhi\Bundle\TeamBundle\Controller\RankController;
use Uhifadhi\Bundle\TeamBundle\Controller\TeamController;
use Uhifadhi\Bundle\TeamBundle\Controller\TeamPostingsController;
use Uhifadhi\Bundle\TeamBundle\Controller\TeamSectionController;
use Uhifadhi\Bundle\TeamBundle\Service\TeamSettingsService;
use Uhifadhi\Contracts\Shell\ModuleTab;
use Uhifadhi\Contracts\Shell\ModuleTabsInterface;

/**
 * THE TEAM SECTION'S TAB SET — Overview · People · Positions · Assignments ·
 * Ranks, and Permissions for a Super Admin.
 *
 * RANKS IS WITHHELD, NOT DRAWN DEAD: it exists while the organization uses
 * ranks and the viewer may read them, and is absent otherwise — a tab the
 * viewer may not have is its module's to withhold.
 *
 * A SECTION WEARS THE AREA IDIOM, and the cheapest way to mean that is to use
 * the same contract an area's modules use rather than to grow a second one.
 * The surface slug is not a module slug: nothing in the registry answers for
 * "team", and the route gate only closes a declared route that also names an
 * area, which none of these do. So the marker buys the frame — the strip, the
 * header, the one Configure action — and costs nothing else.
 *
 * CONFIGURE IS NOT IN THIS LIST. It is an action at the right-hand end of the
 * header on every one of these tabs, written by the frame; a section that put
 * it in the strip would be the only place in the product where it moved.
 *
 * A PERSON'S OWN PAGE IS NOT IN THE STRIP AND GETS NONE. It is headed by their
 * name rather than the section's, so it is inside the section rather than one
 * of its screens — and the same goes for the screen that adds somebody. The
 * sidebar's subtree is what says where you are there.
 */
final readonly class TeamSectionTabs implements ModuleTabsInterface
{
    /**
     * The surface this section is addressed by. Route defaults name it, and
     * the shell resolves the strip, the configure sections and the action from
     * it.
     */
    public const string SURFACE = 'team';

    public function __construct(
        private Door $door,
        private TeamSettingsService $settings,
    ) {
    }

    public function slug(): string
    {
        return self::SURFACE;
    }

    public function tabs(): array
    {
        $tabs = [];

        // EVERY TAB ASKS THE PAIRS ITS ROUTE ENFORCES: the directory for
        // the people and where they stand, positions for the register — and
        // both for the overview, which reads the two together.
        $reads = $this->door->opens(TeamController::READ);
        $positions = $this->door->opens(PositionController::READ);
        if ($reads && $positions) {
            $tabs[] = new ModuleTab('Overview', TeamSectionController::OVERVIEW);
        }
        if ($reads) {
            $tabs[] = new ModuleTab('People', TeamController::PEOPLE);
        }
        if ($positions) {
            $tabs[] = new ModuleTab('Positions', PositionController::REGISTER);
        }
        if ($reads) {
            $tabs[] = new ModuleTab('Assignments', TeamPostingsController::POSTINGS);
        }
        if ($this->settings->current()->usesRanks() && $this->door->opens(RankController::READ)) {
            $tabs[] = new ModuleTab('Ranks', RankController::REGISTER);
        }

        // PERMISSIONS IS A SUPER ADMIN'S, by tier, and last.
        if ($this->door->opens(PermissionsController::TIER)) {
            $tabs[] = new ModuleTab('Permissions', PermissionsController::ROUTE);
        }

        return $tabs;
    }
}
