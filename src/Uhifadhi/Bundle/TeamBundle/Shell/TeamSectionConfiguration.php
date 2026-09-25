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
use Uhifadhi\Bundle\TeamBundle\Controller\PositionController;
use Uhifadhi\Bundle\TeamBundle\Controller\RankConfigureController;
use Uhifadhi\Bundle\TeamBundle\Controller\TeamConfigureController;
use Uhifadhi\Contracts\Shell\ConfigurationSection;
use Uhifadhi\Contracts\Shell\ConfigurationSectionsInterface;

/**
 * WHAT `Configure` OPENS IN THE TEAM SECTION — People, Positions, Assignments.
 *
 * THREE SCREENS, NOT THREE TEMPLATES. An area's configure sections are rendered
 * into the shell's own area-shaped page; an org-level section has no area in
 * its address, so its sections are screens of their own and the strip is built
 * from their routes. That is a difference of address, not of idiom.
 *
 * ONE SECTION PER SIDEBAR SUB-PAGE THAT HAS A RULE TO SET (ruled 2026-09-24).
 * The Overview sets nothing; Roles sets nothing for the whole team — the tiers
 * are the model's and the matrix is edited per position — so neither has an
 * entry. The three that remain are the register pages whose rules are the
 * whole team's, and they answer at the same address shape an area's sections
 * take: /team/configure/<section>.
 *
 * THE ORDER IS THE HOUSE'S. The shell ranks Widget library first and Settings
 * last; none of these is either, so they stand in the order declared, which
 * is the sidebar's, and the first is what the one `Configure` action opens.
 *
 * A SECTION THE VIEWER MAY NOT OPEN IS WITHHELD, for the reason a tab is: a
 * strip entry they cannot walk through is a statement about them, not about
 * the product. A module may add a Team section later through this same
 * contract; the strip is the shell's, not this class's.
 */
final readonly class TeamSectionConfiguration implements ConfigurationSectionsInterface
{
    public function __construct(private Door $door)
    {
    }

    public function slug(): string
    {
        return TeamSectionTabs::SURFACE;
    }

    public function heading(): string
    {
        return 'Team';
    }

    public function summary(): string
    {
        return 'The rules every invitation, every position and every posting is written under.';
    }

    public function sections(): array
    {
        $sections = [];

        if ($this->door->opens(TeamConfigureController::DIRECTORY)) {
            $sections[] = ConfigurationSection::screen('people', 'People', TeamConfigureController::PEOPLE);
        }
        if ($this->door->opens(PositionController::CONFIGURE)) {
            $sections[] = ConfigurationSection::screen('positions', 'Positions', TeamConfigureController::POSITIONS);
        }
        if ($this->door->opens(TeamConfigureController::DIRECTORY)) {
            $sections[] = ConfigurationSection::screen('assignments', 'Assignments', TeamConfigureController::ASSIGNMENTS);
        }
        // RANKS STAYS WHEN RANKS ARE OFF: the switch that turns them back on
        // is in it.
        if ($this->door->opens(RankConfigureController::CONFIGURE)) {
            $sections[] = ConfigurationSection::screen('ranks', 'Ranks', RankConfigureController::SECTION);
        }

        return $sections;
    }
}
