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
use Uhifadhi\Bundle\AreaBundle\Repository\AreaOfInterestRepository;
use Uhifadhi\Contracts\Entity\AreaInterface;

/**
 * AN AREA — the named piece of ground an installation manages, and the axis
 * everything else in the product is filed under. A patrol happens in one, an
 * incident is reported in one, a module is switched on for one.
 *
 * `geom` is a MultiPolygon in WGS84, exchanged as GeoJSON through the
 * fundi-postgis geometry type: multi rather than single because a gazetted
 * boundary is regularly more than one ring — an enclave, an outlying block, a
 * lake excluded from the middle. Addressed publicly by UUID; the sequential id
 * exists so foreign keys are cheap and never appears in a URL.
 *
 * THE PLATFORM'S AREA CONTRACT, ANSWERED. Modules that point at an area map the
 * association at {@see AreaInterface} — the contract published by
 * uhifadhi/contracts — because they hold their tables for installations
 * whose area model is their own (the registry's per-area rows are the oldest such
 * table). This class is the answer, and the bundle states the resolution itself —
 * the installation writes no `resolve_target_entities` line unless it wants to
 * disagree. See {@see \Uhifadhi\Bundle\AreaBundle\AreaBundle::prependExtension()}.
 *
 * THE TABLE NAME IS PART OF THE PROMISE. `area_of_interest` is stated
 * explicitly rather than left to be derived from the class name under whatever
 * naming strategy an installation runs, and it is pinned by
 * Unit\Entity\AreaContractTest so it cannot drift: an installation's migration
 * history is written against this name.
 *
 * THE REGISTRY FIELDS ARE OPTIONAL, and that is honest rather than lax: an
 * installation that drew its own boundary on a map has no IUCN category and no
 * gazettement year, and must not be made to invent them to save a record.
 */
#[ORM\Entity(repositoryClass: AreaOfInterestRepository::class)]
#[ORM\Table(name: 'area_of_interest')]
#[ORM\HasLifecycleCallbacks]
class AreaOfInterest implements AreaInterface
{
    use TimestampableTrait;
    use UuidTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null; // @phpstan-ignore property.unusedType (assigned by Doctrine via reflection)

    #[ORM\Column(length: 128)]
    private ?string $name = null;

    /**
     * THE BOUNDARY IS OPTIONAL AT BIRTH. An area is created from its identity —
     * a name — and its gazetted edge is added afterwards, now or later: the
     * create screen offers the choice and the overview's no-boundary state and
     * the edit screen both carry the import onto an area that already exists.
     * NULL until then, which is exactly what {@see hasBoundary()} reads.
     */
    #[ORM\Column(type: 'multipolygon', nullable: true)]
    private ?string $geom = null;

    /**
     * Where the boundary came from — "WDPA", a shapefile's name, "upload". NULL
     * while there is no boundary, because provenance is a fact ABOUT a boundary
     * and an area without one has none to record; it is set by the same import
     * that sets {@see $geom}, never on its own.
     */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $source = null;

    /** IUCN protected-area category (e.g. "II", "VI") — from the WDPA record. */
    #[ORM\Column(length: 8, nullable: true)]
    private ?string $iucnCategory = null;

    /** Year the area was established/gazetted — from the WDPA record. */
    #[ORM\Column(nullable: true)]
    private ?int $establishedYear = null;

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

    /**
     * Whether this area has a gazetted boundary — a question about the geometry
     * column and nothing else. The provenance in {@see getSource()} says where a
     * boundary came from; it never says whether one is present, so a screen that
     * wants "has a boundary" asks this and not that.
     */
    public function hasBoundary(): bool
    {
        return null !== $this->geom && '' !== $this->geom;
    }

    public function getGeom(): ?string
    {
        return $this->geom;
    }

    public function setGeom(string $geom): static
    {
        $this->geom = $geom;

        return $this;
    }

    public function getSource(): ?string
    {
        return $this->source;
    }

    public function setSource(string $source): static
    {
        $this->source = $source;

        return $this;
    }

    public function getIucnCategory(): ?string
    {
        return $this->iucnCategory;
    }

    public function setIucnCategory(?string $iucnCategory): static
    {
        $this->iucnCategory = $iucnCategory;

        return $this;
    }

    public function getEstablishedYear(): ?int
    {
        return $this->establishedYear;
    }

    public function setEstablishedYear(?int $establishedYear): static
    {
        $this->establishedYear = $establishedYear;

        return $this;
    }
}
