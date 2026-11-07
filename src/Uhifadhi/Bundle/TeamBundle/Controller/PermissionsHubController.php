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

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Twig\Environment;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Bundle\TeamBundle\Enum\PermissionRunTriggerEnum;
use Uhifadhi\Bundle\TeamBundle\Permissions\PermissionFindingsReader;
use Uhifadhi\Bundle\TeamBundle\Permissions\PermissionOverviewReader;
use Uhifadhi\Bundle\TeamBundle\Permissions\PermissionRunService;
use Uhifadhi\Bundle\TeamBundle\Permissions\PermissionsOverviewCharts;
use Uhifadhi\Bundle\TeamBundle\Shell\TeamSectionTabs;

/**
 * TEAM › PERMISSIONS, THE HUB — Overview and Reports, and the two things a
 * Super Admin does from them: ask the worker for a run, and mark the drift
 * reviewed.
 *
 * A SUPER ADMIN READS IT, BY TIER AND NEVER BY A PAIR: the tier is read from
 * the account's own roles, so a Super Admin who has switched into somebody
 * else is that somebody, and is refused.
 *
 * Every figure is the latest run's, read through the readers; nothing here
 * asks a voter.
 */
final readonly class PermissionsHubController
{
    public const string OVERVIEW = 'team_permissions_overview';

    public const string REPORTS = 'team_permissions_reports';

    public const string RECOMPUTE = 'team_permissions_recompute';

    public const string REVIEWED = 'team_permissions_drift_reviewed';

    /** Who reads the pages: the tier above every rule they report on. */
    public const string TIER = 'ROLE_SUPER_ADMIN';

    public const string CSRF_ID = 'team_permissions';

    /** The pages Mark reviewed may send the reader back to. */
    private const array BACK = [self::OVERVIEW, TeamSectionTabs::ANALYSIS];

    public function __construct(
        private Environment $twig,
        private PermissionOverviewReader $overview,
        private PermissionFindingsReader $findings,
        private PermissionRunService $runs,
        private CsrfTokenManagerInterface $csrf,
        private UrlGeneratorInterface $router,
        private TokenStorageInterface $tokens,
    ) {
    }

    #[Route('/team/permissions', name: self::OVERVIEW, defaults: TeamController::SURFACE, methods: ['GET'])]
    #[IsGranted(self::TIER)]
    public function overview(): Response
    {
        $overview = $this->overview->overview();

        return new Response($this->twig->render('@Team/permissions/overview.html.twig', [
            ...$overview,
            'drift' => $this->findings->drift(),
            'concerns' => PermissionsOverviewCharts::concerns($overview['concernsByPositions']),
            'pairs' => PermissionsOverviewCharts::positions($overview['positionsByPairs']),
            'places' => PermissionsOverviewCharts::places($overview['whoCanActByPlace']),
            'months' => PermissionsOverviewCharts::months($overview['changesByMonth']),
            'shownConcerns' => min(PermissionsOverviewCharts::CONCERNS, \count($overview['concernsByPositions'])),
            'shownPositions' => min(PermissionsOverviewCharts::POSITIONS, \count($overview['positionsByPairs'])),
            'token' => $this->token(),
            'tools' => $this->tools(),
        ]));
    }

    #[Route('/team/permissions/reports', name: self::REPORTS, defaults: TeamController::SURFACE, methods: ['GET'])]
    #[IsGranted(self::TIER)]
    public function reports(): Response
    {
        return new Response($this->twig->render('@Team/permissions/reports.html.twig', [
            'freshness' => $this->findings->freshness(),
            'token' => $this->token(),
            'tools' => $this->tools(),
        ]));
    }

    /** RECOMPUTE NOW: a run requested for the worker; the request already waiting is the one. */
    #[Route('/team/permissions/recompute', name: self::RECOMPUTE, methods: ['POST'])]
    #[IsGranted(self::TIER)]
    public function recompute(Request $request): RedirectResponse
    {
        $this->guard($request);
        $this->runs->requestRun(PermissionRunTriggerEnum::Manual, $this->viewer());

        return new RedirectResponse($this->back($request, [self::OVERVIEW, self::REPORTS, TeamSectionTabs::ANALYSIS]));
    }

    /** MARK REVIEWED: the latest run's unreviewed drift, all of it or the `ids[]` sent, by the viewer. */
    #[Route('/team/permissions/drift/reviewed', name: self::REVIEWED, methods: ['POST'])]
    #[IsGranted(self::TIER)]
    public function reviewed(Request $request): RedirectResponse
    {
        $this->guard($request);
        $ids = array_values(array_map(intval(...), array_filter($request->request->all('ids'), is_numeric(...))));
        $this->findings->markReviewed($this->viewer(), [] === $ids ? null : $ids);

        return new RedirectResponse($this->back($request, self::BACK));
    }

    private function guard(Request $request): void
    {
        if (!$this->csrf->isTokenValid(new CsrfToken(self::CSRF_ID, (string) $request->request->get('_token')))) {
            throw new NotFoundHttpException('That form is stale.');
        }
    }

    private function token(): string
    {
        return $this->csrf->getToken(self::CSRF_ID)->getValue();
    }

    private function viewer(): User
    {
        $user = $this->tokens->getToken()?->getUser();
        if (!$user instanceof User) {
            throw new AccessDeniedException();
        }

        return $user;
    }

    /**
     * The page the form came from, among the ones it may name; the overview
     * otherwise.
     *
     * @param list<string> $allowed
     */
    private function back(Request $request, array $allowed): string
    {
        $back = (string) $request->request->get('back');
        $route = \in_array($back, $allowed, true) ? $back : self::OVERVIEW;

        return $this->url($route) ?? $this->router->generate(self::OVERVIEW);
    }

    /**
     * The three lookups the hub links to, each null where its route is not
     * mounted.
     *
     * @return array{positions: ?string, analysis: ?string, check: ?string}
     */
    private function tools(): array
    {
        return [
            'positions' => $this->url(TeamSectionTabs::POSITIONS),
            'analysis' => $this->url(TeamSectionTabs::ANALYSIS),
            'check' => $this->url(TeamSectionTabs::CHECK),
        ];
    }

    private function url(string $route): ?string
    {
        try {
            return $this->router->generate($route);
        } catch (RouteNotFoundException) {
            return null;
        }
    }
}
