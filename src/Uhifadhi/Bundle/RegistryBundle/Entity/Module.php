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

namespace Uhifadhi\Bundle\RegistryBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use Uhifadhi\Bundle\RegistryBundle\Entity\Trait\TimestampableTrait;
use Uhifadhi\Bundle\RegistryBundle\Entity\Trait\UuidTrait;
use Uhifadhi\Bundle\RegistryBundle\Enum\ModuleCategory;
use Uhifadhi\Bundle\RegistryBundle\Enum\ModuleStatus;
use Uhifadhi\Bundle\RegistryBundle\Repository\ModuleRepository;

/**
 * ONE ROW OF THE CATALOGUE: a module this deployment has, as the deployment
 * records it. Whether a given area has it switched on is a different question
 * with a different table — see {@see AreaModule}.
 *
 * A row is not authored. It is written by the seed from an installed provider's
 * own answers, and the provider owns them: rename a module between releases and
 * the next seed follows, because the seed upserts by SLUG, which is the module's
 * identity.
 *
 * THE TABLE IS DELIBERATELY UNPREFIXED. Every module prefixes its tables
 * with its domain word, and by that rule this would be `registry_module`. It is
 * not: renaming it is a production migration on the platform's most-referenced
 * table, bought with nothing but consistency — and the prefix rule exists to
 * stop two bundles colliding on a common noun, which cannot happen to the
 * runtime every bundle registers with.
 *
 * Column names are written out for the same reason the table name is: this
 * schema already exists, and a bundle must produce it identically whichever
 * naming strategy its host configures.
 */
#[ORM\Entity(repositoryClass: ModuleRepository::class)]
#[ORM\Table(name: 'module')]
#[ORM\HasLifecycleCallbacks]
class Module
{
    use TimestampableTrait;
    use UuidTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'id')]
    private ?int $id = null; // @phpstan-ignore property.unusedType (assigned by Doctrine via reflection)

    /** Routing + identity key, e.g. "sightings" → /areas/{uuid}/sightings. */
    #[ORM\Column(name: 'slug', length: 40, unique: true)]
    private ?string $slug = null;

    #[ORM\Column(name: 'name', length: 80)]
    private ?string $name = null;

    /** What the module is, in one line, or null for a module that says nothing beyond its name. */
    #[ORM\Column(name: 'description', length: 160, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(name: 'category', enumType: ModuleCategory::class)]
    private ModuleCategory $category = ModuleCategory::Operations;

    #[ORM\Column(name: 'status', enumType: ModuleStatus::class)]
    private ModuleStatus $status = ModuleStatus::Live;

    /** The upstream data the module draws on, shown as a provenance pill. Never null: no provenance line is an empty one. */
    #[ORM\Column(name: 'data_source', length: 80)]
    private string $dataSource = '';

    /** Always on an area, never reorderable or removable. A flag a provider declares — the registry knows no slug that carries it. */
    #[ORM\Column(name: 'pinned')]
    private bool $pinned = false;

    /** Catalogue display order (the order new areas receive modules in). */
    #[ORM\Column(name: 'position')]
    private int $position = 0;

    /** The icon name a module asks for, or null for whatever the host draws by default. Rendering is not the registry's to decide. */
    #[ORM\Column(name: 'icon', length: 40, nullable: true)]
    private ?string $icon = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSlug(): ?string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): static
    {
        $this->slug = $slug;

        return $this;
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

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getCategory(): ModuleCategory
    {
        return $this->category;
    }

    public function setCategory(ModuleCategory $category): static
    {
        $this->category = $category;

        return $this;
    }

    public function getStatus(): ModuleStatus
    {
        return $this->status;
    }

    public function setStatus(ModuleStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getDataSource(): string
    {
        return $this->dataSource;
    }

    public function setDataSource(string $dataSource): static
    {
        $this->dataSource = $dataSource;

        return $this;
    }

    public function isPinned(): bool
    {
        return $this->pinned;
    }

    public function setPinned(bool $pinned): static
    {
        $this->pinned = $pinned;

        return $this;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): static
    {
        $this->position = $position;

        return $this;
    }

    public function getIcon(): ?string
    {
        return $this->icon;
    }

    public function setIcon(?string $icon): static
    {
        $this->icon = $icon;

        return $this;
    }

    public function __toString(): string
    {
        return $this->name ?? '';
    }
}
