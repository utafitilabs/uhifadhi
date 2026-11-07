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
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Twig\Environment;
use Uhifadhi\Bundle\TeamBundle\Permissions\AnalysisListing;
use Uhifadhi\Bundle\TeamBundle\Permissions\PermissionAnalysisReader;
use Uhifadhi\Bundle\TeamBundle\Permissions\PermissionFindingsReader;
use Uhifadhi\Bundle\TeamBundle\Permissions\PlaceKey;
use Uhifadhi\Bundle\TeamBundle\Repository\PermissionRunRepository;
use Uhifadhi\Bundle\TeamBundle\Shell\TeamSectionTabs;

/**
 * TEAM › PERMISSIONS › ANALYSIS — one question, `?q=` a pair or a power,
 * answered from the worker's latest run, then the lists that run filed and
 * its drift. Nothing here asks a voter.
 *
 * THE QUESTION IS THE ADDRESS. Choosing another in the dropdown loads the
 * page for it, so an answer can be linked to; the people behind every cell
 * of the answer are written with it, and a cell opens them in the page.
 */
final readonly class PermissionsAnalysisController
{
    public function __construct(
        private Environment $twig,
        private PermissionAnalysisReader $analysis,
        private PermissionFindingsReader $findings,
        private PermissionRunRepository $runs,
        private CsrfTokenManagerInterface $csrf,
        private UrlGeneratorInterface $router,
    ) {
    }

    #[Route('/team/permissions/analysis', name: TeamSectionTabs::ANALYSIS, defaults: TeamController::SURFACE, methods: ['GET'])]
    #[IsGranted(PermissionsHubController::TIER)]
    public function analysis(Request $request): Response
    {
        $questions = $this->analysis->questions();
        $asked = $request->query->get('q');
        $key = AnalysisListing::resolve($questions, \is_string($asked) ? $asked : null);
        $isPower = \in_array($key, array_column($questions['powers'], 'key'), true);

        $run = $this->runs->findOneLatestFinished();
        $drift = $this->findings->drift();
        $records = $this->analysis->records();
        $doors = [
            'positions' => array_map(fn (string $uuid): string => $this->router->generate('team_position_show', ['uuid' => $uuid]), $records['positions']),
            'people' => array_map(fn (string $uuid): string => $this->router->generate('team_member', ['uuid' => $uuid]), $records['people']),
            'tiers' => $records['tiers'],
        ];

        return new Response($this->twig->render('@Team/permissions/analysis.html.twig', [
            ...AnalysisListing::build(
                $questions,
                $key,
                $isPower ? null : $this->analysis->forPair($key),
                $isPower ? [] : $this->analysis->peopleByCell($key),
                $isPower ? $this->analysis->forPower($key) : null,
                null === $run ? [] : PlaceKey::places($run->getPlaces()),
                $this->findings->standing(),
                $drift,
                $this->url(TeamSectionTabs::CHECK),
                $this->analysis->firstHolders(),
                $doors,
            ),
            'driftLines' => $drift['lines'],
            'freshness' => $this->findings->freshness(),
            'token' => $this->csrf->getToken(PermissionsHubController::CSRF_ID)->getValue(),
        ]));
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
