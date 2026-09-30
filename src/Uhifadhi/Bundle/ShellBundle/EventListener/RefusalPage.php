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

namespace Uhifadhi\Bundle\ShellBundle\EventListener;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;

/**
 * THE REFUSAL PAGE (ruled 30 Sep, #33, design D with the crumb "not allowed"):
 * a signed-in person who opens a page their position does not reach is
 * answered inside the shell - "There is nothing here you can open", Back and
 * Your dashboard - with a 403, instead of the framework's bare error page.
 *
 * A LISTENER, NOT AN ERROR TEMPLATE. The documented override,
 * `templates/bundles/TwigBundle/Exception/error403.html.twig`, is the
 * installation's file, and a bundle that registered its own path in the
 * `Twig` namespace would outrank the installation's override
 * (`TwigExtension` adds configured paths before `templates/bundles/`); it
 * also only renders outside debug, where nobody testing a position would see
 * it. So this answers on `kernel.exception`, the documented seam for taking
 * over an exception's response:
 * <https://symfony.com/doc/current/controller/error_pages.html#working-with-the-kernel-exception-event>.
 *
 * ONLY A PAGE. The firewall's own listener (priority 1,
 * `vendor/symfony/security-http/Firewall/ExceptionListener.php`) has by then
 * turned a signed-in refusal into an `AccessDeniedHttpException` and sent a
 * signed-out visitor to sign in; this one runs after it (priority 0) and
 * answers only a GET that asked for HTML. A refused POST - a stale form's CSRF
 * token, say - keeps its own message, and the API keeps its JSON.
 */
final readonly class RefusalPage
{
    public function __construct(
        private Environment $twig,
        private UrlGeneratorInterface $urls,
        private string $homeRoute,
    ) {
    }

    public function onException(ExceptionEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest()
            || !$event->getThrowable() instanceof AccessDeniedHttpException
            || !$request->isMethod('GET')
            || 'html' !== $request->getPreferredFormat()
            || str_starts_with($request->getPathInfo(), '/api')) {
            return;
        }

        $event->setResponse(new Response($this->twig->render('@Shell/refusal.html.twig', [
            'dashboardUrl' => $this->pathOrNull('my_dashboard') ?? $this->pathOrNull($this->homeRoute) ?? '/',
        ]), Response::HTTP_FORBIDDEN));
    }

    private function pathOrNull(string $route): ?string
    {
        try {
            return $this->urls->generate($route);
        } catch (RouteNotFoundException) {
            return null;
        }
    }
}
