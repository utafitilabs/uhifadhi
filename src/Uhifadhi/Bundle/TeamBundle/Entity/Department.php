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
use Uhifadhi\Bundle\RegistryBundle\Entity\Module;
use Uhifadhi\Bundle\TeamBundle\Entity\Trait\TimestampableTrait;
use Uhifadhi\Bundle\TeamBundle\Entity\Trait\UuidTrait;
use Uhifadhi\Bundle\TeamBundle\Enum\DepartmentScopeEnum;
use Uhifadhi\Bundle\TeamBundle\Enum\DepartmentVisibilityEnum;
use Uhifadhi\Bundle\TeamBundle\Exception\MissingScopeChangeReasonException;
use Uhifadhi\Bundle\TeamBundle\Repository\DepartmentRepository;
use Uhifadhi\Contracts\Entity\AreaInterface;

/**
 * A part of the organization people are placed in — Ecology, Protection Service,
 * Administration.
 *
 * THIS BUNDLE OWNS IT. Treating a department as an organizational lens owned
 * elsewhere makes a position's name unique across the whole installation, which
 * is wrong about the organizations this product is for. Ecology has an Analyst
 * and Protection Service has an Analyst: two different jobs, different
 * permission sets, one word between them. A model that forbids the pair forces
 * one of them to be renamed to something nobody in the building says out
 * loud.
 *
 * So the department lives here, beside the position whose name it scopes,
 * because a constraint cannot be spelled across a module boundary:
 * `unique(department, name)` needs both columns in one table's index, and an
 * entity somewhere else could not supply one of them. Owning it is not a claim
 * on what a department MEANS to the rest of the product — another module wanting
 * departments as a lens over its own records points at this entity the way it
 * points at a person, through the contract, and nothing here has to know.
 *
 * A DEPARTMENT NAME IS UNIQUE PER SCOPE, NOT ORG-WIDE. Two protected areas each
 * run their own Anti-Poaching unit — two units that share a word, the way two
 * departments each carry an Analyst — so a name is unique WITHIN one area, and
 * within the org-wide bucket (a null area), and the two are independent. See the
 * two partial unique indexes on the table below.
 *
 * A DEPARTMENT GRANTS NOTHING TODAY. It is an organizational fact and not an
 * authorization one: every capability an installation has still arrives through
 * a position's permissions. Banding the roster by department re-orders a page;
 * it never changes what anybody may do.
 *
 * IT CARRIES A SCOPE, AND THE SCOPE IS AN AREA OR THE ABSENCE OF ONE. A
 * department belongs either to the whole organization ({@see $area} is null —
 * org-level, spanning every area) or to one area ({@see $area} is set —
 * area-level, belonging to that area alone). The scope is DERIVED from the
 * nullable area and never stored beside it ({@see getScope()}), because a stored
 * scope and a nullable area are two facts that drift apart and then one is lying.
 * The area is referenced through the platform's {@see AreaInterface} contract
 * (published by uhifadhi/contracts) and
 * resolved by whichever package provides the installation's area entity
 * (AreaBundle), so this bundle points at an area without ever requiring
 * an area package — the same arrangement by which a module points at a person.
 *
 * THE SCOPE IS CHANGEABLE, AND EVERY CHANGE IS AUDITED. {@see changeScopeTo()}
 * is the one door: it refuses a change with no reason, moves the area, and
 * appends a {@see DepartmentScopeChange} recording who, when, why, and which way.
 * A scope change reaches further than a rename — confining an org-wide department
 * re-scopes everything under it — so the transition leaves a record the current
 * state could never reconstruct.
 *
 * THE SCOPE IS NOT AUTHORITY, AND THAT IS THE RULING. A department used to
 * confine a Staff member's authority — the voter read
 * `person.position.department.scope` — which made somebody's reach a property
 * of their job title. Reach is recorded against the PERSON now
 * ({@see Placement}), so a department is where somebody is placed and nothing
 * more: it grants nothing, and confining or promoting one moves nobody's
 * authority. The scope-change audit ({@see changeScopeTo()}) still matters,
 * because the scope says which area's ground the department works in.
 */
#[ORM\Entity(repositoryClass: DepartmentRepository::class)]
#[ORM\Table(name: 'team_department')]
// A DEPARTMENT NAME IS UNIQUE PER SCOPE, NOT ACROSS THE INSTALLATION. Two
// partial unique indexes, not one — a plain unique(name, area_id) would let two
// org-level rows share a name, because in SQL two NULLs are distinct. The org
// bucket (area_id IS NULL) is unique on the name alone; each area (area_id IS
// NOT NULL) is unique on the name within it; the two buckets are independent.
#[ORM\UniqueConstraint(name: 'uniq_team_department_name_org', fields: ['name'], options: ['where' => '(area_id IS NULL)'])]
#[ORM\UniqueConstraint(name: 'uniq_team_department_name_area', fields: ['name', 'area'], options: ['where' => '(area_id IS NOT NULL)'])]
#[ORM\HasLifecycleCallbacks]
class Department
{
    use TimestampableTrait;
    use UuidTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null; // @phpstan-ignore property.unusedType (assigned by Doctrine via reflection)

    /**
     * Unique within its scope — its area, or the org-wide bucket if it has none.
     * Two areas may each own a department of one name; two departments in one
     * area, or two org-wide, may not, because there the pair would be the same
     * department entered twice. Enforced by the two partial indexes on the table.
     */
    #[ORM\Column(length: 120)]
    private ?string $name = null;

    /**
     * THE AREA THIS DEPARTMENT BELONGS TO, OR NULL FOR THE WHOLE ORGANIZATION —
     * the one fact the scope is read from. Nullable by construction: the null is
     * org-level, a real state and not an unfinished field.
     *
     * ON DELETE CASCADE, not SET NULL. If the area an area-level department
     * belongs to is removed, the department goes with it rather than silently
     * becoming org-wide — because once the area-aware voter is wired, a SET NULL
     * here would be a privilege escalation nobody asked for, promoting an area
     * team to org-wide authority the moment their area was deleted. Confining and
     * promoting are audited, deliberate acts ({@see changeScopeTo()}); an area
     * deletion must never perform one as a side effect. This mirrors the registry's
     * own AreaModule, whose row also dies with its area.
     */
    #[ORM\ManyToOne(targetEntity: AreaInterface::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?AreaInterface $area = null;

    /**
     * WHAT KIND OF DEPARTMENT THIS IS — Operational, Scientific, Support — or
     * null for one nobody has kinded, which is legal and reads as unkinded.
     *
     * IT IS A LENS AND NOT A SCOPE. A kind bands the register and orders a
     * list; it grants nothing and confines nothing. SET NULL on delete,
     * because removing a word must not remove the thing it described.
     */
    #[ORM\ManyToOne(targetEntity: DepartmentKind::class, inversedBy: 'departments')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?DepartmentKind $kind = null;

    /**
     * THE MODULES THIS DEPARTMENT LEADS WITH — the lens, and the only thing on
     * this entity that says what its people see first.
     *
     * ATTACHING GRANTS NOTHING AND HIDES NOTHING. It re-orders a page: the
     * attached modules lead the department's overview, and the figures on its
     * performance surfaces are those modules' KPIs rolled up through the
     * attachment. No permission moves, and no row becomes unreachable — a module
     * two departments attach is ONE module, listed first for both, reading the
     * same rows. That is what makes a department a lens and not a fence.
     *
     * THE OWNING SIDE IS HERE AND THERE IS NO INVERSE. The join table is this
     * bundle's ({@see Module} is the registry's, and the registry knows nothing
     * of departments, nor may it: the dependency runs one way, team → registry).
     * So a module carries no `departments` collection, and nothing in the
     * registry has to change for a department to point at one.
     *
     * BOTH SIDES CASCADE ON DELETE. An attachment is a statement about a pair,
     * and it means nothing once either half is gone.
     *
     * @var Collection<int, Module>
     */
    #[ORM\ManyToMany(targetEntity: Module::class)]
    #[ORM\JoinTable(name: 'team_department_module')]
    #[ORM\JoinColumn(name: 'department_id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'module_id', onDelete: 'CASCADE')]
    #[ORM\OrderBy(['position' => 'ASC', 'name' => 'ASC'])]
    private Collection $modules;

    /**
     * WHETHER THIS DEPARTMENT IS STILL IN PLAY, and the reason there is no delete.
     *
     * "This unit was folded into another last year" and "this unit never
     * existed" are different facts, and a DELETE makes them one operation — it
     * would take a scope-change history off the ledger and strand the people
     * placed in it. So a department that is no longer run is DEACTIVATED, not
     * removed: the flag goes false, {@see $deactivatedAt} records when, the row
     * stays in the register greyed under an inactive treatment, and
     * {@see reactivate()} is one click. This is the standing fleet rule
     * (deactivate, never delete), the same one {@see User} keeps.
     *
     * Deactivation HIDES a department from the pickers — the create/confine
     * area pickers and the placement editor — so nobody new is placed into a
     * unit that is winding down; it never removes what is already there, and it
     * never gates data. The footprint ("N positions hold this") is shown before
     * the act and INFORMS, never guards: the administrator may proceed.
     */
    #[ORM\Column(options: ['default' => true])]
    private bool $active = true;

    /**
     * WHO OUTSIDE IT SEES IT (#74): Private, as a new department starts —
     * nobody gains sight of anything until somebody chooses it — Listed, or
     * Open. Changed with the scope, with a reason, through
     * {@see \Uhifadhi\Bundle\TeamBundle\Service\DepartmentService::changeVisibility()}.
     */
    #[ORM\Column(length: 20, enumType: DepartmentVisibilityEnum::class, options: ['default' => 'private'])]
    private DepartmentVisibilityEnum $visibility = DepartmentVisibilityEnum::Private;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $deactivatedAt = null;

    /**
     * THE APPEND-ONLY HISTORY OF THIS DEPARTMENT'S SCOPE CHANGES, newest last.
     * Cascade-persist so a change written by {@see changeScopeTo()} is saved with
     * the department in one flush; no orphanRemoval, because an audit line is
     * never taken back.
     *
     * @var Collection<int, DepartmentScopeChange>
     */
    #[ORM\OneToMany(targetEntity: DepartmentScopeChange::class, mappedBy: 'department', cascade: ['persist'])]
    #[ORM\OrderBy(['recordedAt' => 'ASC'])]
    private Collection $scopeChanges;

    /**
     * WHAT THIS DEPARTMENT SAID IT WOULD DO. Soonest to close first, which
     * is the order anybody reads a list of commitments in.
     *
     * @var Collection<int, DepartmentGoal>
     */
    #[ORM\OneToMany(targetEntity: DepartmentGoal::class, mappedBy: 'department', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['closesAt' => 'ASC'])]
    private Collection $goals;

    public function __construct()
    {
        $this->scopeChanges = new ArrayCollection();
        $this->goals = new ArrayCollection();
        $this->modules = new ArrayCollection();
    }

    /** @return Collection<int, DepartmentGoal> */
    public function getGoals(): Collection
    {
        return $this->goals;
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

    /**
     * The modules this department leads with, in catalogue order.
     *
     * @return Collection<int, Module>
     */
    public function getModules(): Collection
    {
        return $this->modules;
    }

    public function hasModule(Module $module): bool
    {
        return $this->modules->contains($module);
    }

    /**
     * Idempotent: two people pressing one chip is one attachment, not a
     * department that leads with the same module twice.
     */
    public function attachModule(Module $module): static
    {
        if (!$this->modules->contains($module)) {
            $this->modules->add($module);
        }

        return $this;
    }

    public function detachModule(Module $module): static
    {
        $this->modules->removeElement($module);

        return $this;
    }

    public function getKind(): ?DepartmentKind
    {
        return $this->kind;
    }

    public function setKind(?DepartmentKind $kind): static
    {
        $this->kind = $kind;

        return $this;
    }

    public function getArea(): ?AreaInterface
    {
        return $this->area;
    }

    /**
     * Sets the area DIRECTLY, without an audit entry — for construction and
     * seeding, where there is no transition to record because there is no prior
     * state. Every change to a LIVE department's scope goes through
     * {@see changeScopeTo()} instead, which is the only path that writes the audit
     * line the model requires.
     */
    public function setArea(?AreaInterface $area): static
    {
        $this->area = $area;

        return $this;
    }

    /**
     * THE SCOPE, DERIVED FROM THE AREA every time it is asked — never a stored
     * column that could disagree with {@see $area}.
     */
    public function getScope(): DepartmentScopeEnum
    {
        return null === $this->area ? DepartmentScopeEnum::Org : DepartmentScopeEnum::Area;
    }

    public function isOrgLevel(): bool
    {
        return null === $this->area;
    }

    public function isAreaLevel(): bool
    {
        return null !== $this->area;
    }

    /**
     * CHANGE THE SCOPE, AND LEAVE A RECORD — the one door for it on a live
     * department.
     *
     * Passing an area confines the department to it (org→area, or a move between
     * areas); passing null promotes it to org-wide (area→org). Either way the
     * change is refused without a reason, the area is moved, and a
     * {@see DepartmentScopeChange} is appended capturing who, when, why and which
     * way — the record the resulting state could never reconstruct on its own.
     *
     * The returned change is added to {@see $scopeChanges}, whose cascade-persist
     * saves it alongside the department; the caller only has to flush. The actor
     * is nullable because a console or seed transition has no signed-in person,
     * and an audit line by nobody is still a truthful audit line.
     *
     * @throws MissingScopeChangeReasonException if the reason is blank
     */
    public function changeScopeTo(?AreaInterface $newArea, ?User $by, string $reason): DepartmentScopeChange
    {
        $reason = trim($reason);
        if ('' === $reason) {
            throw new MissingScopeChangeReasonException();
        }

        $from = $this->getScope();
        $areaLeft = $this->area;
        $this->area = $newArea;
        $to = $this->getScope();

        // The area-side of the transition: the one it was confined to, or the
        // one it left behind on the way to org-wide.
        $change = new DepartmentScopeChange($this, $from, $to, $newArea ?? $areaLeft, $by, $reason);
        $this->scopeChanges->add($change);

        return $change;
    }

    /**
     * @return Collection<int, DepartmentScopeChange>
     */
    public function getScopeChanges(): Collection
    {
        return $this->scopeChanges;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function getVisibility(): DepartmentVisibilityEnum
    {
        return $this->visibility;
    }

    public function setVisibility(DepartmentVisibilityEnum $visibility): static
    {
        $this->visibility = $visibility;

        return $this;
    }

    /**
     * The way a department winds down. Not a delete, and there is no delete:
     * its scope history stays on the ledger, the people placed in it stay
     * placed, and {@see reactivate()} is one click. Idempotent — a
     * department already inactive keeps the moment it first went so, because the
     * "when" of a deactivation is when it happened, not the last time somebody
     * pressed the button.
     */
    public function deactivate(?\DateTimeImmutable $at = null): static
    {
        if ($this->active) {
            $this->active = false;
            $this->deactivatedAt = $at ?? new \DateTimeImmutable();
        }

        return $this;
    }

    public function reactivate(): static
    {
        $this->active = true;
        // Cleared rather than kept: a stale "deactivated at" on a live
        // department is a fact that reads as true and is not.
        $this->deactivatedAt = null;

        return $this;
    }

    public function getDeactivatedAt(): ?\DateTimeImmutable
    {
        return $this->deactivatedAt;
    }

    public function __toString(): string
    {
        return $this->name ?? '';
    }
}
