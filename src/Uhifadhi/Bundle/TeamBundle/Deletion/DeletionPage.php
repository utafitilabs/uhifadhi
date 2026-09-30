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

namespace Uhifadhi\Bundle\TeamBundle\Deletion;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;
use Uhifadhi\Bundle\ShellBundle\Contract\DeletionPageInterface;

/**
 * THE DELETE PAGE, ONE FOR EVERY KIND OF RECORD (ruled 28 Sep, #48, design C
 * "a page of its own"): what goes, counted and named; what stays; the typed
 * confirmation; and the audit line it will leave. Whoever owns a record gives
 * it a route and hands the record here, so every delete reads and behaves the
 * same and none of them is written twice.
 */
final readonly class DeletionPage implements DeletionPageInterface
{
    public const string CSRF_ID = 'uhifadhi_delete';

    public function __construct(
        private DeletionService $deletions,
        private Environment $twig,
        private CsrfTokenManagerInterface $csrf,
    ) {
    }

    public function mayDelete(): bool
    {
        return $this->deletions->mayDelete();
    }

    public function respond(Request $request, object $record): Response
    {
        if (!$this->deletions->mayDelete()) {
            throw new AccessDeniedException('Only a Super Admin deletes.');
        }

        $plan = $this->deletions->plan($record);
        $refusal = null;

        if ($request->isMethod('POST')) {
            if (!$this->csrf->isTokenValid(new CsrfToken(self::CSRF_ID, $request->request->getString('_token')))) {
                throw new NotFoundHttpException('Invalid CSRF token.');
            }

            try {
                $line = $this->deletions->delete($record, $request->request->getString('reference'));
            } catch (DeletionRefusedException $refused) {
                $refusal = $refused->getMessage();
            }

            if (isset($line)) {
                $session = $request->hasSession() ? $request->getSession() : null;
                if ($session instanceof FlashBagAwareSessionInterface) {
                    $session->getFlashBag()->add('success', \sprintf('%s deleted. %s %s gone · kept in Settings › Deletions.', $line->getTitle(), ucfirst($line->getWhatWent()), str_contains($line->getWhatWent(), ',') || !str_starts_with($line->getWhatWent(), '1 ') ? 'are' : 'is'));
                }

                return new RedirectResponse($plan->subject->afterUrl);
            }
        }

        return new Response($this->twig->render('@Team/deletion/page.html.twig', [
            'plan' => $plan,
            'refusal' => $refusal,
            'token' => $this->csrf->getToken(self::CSRF_ID)->getValue(),
            'now' => new \DateTimeImmutable(),
        ]), null === $refusal ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}
