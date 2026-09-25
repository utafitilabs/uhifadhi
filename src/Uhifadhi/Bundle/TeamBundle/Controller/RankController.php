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
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Twig\Environment;
use Uhifadhi\Bundle\TeamBundle\Access\TeamConcerns;
use Uhifadhi\Bundle\TeamBundle\Model\RankQuery;
use Uhifadhi\Bundle\TeamBundle\Service\CsvExportService;
use Uhifadhi\Bundle\TeamBundle\Service\RankBoard;
use Uhifadhi\Bundle\TeamBundle\Service\TeamSettingsService;
use Uhifadhi\Contracts\Access\Verb;

/**
 * TEAM › RANKS — the organization's ranks in seniority order, and who holds
 * each. A register: one table, a search, a chip per scale once there are
 * several, the count line and the export door. Adding and ordering ranks is
 * configuration and lives on Team configure › Ranks.
 *
 * AN ORGANIZATION THAT DOES NOT USE RANKS HAS NO RANKS PAGE: the address
 * answers 404 rather than an empty table, as the tab does not exist.
 *
 * A PLAIN CLASS, extending nothing, with its collaborators handed to it — the
 * reusable-bundle rule (see config/services.php).
 */
final readonly class RankController
{
    public const string REGISTER = 'team_ranks';
    public const string EXPORT = 'team_ranks_export';

    public const string READ = TeamConcerns::RANKS.'.'.Verb::Read->value;
    public const string EXPORT_PAIR = TeamConcerns::RANKS.'.'.Verb::Export->value;

    public function __construct(
        private Environment $twig,
        private RankBoard $board,
        private TeamSettingsService $settings,
        private CsvExportService $csv,
    ) {
    }

    #[Route('/team/ranks', name: self::REGISTER, defaults: TeamController::SURFACE, methods: ['GET'])]
    #[IsGranted(self::READ)]
    public function index(Request $request): Response
    {
        $this->assertUsesRanks();
        $query = RankQuery::fromRequest($request);
        $bands = $this->board->bands($query);
        $counts = $this->board->countByScale();

        return new Response($this->twig->render('@Team/ranks/index.html.twig', [
            'query' => $query,
            'bands' => $bands,
            'scales' => $this->board->scales(),
            'scaleCounts' => $counts,
            'total' => array_sum($counts),
            'shown' => array_sum(array_map(static fn ($band): int => $band->ranks, $bands)),
        ]));
    }

    /** THE ROWS THE REGISTER SHOWS, AS CSV — the same query, every row, no pager. */
    #[Route('/team/ranks.csv', name: self::EXPORT, methods: ['GET'])]
    #[IsGranted(self::EXPORT_PAIR)]
    public function export(Request $request): Response
    {
        $this->assertUsesRanks();

        $rows = [];
        foreach ($this->board->bands(RankQuery::fromRequest($request)) as $band) {
            foreach ($band->rows as $row) {
                $rows[] = [
                    $row->order,
                    $row->rank->getName(),
                    $row->rank->getShortCode(),
                    $band->scale->getName(),
                    $row->holders,
                    $row->rank->getCreatedAt()?->format('Y-m-d'),
                ];
            }
        }

        return $this->csv->response('ranks.csv', ['Order', 'Rank', 'Short code', 'Scale', 'Holders', 'Since'], $rows);
    }

    private function assertUsesRanks(): void
    {
        if (!$this->settings->current()->usesRanks()) {
            throw new NotFoundHttpException('This organization does not use ranks.');
        }
    }
}
