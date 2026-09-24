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

namespace Uhifadhi\Bundle\AreaBundle\Tests\Integration\Web;

use Uhifadhi\Bundle\AreaBundle\Overview\ContributesStylesheetInterface;
use Uhifadhi\Bundle\AreaBundle\Overview\NowTile;
use Uhifadhi\Bundle\AreaBundle\Overview\OrgOverviewContributorInterface;
use Uhifadhi\Bundle\ShellBundle\Widget\Model\Widget;
use Uhifadhi\Bundle\ShellBundle\Widget\Model\WidgetGroup;
use Uhifadhi\Contracts\Shell\Scope;

/**
 * A MODULE PUTTING A CELL ON THE ORGANIZATION DASHBOARD, playing by the
 * published contract and by nothing else.
 *
 * WITHOUT ONE, THE SEAM IS UNTESTED WHERE IT MATTERS. A dashboard rendered
 * with no contributor but the host proves the host's own cells work and
 * nothing at all about the thing the page exists for — which is exactly how
 * the area overview shipped a host that merged figures flat and fataled on
 * every module's cell. So the suite renders an installation that runs a
 * module.
 *
 * IT READS `by.<slug>`, exactly as the contract documents: rendered with
 * `with_context: false`, everything in ONE map, its own figures under its own
 * slug.
 *
 * AND IT PUBLISHES A FIGURE, so the four-to-a-row strip is exercised as an
 * assembled row rather than as the host's one tile and three absences.
 */
final readonly class FakeOrgWidgets implements OrgOverviewContributorInterface, ContributesStylesheetInterface
{
    /**
     * @param int $figures how many headline figures this stand-in publishes —
     *                     one by default, and four where a suite needs to
     *                     prove that a full row of module figures leaves no
     *                     slot for the organization's own filler
     */
    public function __construct(private string $slug, private int $figures = 1)
    {
    }

    public function moduleSlug(): string
    {
        return $this->slug;
    }

    public function group(): WidgetGroup
    {
        return new WidgetGroup($this->slug, ucfirst($this->slug), 'What the stand-in module puts on the dashboard.');
    }

    /** One cell, named after the module, so two stand-ins never collide. */
    public function widgets(): array
    {
        return [new Widget($this->slug.'_org', 'Out across the organization', $this->slug, 6, [12, 6], true,
            'The stand-in module’s own organization-level cell.')];
    }

    public function partialPattern(): string
    {
        return '@fixtures/org/_w_%s.html.twig';
    }

    public function stylesheet(): string
    {
        return 'bundles/'.$this->slug.'/'.$this->slug.'.css';
    }

    public function figures(Scope $scope, \DateTimeImmutable $now): array
    {
        $tiles = [];
        for ($n = 1; $n <= $this->figures; ++$n) {
            $tiles[] = new NowTile(
                index: 'ST·G'.$n,
                moduleSlug: $this->slug,
                label: 1 === $n ? ucfirst($this->slug).' out' : ucfirst($this->slug).' figure '.$n,
                value: '3',
                subline: 'across every area',
                priority: 50 + $n,
            );
        }

        return $tiles;
    }

    public function context(Scope $scope, \DateTimeImmutable $now): array
    {
        return ['out' => 3, 'walked' => 96, 'organization' => $scope->isOrganization()];
    }
}
