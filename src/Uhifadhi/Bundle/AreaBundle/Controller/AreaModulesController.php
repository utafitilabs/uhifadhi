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

use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Twig\Environment;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\Model\ModuleRegisterRow;
use Uhifadhi\Bundle\AreaBundle\Service\AreaComposition;
use Uhifadhi\Bundle\RegistryBundle\Service\AreaModuleService;

/**
 * THE PER-AREA MODULES SCREENS — the grid of what this area has switched on,
 * and the Modules section of its configure page where that is decided.
 *
 * `area_modules` IS THE ROUTE NAME, AND THAT IS A FLEET CONTRACT rather
 * than a local choice. Three consumers already generate it blind and degrade
 * when nothing answers: this bundle's own {@see \Uhifadhi\Bundle\AreaBundle\Shell\AreaShellSource}
 * drops the Modules tab, and the patrol module's breadcrumb and dashboard
 * back-button print plain text. Mounting it here lights all three with no change
 * to any of them — which is what the name existing before the route was for.
 *
 * THE CONFIGURE SECTION IS A SCREEN OF THE AREA'S CONFIGURE PAGE, at
 * `/areas/{uuid}/configure/modules` like every other section of it. It writes —
 * the switch and the order — so it answers at its own address and is declared
 * as a screen ({@see \Uhifadhi\Bundle\AreaBundle\Shell\AreaConfigurationSections});
 * the frame is the shell's either way: the section strip stands where the data
 * tabs stand and the Configure action is lit.
 *
 * THIS BUNDLE OWNS THE SCREENS AND THE REGISTRY STAYS UI-LESS. Both are a reading
 * of the registry's catalogue against an area's ledger, and "an area" is this
 * module's word: the registry holds that table for installations whose area model is
 * their own and cannot name an area class, let alone draw a page about one. So
 * the registry publishes the data and this bundle draws it. A test in the registry greps
 * its own source to keep it that way.
 *
 * TWO PAIRS, AND THE MAPPING IS DELIBERATE. `modules.read` to see the grid;
 * `modules.configure` to open the section and to move anything in it, because
 * switching a module on for an area is setting what that area runs on. A concern
 * and a verb rather than a role, because composing an area is exactly what that
 * pair describes and a role is not grantable to a position.
 *
 * EVERY WRITE IS A POST WITH A TOKEN, and every write goes through the registry's
 * {@see AreaModuleService} rather than touching a row: the rule that a pinned
 * module cannot be parked lives there.
 */
final readonly class AreaModulesController
{
    /** Seeing the catalogue on an area. */
    public const string VIEW = 'modules.read';

    /** Composing it: switching a module on or off, and setting the order. */
    public const string COMPOSE = 'modules.configure';

    /** Where the strip's Modules entry points, and where every write comes back to. */
    public const string CONFIGURE = 'area_modules_configure';

    public const string TOGGLE = 'area_modules_toggle';

    public const string REORDER = 'area_modules_reorder';

    public function __construct(
        private Environment $twig,
        private AreaComposition $composition,
        private AreaModuleService $areaModules,
        private CsrfTokenManagerInterface $csrf,
        private UrlGeneratorInterface $urls,
    ) {
    }

    /**
     * THE NAME IS THE CONTRACT; THE PATH IS A CONVENTION. `/areas/{uuid}/modules`
     * is the URL SPACE every area-scoped module page lives under — it is how
     * AreaShellSource recognises the Modules tab without knowing one route name
     * — so the grid sits at its root.
     */
    #[Route('/areas/{uuid}/modules', name: 'area_modules', requirements: ['uuid' => Requirement::UUID], methods: ['GET'])]
    #[IsGranted(self::VIEW, subject: 'area')]
    public function grid(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
    ): Response {
        return new Response($this->twig->render('@Area/area/modules.html.twig', [
            'area' => $area,
            'groups' => $this->composition->gridFor($area),
            'parkedCount' => $this->composition->parkedCountFor($area),
        ]));
    }

    /**
     * THE MODULES SECTION — a register of the catalogue as this area holds
     * it: one row per module, running rows first in the area's order, the
     * switch, the grip and the door to the module's own settings.
     *
     * MOUNTED WITH A PRIORITY, and it is load-bearing: the shell's own
     * configure route takes `/areas/{uuid}/configure/{section}` for the
     * sections it renders, and this address is a section it does not.
     */
    #[Route('/areas/{uuid}/configure/modules', name: self::CONFIGURE, requirements: ['uuid' => Requirement::UUID], methods: ['GET'], priority: 1)]
    #[IsGranted(self::COMPOSE, subject: 'area')]
    public function configure(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
    ): Response {
        $rows = $this->composition->registerFor($area);
        $running = \count(array_filter($rows, static fn (ModuleRegisterRow $row): bool => $row->running));
        $withSettings = \count(array_filter($rows, static fn (ModuleRegisterRow $row): bool => null !== $row->settings));

        return new Response($this->twig->render('@Area/area/configure/modules.html.twig', [
            'area' => $area,
            'rows' => $rows,
            'running' => $running,
            'parked' => \count($rows) - $running,
            'withSettings' => $withSettings,
            'token' => $this->csrf->getToken($this->tokenId($area))->getValue(),
        ]));
    }

    /**
     * THE SWITCH. It carries the state it means — `to=on` runs the module
     * here, `to=off` parks it — so a form submitted twice does not flip the
     * module back, and a page held open across somebody else's change does
     * exactly what its label said.
     *
     * Switching on re-activates a parked row in place rather than writing a
     * second one, so a module that has been off and on again keeps whatever it
     * recorded while it was on; switching off parks — the row and its data
     * stay. A pinned module is silently refused by the registry rather than
     * half-parked here.
     *
     * A SLUG THAT IS IN NO CATALOGUE WRITES NOTHING AND SAYS NOTHING. It is not
     * a 404: the register is a live reading of a catalogue that can change under
     * an open page, and somebody switching on a module uninstalled a second ago
     * has done nothing wrong. They get the page back, without it.
     */
    #[Route('/areas/{uuid}/configure/modules/{slug}/toggle', name: self::TOGGLE, requirements: ['uuid' => Requirement::UUID, 'slug' => '[a-z][a-z0-9-]*'], methods: ['POST'], priority: 1)]
    #[IsGranted(self::COMPOSE, subject: 'area')]
    public function toggle(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
        string $slug,
        Request $request,
    ): Response {
        $this->denyUnlessTokenValid($area, $request);

        if ('on' === $request->request->getString('to')) {
            $this->areaModules->install($area, $slug);
        } else {
            $this->areaModules->uninstall($area, $slug);
        }

        return $this->backToTheSection($area);
    }

    /**
     * THE ORDER THE MODULES ARE SHOWN IN, as the rows were dragged into it. The
     * only batch write on the screen, because dragging one row moves every row
     * after it.
     */
    #[Route('/areas/{uuid}/configure/modules/reorder', name: self::REORDER, requirements: ['uuid' => Requirement::UUID], methods: ['POST'], priority: 2)]
    #[IsGranted(self::COMPOSE, subject: 'area')]
    public function reorder(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
        Request $request,
    ): Response {
        $this->denyUnlessTokenValid($area, $request);

        $this->areaModules->reorder($area, array_values(array_filter(
            $request->request->all('order'),
            static fn (mixed $slug): bool => \is_string($slug) && '' !== $slug,
        )));

        return $this->backToTheSection($area);
    }

    /** One token for the whole section, scoped to the area whose composition it changes. */
    public function tokenId(AreaOfInterest $area): string
    {
        return 'area_modules_'.$area->getUuidString();
    }

    private function denyUnlessTokenValid(AreaOfInterest $area, Request $request): void
    {
        if (!$this->csrf->isTokenValid(new CsrfToken($this->tokenId($area), $request->request->getString('_token')))) {
            throw new AccessDeniedException('Invalid CSRF token.');
        }
    }

    /**
     * POST-REDIRECT-GET, so a reload does not switch the same module twice.
     * Back to the section rather than the grid: composing is several decisions
     * in a row, and the strip is what leaves.
     */
    private function backToTheSection(AreaOfInterest $area): RedirectResponse
    {
        return new RedirectResponse($this->urls->generate(self::CONFIGURE, ['uuid' => $area->getUuidString()]));
    }
}
