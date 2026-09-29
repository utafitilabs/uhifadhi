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

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Twig\Environment;
use Uhifadhi\Bundle\TeamBundle\Service\RolesBoard;

/**
 * THE ROLES TAB — what authority exists on this installation, and who holds
 * it.
 *
 * THERE IS NO ROLE ENTITY AND THE TAB ASSUMES NONE. Two things decide
 * authority here: the TIER, which grants nothing except for the two cases that
 * grant everything, and the PERMISSION a position carries. The tab reads both
 * and invents no third.
 *
 * IT WRITES NOTHING. The matrix is edited on Positions.
 *
 * DEPRECATED, AND KEPT FOR ONE RELEASE. What authority exists is the
 * positions register's question now, and it answers it from the declared
 * concerns rather than from the fixed seven. The route stays mounted for a
 * release because an installation's own links and bookmarks point at it.
 *
 * @deprecated since 1.0, use the positions register
 */
final readonly class TeamRolesController
{
    /** The section's fifth tab. */
    public const string ROLES = 'team_roles';

    public function __construct(
        private Environment $twig,
        private RolesBoard $board,
    ) {
    }

    #[Route('/team/roles', name: self::ROLES, defaults: TeamController::SURFACE, methods: ['GET'])]
    #[IsGranted(TeamController::READ)]
    // THE MATRIX IS WHAT EACH POSITION GRANTS, which is the positions
    // register's own reading, so it asks for the register's pair.
    #[IsGranted(PositionController::READ)]
    public function index(): Response
    {
        return new Response($this->twig->render('@Team/team/roles.html.twig', $this->board->read()));
    }
}
