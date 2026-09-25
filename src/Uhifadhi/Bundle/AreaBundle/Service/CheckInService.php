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
use Uhifadhi\Bundle\AreaBundle\Api\DutyApiException;
use Uhifadhi\Bundle\AreaBundle\Api\DutyPayload;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\Entity\CheckIn;
use Uhifadhi\Bundle\AreaBundle\Entity\CheckInCorrection;
use Uhifadhi\Bundle\AreaBundle\Entity\CheckInStatus;
use Uhifadhi\Bundle\AreaBundle\Entity\PersonPosition;
use Uhifadhi\Bundle\AreaBundle\Entity\Station;
use Uhifadhi\Bundle\AreaBundle\Enum\PositionSourceEnum;
use Uhifadhi\Bundle\AreaBundle\Repository\CheckInCorrectionRepository;
use Uhifadhi\Bundle\AreaBundle\Repository\CheckInRepository;
use Uhifadhi\Bundle\AreaBundle\Repository\PersonPositionRepository;
use Uhifadhi\Bundle\AreaBundle\Repository\StationRepository;
use Uhifadhi\Contracts\Entity\UserInterface;

/**
 * WRITING WHAT A HANDSET REPORTS — API-CONTRACT.md §13A–§13C.
 *
 * THREE WRITES AND ONE RULE BEHIND ALL OF THEM: a client reference is
 * accepted once. A queue that retries after a timeout must never
 * produce a second claim, a second correction or a second ping, and the
 * handset deletes nothing until it sees the acknowledgement — so
 * everything here is an upsert keyed on what the phone minted.
 *
 * NOTHING REWRITES WHAT WAS SAID. A check-out sets its own two fields; a
 * correction is a row of its own with its own start; a position that
 * arrives late fills a claim that had none and never replaces one that
 * did — a later, better fix is a different moment, not a better version
 * of this one.
 *
 * EVERY WRITE MOVES ITS OWN ROW'S FACTS, IN ITS OWN TRANSACTION. The
 * pings, the claim and the correction are stored and folded into the
 * check-in row by {@see PresenceFactsService} before the commit, so a
 * reader never sees one without the other — "all operations within one
 * unit of work are executed in one transaction", here widened by hand to
 * take the fold in.
 *
 * NO VERDICT IS STORED. Counts, instants, the newest fix and distances
 * are facts; "verified" is {@see PresenceService}'s, judged when somebody
 * reads, against the ring as it stands then.
 *
 * @see https://www.doctrine-project.org/projects/doctrine-orm/en/current/reference/transactions-and-concurrency.html — "Approach 2: Explicitly", `wrapInTransaction()`
 * @see vendor/doctrine/orm/src/EntityManager.php — `wrapInTransaction()` begins, runs, flushes, commits
 */
final readonly class CheckInService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private CheckInRepository $checkIns,
        private CheckInCorrectionRepository $corrections,
        private PersonPositionRepository $positions,
        private StationRepository $stations,
        private CheckInStatusService $statuses,
        /** THE ROW'S FACTS, folded in before each write commits. */
        private PresenceFactsService $facts,
        /**
         * THE WIRE, ASKED SECOND. Every write here flushes first and then
         * publishes the one mark it changed; the publisher never throws, so
         * a hub that is down or absent cannot fail a ping.
         */
        private PresencePublisher $publisher,
    ) {
    }

    /**
     * THE CLAIM — §13A. Upserts on the client's reference: the second
     * arrival of one is the same claim, answered `duplicate`.
     *
     * @param array<string, mixed> $body
     *
     * @return array{0: CheckIn, 1: bool} the claim, and whether this was a repeat
     *
     * @throws DutyApiException
     */
    public function claim(AreaOfInterest $area, UserInterface $ranger, array $body): array
    {
        $clientRef = DutyPayload::requiredString($body, 'clientRef');
        $existing = $this->checkIns->findByRef($area, $clientRef);
        if (null !== $existing) {
            return [$existing, true];
        }

        $occurredAt = DutyPayload::requiredTimestamp($body, 'occurredAt');
        $status = $this->status($area, DutyPayload::requiredString($body, 'status'));
        $station = $this->stationFor($area, $status, DutyPayload::string($body, 'stationUuid'));
        $fix = DutyPayload::fix($body, $occurredAt);

        $checkIn = new CheckIn()
            ->setArea($area)
            ->setPerson($ranger)
            ->setClientRef($clientRef)
            ->setLocalDate(DutyPayload::localDate($body, 'localDate'))
            ->setStatus($status)
            ->setStation($station)
            ->setOccurredAt($occurredAt)
            ->setDeviceId(DutyPayload::requiredString($body, 'deviceId'))
            ->setAppVersion(DutyPayload::requiredString($body, 'appVersion'))
            // A NOTE ONLY WHERE THE KIND TAKES ONE — §13A. A note sent with
            // a kind that takes none is dropped, not refused: the claim is
            // good, and what the app put in a field this status has no use
            // for is the app's business.
            ->setNote($status->getKind()->takesNote() ? DutyPayload::string($body, 'note') : null);

        if (null !== $fix) {
            $checkIn->setPosition($fix['point'])->setPositionAt($fix['at'])->setAccuracyM($fix['accuracy']);
        }

        $this->entityManager->wrapInTransaction(function () use ($checkIn): void {
            $this->entityManager->persist($checkIn);
            $this->entityManager->flush();
            $this->facts->recordClaimFix($checkIn);
        });
        $this->publisher->publish((string) $area->getUuidString(), (string) $ranger->getUuidString());

        return [$checkIn, false];
    }

    /**
     * THE CHECK-OUT, THE BACK-FILL AND THE CORRECTIONS — §13B. All three
     * append; none of them rewrites the claim.
     *
     * @param array<string, mixed> $body
     *
     * @throws DutyApiException
     */
    public function amend(CheckIn $checkIn, array $body): CheckIn
    {
        $endedAt = DutyPayload::timestamp($body, 'endedAt');
        if (null !== $endedAt) {
            $checkIn->setEndedAt($endedAt)->setHandoverNote(DutyPayload::string($body, 'handoverNote'));
        }

        // THE BACK-FILL FILLS A CLAIM THAT HAD NO POSITION and never
        // replaces one that did: a later, better fix is a different
        // moment, and this row is about the tap.
        $fix = DutyPayload::fix($body, $checkIn->getOccurredAt() ?? new \DateTimeImmutable());
        $backFilled = false;
        if (null !== $fix && null === $checkIn->getPosition()) {
            $checkIn->setPosition($fix['point'])->setPositionAt($fix['at'])->setAccuracyM($fix['accuracy']);
            $backFilled = true;
        }

        $area = $checkIn->getArea();
        $corrected = false;
        foreach (DutyPayload::rows($body, 'corrections') as $row) {
            if (null === $area) {
                break;
            }

            $ref = DutyPayload::requiredString($row, 'clientRef');
            if (null !== $this->corrections->findByRef($checkIn, $ref)) {
                // THE SAME CORRECTION TWICE IS ONE CORRECTION.
                continue;
            }

            $status = $this->status($area, DutyPayload::requiredString($row, 'status'));

            $correction = new CheckInCorrection()
                ->setCheckIn($checkIn)
                ->setClientRef($ref)
                ->setEffectiveFrom(DutyPayload::requiredTimestamp($row, 'effectiveFrom'))
                ->setStatus($status)
                ->setStation($this->stationFor($area, $status, DutyPayload::string($row, 'stationUuid')))
                ->setNote($status->getKind()->takesNote() ? DutyPayload::string($row, 'note') : null);

            $checkIn->addCorrection($correction);
            $this->entityManager->persist($correction);
            $corrected = true;
        }

        $this->entityManager->wrapInTransaction(function () use ($checkIn, $backFilled, $corrected): void {
            $this->entityManager->flush();
            // A CORRECTION MAY NAME ANOTHER POST, and every distance is to a
            // post: the watch is re-measured from its own pings. Otherwise a
            // back-filled position is folded in as the claim's fix.
            if ($corrected) {
                $this->facts->remeasure($checkIn);
            } elseif ($backFilled) {
                $this->facts->recordClaimFix($checkIn);
            }
        });

        // A check-out or a correction moves the mark or takes it off; the
        // reading is derived after the flush, so what goes out is what is
        // stored.
        $person = $checkIn->getPerson();
        if (null !== $area && null !== $person) {
            $this->publisher->publish((string) $area->getUuidString(), (string) $person->getUuidString());
        }

        return $checkIn;
    }

    /**
     * THE PINGS — §13C, sent as an array after an offline stretch, which
     * is the normal case.
     *
     * WHAT COMES BACK IS WHAT WAS STORED, so the phone deletes exactly
     * those and keeps the rest. A ping whose watch this area does not
     * hold is not an error that fails the batch: the rest are accepted
     * and the refused one is simply not acknowledged, because a batch
     * that fails whole is a batch that never drains.
     *
     * @param array<string, mixed> $body
     *
     * @return array{accepted: list<string>, duplicate: bool}
     *
     * @throws DutyApiException
     */
    public function ping(AreaOfInterest $area, UserInterface $ranger, array $body): array
    {
        $rows = DutyPayload::rows($body, 'positions');

        $refs = [];
        foreach ($rows as $row) {
            $refs[] = DutyPayload::requiredString($row, 'clientRef');
        }

        $known = $this->positions->knownRefs($area, $refs);
        $accepted = [];
        $duplicate = false;
        /** @var list<PersonPosition> $stored */
        $stored = [];
        /** @var array<string, CheckIn|null> $watches each watch the batch names, looked up once */
        $watches = [];

        foreach ($rows as $row) {
            $ref = DutyPayload::requiredString($row, 'clientRef');
            if (\in_array($ref, $known, true)) {
                // ALREADY HELD. Acknowledged again, so the phone stops
                // carrying it — that is what an idempotent part is for.
                $accepted[] = $ref;
                $duplicate = true;

                continue;
            }

            $checkinRef = DutyPayload::requiredString($row, 'checkinRef');
            if (!\array_key_exists($checkinRef, $watches)) {
                $watches[$checkinRef] = $this->checkIns->findByRef($area, $checkinRef);
            }
            $checkIn = $watches[$checkinRef];
            if (null === $checkIn) {
                continue;
            }

            $recordedAt = DutyPayload::requiredTimestamp($row, 'recordedAt');
            $fix = DutyPayload::fix($row, $recordedAt)
                ?? throw DutyApiException::invalidGeometry('A ping is a position; this one has none.', ['clientRef' => $ref]);

            $this->entityManager->persist($stored[] = new PersonPosition()
                ->setArea($area)
                ->setPerson($ranger)
                ->setCheckIn($checkIn)
                ->setClientRef($ref)
                ->setRecordedAt($recordedAt)
                ->setPosition($fix['point'])
                ->setAccuracyM($fix['accuracy'] ?? 0.0)
                ->setBatteryPct(DutyPayload::int($row, 'batteryPct'))
                ->setSource(PositionSourceEnum::tryFrom(DutyPayload::string($row, 'source') ?? '') ?? PositionSourceEnum::Gps));

            $accepted[] = $ref;
        }

        // THE PINGS AND THE ROWS THEY MOVE, COMMITTED TOGETHER. The fold
        // reads this batch and each watch's row, never the pings before them.
        $this->entityManager->wrapInTransaction(function () use ($stored): void {
            $this->entityManager->flush();
            $this->facts->recordPings($stored);
        });

        // ONE FRAME PER BATCH, carrying the latest fix, and only where the
        // batch stored something: a batch the area already held moved nobody.
        if ([] !== $stored) {
            $this->publisher->publish((string) $area->getUuidString(), (string) $ranger->getUuidString());
        }

        return ['accepted' => $accepted, 'duplicate' => $duplicate];
    }

    /**
     * ONE OF THE AREA'S OWN ANSWERS, by the stable word the handset sent.
     *
     * @throws DutyApiException
     */
    private function status(AreaOfInterest $area, string $key): CheckInStatus
    {
        foreach ($this->statuses->offeredBy($area) as $status) {
            if ($key === $status->getKey()) {
                return $status;
            }
        }

        throw DutyApiException::unsupportedStatus($key);
    }

    /**
     * THE POST, AND ONLY WHERE THE STATUS TAKES ONE. A station sent with
     * a status that is not at-post is dropped rather than refused: the
     * claim is good, and the extra field is the app's business.
     *
     * @throws DutyApiException
     */
    private function stationFor(AreaOfInterest $area, CheckInStatus $status, ?string $stationUuid): ?Station
    {
        if (!$status->getKind()->takesStation()) {
            return null;
        }

        if (null === $stationUuid) {
            throw DutyApiException::invalidPayload(\sprintf('"%s" names a post, so stationUuid is required.', $status->getKey()), ['field' => 'stationUuid', 'status' => $status->getKey()]);
        }

        $station = $this->stations->findOneBy(['uuid' => $stationUuid, 'area' => $area]);
        if (null === $station) {
            throw DutyApiException::unknownStation($stationUuid);
        }

        return $station;
    }
}
