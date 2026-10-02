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

namespace Uhifadhi\Bundle\TeamBundle\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Uhifadhi\Contracts\Entity\AreaInterface;

/**
 * WHERE ONE PERSON IS PLACED - the second half of every permission check, and
 * the half that belongs to them rather than to their position.
 *
 * TWO DIMENSIONS, AND THEY ARE ANSWERED SEPARATELY:
 *
 *   - THE GROUND: the whole organization, or one or more named areas.
 *   - THE DEPARTMENT: exactly one, the one they belong and report to — and
 *     any number of others they SUPPORT without belonging to them.
 *
 * Ruled 2 Oct 2026: everyone but Super Admins and Admins belongs to exactly
 * one department. An ICT data scientist working for Ecology stays in ICT and
 * supports Ecology; a ranger supporting Ecology belongs to Protection Service.
 * Belonging is the reporting line ({@see belongsTo()}); serving — their own
 * department or one they support — is where their permissions and their
 * modules reach ({@see serves()}).
 *
 * IT FAILS CLOSED, AND THE TWO BOOLEANS ARE WHY. "Everywhere" is a thing
 * somebody decided and wrote down, not the shape an empty list happens to
 * have: a row whose area set failed to save would otherwise read as
 * organization-wide. So the breadth is stored as its own answer, the set is
 * read only when the answer is "named", and a placement that names nothing
 * and claims nothing reaches nowhere.
 *
 * THE AREAS ARE THE PLATFORM'S, not this bundle's: the association points at
 * {@see AreaInterface} and the area bundle resolves it, so the team bundle
 * keeps a real relation to ground it does not own.
 */
#[ORM\Entity]
#[ORM\Table(name: 'team_placement')]
class Placement
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null; // @phpstan-ignore property.unusedType (assigned by Doctrine via reflection)

    /** Whether the ground is the whole organization. False means {@see $areas} is the answer. */
    #[ORM\Column(name: 'whole_organization', options: ['default' => false])]
    private bool $wholeOrganization = false;

    /** @var Collection<int, AreaInterface> */
    #[ORM\ManyToMany(targetEntity: AreaInterface::class)]
    #[ORM\JoinTable(name: 'team_placement_area')]
    #[ORM\JoinColumn(name: 'placement_id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'area_id', onDelete: 'CASCADE')]
    private Collection $areas;

    /**
     * THE ONE DEPARTMENT THEY BELONG TO. Null is an unfinished record — never
     * a mode — and fails closed; deleting the department leaves it null.
     */
    #[ORM\ManyToOne(targetEntity: Department::class)]
    #[ORM\JoinColumn(name: 'department_id', nullable: true, onDelete: 'SET NULL')]
    private ?Department $department = null;

    /**
     * THE DEPARTMENTS THEY SUPPORT, never their own.
     *
     * @var Collection<int, Department>
     */
    #[ORM\ManyToMany(targetEntity: Department::class)]
    #[ORM\JoinTable(name: 'team_placement_support')]
    #[ORM\JoinColumn(name: 'placement_id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'department_id', onDelete: 'CASCADE')]
    #[ORM\OrderBy(['name' => 'ASC'])]
    private Collection $supports;

    public function __construct()
    {
        $this->areas = new ArrayCollection();
        $this->supports = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    // --- the ground -------------------------------------------------------

    public function isWholeOrganization(): bool
    {
        return $this->wholeOrganization;
    }

    /** The whole organization: every area, including ones gazetted after today. */
    public function acrossTheOrganization(): static
    {
        $this->wholeOrganization = true;
        $this->areas->clear();

        return $this;
    }

    /**
     * Named ground, and at least one piece of it.
     *
     * @param list<AreaInterface> $areas
     *
     * @throws \InvalidArgumentException when no area is named
     */
    public function inAreas(array $areas): static
    {
        if ([] === $areas) {
            throw new \InvalidArgumentException('A placement at named areas names at least one. To place somebody everywhere, say so - an empty list is not a way of saying "all".');
        }

        $this->wholeOrganization = false;
        $this->areas->clear();
        foreach ($areas as $area) {
            if (!$this->areas->contains($area)) {
                $this->areas->add($area);
            }
        }

        return $this;
    }

    /**
     * THE NAMED AREAS, or null when the ground is the whole organization -
     * which is the shape the ruling states, and the shape that makes "null
     * means everything" impossible to confuse with "nothing was named".
     *
     * @return list<AreaInterface>|null
     */
    public function getAreas(): ?array
    {
        return $this->wholeOrganization ? null : array_values($this->areas->toArray());
    }

    /**
     * THE SECOND QUESTION: does the placement cover this ground? A null target
     * is "no area in context" - a nav question, a "may I ever?" flag - and is
     * covered by any placement that reaches some ground at all, with the real
     * per-area gate applying once an area is named.
     */
    public function coversArea(?AreaInterface $area): bool
    {
        if ($this->wholeOrganization) {
            return true;
        }

        if ($this->areas->isEmpty()) {
            return false;
        }

        if (null === $area) {
            return true;
        }

        foreach ($this->areas as $placed) {
            if (self::sameArea($placed, $area)) {
                return true;
            }
        }

        return false;
    }

    // --- the department and what it supports -----------------------------

    public function getDepartment(): ?Department
    {
        return $this->department;
    }

    /** Their one department. Moving into a department they supported ends the support. */
    public function inDepartment(Department $department): static
    {
        $this->department = $department;
        foreach ($this->supports->toArray() as $supported) {
            if (self::sameDepartment($supported, $department)) {
                $this->supports->removeElement($supported);
            }
        }

        return $this;
    }

    /**
     * The departments they support — their own is never one of them.
     *
     * @param list<Department> $departments
     */
    public function supporting(array $departments): static
    {
        $this->supports->clear();
        foreach ($departments as $department) {
            if (null !== $this->department && self::sameDepartment($department, $this->department)) {
                continue;
            }
            if (!$this->supports->contains($department)) {
                $this->supports->add($department);
            }
        }

        return $this;
    }

    /**
     * @return list<Department>
     */
    public function getSupports(): array
    {
        return array_values($this->supports->toArray());
    }

    /** Whether this is their own department — the reporting line, not where they help. */
    public function belongsTo(?Department $department): bool
    {
        return null !== $department && null !== $this->department && self::sameDepartment($this->department, $department);
    }

    /**
     * WHETHER THEIR WORK REACHES THIS DEPARTMENT: their own, or one they
     * support. A null department is a concern that belongs to none, and the
     * question does not arise — so it is answered yes. A placement with no
     * department of its own serves nothing.
     */
    public function serves(?Department $department): bool
    {
        if (null === $department) {
            return true;
        }

        if (null === $this->department) {
            return false;
        }

        if (self::sameDepartment($this->department, $department)) {
            return true;
        }

        foreach ($this->supports as $supported) {
            if (self::sameDepartment($supported, $department)) {
                return true;
            }
        }

        return false;
    }

    /**
     * THE DEPARTMENT IN ONE FRAGMENT, for the places a row has one cell for it
     * — a board, a directory facet, a chip: theirs, and what they support.
     */
    public function departmentsLabel(): ?string
    {
        if (null === $this->department) {
            return null;
        }

        $supported = array_values(array_map(static fn (Department $d): string => (string) $d->getName(), $this->supports->toArray()));

        return (string) $this->department->getName().match (\count($supported)) {
            0 => '',
            1 => ' · supports '.$supported[0],
            default => \sprintf(' · supports %s +%d', $supported[0], \count($supported) - 1),
        };
    }

    /**
     * THE GROUND, IN ONE FRAGMENT — what a holders list and a person's row
     * say about where somebody stands.
     *
     * Beside {@see self::departmentsLabel()} because the two are read
     * together and a surface that spelled either itself would spell it
     * differently from the next one.
     */
    public function groundLabel(): string
    {
        if ($this->wholeOrganization) {
            return 'the whole organization';
        }

        $names = array_values(array_map(
            static fn (AreaInterface $a): string => (string) $a->getName(),
            $this->areas->toArray(),
        ));

        return match (\count($names)) {
            0 => 'nowhere',
            1 => $names[0],
            default => \sprintf('%s +%d', $names[0], \count($names) - 1),
        };
    }

    /**
     * WHETHER IT REACHES ANYTHING AT ALL. A placement whose ground is nowhere,
     * or that belongs to no department, is a row that grants its holder
     * nothing, and a surface says so in those words rather than drawing an
     * empty list.
     */
    public function reachesNothing(): bool
    {
        return null === $this->department || (!$this->wholeOrganization && $this->areas->isEmpty());
    }

    /**
     * The two areas are the same one - compared on the public address (uuid)
     * first, then the persistence id, so it holds whether or not the two are
     * the same managed instance.
     */
    private static function sameArea(AreaInterface $one, AreaInterface $other): bool
    {
        $oneUuid = $one->getUuidString();
        $otherUuid = $other->getUuidString();
        if (null !== $oneUuid && null !== $otherUuid) {
            return $oneUuid === $otherUuid;
        }

        $oneId = $one->getId();

        return null !== $oneId && $oneId === $other->getId();
    }

    private static function sameDepartment(Department $one, Department $other): bool
    {
        return $one === $other || (null !== $one->getId() && $one->getId() === $other->getId());
    }
}
