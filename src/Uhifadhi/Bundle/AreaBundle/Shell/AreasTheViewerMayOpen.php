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

namespace Uhifadhi\Bundle\AreaBundle\Shell;

use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Uhifadhi\Bundle\AreaBundle\Repository\AreaOfInterestRepository;
use Uhifadhi\Contracts\Shell\Scope;
use Uhifadhi\Contracts\Shell\ScopeSourceInterface;

/**
 * HOW WIDE AN ORGANIZATION-LEVEL PAGE MAY LOOK — THE DEFAULT, SHIPPED.
 *
 * THE DEFECT THIS CLOSES: the shell draws the scope control only when
 * something tags a {@see ScopeSourceInterface}, and nothing did — so the
 * roster's organization pages rendered with the row, the strip and the
 * figures all correct and no control at all. A seam whose default is
 * "nothing" ships a feature that passes its own suite and is missing on
 * every page an installation actually serves. The core ships the default;
 * a host that wants a different list still replaces the source.
 *
 * THIS BUNDLE ANSWERS IT BECAUSE IT OWNS THE GROUND. The shell holds no
 * areas and no voters; this holds both, and it is already the package that
 * answers the same question for the handset.
 *
 * THE SAME AUTHORITY AS `/api/areas/mine`, ASKED THE SAME WAY: `areas.read`
 * with the AREA AS SUBJECT. Asked without one, the question becomes "does
 * this person have authority anywhere", which is much weaker and would offer
 * somebody a slice they cannot open. The narrowing is therefore the
 * permission model's and cannot drift from it.
 *
 * ONE AREA IS NOT A CHOICE. Where the viewer may open exactly one, "the
 * organization" and "that area" are the same reading, so only the area is
 * offered — and the shell's own rule then leaves the action row empty rather
 * than drawing a control with one row in it.
 *
 * READ LIVE, NEVER CACHED: an area created this morning is in the control
 * this morning, which is the same promise the sidebar keeps.
 */
final readonly class AreasTheViewerMayOpen implements ScopeSourceInterface
{
    /** Seeing an area and everything recorded inside it — the Areas concern, read. */
    private const string PERMISSION = 'areas.read';

    public function __construct(
        private AreaOfInterestRepository $areas,
        private TokenStorageInterface $tokens,
        private AuthorizationCheckerInterface $authorization,
    ) {
    }

    public function scopes(): iterable
    {
        /*
         * NO TOKEN, NO QUESTION. A page can render outside any firewall — an
         * error page, a console-rendered template — and the authorization
         * checker THROWS there rather than answering false.
         */
        if (null === $this->tokens->getToken()) {
            return;
        }

        $open = [];
        // BY NAME, because a control must not reorder between two renders;
        // insertion order is a fact nobody outside the database can see.
        foreach ($this->areas->findBy([], ['name' => 'ASC']) as $area) {
            $uuid = $area->getUuidString();

            /*
             * THE PUBLIC ADDRESS, NEVER THE SEQUENTIAL KEY — an area is a
             * uuid everywhere in this product, and the control round-trips
             * the value through `?area=`. It is written on persist, so a row
             * read back from the database always has one; an area that
             * somehow does not is one no link could name, and offering it
             * would put an empty option in the control.
             */
            if (null !== $uuid && $this->authorization->isGranted(self::PERMISSION, $area)) {
                $open[] = Scope::area($uuid, (string) $area->getName());
            }
        }

        if (\count($open) > 1) {
            yield Scope::organization();
        }

        yield from $open;
    }
}
