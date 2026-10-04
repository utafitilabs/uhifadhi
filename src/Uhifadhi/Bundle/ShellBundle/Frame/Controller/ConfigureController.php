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

namespace Uhifadhi\Bundle\ShellBundle\Frame\Controller;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Twig\Environment;
use Uhifadhi\Bundle\ShellBundle\Frame\Service\ModuleFrameService;
use Uhifadhi\Contracts\Shell\ConfigurationSectionsInterface;

/**
 * THE CONFIGURE PAGE — one controller, two addresses, every surface in the
 * platform.
 *
 * The ruling it exists to hold is that there is ONE configuration entry per
 * surface: one `Configure` action, one page behind it, one strip of sections.
 * Before it, a module invented its own settings screen, its own way in and its
 * own way back, and no two agreed on any of the three. A page each module owned
 * could not have held that rule; a page the shell owns holds it for modules
 * nobody has written yet.
 *
 * IT RENDERS THE FRAME AND NOT ONE SECTION'S CONTENT. The heading, the summary,
 * the strip and which entry is lit are the shell's; what is UNDER the strip is a
 * template the surface named through the contract, included with the surface's
 * own variables in scope. So the shell configures nothing and knows no setting.
 *
 * NO BASE CLASS. A reusable bundle's controller does not extend
 * AbstractController — that would tie it to a service-subscriber container it
 * cannot assume and hide its dependencies behind a container lookup. It takes
 * what it needs in its constructor and is wired explicitly in
 * config/services.php, like every other service here.
 *
 * @see https://symfony.com/doc/current/bundles/best_practices.html
 * @see vendor/symfony/framework-bundle/Controller/TemplateController.php — core's
 *      own controller, which extends nothing and is registered the same way
 */
final readonly class ConfigureController
{
    /**
     * The two route names, published because the frame service matches against
     * them and an application mounting config/routes/configure.php inherits
     * them. A name typed twice is a name that eventually differs.
     */
    public const string AREA_ROUTE = 'shell_area_configure';

    public const string MODULE_ROUTE = 'shell_module_configure';

    public function __construct(
        private Environment $twig,
        private ModuleFrameService $frame,
    ) {
    }

    /**
     * THE AREA'S OWN CONFIGURE PAGE. The surface is the reserved area slug, so
     * the area is configured through exactly the contract a module is — one
     * frame, one page, one button, and no special case in here.
     */
    public function area(Request $request): Response
    {
        return $this->render($request, ConfigurationSectionsInterface::AREA);
    }

    /**
     * A MODULE'S CONFIGURE PAGE. The slug comes off the URL and is never
     * recognised: whether it means anything is the sections registry's answer,
     * and a slug nothing declared is a page that is not there.
     */
    public function module(Request $request, string $slug): Response
    {
        return $this->render($request, $slug);
    }

    private function render(Request $request, string $surface): Response
    {
        $declaration = $this->frame->declarationOf($surface);
        if (null === $declaration) {
            throw new NotFoundHttpException(\sprintf('Nothing declares a configure page for "%s".', $surface));
        }

        /*
         * EVERY SECTION WITHHELD IS A REFUSAL. Each section withholds itself
         * from a viewer it does not open for; a page with none left for them
         * is the house refusal, the same answer a section's own address gives.
         */
        if ([] === $this->frame->sectionsOf($surface)) {
            throw new AccessDeniedException(\sprintf('No section of the "%s" configure page opens for this viewer.', $surface));
        }

        /*
         * THE BARE ADDRESS BELONGS TO THE FIRST SECTION, and a surface that
         * leads with a screen of its own cannot have that section drawn here.
         * A redirect rather than a second-choice section: opening the configure
         * page has one answer, and it is the same answer whichever shape the
         * first section has. Found, not permanent — the surface may re-order its
         * sections in the next release, and a 301 would outlive the decision.
         */
        $elsewhere = $this->frame->bareAddressRedirect($request, $surface);
        if (null !== $elsewhere) {
            return new RedirectResponse($elsewhere);
        }

        $section = $this->frame->currentSection($request, $surface);
        if (null === $section) {
            /*
             * A SURFACE WHOSE SECTIONS ALL LIVE ELSEWHERE has no page here to
             * draw. Rendering a heading and a strip over an empty body would be
             * a page that looks broken rather than a page that is not there.
             */
            throw new NotFoundHttpException(\sprintf('The configure page of "%s" has no section the shell renders.', $surface));
        }

        $named = $request->attributes->get('section');
        if (\is_string($named) && '' !== $named && $named !== $section->id) {
            throw new NotFoundHttpException(\sprintf('"%s" is not a section of this configure page.', $named));
        }

        return new Response($this->twig->render('@Shell/configure.html.twig', [
            'heading' => $declaration->heading(),
            'summary' => $declaration->summary(),
            'section' => $section,
            'back' => $this->frame->frontDoorOf($request, $surface),
        ]));
    }
}
