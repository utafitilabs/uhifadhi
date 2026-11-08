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
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
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
use Uhifadhi\Bundle\AreaBundle\Shell\ModuleSettingsPages;
use Uhifadhi\Bundle\RegistryBundle\Settings\SettingsRefused;
use Uhifadhi\Bundle\ShellBundle\Model\AreaTab;
use Uhifadhi\Bundle\ShellBundle\Service\SettingsReading;
use Uhifadhi\Bundle\ShellBundle\Service\SettingsSection;
use Uhifadhi\Contracts\ModuleProviderInterface;
use Uhifadhi\Contracts\Settings\SettingsTab;

/**
 * SETTINGS › MODULES › <MODULE>: one module's settings as a cascade over the
 * areas, read at `/settings/modules/{slug}` by whoever reads Settings and
 * changed at `/settings/configure/modules/{slug}` by Super Admins and Admins
 * alone (uhifadhi-web/settings/modules/incidents.html,
 * settings/configure/modules/incidents.html). The same page for every module
 * that declares a setting; a slug that names no such module is not a page.
 *
 * Changing a setting is a tier's, never a pair, as on Settings › Core.
 *
 * @see https://symfony.com/doc/current/bundles/best_practices.html — no base class, explicit wiring
 */
final readonly class SettingsModuleController
{
    public const string READ_ROUTE = 'settings_module';
    public const string CONFIGURE_ROUTE = 'settings_configure_module';
    public const string READ = 'settings.read';
    public const string CSRF_ID = 'settings_configure_module';

    /** The tiers that change a setting, read from the token as every gate here reads a tier. */
    private const array TIERS = ['ROLE_ADMIN', 'ROLE_SUPER_ADMIN'];

    /** The flash a save leaves for the bar's "Saved"; taken before the frame draws its flashes. */
    private const string SAVED = 'settings_cascade_saved';

    public function __construct(
        private Environment $twig,
        private SettingsCascadeService $cascade,
        private ModuleSettingsPages $pages,
        private SettingsSection $section,
        private SettingsReading $reading,
        private CsrfTokenManagerInterface $csrf,
        private UrlGeneratorInterface $urls,
        private AuthorizationCheckerInterface $authorization,
        private TokenStorageInterface $tokens,
    ) {
    }

    #[Route('/settings/modules/{slug}', name: self::READ_ROUTE, requirements: ['slug' => '[a-z][a-z0-9_-]*'], methods: ['GET'])]
    #[IsGranted(self::READ)]
    public function read(string $slug): Response
    {
        $module = $this->module($slug);
        $description = trim((string) $module->description());

        return new Response($this->twig->render('@Area/settings/module.html.twig', [
            'title' => $module->name(),
            'subtitle' => ('' === $description ? '' : rtrim($description, '.').'. ').'The default for every area, and where an area sets its own.',
            'tabs' => $this->section->tabs(SettingsTab::Modules),
            'slug' => $slug,
            'table' => $this->cascade->table($slug, $this->reading->identity()->name),
            'modulesUrl' => $this->section->addressOf(SettingsTab::Modules),
            'configureUrl' => $this->mayChange() ? $this->urls->generate(self::CONFIGURE_ROUTE, ['slug' => $slug]) : null,
        ]));
    }

    #[Route('/settings/configure/modules/{slug}', name: self::CONFIGURE_ROUTE, requirements: ['slug' => '[a-z][a-z0-9_-]*'], methods: ['GET', 'POST'])]
    public function configure(Request $request, string $slug): Response
    {
        if (!$this->mayChange()) {
            throw new AccessDeniedException('Only Super Admins and Admins change a setting.');
        }
        $module = $this->module($slug);

        $refusal = null;
        if ($request->isMethod('POST')) {
            if (!$this->csrf->isTokenValid(new CsrfToken(self::CSRF_ID, $request->request->getString('_token')))) {
                throw new AccessDeniedException('Invalid CSRF token.');
            }

            try {
                $this->cascade->save($slug, $request->request->all('org'), $request->request->all('area'), $this->tokens->getToken()?->getUserIdentifier() ?? '');
                $session = $request->hasSession() ? $request->getSession() : null;
                if ($session instanceof FlashBagAwareSessionInterface) {
                    $session->getFlashBag()->add(self::SAVED, 'Saved');
                }

                return new RedirectResponse($this->urls->generate(self::CONFIGURE_ROUTE, ['slug' => $slug]));
            } catch (SettingsRefused $refused) {
                $refusal = $refused->getMessage();
            }
        }

        $session = $request->hasSession() ? $request->getSession() : null;
        $saved = $session instanceof FlashBagAwareSessionInterface && [] !== $session->getFlashBag()->get(self::SAVED);
        $here = $this->urls->generate(self::CONFIGURE_ROUTE, ['slug' => $slug]);

        return new Response($this->twig->render('@Area/settings/configure_module.html.twig', [
            'title' => $module->name(),
            'subtitle' => 'Change the default for every area, or set an area’s own. Nothing is saved until Save.',
            'tabs' => [
                new AreaTab(SettingsTab::Core->label(), $this->urls->generate(SettingsCoreController::CONFIGURE_ROUTE), false),
                new AreaTab(SettingsTab::Modules->label(), $here, true),
            ],
            'slug' => $slug,
            'table' => $this->cascade->table($slug, $this->reading->identity()->name),
            'action' => $here,
            'readUrl' => $this->urls->generate(self::READ_ROUTE, ['slug' => $slug]),
            'token' => $this->csrf->getToken(self::CSRF_ID)->getValue(),
            'saved' => $saved,
            'refusal' => $refusal,
        ]), null === $refusal ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    private function module(string $slug): ModuleProviderInterface
    {
        return $this->pages->module($slug) ?? throw new NotFoundHttpException(\sprintf('No module "%s" declares a setting.', $slug));
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
