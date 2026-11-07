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
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Twig\Environment;
use Uhifadhi\Bundle\ShellBundle\Service\SettingsSection;
use Uhifadhi\Bundle\ShellBundle\Service\TablePageService;
use Uhifadhi\Bundle\TeamBundle\Deletion\DeletionService;
use Uhifadhi\Bundle\TeamBundle\Repository\DeletionRecordRepository;
use Uhifadhi\Contracts\Settings\SettingsTab;

/**
 * SETTINGS › DELETIONS (ruled 28 Sep, #48): every delete a Super Admin made,
 * newest first, as a register — the day a cell of each row, the house bar
 * narrowing by kind (`?kind`), by who deleted (`?by`) and by the words of
 * the title, the reference or what went (`?q`), the register foot paging it. A Settings screen served
 * here because the deletes are the Team's; the Shell generates the same
 * address and draws the strip around it.
 */
final readonly class SettingsDeletionsController
{
    public function __construct(
        private Environment $twig,
        private SettingsSection $section,
        private DeletionService $deletions,
        private DeletionRecordRepository $records,
        private TablePageService $tablePages,
    ) {
    }

    #[Route('/settings/deletions', name: 'team_settings_deletions', methods: ['GET'], priority: 10)]
    public function __invoke(Request $request): Response
    {
        if (!$this->deletions->mayDelete()) {
            throw new AccessDeniedException('Only a Super Admin reads the deletions.');
        }

        $kind = self::chosen($request, 'kind');
        $by = self::chosen($request, 'by');
        $search = self::chosen($request, 'q');
        $table = $this->tablePages->read($request, $this->records->countMatching($kind, $by, $search));
        $kinds = $this->records->countBy('kind');
        $people = $this->records->countBy('byName');

        return new Response($this->twig->render('@Team/settings/deletions.html.twig', [
            'title' => SettingsSection::TITLE,
            'subtitle' => SettingsTab::Deletions->subtitle(),
            'scope' => SettingsSection::SCOPE,
            'tabs' => $this->section->tabs(SettingsTab::Deletions),
            'lines' => $this->records->findMatching($kind, $by, $search, $table->offset, $table->perPage),
            'table' => $table,
            'links' => $this->tablePages->links($request, $table),
            'all' => array_sum($kinds),
            'kind' => $kind,
            'by' => $by,
            'search' => $search ?? '',
            'kindOptions' => self::options($kinds),
            'byOptions' => self::options($people),
        ]));
    }

    private static function chosen(Request $request, string $key): ?string
    {
        $value = $request->query->all()[$key] ?? null;

        return \is_string($value) && '' !== trim($value) ? trim($value) : null;
    }

    /**
     * @param array<string, int> $counts
     *
     * @return list<array{value: string, label: string, count: int}>
     */
    private static function options(array $counts): array
    {
        return array_map(static fn (string $value, int $n): array => ['value' => $value, 'label' => $value, 'count' => $n], array_map('strval', array_keys($counts)), array_values($counts));
    }
}
