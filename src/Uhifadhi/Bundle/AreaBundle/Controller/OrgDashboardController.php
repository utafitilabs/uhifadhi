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
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;
use Twig\Environment;
use Uhifadhi\Bundle\AreaBundle\Service\AreaMapService;
use Uhifadhi\Bundle\AreaBundle\Service\AreaOverview;
use Uhifadhi\Bundle\AreaBundle\Service\AreaPresetLibrary;
use Uhifadhi\Bundle\AreaBundle\Service\AreaRegister;
use Uhifadhi\Bundle\AreaBundle\Service\OrgOverviewCatalogue;
use Uhifadhi\Bundle\ShellBundle\Widget\Service\WidgetEndpoint;
use Uhifadhi\Bundle\ShellBundle\Widget\Service\WidgetService;
use Uhifadhi\Contracts\Area\LivePositionsInterface;
use Uhifadhi\Contracts\Entity\UserInterface as ModuleUserInterface;
use Uhifadhi\Contracts\Shell\Scope;

/**
 * THE ORGANIZATION DASHBOARD — what `/` is.
 *
 * IT IS THE AREA OVERVIEW ONE SCOPE WIDER, and it is composed the same way:
 * a widget surface whose cells arrive from contributors, a grid the host
 * owns, and five presets. The host writes the grid, the presets and its own
 * five cells; every operational figure on the page belongs to a module.
 *
 * THERE IS NO SCOPE CONTROL ON THIS SURFACE (ruled). The dashboard IS the
 * organization, so a dropdown here would have one setting for ever; scope
 * belongs to the Observatory pages that can be read at more than one.
 *
 * AN INSTALLATION WITH NO AREAS STILL DRAWS A PAGE. The figures keep their
 * slots and say nothing was measured, the plate is left out — a map of no
 * areas is a map of nothing — and the hint at the bottom points at the one
 * page that says what to do next. Fail-honest, the rule the area overview
 * already follows: an empty installation is a working one.
 *
 * WHY THIS LIVES IN THE AREA BUNDLE. The dashboard's own cells are area
 * facts — the areas themselves, what runs in them, the ground and everybody
 * on it — and the live-position seam it draws from is this bundle's. The
 * shell owns the widget machinery and no domain at all.
 */
final readonly class OrgDashboardController
{
    /** `/`, and what the brandmark points at. */
    public const string ROUTE = 'organization_dashboard';

    /** The pair the dashboard enforces, and the one its sidebar row asks. */
    public const string READ = 'areas.read';

    /** Its widget library. */
    public const string WIDGETS_ROUTE = 'organization_widgets';

    /** A structurally valid uuid that addresses nothing — the library's URL template. */
    private const string PLACEHOLDER_UUID = '00000000-0000-4000-8000-000000000000';

    public function __construct(
        private Environment $twig,
        private OrgOverviewCatalogue $catalogue,
        private WidgetService $widgets,
        private WidgetEndpoint $endpoint,
        private UrlGeneratorInterface $router,
        private TokenStorageInterface $tokens,
        private AreaRegister $register,
        private AreaOverview $overview,
        private AreaMapService $areaMap,
        /** How a register row becomes a thing on a plate — the one mapping. */
        private AreaPresetLibrary $library,
        /** Where everybody is, asked one scope wider. */
        private LivePositionsInterface $positions,
    ) {
    }

    #[Route('/', name: self::ROUTE, methods: ['GET'])]
    #[IsGranted(self::READ)]
    public function dashboard(): Response
    {
        // ONE MOMENT FOR THE WHOLE PAGE, handed to every cell, so two figures
        // are never measured a second apart and then read side by side.
        $now = new \DateTimeImmutable();
        $context = $this->read($now);

        $catalog = $this->catalogue->catalog();
        $cells = array_values(array_filter(
            $this->widgets->resolve($catalog, $this->signedIn()),
            static fn (array $cell): bool => $cell['on'],
        ));

        return new Response($this->twig->render('@Area/org/dashboard.html.twig', [
            ...$context,
            'cells' => $cells,
            'cellContext' => $context,
            'partials' => $this->catalogue->partials(),
            'moduleStylesheets' => $this->catalogue->stylesheets(),
            'libraryUrl' => $this->router->generate(self::WIDGETS_ROUTE),
        ]));
    }

    #[Route('/widgets', name: self::WIDGETS_ROUTE, methods: ['GET'])]
    #[IsGranted('areas.read')]
    public function library(): Response
    {
        $now = new \DateTimeImmutable();
        $catalog = $this->catalogue->catalog();
        $user = $this->endpoint->user();

        return new Response($this->twig->render('@Area/org/widgets.html.twig', [
            'catalog' => $catalog,
            'builtins' => $catalog->builtins(),
            'customPresets' => $this->widgets->customPresets($catalog, $user),
            'active' => $this->widgets->activeRef($catalog, $user),
            'widgets' => $this->widgets->resolve($catalog, $user),
            // A MAP, NOT A PATTERN. Each cell is drawn from its own
            // contributor's namespace — the library takes either shape, and
            // one sprintf pattern would mean every module's cell had to live
            // in this bundle's templates.
            'partial' => $this->catalogue->partials(),
            // EVERY PARTIAL RENDERS THE REAL CELL ON REAL DATA, at full size:
            // the picture of a widget IS the widget, so what somebody
            // arranges here is exactly what they get.
            'widgetContext' => $this->read($now),
            'moduleStylesheets' => $this->catalogue->stylesheets(),
            'urls' => $this->urls(),
            'csrfToken' => $this->endpoint->csrfToken($catalog),
        ]));
    }

    #[Route('/widgets/save', name: 'organization_widgets_save', methods: ['POST'])]
    #[IsGranted('areas.read')]
    public function save(Request $request): Response
    {
        return $this->endpoint->save($request, $this->catalogue->catalog());
    }

    #[Route('/widgets/reset', name: 'organization_widgets_reset', methods: ['POST'])]
    #[IsGranted('areas.read')]
    public function reset(Request $request): Response
    {
        $catalog = $this->catalogue->catalog();
        $shipped = $catalog->preset($catalog->defaultPresetId());

        return $this->afterWrite(
            $request,
            $this->endpoint->reset($request, $catalog),
            \sprintf('The dashboard is back to “%s”.', null !== $shipped ? $shipped->label : 'the shipped default'),
        );
    }

    #[Route('/widgets/preset/{presetId}', name: 'organization_widgets_preset', requirements: ['presetId' => '[a-z0-9_-]+'], methods: ['POST'])]
    #[IsGranted('areas.read')]
    public function applyPreset(Request $request, string $presetId): Response
    {
        $catalog = $this->catalogue->catalog();
        $adopted = $catalog->preset($presetId);

        return $this->afterWrite(
            $request,
            $this->endpoint->applyPreset($request, $catalog, $presetId),
            \sprintf('Your dashboard now follows “%s”.', null !== $adopted ? $adopted->label : $presetId),
        );
    }

    #[Route('/widgets/preset/{presetId}/copy', name: 'organization_widgets_preset_copy', requirements: ['presetId' => '[a-z0-9_-]+'], methods: ['POST'], priority: 1)]
    #[IsGranted('areas.read')]
    public function copyPreset(Request $request, string $presetId): Response
    {
        return $this->afterWrite(
            $request,
            $this->endpoint->copyPreset($request, $this->catalogue->catalog(), $presetId),
            'Copied — the copy is yours to edit, and the design it came from is untouched.',
        );
    }

    #[Route('/widgets/presets', name: 'organization_widgets_preset_create', methods: ['POST'])]
    #[IsGranted('areas.read')]
    public function createPreset(Request $request): Response
    {
        return $this->afterWrite(
            $request,
            $this->endpoint->createCustomPreset($request, $this->catalogue->catalog()),
            'Saved — this arrangement is now one of your own designs.',
        );
    }

    #[Route('/widgets/presets/{presetUuid}/apply', name: 'organization_widgets_preset_apply', requirements: ['presetUuid' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted('areas.read')]
    public function applyCustomPreset(Request $request, string $presetUuid): Response
    {
        return $this->afterWrite(
            $request,
            $this->endpoint->applyCustomPreset($request, $this->catalogue->catalog(), Uuid::fromString($presetUuid)),
            'Your dashboard now follows your own design.',
        );
    }

    #[Route('/widgets/presets/{presetUuid}/rename', name: 'organization_widgets_preset_rename', requirements: ['presetUuid' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted('areas.read')]
    public function renameCustomPreset(Request $request, string $presetUuid): Response
    {
        return $this->afterWrite(
            $request,
            $this->endpoint->renameCustomPreset($request, $this->catalogue->catalog(), Uuid::fromString($presetUuid)),
            'Renamed.',
        );
    }

    #[Route('/widgets/presets/{presetUuid}/delete', name: 'organization_widgets_preset_delete', requirements: ['presetUuid' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted('areas.read')]
    public function deleteCustomPreset(Request $request, string $presetUuid): Response
    {
        return $this->afterWrite(
            $request,
            $this->endpoint->deleteCustomPreset($request, $this->catalogue->catalog(), Uuid::fromString($presetUuid)),
            'Design deleted. Your dashboard is back on the one this installation ships with.',
        );
    }

    /**
     * EVERYTHING EVERY CELL ON THIS PAGE READS, at one moment.
     *
     * THE SHARED HALF OF THE CONTRACT is in the names the contract publishes
     * — `scope`, `now`, `figures`, `attention`, `areas` — alongside each
     * contributor's own reading under `by.<slug>`. A module template is
     * written against those names, so the page supplies all of them and not
     * merely the ones today's modules happen to read.
     *
     * @return array<string, mixed>
     */
    private function read(\DateTimeImmutable $now): array
    {
        $scope = Scope::organization();
        $rows = $this->register->rows($now);

        return [
            'scope' => $scope,
            'now' => $now,
            'by' => $this->catalogue->contextFor($scope, $now),
            'figures' => $this->catalogue->figures($scope, $now),
            'attention' => $this->overview->attentionForScope($scope, $now),
            'areas' => $rows,
            'areaCounts' => $this->register->counts($rows),
            // THE OPERATIONAL COLUMNS ARE THE MODULES', read from the same
            // now-tiles the areas register reads: the host states how much
            // ground an area holds and how many modules it runs, and every
            // other column on the row belongs to whoever published it.
            'statColumns' => AreaPresetLibrary::statColumns($rows),
            // NO PLATE WHERE THERE IS NO GROUND. A map of no areas is a map
            // of nothing, so the cell is left out rather than drawn empty —
            // and every other cell keeps its slot and says what it cannot
            // measure.
            'map' => [] === $rows ? null : $this->areaMap->organization(
                $this->library->mapAreas($rows),
                $this->positions->forScope($scope, $now),
            ),
            'moduleTable' => $this->catalogue->widgetCounts(),
            // THE WAY TO THE REGISTER OF WHAT IS INSTALLED, where the
            // application has mounted the settings section at all. A card
            // that linked unconditionally would take the whole dashboard
            // down on an installation that did not import that resource.
            'modulesUrl' => $this->settingsModulesUrl(),
            'latest' => AreaController::ATTENTION_SHOWN,
        ];
    }

    /** Null where this installation has not mounted the settings section. */
    private function settingsModulesUrl(): ?string
    {
        try {
            return $this->router->generate('settings', ['tab' => 'modules']);
        } catch (RouteNotFoundException) {
            return null;
        }
    }

    /** @return array<string, string> */
    private function urls(): array
    {
        return [
            'save' => $this->router->generate('organization_widgets_save'),
            'reset' => $this->router->generate('organization_widgets_reset'),
            'preset' => $this->router->generate('organization_widgets_preset', ['presetId' => '__ID__']),
            'copy' => $this->router->generate('organization_widgets_preset_copy', ['presetId' => '__ID__']),
            'presets' => $this->router->generate('organization_widgets_preset_create'),
            'apply' => $this->router->generate('organization_widgets_preset_apply', ['presetUuid' => self::PLACEHOLDER_UUID]),
            'rename' => $this->router->generate('organization_widgets_preset_rename', ['presetUuid' => self::PLACEHOLDER_UUID]),
            'delete' => $this->router->generate('organization_widgets_preset_delete', ['presetUuid' => self::PLACEHOLDER_UUID]),
            'dashboard' => $this->router->generate(self::ROUTE),
        ];
    }

    private function afterWrite(Request $request, Response $response, string $flash): Response
    {
        if (Response::HTTP_NO_CONTENT !== $response->getStatusCode()) {
            return $response;
        }

        $session = $request->hasSession() ? $request->getSession() : null;
        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add('success', $flash);
        }

        return new RedirectResponse($this->router->generate(self::WIDGETS_ROUTE));
    }

    private function signedIn(): ?ModuleUserInterface
    {
        $user = $this->tokens->getToken()?->getUser();

        return $user instanceof ModuleUserInterface ? $user : null;
    }
}
