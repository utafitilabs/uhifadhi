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

namespace Uhifadhi\Bundle\AreaBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use Uhifadhi\Bundle\AreaBundle\Entity\Trait\TimestampableTrait;
use Uhifadhi\Bundle\AreaBundle\Entity\Trait\UuidTrait;
use Uhifadhi\Bundle\AreaBundle\Enum\StationPositionSource;
use Uhifadhi\Bundle\AreaBundle\Repository\StationRepository;

/**
 * A STATION — a place in an area that people work out of, and the thing a
 * posting attaches somebody to.
 *
 * A POINT, NOT AN AREA. A station is where a post stands: a gate, a ranger
 * post, a camp. What ground it is responsible for is not a property of the
 * station at all — a zone answers that, and the two are joined by geometry
 * rather than by a field somebody keeps in step.
 *
 * THE ZONE IS DERIVED AND CACHED, NEVER TYPED. Which zone a station is in is a
 * question about its point, answered by PostGIS and stored here so that every
 * surface that groups stations by zone does not put a spatial query behind
 * each row. It is recomputed whenever either side moves — the station's point,
 * an import, a replaced ring, a cleared set — by
 * {@see \Uhifadhi\Bundle\AreaBundle\Service\StationService}, which is the only
 * supported way it is written. NULL IS UNZONED AND UNZONED IS LEGAL: zones are
 * presence-driven, so ground nobody works may belong to none.
 *
 * SET NULL, NOT CASCADE, ON THE ZONE. Removing a zone must never delete the
 * posts standing in it; they become unzoned, which is a state the product
 * already draws. The AREA is the other way round: a station lives and dies
 * with its area, as a zone does.
 *
 * DEACTIVATED, NOT DELETED. A post that closes keeps its rows — the patrols
 * that went out of it, the people who were posted there — so `active` is how a
 * station leaves the working set without taking its history with it.
 *
 * THE CODE IS THE INSTALLATION'S, NOT THE PRODUCT'S. "ST-01" is what an
 * installation calls it on a radio and in a paper log; the product neither
 * generates it nor requires it, because an installation that does not use
 * codes should not have to invent them.
 */
#[ORM\Entity(repositoryClass: StationRepository::class)]
#[ORM\Table(name: 'station')]
#[ORM\UniqueConstraint(name: 'uniq_station_area_name', columns: ['area_id', 'name'])]
#[ORM\HasLifecycleCallbacks]
class Station
{
    use TimestampableTrait;
    use UuidTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null; // @phpstan-ignore property.unusedType (assigned by Doctrine via reflection)

    #[ORM\Column(length: 128)]
    private ?string $name = null;

    /** What the installation calls it on the radio; null where it uses no codes. */
    #[ORM\Column(length: 32, nullable: true)]
    private ?string $code = null;

    /** The station lives and dies with its area — the FK cascades in the database. */
    #[ORM\ManyToOne(targetEntity: AreaOfInterest::class)]
    #[ORM\JoinColumn(name: 'area_id', nullable: false, onDelete: 'CASCADE')]
    private ?AreaOfInterest $area = null;

    #[ORM\Column(type: 'point')]
    private ?string $point = null;

    /**
     * WHERE THE POINT CAME FROM: surveyed when somebody recorded it, estimated
     * when it was put there until somebody does. Written with the point, by
     * {@see \Uhifadhi\Bundle\AreaBundle\Service\StationService}.
     */
    #[ORM\Column(length: 16, enumType: StationPositionSource::class)]
    private StationPositionSource $positionSource = StationPositionSource::Surveyed;

    /**
     * THE ZONE ITS POINT FALLS IN, cached. Written only by the service that
     * derives it; a value set by hand here is a value the next recompute
     * overwrites, which is the right outcome and a confusing way to learn it.
     */
    #[ORM\ManyToOne(targetEntity: Zone::class)]
    #[ORM\JoinColumn(name: 'zone_id', nullable: true, onDelete: 'SET NULL')]
    private ?Zone $zone = null;

    /** Metres above sea level, where somebody recorded it. */
    #[ORM\Column(nullable: true)]
    private ?int $elevationM = null;

    /** Where it stands, in the words people use for it — "crater rim road". */
    #[ORM\Column(length: 96, nullable: true)]
    private ?string $locality = null;

    /** A closed post keeps its rows and leaves the working set. */
    #[ORM\Column]
    private bool $active = true;

    /** The day it opened, where that is known; null is unrecorded, never today. */
    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $openedAt = null;

    /**
     * WHAT "INSIDE THIS POST" MEANS, in metres — the ring a check-in is
     * judged against, and the dashed circle a ranger sees before they
     * tap.
     *
     * NULL IS A REAL STATE, not a default waiting to be filled: a post
     * with no ring has no inside, the handset says so rather than
     * picking a radius of its own, and a day claimed there derives as
     * unverified rather than as wrong. A number invented here would be a
     * verdict the organization never made.
     */
    #[ORM\Column(name: 'catchment_m', nullable: true)]
    private ?int $catchmentM = null;

    public function getCatchmentM(): ?int
    {
        return $this->catchmentM;
    }

    public function setCatchmentM(?int $catchmentM): static
    {
        $this->catchmentM = $catchmentM;

        return $this;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getCode(): ?string
    {
        return $this->code;
    }

    public function setCode(?string $code): static
    {
        $this->code = $code;

        return $this;
    }

    public function getArea(): ?AreaOfInterest
    {
        return $this->area;
    }

    public function setArea(AreaOfInterest $area): static
    {
        $this->area = $area;

        return $this;
    }

    public function getPoint(): ?string
    {
        return $this->point;
    }

    public function setPoint(string $point): static
    {
        $this->point = $point;

        return $this;
    }

    public function getPositionSource(): StationPositionSource
    {
        return $this->positionSource;
    }

    public function setPositionSource(StationPositionSource $positionSource): static
    {
        $this->positionSource = $positionSource;

        return $this;
    }

    public function getZone(): ?Zone
    {
        return $this->zone;
    }

    public function setZone(?Zone $zone): static
    {
        $this->zone = $zone;

        return $this;
    }

    public function getElevationM(): ?int
    {
        return $this->elevationM;
    }

    public function setElevationM(?int $elevationM): static
    {
        $this->elevationM = $elevationM;

        return $this;
    }

    public function getLocality(): ?string
    {
        return $this->locality;
    }

    public function setLocality(?string $locality): static
    {
        $this->locality = $locality;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): static
    {
        $this->active = $active;

        return $this;
    }

    public function getOpenedAt(): ?\DateTimeImmutable
    {
        return $this->openedAt;
    }

    public function setOpenedAt(?\DateTimeImmutable $openedAt): static
    {
        $this->openedAt = $openedAt;

        return $this;
    }

    public function __toString(): string
    {
        return $this->name ?? '';
    }
}
