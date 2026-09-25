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
use Symfony\Component\HttpFoundation\File\UploadedFile;
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
use Uhifadhi\Bundle\AreaBundle\Exception\AreaIdentityException;
use Uhifadhi\Bundle\AreaBundle\Exception\BoundaryImportException;
use Uhifadhi\Bundle\AreaBundle\Repository\ZoneRepository;
use Uhifadhi\Bundle\AreaBundle\Service\AreaIdentity;
use Uhifadhi\Bundle\AreaBundle\Service\AreaMapPayload;
use Uhifadhi\Bundle\AreaBundle\Service\AreaMapService;
use Uhifadhi\Bundle\AreaBundle\Service\AreaRegister;
use Uhifadhi\Bundle\AreaBundle\Service\BoundaryImport;
use Uhifadhi\Bundle\AreaBundle\Service\ZoneOverlapService;
use Uhifadhi\Bundle\ShellBundle\Frame\Controller\ConfigureController;
use Uhifadhi\Contracts\Shell\ConfigurationSection;

/**
 * THE EDIT SCREEN — an area's identity and its boundary, on one page, and the
 * door the settings page's "Edit area" and the overview's "Area settings"
 * finally lead to.
 *
 * TWO WRITES THAT ARE NOTHING ALIKE, AND THE SCREEN SAYS SO. Editing the name,
 * the IUCN category or the gazettement year touches plain metadata with no
 * geometric dependency — {@see AreaIdentity} saves it in place. Replacing the
 * boundary supersedes the geometry everything else in the area is filed against,
 * so it is the one dangerous action here: it runs the same {@see BoundaryImport}
 * as a new area, validates the file BEFORE anything is committed, and asks for an
 * explicit confirmation the guard in the sidebar spells out. Deactivate, never
 * destroy — the zones and the records that reference the old boundary keep their
 * rows; the old geometry is superseded, not deleted.
 *
 * ONE FORM PER WRITE, EACH ITS OWN ROUTE. The identity form posts to
 * `/areas/{uuid}/edit`; the boundary form posts to
 * `/areas/{uuid}/boundary/replace`. A refusal comes back to the edit screen with
 * the reason printed on the card it belongs to, so whoever was refused fixes the
 * one thing the sentence names without losing the other card's state.
 *
 * hasBoundary DRIVES THE SCREEN. An area with a boundary offers to REPLACE it,
 * previews it on the shared map plate and carries the heads-up guard; an area
 * with none offers to ADD one, has nothing to preview and needs no confirmation
 * because there is nothing to supersede.
 *
 * A PLAIN CLASS, extending nothing, gated on `areas.configure` — the Areas
 * concern with the Configure verb, answered by whichever module an installation
 * trusts with grants. See {@see AreaController}.
 */
final readonly class AreaEditController
{
    /** One screen, two writes, two token ids. */
    public const string IDENTITY_TOKEN = 'area_edit';
    public const string BOUNDARY_TOKEN = 'area_boundary_replace';

    /** The pair both screens enforce, and the one every door to them asks. */
    public const string CONFIGURE = 'areas.configure';

    public function __construct(
        private Environment $twig,
        private AreaIdentity $identity,
        private BoundaryImport $boundaries,
        private AreaMapPayload $mapPayload,
        private AreaMapService $areaMap,
        private AreaRegister $register,
        private ZoneRepository $zones,
        private CsrfTokenManagerInterface $csrf,
        private UrlGeneratorInterface $urls,
    ) {
    }

    /**
     * THE IDENTITY WRITE. GET renders the screen; POST saves the plain metadata
     * and returns to the read-only record on the settings page, where the fresh
     * values are the lead.
     */
    #[Route('/areas/{uuid}/edit', name: 'area_edit', requirements: ['uuid' => Requirement::UUID], methods: ['GET', 'POST'])]
    #[IsGranted(self::CONFIGURE, subject: 'area')]
    public function edit(
        Request $request,
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
    ): Response {
        if (!$request->isMethod('POST')) {
            return $this->render($area);
        }

        $this->denyUnlessTokenValid($request, self::IDENTITY_TOKEN);

        [$name, $iucn, $established, $tolerance] = $this->identityFrom($request);

        try {
            $this->identity->update($area, $name, $iucn, $established, $tolerance);
        } catch (AreaIdentityException $e) {
            return $this->render($area, identityError: $e->getMessage(), status: Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new RedirectResponse($this->urls->generate(ConfigureController::AREA_ROUTE, ['uuid' => $area->getUuidString(), 'section' => ConfigurationSection::SETTINGS]));
    }

    /**
     * THE BOUNDARY WRITE — the dangerous one. It runs the same import pipeline as
     * a new area and supersedes the geometry in place. On an area that already
     * has a boundary it will not proceed without an explicit confirmation, and it
     * lands on the overview so the new geometry is the first thing seen; adding a
     * boundary to an area that had none supersedes nothing and needs no
     * confirmation.
     */
    #[Route('/areas/{uuid}/boundary/replace', name: 'area_boundary_replace', requirements: ['uuid' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted(self::CONFIGURE, subject: 'area')]
    public function replaceBoundary(
        Request $request,
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
    ): Response {
        $this->denyUnlessTokenValid($request, self::BOUNDARY_TOKEN);

        /*
         * THE CONFIRM GATE IS ON THE REPLACE, NOT ON THE ADD. Superseding a
         * boundary everything references is the consequential act the guard
         * warns about; adding a first boundary to an area that has none touches
         * nothing that already exists, so it is refused only when unconfirmed AND
         * there is something to supersede. Not a hard block — a refusal that says
         * so, on the same screen, with the file still to confirm.
         */
        if ($area->hasBoundary() && '1' !== $request->request->getString('confirm')) {
            return $this->render(
                $area,
                boundaryError: 'Replacing the boundary supersedes the geometry every zone and record references. Tick the confirmation to go ahead — nothing is deleted, the current boundary is kept as a superseded version.',
                status: Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        /*
         * TWO WAYS A FILE FAILS TO ARRIVE — the same pair {@see AreaCreateController}
         * handles: past post_max_size the whole body is discarded and no file
         * arrives; past upload_max_filesize a file arrives invalid with an error
         * code. Both are "it was too big" to the person holding it.
         */
        $file = $request->files->get('boundary');
        if (!$file instanceof UploadedFile || !$file->isValid()) {
            return $this->render($area, boundaryError: \sprintf(
                'The file was larger than this server\'s upload limit (%s) and was not received.',
                (string) (\ini_get('post_max_size') ?: 'unknown'),
            ), status: Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        /*
         * VALIDATED BEFORE ANYTHING IS COMMITTED. importInto() reads the file to
         * a MultiPolygon and throws on a bad one BEFORE it touches the geometry,
         * so a refused replace leaves the current boundary exactly as it was —
         * there is no half-replace.
         */
        try {
            $this->boundaries->importInto($area, $file, $file->getClientOriginalName());
        } catch (BoundaryImportException $e) {
            return $this->render($area, boundaryError: $e->getMessage(), status: Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new RedirectResponse($this->urls->generate('area_show', ['uuid' => $area->getUuidString()]));
    }

    /**
     * The typed identity, read in the order {@see AreaIdentity::update()} takes
     * it. A blank IUCN or year is null — unrecorded — never the empty string.
     *
     * A blank tolerance is null too — "not set", which reads as the platform's
     * default rather than as zero.
     *
     * @return array{0: string, 1: string|null, 2: int|null, 3: float|null}
     */
    private function identityFrom(Request $request): array
    {
        $iucn = trim($request->request->getString('iucn'));
        $established = trim($request->request->getString('established'));
        $tolerance = trim($request->request->getString('zoneOverlapTolerance'));

        return [
            $request->request->getString('name'),
            '' === $iucn ? null : $iucn,
            '' === $established ? null : (int) $established,
            '' === $tolerance ? null : (float) $tolerance,
        ];
    }

    private function denyUnlessTokenValid(Request $request, string $id): void
    {
        if (!$this->csrf->isTokenValid(new CsrfToken($id, $request->request->getString('_token')))) {
            throw new AccessDeniedException('Invalid CSRF token.');
        }
    }

    private function render(
        AreaOfInterest $area,
        ?string $identityError = null,
        ?string $boundaryError = null,
        int $status = Response::HTTP_OK,
    ): Response {
        return new Response(
            $this->twig->render('@Area/area/edit.html.twig', [
                'area' => $area,
                'areaKm2' => $this->register->areaKm2($area),
                'defaultZoneOverlapTolerance' => ZoneOverlapService::DEFAULT_TOLERANCE_PCT,
                'zoneCount' => $this->zones->countFor($area),
                'map' => $this->areaMap->overview($this->mapPayload->forArea($area)),
                'identityError' => $identityError,
                'boundaryError' => $boundaryError,
                'identityToken' => $this->csrf->getToken(self::IDENTITY_TOKEN)->getValue(),
                'boundaryToken' => $this->csrf->getToken(self::BOUNDARY_TOKEN)->getValue(),
            ]),
            $status,
        );
    }
}
