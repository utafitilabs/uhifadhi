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

namespace Uhifadhi\Bundle\ShellBundle\Twig;

use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\SwitchUserToken;
use Symfony\Component\Security\Core\User\UserInterface;
use Twig\Extension\RuntimeExtensionInterface;
use Uhifadhi\Bundle\ShellBundle\Frame\Model\ConfigureAction;
use Uhifadhi\Bundle\ShellBundle\Frame\Service\ModuleFrameService;
use Uhifadhi\Bundle\ShellBundle\Model\AreaTab;
use Uhifadhi\Bundle\ShellBundle\Model\NavSection;
use Uhifadhi\Bundle\ShellBundle\Model\OrgFrame;
use Uhifadhi\Bundle\ShellBundle\Service\AreaShell;
use Uhifadhi\Bundle\ShellBundle\Service\Navigation;
use Uhifadhi\Bundle\ShellBundle\Service\OrgShell;
use Uhifadhi\Bundle\ShellBundle\Service\Stylesheets;
use Uhifadhi\Bundle\ShellBundle\Service\Theme;
use Uhifadhi\Bundle\ShellBundle\Service\UserBadgeReader;
use Uhifadhi\Contracts\Shell\UserBadge;

/**
 * What the shell's templates actually call. Built lazily, on the first render —
 * see {@see ShellExtension} for why that matters at image-build time.
 *
 * Everything here is a READ. The shell draws; it does not remember, it does not
 * write, and it decides nothing about who may see what.
 *
 * @see https://symfony.com/doc/current/templating/twig_extension.html — a lazy-loaded extension's work lives in a RuntimeExtensionInterface class
 * @see vendor/symfony/twig-bundle/DependencyInjection/Compiler/RuntimeLoaderPass.php — the 'twig.runtime' tag config/services.php writes by hand, collected into twig.runtime_loader
 * @see vendor/symfony/twig-bundle/Resources/config/twig.php — 'twig.runtime_loader', the ContainerRuntimeLoader that builds this class on the first call
 */
final class ShellRuntime implements RuntimeExtensionInterface
{
    public function __construct(
        private readonly Navigation $navigation,
        private readonly Stylesheets $stylesheets,
        private readonly AreaShell $areaShell,
        private readonly ModuleFrameService $frame,
        private readonly OrgShell $orgShell,
        private readonly UserBadgeReader $userBadge,
        private readonly Theme $theme,
        private readonly RouterInterface $router,
        private readonly string $brandName,
        private readonly string $homeRoute,
        /** Absent on an installation with no security at all; then nobody is ever impersonated. */
        private readonly ?TokenStorageInterface $tokens = null,
    ) {
    }

    /**
     * WHOSE SESSION THIS REALLY IS, while an administrator has borrowed one:
     * the borrowed account's name and the real one's. Null on an ordinary
     * session, and null where the installation has no security — the band
     * that reads this is then simply absent, never an error.
     *
     * @return array{borrowed: string, real: string}|null
     */
    public function impersonation(): ?array
    {
        $token = $this->tokens?->getToken();
        if (!$token instanceof SwitchUserToken) {
            return null;
        }

        return [
            'borrowed' => self::nameOf($token->getUser()),
            'real' => self::nameOf($token->getOriginalToken()->getUser()),
        ];
    }

    private static function nameOf(?UserInterface $user): string
    {
        if (null === $user) {
            return 'somebody';
        }
        if (method_exists($user, 'getFullName')) {
            $name = $user->getFullName();
            if (\is_string($name) && '' !== $name) {
                return $name;
            }
        }

        return $user->getUserIdentifier();
    }

    /**
     * @return list<NavSection>
     */
    public function nav(): array
    {
        return $this->navigation->sections();
    }

    /**
     * THE SHEETS EVERY PAGE LINKS FOR THE COMPONENTS IT MAY DRAW — the
     * middle rung between the shell's own sheet and whatever the page
     * links for itself.
     *
     * @return list<string>
     */
    public function stylesheets(): array
    {
        return $this->stylesheets->all();
    }

    /**
     * ONE STRIP, ONE POSITION, AND THE REQUEST DECIDES WHAT IS IN IT: an area's
     * own screens, the data places of the module the viewer is inside, or — on a
     * configure page — that surface's configure sections, standing exactly where
     * the data tabs stand. A page asks for the strip; it never says which.
     *
     * @return list<AreaTab>
     */
    public function tabs(): array
    {
        return $this->frame->tabs();
    }

    /**
     * THE ORGANIZATION-LEVEL FRAME — the module's own name, the screen the
     * viewer is on, its sibling screens as a strip, and how wide the page is
     * looking. Null on every page that is in no module's org page set, which
     * is most of the product.
     *
     * ONE CALL, ONE ANSWER. The trail, the head, the strip and the scope
     * control are four readings of the same question, and a template asking
     * it four times could be given four answers by a later change.
     */
    public function org(): ?OrgFrame
    {
        return $this->orgShell->frame();
    }

    /**
     * THE SURFACE'S ONE CONFIGURATION ENTRY, or null on a page with nothing to
     * configure. Two states, one control: it opens the configure page, and on
     * the configure page it is lit and goes back to the overview.
     */
    public function configure(): ?ConfigureAction
    {
        return $this->frame->configure();
    }

    /**
     * THE PAGE TITLE, COMPOSED ONCE: "<page> — <place> — <brand>".
     *
     * A platform where every page types this join itself is a platform where
     * some pages use a hyphen, some an em dash, and some forget the brand. A
     * page says only what it is; the shell says where it is and whose it is,
     * because those are the two parts a page cannot know reliably.
     */
    public function title(string $page = ''): string
    {
        $parts = array_filter(
            [trim($page), $this->areaShell->place(), $this->brandName],
            static fn (?string $part): bool => null !== $part && '' !== trim($part),
        );

        return implode(' — ', array_map(trim(...), $parts));
    }

    /**
     * WHO THE TOP BAR NAMES, or null when it names nobody — a fresh
     * installation, a sign-in page, an anonymous request. The card is composed
     * by whoever knows who is signed in and reaches the shell already made; see
     * {@see UserBadge} and the registry it arrives through.
     */
    public function userBadge(): ?UserBadge
    {
        return $this->userBadge->badge();
    }

    public function defaultTheme(): string
    {
        return $this->theme->default();
    }

    /**
     * The wordmark and where the tile links.
     *
     * ROUTE-TOLERANT, and this is the stand-alone rule written into the
     * shell: a fresh installation has no home route yet, and a shell
     * that generated one unconditionally would 500 the very first page of every
     * new install. Home is then the site root, which is true and reachable.
     *
     * @return array{name: string, url: string}
     */
    public function brand(): array
    {
        $declared = null !== $this->router->getRouteCollection()->get($this->homeRoute);

        return [
            'name' => $this->brandName,
            'url' => $declared ? $this->router->generate($this->homeRoute) : '/',
        ];
    }
}
