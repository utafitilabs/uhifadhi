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
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\Exception\CheckInStatusException;
use Uhifadhi\Bundle\AreaBundle\Model\CheckInStatusDraft;
use Uhifadhi\Bundle\AreaBundle\Service\CheckInStatusService;
use Uhifadhi\Bundle\ShellBundle\Frame\Controller\ConfigureController;
use Uhifadhi\Contracts\Shell\ConfigurationSection;

/**
 * THE SAVE OF AN AREA'S CHECK-IN STATUSES — the Check-in statuses card on
 * Configure › Area settings posts its whole list here
 * (uhifadhi-web/areas/kilimani/configure/index.html#check-in-statuses).
 *
 * Saved, it goes back to the card, whose bar says "Saved". Refused, the
 * Area settings page is drawn again with the refusal on the bar and every
 * edit kept, answered 422 as Settings › Core answers one: the page is the
 * shell's, so it is drawn by forwarding to the shell's configure controller
 * with the refusal on the sub-request.
 *
 * @see https://symfony.com/doc/current/controller/forwarding.html — a forward is a sub-request to another controller
 * @see vendor/symfony/framework-bundle/Controller/AbstractController.php forward() — duplicate the request with the attributes, handle it as a SUB_REQUEST
 * @see https://symfony.com/doc/current/bundles/best_practices.html — no base class, explicit wiring
 */
final readonly class CheckInStatusesController
{
    public const string ROUTE = 'area_check_in_statuses';
    public const string TOKEN = 'area_check_in_statuses';

    /** The sub-request attribute the refused list travels on, read by the Area settings section. */
    public const string REFUSED = '_area_check_in_statuses_refused';

    /** The flash a save leaves for the bar's "Saved". */
    public const string SAVED = 'area_check_in_statuses_saved';

    /** The card's own anchor on the Area settings page. */
    public const string ANCHOR = 'check-in-statuses';

    public function __construct(
        private CheckInStatusService $statuses,
        private CsrfTokenManagerInterface $csrf,
        private UrlGeneratorInterface $urls,
        private HttpKernelInterface $kernel,
    ) {
    }

    #[Route('/areas/{uuid}/configure/check-in-statuses', name: self::ROUTE, requirements: ['uuid' => Requirement::UUID], methods: ['POST'], priority: 1)]
    #[IsGranted(AreaEditController::CONFIGURE, subject: 'area')]
    public function save(
        Request $request,
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
    ): Response {
        if (!$this->csrf->isTokenValid(new CsrfToken(self::TOKEN, $request->request->getString('_token')))) {
            throw new AccessDeniedException('Invalid CSRF token.');
        }

        $drafts = $this->statuses->draftsFrom($request->request->all('statuses'));
        $uuid = (string) $area->getUuidString();

        try {
            $this->statuses->save($area, $drafts);
        } catch (CheckInStatusException $refused) {
            return $this->refused($request, $uuid, $refused, $drafts);
        }

        $session = $request->hasSession() ? $request->getSession() : null;
        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add(self::SAVED, 'Saved');
        }

        return new RedirectResponse($this->urls->generate(ConfigureController::AREA_ROUTE, ['uuid' => $uuid, 'section' => ConfigurationSection::SETTINGS]).'#'.self::ANCHOR);
    }

    /**
     * @param list<CheckInStatusDraft> $drafts
     */
    private function refused(Request $request, string $uuid, CheckInStatusException $refused, array $drafts): Response
    {
        $parameters = ['uuid' => $uuid, 'section' => ConfigurationSection::SETTINGS];
        $sub = $request->duplicate([], null, [
            ...$parameters,
            '_controller' => 'shell.controller.configure::area',
            '_route' => ConfigureController::AREA_ROUTE,
            '_route_params' => $parameters,
            self::REFUSED => [
                'refusal' => $refused->getMessage(),
                'key' => $refused->key,
                'drafts' => $drafts,
            ],
        ]);
        $sub->setMethod('GET');

        $response = $this->kernel->handle($sub, HttpKernelInterface::SUB_REQUEST);
        if ($response->isSuccessful()) {
            $response->setStatusCode(Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $response;
    }
}
