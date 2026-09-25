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

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Uhifadhi\Bundle\AreaBundle\Entity\Trait\TimestampableTrait;
use Uhifadhi\Bundle\AreaBundle\Repository\CheckInRepository;
use Uhifadhi\Contracts\Entity\UserInterface;

/**
 * WHAT A RANGER SAID ABOUT THEIR DAY, AT THE MOMENT THEY SAID IT.
 *
 * THE CLAIM IS THE RANGER'S AND THE PROOF IS THE DEVICE'S. This row is
 * the claim: one status, at one moment, from one phone — and, when there
 * was a fix to be had, where the phone was. Whether that position bears
 * the claim out is NOT here and never will be: `verified` and
 * `unverified` are computed on read from the post's catchment, so a
 * catchment corrected next month re-derives every day that used it.
 *
 * WHAT WAS SAID AT 06:08 STAYS WHAT WAS SAID AT 06:08. Nothing that
 * happens afterwards rewrites `status`, `station` or `occurredAt`: a
 * check-out sets its own fields, a correction is a
 * {@see CheckInCorrection} with its own start, and a position that
 * arrives late back-fills only a claim that had none. A claim somebody
 * could edit is a claim nobody can rely on.
 *
 * THE CLIENT MINTS THE IDENTITY. `clientRef` comes from the handset and
 * is unique within the area, so a queue that retries after a timeout can
 * never produce a second check-in — the same rule, and the same reason,
 * as a patrol's client uuid.
 *
 * THE INSTANTS CARRY THEIR OFFSET. A 06:00 watch in +03:00 is not the
 * same moment as 06:00 in UTC and the difference is the whole day; the
 * ranger's own date is stored separately for exactly that reason.
 *
 * THE ROW ALSO CARRIES WHAT THE WATCH REPORTED — facts, never verdicts. How
 * many pings, the first and the last, the last fix and how far it lies from
 * the watch's post, the nearest any fix came to that post, the zone the last
 * fix falls in and the post nearest to it. They are written by
 * {@see \Uhifadhi\Bundle\AreaBundle\Service\PresenceFactsService} in the
 * same transaction as the ping or the claim that changed them, and nowhere
 * else; `area:presence:rebuild` recomputes them from the pings, which are
 * kept. Nothing here says "verified": that is judged when a page reads,
 * against the ring as it stands then.
 *
 * OPEN WATCHES HAVE THEIR OWN INDEX. Every live read asks for the area's
 * watches nobody has checked out of, so a partial index holds exactly those
 * rows and stays the size of the headcount on duty, not of the history:
 * "a partial index … contains entries only for those table rows that satisfy
 * the predicate".
 *
 * @see https://www.postgresql.org/docs/current/indexes-partial.html
 * @see API-CONTRACT.md §13A
 */
#[ORM\Entity(repositoryClass: CheckInRepository::class)]
#[ORM\Table(name: 'duty_checkin')]
#[ORM\UniqueConstraint(name: 'uniq_duty_checkin_ref', columns: ['area_id', 'client_ref'])]
#[ORM\Index(name: 'idx_duty_checkin_day', columns: ['area_id', 'local_date'])]
#[ORM\Index(name: 'idx_duty_checkin_open', columns: ['area_id', 'occurred_at'], options: ['where' => '(ended_at IS NULL)'])]
#[ORM\HasLifecycleCallbacks]
class CheckIn
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null; // @phpstan-ignore property.unusedType (assigned by Doctrine via reflection)

    #[ORM\ManyToOne(targetEntity: AreaOfInterest::class)]
    #[ORM\JoinColumn(name: 'area_id', nullable: false, onDelete: 'CASCADE')]
    private ?AreaOfInterest $area = null;

    /** WHOSE DAY IT IS — the published contract, never somebody's class. */
    #[ORM\ManyToOne(targetEntity: UserInterface::class)]
    #[ORM\JoinColumn(name: 'person_id', nullable: false, onDelete: 'CASCADE')]
    private ?UserInterface $person = null;

    /** The handset's own identity for this claim, unique within the area. */
    #[ORM\Column(name: 'client_ref', length: 64)]
    private string $clientRef = '';

    /**
     * THE RANGER'S DAY, and deliberately not derived from the instants: a
     * 06:00 watch belongs to that date whatever the offset says.
     */
    #[ORM\Column(name: 'local_date', type: 'date_immutable')]
    private ?\DateTimeImmutable $localDate = null;

    /**
     * WHICH OF THE AREA'S OWN ANSWERS THE RANGER GAVE.
     *
     * A relation and not a word, because the words are the area's and
     * they change: "Outside the park" may be renamed next year and last
     * month's check-ins still mean what they meant. What the platform
     * reasons about — a post required, on duty or not — is the status's
     * KIND.
     *
     * NOT NULLABLE AND NOT CASCADED: a status is deactivated rather than
     * deleted, precisely so that a day already recorded keeps its answer.
     */
    #[ORM\ManyToOne(targetEntity: CheckInStatus::class)]
    #[ORM\JoinColumn(name: 'status_id', nullable: false, onDelete: 'RESTRICT')]
    private ?CheckInStatus $status = null;

    /** The post — set for `at_post` and null for every other status. */
    #[ORM\ManyToOne(targetEntity: Station::class)]
    #[ORM\JoinColumn(name: 'station_id', nullable: true, onDelete: 'SET NULL')]
    private ?Station $station = null;

    /** The tap. Always present: the claim is recorded whether or not there is a fix. */
    #[ORM\Column(name: 'occurred_at', type: 'datetimetz_immutable')]
    private ?\DateTimeImmutable $occurredAt = null;

    /**
     * WHERE THE PHONE WAS, or nothing at all. Best-effort: a missing fix
     * never blocks a check-in, and the day derives as unverified rather
     * than as absent.
     */
    #[ORM\Column(type: 'point', nullable: true)]
    private ?string $position = null;

    /** The fix's own clock, which is not the tap's. */
    #[ORM\Column(name: 'position_at', type: 'datetimetz_immutable', nullable: true)]
    private ?\DateTimeImmutable $positionAt = null;

    #[ORM\Column(name: 'accuracy_m', nullable: true)]
    private ?float $accuracyM = null;

    #[ORM\Column(name: 'device_id', length: 64)]
    private string $deviceId = '';

    #[ORM\Column(name: 'app_version', length: 32)]
    private string $appVersion = '';

    /** Only on `outside` and `special`. */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $note = null;

    /** The check-out. Null while the watch is open — or never sent at all. */
    #[ORM\Column(name: 'ended_at', type: 'datetimetz_immutable', nullable: true)]
    private ?\DateTimeImmutable $endedAt = null;

    /** What is handed to the next watch. Absent means nothing is. */
    #[ORM\Column(name: 'handover_note', type: 'text', nullable: true)]
    private ?string $handoverNote = null;

    /** HOW MANY PINGS THE WATCH SENT — the check-in's own position is a fix, not a ping. */
    #[ORM\Column(name: 'ping_count', options: ['default' => 0])]
    private int $pingCount = 0;

    #[ORM\Column(name: 'first_ping_at', type: 'datetimetz_immutable', nullable: true)]
    private ?\DateTimeImmutable $firstPingAt = null; // @phpstan-ignore property.unusedType (assigned by Doctrine from the row the facts statement wrote)

    #[ORM\Column(name: 'last_ping_at', type: 'datetimetz_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastPingAt = null; // @phpstan-ignore property.unusedType (assigned by Doctrine from the row the facts statement wrote)

    /** THE NEWEST FIX, the check-in's own position included: where the phone is now. */
    #[ORM\Column(name: 'last_fix', type: 'point', nullable: true)]
    private ?string $lastFix = null; // @phpstan-ignore property.unusedType (assigned by Doctrine from the row the facts statement wrote)

    #[ORM\Column(name: 'last_fix_at', type: 'datetimetz_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastFixAt = null; // @phpstan-ignore property.unusedType (assigned by Doctrine from the row the facts statement wrote)

    #[ORM\Column(name: 'last_fix_accuracy_m', nullable: true)]
    private ?float $lastFixAccuracyM = null; // @phpstan-ignore property.unusedType (assigned by Doctrine from the row the facts statement wrote)

    #[ORM\Column(name: 'last_fix_battery_pct', nullable: true)]
    private ?int $lastFixBatteryPct = null; // @phpstan-ignore property.unusedType (assigned by Doctrine from the row the facts statement wrote)

    /** Metres from the newest fix to the watch's post; null where there is no post or no fix. */
    #[ORM\Column(name: 'last_fix_m', nullable: true)]
    private ?float $lastFixM = null; // @phpstan-ignore property.unusedType (assigned by Doctrine from the row the facts statement wrote)

    /** THE NEAREST ANY FIX CAME TO THE WATCH'S POST, in metres — what a ring is judged against. */
    #[ORM\Column(name: 'closest_m', nullable: true)]
    private ?float $closestM = null; // @phpstan-ignore property.unusedType (assigned by Doctrine from the row the facts statement wrote)

    /** The zone the newest fix falls in, derived by geometry as a station's is. */
    #[ORM\ManyToOne(targetEntity: Zone::class)]
    #[ORM\JoinColumn(name: 'last_fix_zone_id', nullable: true, onDelete: 'SET NULL')]
    private ?Zone $lastFixZone = null; // @phpstan-ignore property.unusedType (assigned by Doctrine from the row the facts statement wrote)

    /** The working post nearest the newest fix — one nearest-neighbour lookup. */
    #[ORM\ManyToOne(targetEntity: Station::class)]
    #[ORM\JoinColumn(name: 'nearest_station_id', nullable: true, onDelete: 'SET NULL')]
    private ?Station $nearestStation = null; // @phpstan-ignore property.unusedType (assigned by Doctrine from the row the facts statement wrote)

    /**
     * THE SECOND CLAIMS, APPENDED. A correction does not rewrite this row;
     * it says what became true later, from its own moment.
     *
     * @var Collection<int, CheckInCorrection>
     */
    #[ORM\OneToMany(targetEntity: CheckInCorrection::class, mappedBy: 'checkIn', cascade: ['persist'])]
    #[ORM\OrderBy(['effectiveFrom' => 'ASC'])]
    private Collection $corrections;

    public function __construct()
    {
        $this->corrections = new ArrayCollection();
    }

    /**
     * WHAT WAS TRUE AT AN INSTANT — the claim, or whichever correction had
     * come into effect by then.
     *
     * The day's own reading is this at the end of the watch; a duty
     * officer looking at 09:00 asks for 09:00. Either way it is derived
     * here rather than stored anywhere.
     *
     * @return array{status: CheckInStatus|null, station: Station|null, note: string|null}
     */
    public function stateAt(\DateTimeImmutable $instant): array
    {
        $state = ['status' => $this->status, 'station' => $this->station, 'note' => $this->note];

        foreach ($this->corrections as $correction) {
            $from = $correction->getEffectiveFrom();
            if (null !== $from && $from <= $instant) {
                $state = [
                    'status' => $correction->getStatus(),
                    'station' => $correction->getStation(),
                    'note' => $correction->getNote(),
                ];
            }
        }

        return $state;
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

    public function getPerson(): ?UserInterface
    {
        return $this->person;
    }

    public function setPerson(UserInterface $person): static
    {
        $this->person = $person;

        return $this;
    }

    public function getClientRef(): string
    {
        return $this->clientRef;
    }

    public function setClientRef(string $clientRef): static
    {
        $this->clientRef = $clientRef;

        return $this;
    }

    public function getLocalDate(): ?\DateTimeImmutable
    {
        return $this->localDate;
    }

    public function setLocalDate(\DateTimeImmutable $localDate): static
    {
        $this->localDate = $localDate;

        return $this;
    }

    public function getStatus(): ?CheckInStatus
    {
        return $this->status;
    }

    public function setStatus(CheckInStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getStation(): ?Station
    {
        return $this->station;
    }

    public function setStation(?Station $station): static
    {
        $this->station = $station;

        return $this;
    }

    public function getOccurredAt(): ?\DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function setOccurredAt(\DateTimeImmutable $occurredAt): static
    {
        $this->occurredAt = $occurredAt;

        return $this;
    }

    public function getPosition(): ?string
    {
        return $this->position;
    }

    public function setPosition(?string $position): static
    {
        $this->position = $position;

        return $this;
    }

    public function getPositionAt(): ?\DateTimeImmutable
    {
        return $this->positionAt;
    }

    public function setPositionAt(?\DateTimeImmutable $positionAt): static
    {
        $this->positionAt = $positionAt;

        return $this;
    }

    public function getAccuracyM(): ?float
    {
        return $this->accuracyM;
    }

    public function setAccuracyM(?float $accuracyM): static
    {
        $this->accuracyM = $accuracyM;

        return $this;
    }

    public function getDeviceId(): string
    {
        return $this->deviceId;
    }

    public function setDeviceId(string $deviceId): static
    {
        $this->deviceId = $deviceId;

        return $this;
    }

    public function getAppVersion(): string
    {
        return $this->appVersion;
    }

    public function setAppVersion(string $appVersion): static
    {
        $this->appVersion = $appVersion;

        return $this;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function setNote(?string $note): static
    {
        $this->note = $note;

        return $this;
    }

    public function getEndedAt(): ?\DateTimeImmutable
    {
        return $this->endedAt;
    }

    public function setEndedAt(?\DateTimeImmutable $endedAt): static
    {
        $this->endedAt = $endedAt;

        return $this;
    }

    public function getHandoverNote(): ?string
    {
        return $this->handoverNote;
    }

    public function setHandoverNote(?string $handoverNote): static
    {
        $this->handoverNote = $handoverNote;

        return $this;
    }

    public function getPingCount(): int
    {
        return $this->pingCount;
    }

    public function getFirstPingAt(): ?\DateTimeImmutable
    {
        return $this->firstPingAt;
    }

    public function getLastPingAt(): ?\DateTimeImmutable
    {
        return $this->lastPingAt;
    }

    public function getLastFix(): ?string
    {
        return $this->lastFix;
    }

    public function getLastFixAt(): ?\DateTimeImmutable
    {
        return $this->lastFixAt;
    }

    public function getLastFixAccuracyM(): ?float
    {
        return $this->lastFixAccuracyM;
    }

    public function getLastFixBatteryPct(): ?int
    {
        return $this->lastFixBatteryPct;
    }

    public function getLastFixM(): ?float
    {
        return $this->lastFixM;
    }

    public function getClosestM(): ?float
    {
        return $this->closestM;
    }

    public function getLastFixZone(): ?Zone
    {
        return $this->lastFixZone;
    }

    public function getNearestStation(): ?Station
    {
        return $this->nearestStation;
    }

    /** @return Collection<int, CheckInCorrection> */
    public function getCorrections(): Collection
    {
        return $this->corrections;
    }

    public function addCorrection(CheckInCorrection $correction): static
    {
        if (!$this->corrections->contains($correction)) {
            $this->corrections->add($correction);
            $correction->setCheckIn($this);
        }

        return $this;
    }
}
