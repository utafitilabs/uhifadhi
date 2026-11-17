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

use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\EventListener\AbstractSessionListener;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\AuthenticatedVoter;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Uhifadhi\Bundle\ShellBundle\Service\ManualService;

/**
 * THE INSTALLATION'S MANUAL, READ IN THE APP — so everybody reads the version
 * deployed and nobody mails PDFs around: the page at `/manual/`, its pictures
 * under `/manual/img/`, and the PDF to download.
 *
 * THE PAGE IS SERVED AS IT IS. The manual is a standalone document with its
 * own styles, built by the installation; wrapping it in the frame would be
 * restyling somebody else's book. The address ends in a slash so the page's
 * own relative links (`img/…`) resolve under it.
 *
 * FOR ANYBODY SIGNED IN, and nobody else: the manual names no record, so it
 * asks no pair — being signed in (remembered counts) is the whole of it. The
 * firewall's own rule usually answers first; this is the check that holds
 * where an installation's access control does not cover the address. A
 * kernel with no security at all has nobody to ask.
 *
 * NOTHING CONFIGURED, NOTHING THERE: 404, for every address.
 *
 * PRIVATE AND UNINDEXED. `private, no-cache` — no shared cache keeps it, and
 * a browser asks again each time, so a deploy is read the same minute — and
 * `noindex`. The session listener is told to leave that header alone.
 *
 * NO BASE CLASS: a reusable bundle's controller takes what it needs in its
 * constructor and is wired explicitly in config/services.php.
 *
 * @see https://symfony.com/doc/current/bundles/best_practices.html
 * @see https://symfony.com/doc/current/components/http_foundation.html#serving-files
 * @see vendor/symfony/http-kernel/EventListener/AbstractSessionListener.php — NO_AUTO_CACHE_CONTROL_HEADER
 */
final readonly class ManualController
{
    /** The page. */
    public const string ROUTE = 'manual';

    /** One of its pictures. */
    public const string PICTURE_ROUTE = 'manual_picture';

    /** The PDF. */
    public const string PDF_ROUTE = 'manual_pdf';

    private const array HEADERS = [
        'Cache-Control' => 'private, no-cache',
        'X-Robots-Tag' => 'noindex',
        AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER => 'true',
    ];

    public function __construct(
        private ManualService $manual,
        private ?AuthorizationCheckerInterface $authorization = null,
    ) {
    }

    public function page(): BinaryFileResponse
    {
        $this->signedIn();

        return $this->file($this->manual->page(), 'text/html; charset=UTF-8');
    }

    public function picture(string $name): BinaryFileResponse
    {
        $this->signedIn();

        return $this->file($this->manual->picture($name), ManualService::pictureType($name));
    }

    public function pdf(): BinaryFileResponse
    {
        $this->signedIn();

        $response = $this->file($this->manual->pdf(), 'application/pdf');
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $this->manual->downloadName());

        return $response;
    }

    private function signedIn(): void
    {
        if (null !== $this->authorization && !$this->authorization->isGranted(AuthenticatedVoter::IS_AUTHENTICATED_REMEMBERED)) {
            throw new AccessDeniedException('The manual is for people signed in.');
        }
    }

    private function file(?string $path, string $type): BinaryFileResponse
    {
        if (null === $path) {
            throw new NotFoundHttpException('This installation has no such manual file.');
        }

        return new BinaryFileResponse($path, headers: ['Content-Type' => $type, ...self::HEADERS], public: false);
    }
}
