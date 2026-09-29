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

namespace Uhifadhi\Bundle\ShellBundle\Controller;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Twig\Environment;
use Uhifadhi\Bundle\ShellBundle\Access\ShellConcerns;
use Uhifadhi\Bundle\ShellBundle\Service\SettingsSection;

/**
 * THE SETTINGS SECTION'S FOUR SCREENS, AT ONE ADDRESS.
 *
 * A PRESENTATION CONTROLLER, which is the only kind this bundle has — the
 * same shape {@see WelcomeController} set. It resolves nothing and reads no
 * domain: it asks the section which screen the address names and renders what
 * it is handed. Everything on the screens arrives through the tagged contracts,
 * exactly as the sidebar's rows do.
 *
 * ONE ACTION, NOT FOUR. The screens differ only in which template draws them
 * and which figures that template pulls, and four actions would be four places
 * to keep the section's shape in step with the tab strip and the sidebar. The
 * set is declared once, in the contracts package, and read here through the
 * section.
 *
 * AN UNKNOWN SEGMENT IS A 404, not a redirect to the first screen. A mistyped
 * address that quietly drew something is how somebody bookmarks a page that
 * is not the one they meant.
 *
 * IT IS REACHABLE ONLY THROUGH THE APPLICATION'S IMPORT, like everything else
 * here: config/routes/settings.php is addressed by nobody in this bundle.
 *
 * WHO MAY READ IT IS {@see ShellConcerns::SETTINGS_READ} — an administrator's
 * reading of the installation, held by a position and never by being signed
 * in. The section asks it here and its sidebar row asks it in its source; the
 * renderer asks nothing. Each contributed figure, check and decision is still
 * gated by its own source. A kernel with no security at all has nobody to ask,
 * and the section is behind whatever its firewall puts it behind.
 *
 * NO BASE CLASS: a reusable bundle's controller takes what it needs in its
 * constructor and is wired explicitly in config/services.php.
 *
 * @see https://symfony.com/doc/current/bundles/best_practices.html
 */
final readonly class SettingsController
{
    public function __construct(
        private Environment $twig,
        private SettingsSection $section,
        private ?AuthorizationCheckerInterface $authorization = null,
    ) {
    }

    public function __invoke(?string $tab = null): Response
    {
        if (null !== $this->authorization && !$this->authorization->isGranted(ShellConcerns::SETTINGS_READ)) {
            throw new AccessDeniedException(\sprintf('The settings section asks for "%s".', ShellConcerns::SETTINGS_READ));
        }

        $screen = $this->section->screen($tab);
        if (null === $screen) {
            throw new NotFoundHttpException(\sprintf('"%s" is not a screen of the settings section.', (string) $tab));
        }

        return new Response($this->twig->render($screen->template, $screen->variables));
    }
}
