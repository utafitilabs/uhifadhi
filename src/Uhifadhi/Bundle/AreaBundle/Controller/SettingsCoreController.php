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

namespace Uhifadhi\Bundle\AreaBundle\Controller;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Twig\Environment;
use Uhifadhi\Bundle\AreaBundle\Service\SettingsCascadeService;
use Uhifadhi\Bundle\AreaBundle\Settings\CoreSettings;
use Uhifadhi\Bundle\RegistryBundle\Settings\SettingsRefused;
use Uhifadhi\Bundle\ShellBundle\Model\AreaTab;
use Uhifadhi\Bundle\ShellBundle\Service\SettingsReading;
use Uhifadhi\Bundle\ShellBundle\Service\SettingsSection;
use Uhifadhi\Contracts\Settings\SettingsTab;

/**
 * SETTINGS › CORE: the core's settings as a cascade over the areas, read at
 * `/settings/core` by whoever reads Settings and changed at
 * `/settings/configure/core` by Super Admins and Admins alone
 * (uhifadhi-web/settings/core.html, settings/configure/core.html).
 *
 * SERVED HERE because the places a core setting is customised for are areas;
 * the shell generates the address for its strip and draws the frame around it.
 *
 * Changing a setting is a tier's, never a pair: the configure page asks the
 * tier and nothing else, as the Permissions screens do.
 *
 * @see https://symfony.com/doc/current/bundles/best_practices.html — no base class, explicit wiring
 */
final readonly class SettingsCoreController
{
    public const string READ_ROUTE = 'settings_core';
    public const string CONFIGURE_ROUTE = 'settings_configure_core';
    public const string READ = 'settings.read';
    public const string CSRF_ID = 'settings_configure_core';

    /** The tiers that change a setting, read from the token as every gate here reads a tier. */
    private const array TIERS = ['ROLE_ADMIN', 'ROLE_SUPER_ADMIN'];

    /** The flash a save leaves for the bar's "Saved"; taken before the frame draws its flashes. */
    private const string SAVED = 'settings_cascade_saved';

    public function __construct(
        private Environment $twig,
        private SettingsCascadeService $cascade,
        private SettingsSection $section,
        private SettingsReading $reading,
        private CsrfTokenManagerInterface $csrf,
        private UrlGeneratorInterface $urls,
        private AuthorizationCheckerInterface $authorization,
        private TokenStorageInterface $tokens,
    ) {
    }

    #[Route('/settings/core', name: self::READ_ROUTE, methods: ['GET'], priority: 10)]
    #[IsGranted(self::READ)]
    public function read(): Response
    {
        return new Response($this->twig->render('@Area/settings/core.html.twig', [
            'title' => SettingsSection::TITLE,
            'subtitle' => SettingsTab::Core->subtitle(),
            'tabs' => $this->section->tabs(SettingsTab::Core),
            'table' => $this->cascade->table(CoreSettings::OWNER, $this->reading->identity()->name),
            'configureUrl' => $this->mayChange() ? $this->urls->generate(self::CONFIGURE_ROUTE) : null,
        ]));
    }

    #[Route('/settings/configure/core', name: self::CONFIGURE_ROUTE, methods: ['GET', 'POST'])]
    public function configure(Request $request): Response
    {
        if (!$this->mayChange()) {
            throw new AccessDeniedException('Only Super Admins and Admins change a setting.');
        }

        $refusal = null;
        if ($request->isMethod('POST')) {
            if (!$this->csrf->isTokenValid(new CsrfToken(self::CSRF_ID, $request->request->getString('_token')))) {
                throw new AccessDeniedException('Invalid CSRF token.');
            }

            try {
                $this->cascade->save(CoreSettings::OWNER, $request->request->all('org'), $request->request->all('area'), $this->tokens->getToken()?->getUserIdentifier() ?? '');
                $session = $request->hasSession() ? $request->getSession() : null;
                if ($session instanceof FlashBagAwareSessionInterface) {
                    $session->getFlashBag()->add(self::SAVED, 'Saved');
                }

                return new RedirectResponse($this->urls->generate(self::CONFIGURE_ROUTE));
            } catch (SettingsRefused $refused) {
                $refusal = $refused->getMessage();
            }
        }

        $session = $request->hasSession() ? $request->getSession() : null;
        $saved = $session instanceof FlashBagAwareSessionInterface && [] !== $session->getFlashBag()->get(self::SAVED);

        return new Response($this->twig->render('@Area/settings/configure_core.html.twig', [
            'title' => SettingsSection::TITLE,
            'subtitle' => 'Change the default for every area, or set an area’s own. Nothing is saved until Save.',
            'tabs' => [new AreaTab(SettingsTab::Core->label(), $this->urls->generate(self::CONFIGURE_ROUTE), true)],
            'table' => $this->cascade->table(CoreSettings::OWNER, $this->reading->identity()->name),
            'readUrl' => $this->urls->generate(self::READ_ROUTE),
            'token' => $this->csrf->getToken(self::CSRF_ID)->getValue(),
            'saved' => $saved,
            'refusal' => $refusal,
        ]), null === $refusal ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    private function mayChange(): bool
    {
        foreach (self::TIERS as $tier) {
            if ($this->authorization->isGranted($tier)) {
                return true;
            }
        }

        return false;
    }
}
