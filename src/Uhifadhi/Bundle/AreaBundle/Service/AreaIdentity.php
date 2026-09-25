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

namespace Uhifadhi\Bundle\AreaBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\Exception\AreaIdentityException;

/**
 * AN AREA'S IDENTITY, EDITED IN PLACE — the name, the IUCN category and the
 * gazettement year, changed on an area that already exists. The boundary is
 * deliberately NOT here: it is geometry everything else references, so replacing
 * it goes through {@see BoundaryImport} with a guard in front of it, never
 * through a plain field save.
 *
 * THE SIBLING OF {@see AreaCreator}, AND FOR THE SAME REASONS. Registered beside
 * the entity rather than with the screens, because editing an area is a model
 * concern a console command or a fixture loader with no twig would want too. It
 * holds the ONE invariant a name has — that it is not blank — the same rule
 * creation enforces, because an area cannot be left nameless any more than it
 * can be born nameless.
 *
 * THE GAZETTED FACTS ARE OPTIONAL, AND CLEARING THEM IS A VALID EDIT. An area
 * whose IUCN category or established year was recorded in error can have it set
 * back to unrecorded — a blank is UNRECORDED, never zero — so both take null and
 * null is written through.
 *
 * AND THE AREA'S TWO SETTINGS SAVE WITH IT, because the settings section has
 * one write: the zone overlap tolerance, and how often the area's handsets
 * report a position ({@see PingInterval}). A blank is "not set" for both and
 * reads as the product's default.
 */
final readonly class AreaIdentity
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /**
     * Save an area's identity. The name is required and trimmed; the gazetted
     * facts are optional and a null clears them back to unrecorded.
     *
     * @param int|null $pingIntervalMinutes null is "not set" and reads as {@see PingInterval::DEFAULT_MINUTES}
     *
     * @throws AreaIdentityException when the name is blank, the tolerance is out of range, or the interval is under a minute
     */
    public function update(
        AreaOfInterest $area,
        string $name,
        ?string $iucnCategory,
        ?int $establishedYear,
        ?float $zoneOverlapTolerancePct = null,
        ?int $pingIntervalMinutes = null,
    ): AreaOfInterest {
        $name = trim($name);
        if ('' === $name) {
            throw new AreaIdentityException('An area needs a name — as its official record names it.');
        }

        /*
         * A TOLERANCE OUT OF RANGE IS REFUSED RATHER THAN CLAMPED. Somebody who
         * typed 50 meant something, and half a zone is a decision about the
         * ground; silently saving 10 would leave them believing they had made
         * it.
         */
        if (null !== $zoneOverlapTolerancePct
            && ($zoneOverlapTolerancePct < 0.0 || $zoneOverlapTolerancePct > ZoneOverlapService::MAX_TOLERANCE_PCT)) {
            throw new AreaIdentityException(\sprintf('Zone overlap tolerance is a percentage between 0 and %s. Past that, the answer is to fix the scheme rather than to accept the overlap.', (string) ZoneOverlapService::MAX_TOLERANCE_PCT));
        }

        /*
         * BELOW A MINUTE IS NO INTERVAL, AND IT IS REFUSED RATHER THAN
         * DEFAULTED. A phone told zero would never ping or never stop; saving
         * the default instead would leave whoever typed it believing it held.
         */
        if (null !== $pingIntervalMinutes && $pingIntervalMinutes < 1) {
            throw new AreaIdentityException('Ping every is at least one minute. Leave it blank to run at the default of '.PingInterval::DEFAULT_MINUTES.' minutes.');
        }

        $area
            ->setName($name)
            ->setIucnCategory($iucnCategory)
            ->setEstablishedYear($establishedYear)
            ->setZoneOverlapTolerancePct($zoneOverlapTolerancePct)
            ->setPingIntervalMinutes($pingIntervalMinutes);

        $this->entityManager->flush();

        return $area;
    }
}
