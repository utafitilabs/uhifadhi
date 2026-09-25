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
use Uhifadhi\Bundle\AreaBundle\Entity\Station;
use Uhifadhi\Bundle\AreaBundle\Enum\StationPositionSource;
use Uhifadhi\Bundle\AreaBundle\Repository\StationRepository;

/**
 * THE ONLY SUPPORTED WAY A STATION GETS A POINT, and therefore a zone.
 *
 * A STATION'S ZONE IS NEVER TYPED. It is derived from the point by PostGIS and
 * cached on the row; every write that can move the answer comes through here
 * and re-derives it, so the cache cannot drift from the map.
 *
 * FOUR THINGS MOVE THE ANSWER, and only one of them is the station:
 *
 *   1. the station's own point moves       — {@see moveTo()}
 *   2. an import adds zones                — the importer calls {@see rederiveFor()}
 *   3. a zone's ring is replaced           — the same
 *   4. a zone is removed, or the set is    — the same
 *
 * The last three are the area's business rather than the station's, which is
 * why they are one public method the zone surfaces call rather than an event
 * this service listens for: a listener would make the order of two writes
 * decide the answer, and a caller that forgot would leave a stale zone nobody
 * could see was stale.
 *
 * ONE STATEMENT FOR THE WHOLE AREA. An import moves every station's answer at
 * once, so the recompute is a single UPDATE rather than a query per station —
 * see {@see StationRepository::updateDerivedZones()}, which also carries the
 * tie-break.
 */
final readonly class StationService
{
    /**
     * THE RING A NEW POST OPENS WITH, in metres — the design's own default,
     * stated on the form beside the field.
     *
     * A DEFAULT IS NOT AN INVENTED VERDICT. The entity is right that null is
     * a real state and that nobody may invent a radius for a post that has
     * none; this is the other case — a post being CREATED, by somebody
     * looking at the field with the default written next to it. What they
     * leave alone they have agreed to.
     */
    public const int DEFAULT_CATCHMENT_M = 1500;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private StationRepository $stations,
        private StationEventService $events,
        private PostingService $postings,
    ) {
    }

    /**
     * THE NEXT CODE THIS AREA HAS NOT ISSUED — ST-01, ST-02, and so on.
     *
     * ISSUED ON SAVE AND NEVER AGAIN. A code is what a radio call and a paper
     * form say instead of a name, so it is short, it is the area's own
     * sequence, and it outlives every rename. It counts UP from the highest
     * ever issued rather than filling gaps: reissuing a retired post's code
     * would make two different places in the archive read as one.
     */
    public function nextCode(AreaOfInterest $area): string
    {
        $highest = 0;
        foreach ($this->stations->findByArea($area) as $station) {
            if (1 === preg_match('/^ST-(\\d+)$/', (string) $station->getCode(), $found)) {
                $highest = max($highest, (int) $found[1]);
            }
        }

        return \sprintf('ST-%02d', $highest + 1);
    }

    /**
     * A STATION, AT A POINT. Longitude then latitude, in that order, because
     * GeoJSON and PostGIS both put them that way round and a product that
     * reversed them once would reverse them everywhere.
     *
     * SURVEYED UNLESS SAID OTHERWISE: a point typed on the form is a point
     * somebody recorded. A caller that invents one says so.
     */
    public function add(
        AreaOfInterest $area,
        string $name,
        float $lon,
        float $lat,
        ?string $code = null,
        ?string $actor = null,
        ?int $elevationM = null,
        ?string $locality = null,
        ?\DateTimeImmutable $openedAt = null,
        ?int $catchmentM = self::DEFAULT_CATCHMENT_M,
        StationPositionSource $positionSource = StationPositionSource::Surveyed,
    ): Station {
        $station = new Station()
            ->setArea($area)
            ->setName(trim($name))
            ->setCode(self::orNull($code) ?? $this->nextCode($area))
            ->setPoint(self::pointAt($lon, $lat))
            ->setPositionSource($positionSource)
            ->setElevationM($elevationM)
            ->setLocality(self::orNull($locality))
            ->setOpenedAt($openedAt)
            ->setCatchmentM($catchmentM);

        $this->entityManager->persist($station);
        $this->entityManager->flush();

        $this->events->recorded($station, $actor);
        $this->rederiveFor($area, 'the station was recorded');
        $this->entityManager->refresh($station);

        return $station;
    }

    /**
     * The post moved. Its ground may have changed hands, so the answer is
     * re-asked — and the log says how far it went and which way, because "the
     * point changed" is a fact nobody can check and "340 m west" is one
     * somebody can walk to.
     *
     * A POINT SOMEBODY PLACED IS SURVEYED, whatever the post stood on before.
     */
    public function moveTo(Station $station, float $lon, float $lat, ?string $actor = null): Station
    {
        $area = $station->getArea();
        if (null === $area) {
            throw new \LogicException('A station always belongs to an area.');
        }

        $to = self::pointAt($lon, $lat);
        [$metres, $heading] = $this->stations->stDisplacement((string) $station->getPoint(), $to);

        $station->setPoint($to)->setPositionSource(StationPositionSource::Surveyed);
        $this->entityManager->flush();

        if ($metres > 0) {
            $this->events->pointMoved($station, $metres, $heading, $actor);
        }

        $this->rederiveFor($area, 'the station moved');
        $this->entityManager->refresh($station);

        return $station;
    }

    /**
     * WHAT "INSIDE THIS POST" MEANS, in metres — the ring a check-in claiming
     * this post is judged against.
     *
     * A VERB OF ITS OWN, because it is a different act from describing the
     * place: the elevation and the locality say what a radio call would say,
     * and this decides whether somebody standing there counts as being at
     * their post. The two are edited on the same card and are not the same
     * question.
     *
     * NULL CLEARS IT, and that is a real answer: a post with no ring has no
     * inside, the handset says so rather than picking a radius of its own,
     * and a day claimed there derives as unverified rather than as wrong.
     * A ZERO OR NEGATIVE RADIUS IS NOT A SMALL RING, it is not a ring, so it
     * clears too rather than being stored as a circle nobody can stand in.
     */
    public function setCatchment(Station $station, ?int $metres): Station
    {
        $station->setCatchmentM(null === $metres || $metres <= 0 ? null : $metres);
        $this->entityManager->flush();

        return $station;
    }

    /**
     * THE FACTS ABOUT THE PLACE THAT ARE NOT ITS POINT — what it is called on
     * the radio, how high it stands, and where people say it is.
     *
     * A VERB, NOT A SETTER. Every one of these is a column, and a caller that
     * wrote the column instead of calling this would skip the trimming, the
     * empty-is-null rule and — the day one of them earns a log line — the log
     * line. There is one writer for each fact and this is it.
     */
    public function describe(
        Station $station,
        ?string $code = null,
        ?int $elevationM = null,
        ?string $locality = null,
        ?\DateTimeImmutable $openedAt = null,
    ): Station {
        $station
            ->setCode(self::orNull($code))
            ->setElevationM($elevationM)
            ->setLocality(self::orNull($locality))
            ->setOpenedAt($openedAt);

        $this->entityManager->flush();

        return $station;
    }

    /** A rename touches nothing else: the post is where it was. */
    public function rename(Station $station, string $name, ?string $actor = null): Station
    {
        $was = (string) $station->getName();
        $name = trim($name);
        if ('' === $name || $was === $name) {
            return $station;
        }

        $station->setName($name);
        $this->entityManager->flush();
        $this->events->renamed($station, $was, $name, $actor);

        return $station;
    }

    /** Closed, not deleted: every line, every posting and every patrol stays. */
    public function deactivate(Station $station, ?\DateTimeImmutable $on = null, ?string $actor = null): Station
    {
        if (!$station->isActive()) {
            return $station;
        }

        /*
         * THE POSTINGS END WITH IT. A post that has closed has nobody
         * standing at it, and leaving people posted to a closed post would
         * put them on next week's staffing list. They END rather than
         * disappear: last season's patrol still has its crew, and every
         * record already made against this post still points at it.
         */
        foreach ($this->postings->standingAt($station) as $posting) {
            $this->postings->end($posting, $on, $actor);
        }

        $station->setActive(false);
        $this->entityManager->flush();
        $this->events->deactivated($station, $actor);

        return $station;
    }

    public function reactivate(Station $station, ?string $actor = null): Station
    {
        if (!$station->isActive()) {
            $station->setActive(true);
            $this->entityManager->flush();
            $this->events->reactivated($station, $actor);
        }

        return $station;
    }

    /**
     * EVERY STATION IN THE AREA, RE-ASKED. Called by whatever moved the zones
     * under them — an import, a replaced ring, a removal, a cleared set.
     *
     * EACH ONE THAT MOVED GETS A LINE IN ITS OWN LOG, and `$because` names
     * what did it: an import, a replaced ring, a removal. A station whose zone
     * did NOT change gets none — the alternative is a line on every post in
     * the area every time anybody edits a zone.
     *
     * `$alsoNotify` NAMES THE STATIONS THE DATABASE ALREADY ANSWERED FOR. When
     * a zone is deleted the foreign key sets `zone_id` to null before this
     * runs, so the recompute finds nothing to change and would log nothing —
     * for the one event a reader most wants to see. The caller that deleted
     * the zone knows who stood in it, and says so here.
     *
     * @param list<int> $alsoNotify station ids to log even when the statement changed nothing
     *
     * @return int how many stations changed zone
     */
    public function rederiveFor(AreaOfInterest $area, string $because = 'the zones changed', array $alsoNotify = []): int
    {
        $moved = $this->stations->updateDerivedZones($area);

        foreach (array_unique([...$moved, ...$alsoNotify]) as $id) {
            $station = $this->stations->find($id);
            if (null === $station) {
                continue;
            }

            // The statement went round the unit of work, so the loaded row
            // still holds the zone it had before it.
            $this->entityManager->refresh($station);
            $this->events->zoneDerived($station, $station->getZone()?->getName(), $because);
        }

        return \count($moved);
    }

    /** Blank is UNRECORDED, never the empty string: a dash on the page, not a gap. */
    private static function orNull(?string $value): ?string
    {
        return null === $value || '' === trim($value) ? null : trim($value);
    }

    /** A point as the column's GeoJSON, longitude first. */
    private static function pointAt(float $lon, float $lat): string
    {
        return (string) json_encode(
            ['type' => 'Point', 'coordinates' => [$lon, $lat]],
            \JSON_THROW_ON_ERROR,
        );
    }
}
