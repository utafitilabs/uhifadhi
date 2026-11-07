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

use Symfony\Component\HttpFoundation\RequestStack;
use Uhifadhi\Bundle\TeamBundle\Access\Door;
use Uhifadhi\Bundle\TeamBundle\Controller\PermissionsHubController;
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
 * PERMISSIONS HAS STRIPS OF ITS OWN. The hub (Overview · Reports) and the
 * three lookups (Positions · Analysis · Check) are Team pages, but each set
 * shows its own strip and never the section's: the frame asks one declaration
 * per surface, so this one answers by the route the request matched.
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

    /** The three lookups' routes. A tab whose route is not mounted is not drawn. */
    public const string POSITIONS = 'team_permissions_positions';

    public const string ANALYSIS = 'team_permissions_analysis';

    public const string CHECK = 'team_permissions_check';

    /** The hub's two pages. */
    public const array HUB = [PermissionsHubController::OVERVIEW, PermissionsHubController::REPORTS];

    /** The lookups' pages. */
    public const array LOOKUPS = [self::POSITIONS, self::ANALYSIS, self::CHECK];

    public function __construct(
        private Door $door,
        private TeamSettingsService $settings,
        private RequestStack $requests,
    ) {
    }

    public function slug(): string
    {
        return self::SURFACE;
    }

    public function tabs(): array
    {
        $route = $this->requests->getCurrentRequest()?->attributes->get('_route');
        if (\in_array($route, [...self::HUB, ...self::LOOKUPS], true)) {
            return $this->permissionsTabs(\in_array($route, self::HUB, true));
        }

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
        if ($this->door->opens(PermissionsHubController::TIER)) {
            $tabs[] = new ModuleTab('Permissions', PermissionsHubController::OVERVIEW);
        }

        return $tabs;
    }

    /**
     * The hub's strip, or the lookups', for a Super Admin.
     *
     * @return list<ModuleTab>
     */
    private function permissionsTabs(bool $hub): array
    {
        if (!$this->door->opens(PermissionsHubController::TIER)) {
            return [];
        }

        return $hub
            ? [new ModuleTab('Overview', PermissionsHubController::OVERVIEW), new ModuleTab('Reports', PermissionsHubController::REPORTS)]
            : [new ModuleTab('Positions', self::POSITIONS), new ModuleTab('Analysis', self::ANALYSIS), new ModuleTab('Check', self::CHECK)];
    }
}
