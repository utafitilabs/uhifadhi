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
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Twig\Environment;
use Uhifadhi\Bundle\TeamBundle\Access\ConcernCatalogue;
use Uhifadhi\Bundle\TeamBundle\Permissions\PositionsListing;
use Uhifadhi\Bundle\TeamBundle\Permissions\PositionsReportReader;
use Uhifadhi\Bundle\TeamBundle\Shell\TeamSectionTabs;
use Uhifadhi\Contracts\Access\ConcernInterface;

/**
 * TEAM › PERMISSIONS › POSITIONS — what holding each position grants, read as
 * written. Nothing here asks a voter or reads the run.
 *
 * THE FILTERS ARE THE BROWSER'S. Every position is one row on the page, and
 * the search, the concern, the sort, the pills and the folds work over those
 * rows in the page's controller, as the design's script does: an
 * installation has tens of positions, never thousands, and each filter is
 * answered at once with no round trip. The counts the server writes are the
 * same predicates over the same rows ({@see PositionsListing}).
 */
final readonly class PermissionsPositionsController
{
    public function __construct(
        private Environment $twig,
        private PositionsReportReader $report,
        private ConcernCatalogue $catalogue,
        private UrlGeneratorInterface $router,
    ) {
    }

    #[Route('/team/permissions/positions', name: TeamSectionTabs::POSITIONS, defaults: TeamController::SURFACE, methods: ['GET'])]
    #[IsGranted(PermissionsHubController::TIER)]
    public function positions(): Response
    {
        $catalogue = [];
        foreach ($this->catalogue->grouped() as $group => $concerns) {
            $catalogue[$group] = array_map(static fn (ConcernInterface $c): array => ['key' => $c->key(), 'label' => $c->label()], $concerns);
        }

        return new Response($this->twig->render('@Team/permissions/positions.html.twig', [
            ...PositionsListing::build($this->report->report(), $catalogue),
            'analysis' => $this->url(TeamSectionTabs::ANALYSIS),
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
