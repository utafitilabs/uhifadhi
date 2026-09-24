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

namespace Uhifadhi\Bundle\TeamBundle\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Twig\Environment;
use Uhifadhi\Bundle\RegistryBundle\RegistryBundle;
use Uhifadhi\Bundle\TeamBundle\Access\TeamConcerns;
use Uhifadhi\Bundle\TeamBundle\Enum\RosterStateEnum;
use Uhifadhi\Bundle\TeamBundle\Enum\TeamRoleEnum;
use Uhifadhi\Bundle\TeamBundle\Model\RosterQuery;
use Uhifadhi\Bundle\TeamBundle\Repository\DepartmentRepository;
use Uhifadhi\Bundle\TeamBundle\Repository\PositionRepository;
use Uhifadhi\Bundle\TeamBundle\Repository\UserRepository;
use Uhifadhi\Bundle\TeamBundle\Service\TeamOverview;
use Uhifadhi\Contracts\Access\Grant;
use Uhifadhi\Contracts\Access\Verb;

/**
 * THE TEAM PAGE — everybody who can sign in to this installation.
 *
 * GATED ON `directory.read`, not on a tier. Reading who is on the team is what
 * a colleague needs, and it is asked of the concern catalogue rather than of a
 * rank: a Staff member whose position carries the cell gets in and a tier
 * nobody granted does not decide it. Super Admin and Admin pass the same check,
 * because the voter grants them everything by tier.
 *
 * IT IS A WIDGET SURFACE. The body is the person's own resolved layout, so this
 * controller's job is to gather every fact ANY of the nine widgets might want
 * and hand them over — the widget framework decides which are drawn. That is
 * why the roster is fetched twice in different shapes: the table direction is
 * PAGED and the five banded ones are not, because a band cut in half by a pager
 * is a band that lies about its own count.
 *
 * A PLAIN CLASS, extending nothing, with its collaborators handed to it — the
 * reusable-bundle rule (see config/services.php).
 */
final readonly class TeamController
{
    /**
     * THE SURFACE MARKER — the route default that tells the shell this page is
     * inside the Team section, so the frame draws the section's strip, its
     * header and its one Configure action. It is a route DEFAULT and not a
     * path segment, so no address changes to gain a frame.
     *
     * It names no module: nothing in the registry answers for "team", and the
     * registry's gate only closes a declared route that ALSO names an area —
     * which none of this section's do.
     */
    public const array SURFACE = ['_uhifadhi_module' => 'team'];

    /**
     * THE SAME MARKER FOR A RECORD UNDER THE SECTION, whose `{uuid}` names a
     * person or a position and never an area: the registry's route gate reads
     * the area from `uuid` by default and would refuse the page as "an area
     * not running the team module" — so the record names an area parameter
     * the route does not have, and the gate finds no area to ask about.
     */
    public const array SURFACE_RECORD = ['_uhifadhi_module' => 'team', RegistryBundle::MODULE_ROUTE_AREA_DEFAULT => 'area'];

    /** The section's second tab: everybody who can sign in here. */
    public const string PEOPLE = 'team_index';

    public function __construct(
        private Environment $twig,
        private UserRepository $users,
        private PositionRepository $positions,
        private DepartmentRepository $departments,
        private TeamOverview $overview,
    ) {
    }

    #[Route('/team', name: self::PEOPLE, defaults: self::SURFACE, methods: ['GET'])]
    #[IsGranted('directory.read')]
    public function index(Request $request): Response
    {
        // ONE TABLE (owner 2026-09-22): a register is app mechanics, so it is
        // one shape with no library and no presets — the People table, its
        // filters and its pager. Widgets stay on the data surfaces.
        return new Response($this->twig->render('@Team/team/index.html.twig', $this->context($request)));
    }

    /**
     * EVERY FACT ANY OF THE NINE WIDGETS MIGHT WANT, gathered once.
     *
     * PUBLIC, because the widget library renders the same nine partials on the
     * same real data — the picture of a widget IS the widget, so what somebody
     * arranges there is exactly what they get here. A second, thinner context
     * for the preview would be the one place the two screens could disagree.
     *
     * A widget cannot know which of its siblings are on, so it cannot fetch for
     * itself without the page issuing the same query five times — and five
     * independently-fetched copies of one roster can disagree, which on a page
     * about who may do what is the one failure worth spending a join to avoid.
     *
     * @return array<string, mixed>
     */
    /**
     * WHAT THE PEOPLE TABLE READS: the paged roster, the counts, and the
     * vocabulary its filters offer.
     *
     * @return array<string, mixed>
     */
    public function context(Request $request): array
    {
        $query = RosterQuery::fromRequest($request);

        return [
            'query' => $query,
            // The paged shape, for the table direction: its whole state is the URL.
            'page' => $this->users->findRoster($query),
            // The unpaged shape, for the five banded directions. A band cut in
            // half by a pager is a band that lies about its own count.
            'everybody' => $this->users->findAllByName(),
            'overview' => $this->overview->build(),
            'tierCounts' => $this->users->countByTier(),
            'tiers' => TeamRoleEnum::cases(),
            'states' => RosterStateEnum::cases(),
            'departments' => $this->departments->findAllOrdered(),
            'positions' => $this->positions->findAllOrdered(),
            'teamManage' => (string) Grant::of(TeamConcerns::DIRECTORY, Verb::Manage),
        ];
    }

    /*
     * The signed-in person as the CONTRACT sees them, which is what the widget
     * framework stores a layout against — it never type-hints this bundle's
     * User, and this call site is not where that would start.
     *
     * Null is a real answer rather than a guard: the framework hands an
     * anonymous request the catalogue's defaults, which is exactly right for a
     * page nobody is signed in to. That this route is gated makes it unreachable
     * in practice, and relying on a gate to make a null impossible is how a
     * later change to the gate becomes a 500 here.
     */
}
