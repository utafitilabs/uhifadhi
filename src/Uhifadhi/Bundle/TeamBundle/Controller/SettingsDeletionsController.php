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
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Twig\Environment;
use Uhifadhi\Bundle\ShellBundle\Service\SettingsSection;
use Uhifadhi\Bundle\TeamBundle\Deletion\DeletionService;
use Uhifadhi\Bundle\TeamBundle\Entity\DeletionRecord;
use Uhifadhi\Bundle\TeamBundle\Repository\DeletionRecordRepository;
use Uhifadhi\Contracts\Settings\SettingsTab;

/**
 * SETTINGS › DELETIONS (ruled 28 Sep, #48; drawn as a timeline by day, ruled
 * 30 Sep): every delete a Super Admin made, under the day it happened. A
 * Settings screen served here because the deletes are the Team's; the Shell
 * generates the same address and draws the strip around it.
 */
final readonly class SettingsDeletionsController
{
    /** The newest lines drawn: a delete is rare, and the design draws no pager. */
    public const int LINES = 500;

    public function __construct(
        private Environment $twig,
        private SettingsSection $section,
        private DeletionService $deletions,
        private DeletionRecordRepository $records,
    ) {
    }

    #[Route('/settings/deletions', name: 'team_settings_deletions', methods: ['GET'], priority: 10)]
    public function __invoke(): Response
    {
        if (!$this->deletions->mayDelete()) {
            throw new AccessDeniedException('Only a Super Admin reads the deletions.');
        }

        $days = [];
        foreach ($this->records->findPage(0, self::LINES) as $line) {
            $days[$line->getDeletedAt()->format('Y-m-d')][] = $line;
        }

        return new Response($this->twig->render('@Team/settings/deletions.html.twig', [
            'title' => SettingsSection::TITLE,
            'subtitle' => SettingsTab::Deletions->subtitle(),
            'scope' => SettingsSection::SCOPE,
            'tabs' => $this->section->tabs(SettingsTab::Deletions),
            /** @var array<string, list<DeletionRecord>> */
            'days' => $days,
        ]));
    }
}
