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

use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Twig\Environment;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\Exception\AreaCreationException;
use Uhifadhi\Bundle\AreaBundle\Exception\BoundaryImportException;
use Uhifadhi\Bundle\AreaBundle\Service\AreaCreator;
use Uhifadhi\Bundle\AreaBundle\Service\BoundaryImport;
use Uhifadhi\Contracts\Access\WholeOrganization;

/**
 * THE CREATE SCREEN — where an installation gets its first area, and the reason
 * the register's "+ New area" button is now an address instead of a reminder.
 *
 * ONE ROUTE, TWO METHODS. The form and the thing it posts to are the same URL,
 * because a failed import has to come back to the page the file was chosen on
 * with the reason printed on it — and a separate POST address would have to
 * redirect and lose the typed name to do it.
 *
 * IDENTITY FIRST, BOUNDARY OPTIONAL. An area is created from its name — the one
 * thing it cannot be without — and its gazetted edge is a choice made on the
 * same screen: add it now (the file is imported as the area is created) or add
 * it later (the area is created bare and lands on its overview's no-boundary
 * state, where the same import waits inline). {@see AreaCreator} makes the area;
 * {@see BoundaryImport} puts the edge on it. The import is never the creation.
 *
 * NO FORM COMPONENT. A handful of fields, one of which is a file, is not worth
 * putting symfony/form and symfony/validator into every installation that wants
 * an area entity: this bundle already refuses to make a console-only
 * installation carry twig, and the same rule applies here. The validation that
 * matters is not field-shaped anyway — it is "is there a polygon in this file",
 * which only {@see BoundaryImport} can answer.
 *
 * 422, NOT 200, ON A REFUSAL. The page renders again either way, but the status
 * is what tells a caller — Turbo, a screen reader, a test — that the submission
 * did not take. A 200 here is how a form comes to look like it worked.
 *
 * GATED ON `areas.configure` — the Areas concern with the Configure verb, since
 * bringing an area into existence is setting up what this installation runs on.
 * Answered by whichever module an installation trusts with grants.
 * See {@see AreaController}.
 */
final readonly class AreaCreateController
{
    public function __construct(
        private Environment $twig,
        private AreaCreator $creator,
        private BoundaryImport $boundaries,
        private CsrfTokenManagerInterface $csrf,
        private UrlGeneratorInterface $urls,
        private AuthorizationCheckerInterface $authorization,
    ) {
    }

    /**
     * MOUNTED BEFORE NOTHING, AND IT DOES NOT NEED TO BE. `/areas/new` cannot be
     * swallowed by `/areas/{uuid}` because that route requires a UUID, so the
     * two never compete however they are ordered.
     */
    #[Route('/areas/new', name: 'area_new', methods: ['GET', 'POST'])]
    #[IsGranted(self::PAIR)]
    public function new(Request $request): Response
    {
        /*
         * AN AREA IS ADDED TO THE WHOLE ORGANIZATION. Holding `areas.configure`
         * at one area keeps that area's identity and boundary; adding another
         * reaches beyond it, so the pair is asked of the organization itself,
         * and only somebody whose authority reaches all of it may.
         */
        if (!$this->authorization->isGranted(self::PAIR, new WholeOrganization())) {
            throw new AccessDeniedException('Creating an area reaches the whole organization.');
        }

        if (!$request->isMethod('POST')) {
            return $this->form();
        }

        /*
         * CHECKED BEFORE THE FILE IS TOUCHED. Creating an area is the most
         * consequential write on this bundle's screens, and it was the one POST
         * here without a token while the module shop's three all carried one —
         * an inconsistency inside a single bundle, which is the shape a hole
         * usually comes in. `areas.configure` answers WHO may create; this answers
         * whether THIS page asked, and a permission is no defence against a form
         * on somebody else's site posting here with the viewer's own cookie.
         */
        $this->denyUnlessTokenValid($request);

        $identity = $this->identityFrom($request);
        [$name, $iucn, $established] = $identity;

        /*
         * ADD IT LATER — the area is born from its identity alone and lands on
         * its overview's no-boundary state, where the same import waits inline.
         * No file is read, so no file can refuse the creation.
         */
        if ('later' === $request->request->getString('boundary_mode')) {
            try {
                $area = $this->creator->create($name, $iucn, $established);
            } catch (AreaCreationException $e) {
                return $this->form($e->getMessage(), $identity, 'later');
            }

            return $this->toArea($area);
        }

        /*
         * ADD THE BOUNDARY NOW. The file is turned into geometry BEFORE the area
         * is created, so a bad file is refused with nothing left behind — no
         * half-made area — and a good one is stored in the same step.
         */
        $file = $request->files->get('boundary');

        /*
         * TWO WAYS A FILE FAILS TO ARRIVE, AND THEY LOOK NOTHING ALIKE.
         *
         * Past `post_max_size` PHP discards the whole body before any code runs:
         * there is no file and no fields, and the POST looks like an empty form.
         * Past `upload_max_filesize` a file DOES arrive — an UploadedFile
         * carrying an error code and, outside the test harness, an empty path.
         * Reading that one blindly is the trap: it parses as "the file is not
         * valid GeoJSON", which sends somebody off to re-export a file that was
         * never the problem.
         *
         * Both are the same fact for the person holding the file — it was too
         * big — so both get the same sentence, with the limit named. "Please
         * choose a file" would send them back to do exactly what they just did.
         */
        if (!$file instanceof UploadedFile || !$file->isValid()) {
            return $this->form(\sprintf(
                'The file was larger than this server\'s upload limit (%s) and was not received.',
                (string) (\ini_get('post_max_size') ?: 'unknown'),
            ), $identity);
        }

        try {
            $geom = $this->boundaries->readMultiPolygon($file, $file->getClientOriginalName());
            $area = $this->creator->create($name, $iucn, $established, $geom, BoundaryImport::SOURCE);
        } catch (BoundaryImportException|AreaCreationException $e) {
            return $this->form($e->getMessage(), $identity);
        }

        return $this->toArea($area);
    }

    /**
     * The typed identity, read once and in the order {@see AreaCreator::create()}
     * takes it, so the create call is a spread and the re-render on a refusal
     * keeps every field the person typed.
     *
     * @return array{0: string, 1: string|null, 2: int|null}
     */
    private function identityFrom(Request $request): array
    {
        $iucn = trim($request->request->getString('iucn'));
        $established = trim($request->request->getString('established'));

        return [
            $request->request->getString('name'),
            '' === $iucn ? null : $iucn,
            '' === $established ? null : (int) $established,
        ];
    }

    /**
     * Straight into the area it just made: the next thing anybody wants is to
     * add a module — or the boundary, if they chose to add it later.
     */
    private function toArea(AreaOfInterest $area): RedirectResponse
    {
        return new RedirectResponse($this->urls->generate('area_show', ['uuid' => $area->getUuidString()]));
    }

    /** The token id the create form mints. One screen, one write, one id. */
    public const string TOKEN_ID = 'area_new';

    /** What creating an area asks, of the whole organization. */
    public const string PAIR = 'areas.configure';

    private function denyUnlessTokenValid(Request $request): void
    {
        if (!$this->csrf->isTokenValid(new CsrfToken(self::TOKEN_ID, $request->request->getString('_token')))) {
            throw new AccessDeniedException('Invalid CSRF token.');
        }
    }

    /**
     * @param array{0: string, 1: string|null, 2: int|null} $identity name, IUCN, established year
     */
    private function form(?string $error = null, array $identity = ['', null, null], string $mode = 'now'): Response
    {
        return new Response(
            $this->twig->render('@Area/area/new.html.twig', [
                'error' => $error,
                'name' => $identity[0],
                'iucn' => $identity[1],
                'established' => $identity[2],
                'boundary_mode' => $mode,
                'token' => $this->csrf->getToken(self::TOKEN_ID)->getValue(),
            ]),
            null === $error ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }
}
