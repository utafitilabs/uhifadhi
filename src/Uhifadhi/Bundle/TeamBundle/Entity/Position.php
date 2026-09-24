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

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Uhifadhi\Bundle\TeamBundle\Entity\Trait\TimestampableTrait;
use Uhifadhi\Bundle\TeamBundle\Entity\Trait\UuidTrait;
use Uhifadhi\Bundle\TeamBundle\Exception\UnknownGrantException;
use Uhifadhi\Bundle\TeamBundle\Repository\PositionRepository;
use Uhifadhi\Contracts\Access\Grant;
use Uhifadhi\Contracts\Access\ScopeKind;
use Uhifadhi\Contracts\Access\Verb;

/**
 * WHAT HOLDING IT GRANTS - the concerns and verbs, and which kinds of
 * placement it allows. It carries no department.
 *
 * PERMISSIONS ARE SET ON POSITIONS; REACH IS SET ON THE PERSON. A position is
 * written once and held by as many people as the work needs - every Sergeant
 * grants the same things. Where each of those Sergeants may exercise it is a
 * separate fact, recorded against each person ({@see Placement}). So renaming
 * a position or adding a verb changes everybody who holds it at once, and
 * moving somebody between areas changes nobody but them.
 *
 * A DEPARTMENT IS A PLACEMENT, NOT AN OWNER. A position belonging to a
 * department was the wrong shape: a data scientist supporting Ecology and
 * Protection is still ONE position, placed against two departments, and under
 * the old shape they needed either a second position or a department of
 * convenience invented to hold them. So the department left this class, and
 * with it the department-scoped name: THE NAME IS UNIQUE ACROSS THE WHOLE
 * ORGANIZATION. There is one Sergeant, not one per department, and a reader
 * of a person's record never has to ask which one.
 *
 * ITS ONE LEVER OVER REACH IS {@see $allowedKinds}, and it is a lever over
 * KINDS, never over values. A position meant to be local cannot be widened by
 * mistake when somebody is assigned, because the wider kind is not on offer;
 * one meant to be organization-wide cannot be quietly narrowed for one person,
 * for the same reason. The two kinds a PLACEMENT can be made at are
 * organization and area - the ground - because that is what a placement says.
 * Department is the placement's other dimension and is not gated here, and
 * {@see ScopeKind::Own} is a thing a CONCERN offers, not a way of placing
 * somebody.
 */
#[ORM\Entity(repositoryClass: PositionRepository::class)]
#[ORM\Table(name: 'team_position')]
#[ORM\UniqueConstraint(name: 'uniq_team_position_name', fields: ['name'])]
#[ORM\HasLifecycleCallbacks]
class Position
{
    use TimestampableTrait;
    use UuidTrait;

    /** The kinds a placement can actually be made at. See the class banner. */
    public const array PLACEMENT_KINDS = [ScopeKind::Organization, ScopeKind::Area];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null; // @phpstan-ignore property.unusedType (assigned by Doctrine via reflection)

    #[ORM\Column(length: 120)]
    private ?string $name = null;

    /**
     * HOW MANY PEOPLE MAY HOLD IT, and null is unlimited rather than unknown.
     *
     * Some posts are singular and some are not: Head of Protection is one
     * seat, Data Analyst is many. Assigning somebody to a full position is
     * refused, and the refusal names who already holds it - which is the
     * whole point of storing a number rather than a flag.
     */
    #[ORM\Column(name: 'seat_count', nullable: true)]
    private ?int $seatCount = null;

    /**
     * WHICH KINDS OF PLACEMENT IT ALLOWS - organization, area, or both.
     *
     * Stored as the scope kinds' own values so the column reads, and never
     * empty: a position nobody can be placed at is a position nobody can
     * hold.
     *
     * @var list<string>
     */
    #[ORM\Column(name: 'allowed_kinds', type: Types::JSON)]
    private array $allowedKinds = [ScopeKind::Area->value];

    /**
     * THE (CONCERN, VERB) PAIRS IT GRANTS, in the order they were granted,
     * each written the one way {@see Grant} spells a pair.
     *
     * PLAIN STRINGS RATHER THAN PARSED VALUES, because a pair whose module
     * has been uninstalled has to survive being stored, read back and revoked
     * without anything being able to resolve it.
     *
     * @var list<string>
     */
    #[ORM\Column(type: Types::JSON, options: ['default' => '[]'])]
    private array $grants = [];

    /**
     * THE DAY THIS POST FELL EMPTY, and null while somebody stands in it.
     *
     * NULL ALSO MEANS UNKNOWN, for a post that was already empty before
     * the day was recorded: a surface reads that as "unknown" rather than
     * starting the clock at the upgrade, which would make an
     * eighteen-month vacancy look like a fresh one. Which of the two a
     * null is, is answered by whether anybody holds the post.
     */
    #[ORM\Column(name: 'vacant_since', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $vacantSince = null;

    /**
     * THE DAY THIS POSITION WAS RETIRED, and null while it is in use.
     *
     * POSITIONS ARE RETIRED, NEVER DELETED. Everything a position granted
     * keeps its history, the holders it had keep theirs, and the row can
     * come back — so closing one is a stamp rather than a DELETE. A retired
     * position cannot be assigned, is absent from the picker, and is drawn
     * greyed in the register rather than hidden: an administrator looking
     * for a name that is "already taken" has to be able to find it.
     */
    #[ORM\Column(name: 'retired_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $retiredAt = null;

    /** Reserved: a position whose label is fixed. Unused today. */
    #[ORM\Column]
    private bool $locked = false;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getVacantSince(): ?\DateTimeImmutable
    {
        return $this->vacantSince;
    }

    public function setVacantSince(?\DateTimeImmutable $vacantSince): static
    {
        $this->vacantSince = $vacantSince;

        return $this;
    }

    public function getRetiredAt(): ?\DateTimeImmutable
    {
        return $this->retiredAt;
    }

    public function isRetired(): bool
    {
        return null !== $this->retiredAt;
    }

    /** Closing a position, which is a stamp — the row and its history stay. */
    public function retire(\DateTimeImmutable $when): static
    {
        $this->retiredAt = $when;

        return $this;
    }

    /** Reopening one. A retired position is assignable again the moment the stamp is cleared. */
    public function reinstate(): static
    {
        $this->retiredAt = null;

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

    /** Null is unlimited. */
    public function getSeatCount(): ?int
    {
        return $this->seatCount;
    }

    /**
     * @throws \InvalidArgumentException when the count is not a number of seats
     */
    public function setSeatCount(?int $seatCount): static
    {
        if (null !== $seatCount && $seatCount < 1) {
            throw new \InvalidArgumentException(\sprintf('A position has at least one seat, or unlimited seats. %d is neither - to close a position, deactivate it rather than seating nobody.', $seatCount));
        }

        $this->seatCount = $seatCount;

        return $this;
    }

    public function hasUnlimitedSeats(): bool
    {
        return null === $this->seatCount;
    }

    /** @return list<ScopeKind> */
    public function getAllowedKinds(): array
    {
        $kinds = [];
        foreach ($this->allowedKinds as $value) {
            $kind = ScopeKind::tryFrom($value);
            if (null !== $kind) {
                $kinds[] = $kind;
            }
        }

        return $kinds;
    }

    /**
     * @param list<ScopeKind> $kinds
     *
     * @throws \InvalidArgumentException when a kind is not one a placement can be made at
     */
    public function setAllowedKinds(array $kinds): static
    {
        if ([] === $kinds) {
            throw new \InvalidArgumentException('A position allows at least one kind of placement. One that allows none is one nobody can be placed at, and therefore one nobody can hold.');
        }

        $values = [];
        foreach ($kinds as $kind) {
            if (!\in_array($kind, self::PLACEMENT_KINDS, true)) {
                throw new \InvalidArgumentException(\sprintf('A placement is made at the organization or at named areas, so "%s" is not a kind a position can allow. Department is the placement\'s other dimension and is not gated by the position; "own" is a scope a concern offers, not a way of placing somebody.', $kind->value));
            }
            $values[$kind->value] = true;
        }

        $this->allowedKinds = array_keys($values);

        return $this;
    }

    public function allows(ScopeKind $kind): bool
    {
        return \in_array($kind->value, $this->allowedKinds, true);
    }

    /**
     * THE RAW GRANTED PAIRS - the only reading surface, because it is the
     * only one that can tell the truth. A parsed accessor drops every pair
     * whose module has been uninstalled on the floor, and those are exactly
     * the ones an administrator needs to see in order to revoke them.
     *
     * @return list<string>
     */
    public function getGrantValues(): array
    {
        return $this->grants;
    }

    /**
     * THE ONLY WRITE PATH FOR PAIRS, AND IT VALIDATES.
     *
     * The live catalogue is a REQUIRED second argument rather than something
     * this entity fetches, because an entity that reached for a service to
     * validate itself would be an entity you cannot construct in a test - and
     * because making it required is what stops the unvalidated call from
     * existing at all.
     *
     * WHAT IS ACCEPTED is the live catalogue UNION the pairs this position
     * already holds. The catalogue half makes an unknown NEW pair fail
     * loudly; the already-held half is prune-not-purge, because editing a
     * position is not a migration and a module uninstalled last week must not
     * have its grants silently stripped by an unrelated save.
     *
     * @param list<string> $values    what the position should hold after this call
     * @param list<string> $catalogue every pair this installation currently declares
     *
     * @throws UnknownGrantException when a submitted pair is neither declared nor already held
     */
    public function setGrantValues(array $values, array $catalogue): static
    {
        $accepted = [...$catalogue, ...$this->grants];

        $unknown = array_values(array_unique(array_filter(
            $values,
            static fn (string $value): bool => !\in_array($value, $accepted, true),
        )));

        if ([] !== $unknown) {
            throw new UnknownGrantException($unknown);
        }

        $this->grants = array_values(array_unique($values));

        return $this;
    }

    public function hasGrant(Grant $grant): bool
    {
        return \in_array((string) $grant, $this->grants, true);
    }

    /** The first of the three questions a check asks: does the position grant it? */
    public function grantsVerbOn(string $concern, Verb $verb): bool
    {
        return $this->hasGrant(Grant::of($concern, $verb));
    }

    public function isLocked(): bool
    {
        return $this->locked;
    }

    public function setLocked(bool $locked): static
    {
        $this->locked = $locked;

        return $this;
    }

    public function __toString(): string
    {
        return $this->name ?? '';
    }
}
