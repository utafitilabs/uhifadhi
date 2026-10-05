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
use Uhifadhi\Bundle\TeamBundle\Permissions\LedgerQuery;
use Uhifadhi\Bundle\TeamBundle\Permissions\PermissionsPage;

/**
 * TEAM › PERMISSIONS — what every kind of person may do to every other,
 * asked of the rules the app enforces, for real accounts, changing nothing.
 *
 * A SUPER ADMIN READS IT, BY TIER AND NEVER BY A PAIR: the tier is read from
 * the account's own roles, so a Super Admin who has switched into somebody
 * else is that somebody, and is refused.
 */
final readonly class PermissionsController
{
    public const string ROUTE = 'team_permissions';

    /** Who reads the page: the tier above every rule it reports on. */
    public const string TIER = 'ROLE_SUPER_ADMIN';

    public function __construct(
        private Environment $twig,
        private PermissionsPage $page,
    ) {
    }

    #[Route('/team/permissions', name: self::ROUTE, defaults: TeamController::SURFACE, methods: ['GET'])]
    #[IsGranted(self::TIER)]
    public function index(Request $request): Response
    {
        return new Response($this->twig->render('@Team/team/permissions.html.twig', $this->page->build(LedgerQuery::from($request))));
    }
}
