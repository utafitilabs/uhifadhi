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

namespace Uhifadhi\Bundle\ShellBundle\Service;

use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\AuthenticatedVoter;
use Uhifadhi\Bundle\ShellBundle\Contract\NavigationSourceInterface;
use Uhifadhi\Bundle\ShellBundle\Controller\ManualController;
use Uhifadhi\Bundle\ShellBundle\Model\NavItem;
use Uhifadhi\Bundle\ShellBundle\Model\NavSection;
use Uhifadhi\Contracts\Shell\NavGroup;

/**
 * THE HELP GROUP: ONE ROW, THE INSTALLATION'S MANUAL, AT THE FOOT (#24).
 *
 * ONLY WHERE THERE IS A MANUAL TO OPEN — a folder named under `shell.manual`
 * with its page built — and only for somebody signed in, which is all the
 * manual's own addresses ask. No token, no question: outside a firewall the
 * checker throws rather than answering.
 *
 * IT OPENS IN A NEW TAB. The manual is a document of its own, read beside
 * the screen it explains rather than instead of it, so the row is never the
 * current one either.
 *
 * ROUTE-TOLERANT, like the settings row: an installation whose routing has
 * not mounted the manual's addresses gets no row rather than a broken page.
 *
 * BUILT PER CALL, NEVER CACHED — the shell reads its sources live.
 */
final readonly class ManualNavigation implements NavigationSourceInterface
{
    /** The group, and it is the foot of the sidebar. */
    public const string SECTION = NavGroup::HELP;

    /** What the row says. */
    public const string LABEL = 'Manual';

    public function __construct(
        private ManualService $manual,
        private UrlGeneratorInterface $urls,
        private ?AuthorizationCheckerInterface $authorization = null,
        private ?TokenStorageInterface $tokens = null,
    ) {
    }

    public function sections(): iterable
    {
        if (null === $this->manual->page()) {
            return;
        }

        if (null !== $this->authorization) {
            if (null === $this->tokens?->getToken() || !$this->authorization->isGranted(AuthenticatedVoter::IS_AUTHENTICATED_REMEMBERED)) {
                return;
            }
        }

        try {
            $url = $this->urls->generate(ManualController::ROUTE);
        } catch (RouteNotFoundException) {
            return;
        }

        yield new NavSection(self::SECTION, [new NavItem(
            label: self::LABEL,
            url: $url,
            icon: 'shell:book-open',
            newTab: true,
        )]);
    }
}
