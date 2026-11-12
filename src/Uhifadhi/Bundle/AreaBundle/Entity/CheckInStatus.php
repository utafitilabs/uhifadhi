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
use Uhifadhi\Bundle\AreaBundle\Enum\CheckInStatusKind;
use Uhifadhi\Bundle\AreaBundle\Repository\CheckInStatusRepository;

/**
 * ONE OF THE ANSWERS A RANGER MAY GIVE WHEN THEY CHECK IN — the area's
 * own word for it.
 *
 * THE WORDS ARE THE ORGANIZATION'S. One park's rangers are "At post",
 * "On escort" and "Court"; another's are "At post", "Outside the park"
 * and "Unfit". Four statuses hard-coded in a phone would make every
 * organization speak the same four, and the first one that needed a
 * fifth would have to wait for a store release. This is the same
 * vocabulary pattern the patrol types already follow: written here,
 * delivered at the next sync, and nothing in the app hard-codes a word.
 *
 * THE KEY IS STABLE AND THE LABEL IS NOT. A handset sends the key, and
 * an area may rename "Outside the park" to "Off park" without changing
 * what last month's check-ins meant.
 *
 * WHAT THE PLATFORM REASONS ABOUT IS THE KIND, never the label: whether
 * a post is required, and whether the person is on duty. See
 * {@see CheckInStatusKind}.
 *
 * DEACTIVATED, NEVER DELETED. A status stops being offered and every
 * check-in that used it still reads: deleting one would rewrite days
 * that have already happened.
 *
 * @see API-CONTRACT.md §13A, §13D
 */
#[ORM\Entity(repositoryClass: CheckInStatusRepository::class)]
#[ORM\Table(name: 'duty_checkin_status')]
#[ORM\UniqueConstraint(name: 'uniq_duty_status_key', columns: ['area_id', 'status_key'])]
#[ORM\HasLifecycleCallbacks]
class CheckInStatus
{
    use TimestampableTrait;
    use UuidTrait;

    /**
     * WHAT AN AREA STARTS WITH — the four the handset already speaks, so
     * an installation that configures nothing is an installation whose
     * app keeps working.
     *
     * @var list<array{key: string, label: string, kind: CheckInStatusKind}>
     */
    public const array DEFAULTS = [
        ['key' => 'at_post', 'label' => 'At post', 'kind' => CheckInStatusKind::AtPost],
        ['key' => 'unfit', 'label' => 'Unfit for duty', 'kind' => CheckInStatusKind::NotWorking],
        ['key' => 'outside', 'label' => 'Outside the park', 'kind' => CheckInStatusKind::WorkingElsewhere],
        ['key' => 'special', 'label' => 'Special assignment', 'kind' => CheckInStatusKind::Special],
    ];

    /**
     * WHAT A KEY MAY BE: what the handset sends and every check-in carries,
     * so it is written once and kept to a shape any client can store.
     */
    public const string KEY_PATTERN = '/^[a-z][a-z0-9_]{0,31}$/';

    public const int LABEL_LENGTH = 64;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null; // @phpstan-ignore property.unusedType (assigned by Doctrine via reflection)

    #[ORM\ManyToOne(targetEntity: AreaOfInterest::class)]
    #[ORM\JoinColumn(name: 'area_id', nullable: false, onDelete: 'CASCADE')]
    private ?AreaOfInterest $area = null;

    /** The stable word the handset sends. Never renamed, unlike the label. */
    #[ORM\Column(name: 'status_key', length: 32)]
    private string $key = '';

    /** What a ranger reads on the button. */
    #[ORM\Column(length: self::LABEL_LENGTH)]
    private string $label = '';

    #[ORM\Column(length: 24, enumType: CheckInStatusKind::class)]
    private CheckInStatusKind $kind = CheckInStatusKind::AtPost;

    /** The order they are offered in — the area's own arrangement. */
    #[ORM\Column]
    private int $position = 0;

    /** A status stops being offered; it is never deleted. */
    #[ORM\Column]
    private bool $active = true;

    /** Whether choosing this status means naming a post. */
    public function takesStation(): bool
    {
        return $this->kind->takesStation();
    }

    /** Whether somebody who chose it is on duty today. */
    public function countsAsPresent(): bool
    {
        return $this->kind->countsAsPresent();
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getKey(): string
    {
        return $this->key;
    }

    public function setKey(string $key): static
    {
        $this->key = $key;

        return $this;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(string $label): static
    {
        $this->label = $label;

        return $this;
    }

    public function getKind(): CheckInStatusKind
    {
        return $this->kind;
    }

    public function setKind(CheckInStatusKind $kind): static
    {
        $this->kind = $kind;

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

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): static
    {
        $this->active = $active;

        return $this;
    }
}
