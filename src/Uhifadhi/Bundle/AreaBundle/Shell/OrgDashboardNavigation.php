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

namespace Uhifadhi\Bundle\AreaBundle\Shell;

use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Uhifadhi\Bundle\AreaBundle\Controller\OrgDashboardController;
use Uhifadhi\Bundle\ShellBundle\Contract\NavigationSourceInterface;
use Uhifadhi\Bundle\ShellBundle\Model\NavItem;
use Uhifadhi\Bundle\ShellBundle\Model\NavSection;
use Uhifadhi\Contracts\Shell\NavGroup;

/**
 * DASHBOARD — THE FIRST ROW OF OBSERVATORY.
 *
 * `/` IS A PAGE NOW, SO IT NEEDS A DOOR (ruled 2026-09-20). Before the
 * organization dashboard existed, `/` was a welcome screen nobody navigated
 * back to and the sidebar began with Areas. It begins with Dashboard now, and
 * the brandmark points at the same address — a page you can only reach by
 * clicking a logo is a page most people never reach twice.
 *
 * FIRST, AND THE NUMBER SAYS SO. Observatory reads outward: the whole
 * organization, then its areas, then its performance, then each module's
 * org-level reading. Position 5 puts this before the areas' 10 without
 * renumbering anything that already declared one.
 *
 * EXACTLY HERE, NOT UNDER HERE. `/` is the prefix of every address in the
 * installation, so the usual "is the path inside mine" test would light this
 * row on every page in the product.
 *
 * ROUTE-TOLERANT. The address belongs to the application, which imports this
 * bundle's controllers or does not; no route, no row.
 */
final readonly class OrgDashboardNavigation implements NavigationSourceInterface
{
    /** What the organization watches — and this is the whole of it. */
    public const string SECTION = NavGroup::OBSERVATORY;

    /** Before the areas (10) and everything after them. */
    public const int POSITION = 5;

    public function __construct(
        private UrlGeneratorInterface $urls,
        private RequestStack $requests,
        private AuthorizationCheckerInterface $authorization,
    ) {
    }

    public function sections(): iterable
    {
        // THE ROW ASKS WHAT THE PAGE BEHIND IT ENFORCES, so nobody is offered
        // a dashboard that refuses them.
        if (!$this->authorization->isGranted(OrgDashboardController::READ)) {
            return;
        }

        try {
            $url = $this->urls->generate(OrgDashboardController::ROUTE);
        } catch (RouteNotFoundException) {
            return;
        }

        yield new NavSection(self::SECTION, [new NavItem(
            label: 'Dashboard',
            url: $url,
            icon: 'shell:layout-template',
            current: $this->viewerIsAt($url),
        )], position: self::POSITION);
    }

    /**
     * EXACTLY HERE. `/` prefixes every address there is, so a prefix match
     * would mark the dashboard current on every page in the installation and
     * the sidebar would answer "where am I" with "everywhere".
     */
    private function viewerIsAt(string $url): bool
    {
        $request = $this->requests->getCurrentRequest();
        if (null === $request) {
            return false;
        }

        return $request->getBaseUrl().$request->getPathInfo() === $url;
    }
}
