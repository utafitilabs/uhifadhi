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

namespace Uhifadhi\Bundle\AreaBundle\Widget;

use Uhifadhi\Bundle\AreaBundle\Overview\NowTile;
use Uhifadhi\Bundle\AreaBundle\Overview\OrgOverviewContributorInterface;
use Uhifadhi\Bundle\AreaBundle\Service\AreaRegister;
use Uhifadhi\Bundle\ShellBundle\Widget\Model\Widget;
use Uhifadhi\Bundle\ShellBundle\Widget\Model\WidgetGroup;
use Uhifadhi\Contracts\Shell\Scope;

/**
 * THE ORGANIZATION'S OWN CELLS ON ITS DASHBOARD — and it contributes them
 * the same way a module does.
 *
 * THE PAGE OWNS THE SURFACE AND WRITES NO WIDGET MARKUP, exactly as the area
 * overview does: if the host rendered its own cards directly and modules went
 * through a contract, the page would have two ways of putting a card on one
 * grid and only one of them would be open to extension.
 *
 * FIVE CELLS ARE THE ORGANIZATION'S BY RIGHT. The figures strip (which other
 * contributors fill), what needs a decision anywhere, the ground with
 * everybody on it, the areas themselves, and what runs where.
 *
 * WHY THE PLATE IS HERE AND NOT THE ROSTER'S. The design groups it under the
 * roster, because in the drawing the roster is what knows where people are.
 * In this platform WHERE SOMEBODY IS arrives through a core seam —
 * `Contracts\Area\LivePositionsInterface`, which the area bundle implements
 * and a module answers into — so the ground and the markers on it are the
 * host's to draw and the DATA is contributed. A roster that owned the cell
 * would mean an installation without one had no map of its own areas.
 */
final readonly class OrgOverviewWidgets implements OrgOverviewContributorInterface
{
    /** The organization's own "module" slug — it is always asked. */
    public const string SLUG = 'organization';

    public const string KPIS = 'kpis';
    public const string ATTENTION = 'attention';
    public const string PLATE = 'plate';
    public const string AREAS = 'areas';
    public const string MODULES = 'modules';

    public function __construct(private AreaRegister $register)
    {
    }

    public function moduleSlug(): string
    {
        return self::SLUG;
    }

    public function group(): WidgetGroup
    {
        return new WidgetGroup(
            'host',
            'The organization itself',
            'The figures strip, what needs a decision anywhere, the ground with everybody on it, the areas and what runs in them. Two of these render parts CONTRIBUTED by the modules rather than a list the host wrote, which is why the strip has a duty figure on it and the host knows nothing about rosters.',
        );
    }

    public function widgets(): array
    {
        return [
            new Widget(self::KPIS, 'The organization right now', 'host', 12, [12], true,
                'Four figures, one per contributor. A module that contributes no figure adds no tile, and the strip is still four wide.'),
            new Widget(self::ATTENTION, 'Needs a decision', 'host', 12, [12], true,
                'Every ranger, patrol, incident and area that needs somebody to act, anywhere in the organization, sorted by urgency rather than by module.'),
            // TWELVE OR SIX: the areas wall puts the ground beside the
            // watches, which is the only composition that asks a plate to
            // read at half width.
            new Widget(self::PLATE, 'Where everybody is', 'host', 12, [12, 6], true,
                'The organization’s ground with a marker per live position. Only areas that report draw anything, and the caption says how many of them there are.'),
            new Widget(self::AREAS, 'The areas', 'host', 6, [12, 6], true,
                'One row per area: what it runs, how much ground it holds, and whether it has been set up at all.'),
            new Widget(self::MODULES, 'Modules across the organization', 'host', 6, [12, 6], false,
                'Which modules are installed and which areas run them. The register lives in Settings; this is the reading of it.'),
        ];
    }

    public function partialPattern(): string
    {
        return '@Area/org/_w_%s.html.twig';
    }

    /**
     * THE ONE FIGURE THE PAGE MAY STATE ITSELF — how many areas there are,
     * and how many of them report at all.
     *
     * It is the organization's own because it is the length of a list this
     * page already draws, which is the same argument that puts the attention
     * count on the area's strip. Everything else on the row is a module's.
     */
    public function figures(Scope $scope, \DateTimeImmutable $now): array
    {
        // THE REGISTER'S OWN READING, counted by the register's own counter:
        // "how many areas, and how many of them report" is the areas page's
        // question and this tile is the same answer, not a second one.
        $counts = $this->register->counts($this->register->rows($now));
        $areas = $counts['all'];
        $live = $counts['live'];

        return [new NowTile(
            // THE DESIGN'S OWN REFERENCE FOR THIS FIGURE. `NowTile` insists on
            // one because a module's tiles are discussed by it; it is never
            // rendered — a workshop index in shipped markup is refused by
            // tests/Unit/Template/NoWorkshopLabelsTest.
            index: 'OR·G1',
            moduleSlug: self::SLUG,
            label: 'Areas',
            // NOTHING MEASURED IS NOT NOUGHT. An installation with no areas
            // has not measured nought areas — there is nothing yet to
            // measure — and the empty dashboard says so rather than
            // reporting a figure nobody took.
            value: 0 === $areas ? '—' : (string) $areas,
            subline: 0 === $areas
                ? 'nothing measured · no area registered'
                : \sprintf('%d running', $live),
            alarm: 0 !== $areas && $live < $areas ? \sprintf('%d awaiting setup', $areas - $live) : null,
            priority: 10,
        )];
    }

    /**
     * NOTHING, AND THAT IS DELIBERATE. Every fact these cells draw is already
     * resolved by the controller for the page as a whole — the plate, the
     * queue, the areas table — and computing them again here would be the
     * same queries twice and two answers that could disagree. A module's
     * contributor has no such caller, which is why the contract offers this.
     */
    public function context(Scope $scope, \DateTimeImmutable $now): array
    {
        return [];
    }
}
