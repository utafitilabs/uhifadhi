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

namespace Uhifadhi\Bundle\AreaBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\Entity\CheckIn;
use Uhifadhi\Bundle\AreaBundle\Model\WatchFacts;
use Uhifadhi\Contracts\Entity\UserInterface;

/**
 * THE CLAIMS, AND THE FACTS EACH ROW CARRIES ABOUT ITS WATCH.
 *
 * The reads here load a day's or an open set's rows with everything a reading
 * needs in the same statement — the person, the post, the status and the
 * corrections with theirs — so a board costs a fixed number of statements
 * whatever its headcount.
 *
 * The fact statements are the only writers of the row's fact columns: one
 * folds a batch of pings in, one folds the check-in's own position in, one
 * derives what follows from the newest fix, and one recomputes every fact
 * from the kept pings. A fold is O(batch) and never reads the pings before
 * it; only the recompute does.
 *
 * @extends ServiceEntityRepository<CheckIn>
 */
class CheckInRepository extends ServiceEntityRepository
{
    /**
     * THE POST A WATCH IS MEASURED AGAINST — the one its last correction
     * names, or the claim's own where nothing corrected it; a correction to a
     * status without a post leaves it with none. The same answer
     * {@see CheckIn::stateAt()} gives at the watch's end, spelt in SQL over
     * the row `c`.
     */
    private const string WATCH_STATION = <<<'SQL'
        CASE WHEN EXISTS (SELECT 1 FROM duty_checkin_correction k WHERE k.checkin_id = c.id)
             THEN (SELECT k.station_id FROM duty_checkin_correction k WHERE k.checkin_id = c.id ORDER BY k.effective_from DESC, k.id DESC LIMIT 1)
             ELSE c.station_id END
        SQL;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CheckIn::class);
    }

    /** One claim, by the identity the handset minted for it. */
    public function findByRef(AreaOfInterest $area, string $clientRef): ?CheckIn
    {
        return $this->findOneBy(['area' => $area, 'clientRef' => $clientRef]);
    }

    /**
     * THIS AREA'S CLAIMS FOR ONE DAY, with everything a reading needs
     * already loaded: the corrections that may have overtaken them and
     * the post each names.
     *
     * @return list<CheckIn>
     */
    public function findForDay(AreaOfInterest $area, \DateTimeImmutable $localDate): array
    {
        /** @var list<CheckIn> $rows */
        $rows = $this->withEverythingAReadingNeeds()
            ->andWhere('c.area = :area')
            ->andWhere('c.localDate = :day')
            ->setParameter('area', $area)
            ->setParameter('day', $localDate->setTime(0, 0))
            ->orderBy('c.occurredAt', 'ASC')
            ->getQuery()
            ->getResult();

        return $rows;
    }

    /**
     * EVERY WATCH IN THIS AREA NOBODY HAS CHECKED OUT OF — what "live"
     * is made of.
     *
     * OPEN MEANS NO CHECK-OUT, and that is all this can know. A watch
     * whose ROSTERED end has passed is closed too, but the rostered end
     * is the roster's to say and this bundle does not hold it; the
     * caller that has a roster drops those, exactly as the day read
     * does.
     *
     * NOT KEYED BY DAY, on purpose: a night watch that began yesterday
     * and has not been closed is somebody who is on duty now, and a
     * query filtered to today's date would lose them at midnight. The
     * partial index `idx_duty_checkin_open` holds exactly these rows.
     *
     * @return list<CheckIn>
     */
    public function findOpenIn(AreaOfInterest $area): array
    {
        /** @var list<CheckIn> $rows */
        $rows = $this->withEverythingAReadingNeeds()
            ->andWhere('c.area = :area')
            ->andWhere('c.endedAt IS NULL')
            ->setParameter('area', $area)
            ->orderBy('c.occurredAt', 'ASC')
            ->getQuery()
            ->getResult();

        return $rows;
    }

    /** One person's claim for one day, where they made one. */
    public function findForPersonOnDay(AreaOfInterest $area, UserInterface $person, \DateTimeImmutable $localDate): ?CheckIn
    {
        return $this->findOneBy([
            'area' => $area,
            'person' => $person,
            'localDate' => $localDate->setTime(0, 0),
        ]);
    }

    /**
     * ONE PERSON'S OPEN WATCHES IN AN AREA — what their live mark is made of.
     *
     * @return list<CheckIn>
     */
    public function findOpenForPerson(AreaOfInterest $area, string $personUuid): array
    {
        /** @var list<CheckIn> $rows */
        $rows = $this->withEverythingAReadingNeeds()
            ->andWhere('c.area = :area')
            ->andWhere('c.endedAt IS NULL')
            ->andWhere('person.uuid = :person')
            ->setParameter('area', $area)
            ->setParameter('person', $personUuid)
            ->orderBy('c.occurredAt', 'ASC')
            ->getQuery()
            ->getResult();

        return $rows;
    }

    /**
     * ONE PERSON'S CLAIMS FOR ONE DAY, oldest first.
     *
     * @return list<CheckIn>
     */
    public function findForPersonDay(AreaOfInterest $area, string $personUuid, \DateTimeImmutable $localDate): array
    {
        /** @var list<CheckIn> $rows */
        $rows = $this->withEverythingAReadingNeeds()
            ->andWhere('c.area = :area')
            ->andWhere('c.localDate = :day')
            ->andWhere('person.uuid = :person')
            ->setParameter('area', $area)
            ->setParameter('day', $localDate->setTime(0, 0))
            ->setParameter('person', $personUuid)
            ->orderBy('c.occurredAt', 'ASC')
            ->getQuery()
            ->getResult();

        return $rows;
    }

    /**
     * THE FACTS OF THESE ROWS, keyed by check-in id — one statement for a
     * whole board.
     *
     * @param list<CheckIn> $checkIns
     *
     * @return array<int, WatchFacts>
     */
    public function findFactsFor(array $checkIns): array
    {
        $ids = [];
        foreach ($checkIns as $checkIn) {
            if (null !== $checkIn->getId()) {
                $ids[] = $checkIn->getId();
            }
        }
        if ([] === $ids) {
            return [];
        }

        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            <<<'SQL'
                SELECT id, ping_count, last_ping_at, ST_Y(last_fix) AS lat, ST_X(last_fix) AS lon, last_fix_at,
                       last_fix_accuracy_m, last_fix_battery_pct, last_fix_m, closest_m
                FROM duty_checkin WHERE id IN (:ids)
                SQL,
            ['ids' => $ids],
            ['ids' => ArrayParameterType::INTEGER],
        );

        $facts = [];
        foreach ($rows as $row) {
            /** @var array{id: int, ping_count: int, last_ping_at: string|null, lat: float|numeric-string|null, lon: float|numeric-string|null, last_fix_at: string|null, last_fix_accuracy_m: float|numeric-string|null, last_fix_battery_pct: int|null, last_fix_m: float|numeric-string|null, closest_m: float|numeric-string|null} $row */
            $facts[(int) $row['id']] = new WatchFacts(
                pings: (int) $row['ping_count'],
                lastPingAt: null === $row['last_ping_at'] ? null : new \DateTimeImmutable($row['last_ping_at']),
                latitude: null === $row['lat'] ? null : (float) $row['lat'],
                longitude: null === $row['lon'] ? null : (float) $row['lon'],
                lastFixAt: null === $row['last_fix_at'] ? null : new \DateTimeImmutable($row['last_fix_at']),
                lastFixAccuracyM: null === $row['last_fix_accuracy_m'] ? null : (float) $row['last_fix_accuracy_m'],
                lastFixBatteryPct: null === $row['last_fix_battery_pct'] ? null : (int) $row['last_fix_battery_pct'],
                lastFixM: null === $row['last_fix_m'] ? null : (float) $row['last_fix_m'],
                closestM: null === $row['closest_m'] ? null : (float) $row['closest_m'],
            );
        }

        return $facts;
    }

    /**
     * FOLD A BATCH OF STORED PINGS INTO THEIR WATCH'S ROW — one statement,
     * reading only the batch.
     *
     * The count grows by the batch; the first and last ping widen to take it
     * in; the nearest any fix came to the post is the nearer of the row's and
     * the batch's; the newest fix is replaced only by a newer one (a ping on
     * the same second as the one held wins, and a later-stored one wins over
     * an earlier), so a batch drained late never moves a phone backwards.
     * Arithmetic in SQL, on the row's own values, so two batches of one watch
     * cannot lose each other's count.
     *
     * @param list<int> $pingIds the ids of the pings just stored for this watch
     */
    public function foldPings(CheckIn $checkIn, array $pingIds): void
    {
        if ([] === $pingIds || null === $checkIn->getId()) {
            return;
        }

        $newer = 'c.last_fix_at IS NULL OR latest.recorded_at >= c.last_fix_at';

        $this->getEntityManager()->getConnection()->executeStatement(
            'WITH b AS ('
            .'  SELECT p.id, p.position, p.recorded_at, p.accuracy_m, p.battery_pct'
            .'  FROM duty_position p WHERE p.checkin_id = :checkin AND p.id IN (:ids)'
            .'), post AS ('
            .'  SELECT s.point FROM duty_checkin c JOIN station s ON s.id = ('.self::WATCH_STATION.') WHERE c.id = :checkin'
            .'), agg AS ('
            .'  SELECT COUNT(*) AS n, MIN(b.recorded_at) AS first_at, MAX(b.recorded_at) AS last_at,'
            .'         (SELECT MIN(ST_Distance(f.position::geography, post.point::geography)) FROM b f, post) AS closest'
            .'  FROM b'
            .'), latest AS ('
            .'  SELECT b.position, b.recorded_at, b.accuracy_m, b.battery_pct FROM b ORDER BY b.recorded_at DESC, b.id DESC LIMIT 1'
            .')'
            .' UPDATE duty_checkin c SET'
            .'  ping_count = c.ping_count + agg.n,'
            .'  first_ping_at = LEAST(c.first_ping_at, agg.first_at),'
            .'  last_ping_at = GREATEST(c.last_ping_at, agg.last_at),'
            .'  closest_m = LEAST(c.closest_m, agg.closest),'
            .'  last_fix = CASE WHEN '.$newer.' THEN latest.position ELSE c.last_fix END,'
            .'  last_fix_accuracy_m = CASE WHEN '.$newer.' THEN latest.accuracy_m ELSE c.last_fix_accuracy_m END,'
            .'  last_fix_battery_pct = CASE WHEN '.$newer.' THEN latest.battery_pct ELSE c.last_fix_battery_pct END,'
            .'  last_fix_at = CASE WHEN '.$newer.' THEN latest.recorded_at ELSE c.last_fix_at END'
            .' FROM agg, latest'
            .' WHERE c.id = :checkin',
            ['checkin' => $checkIn->getId(), 'ids' => $pingIds],
            ['ids' => ArrayParameterType::INTEGER],
        );

        $this->deriveFromTheNewestFix('c.id = :checkin', ['checkin' => $checkIn->getId()]);
    }

    /**
     * FOLD THE CHECK-IN'S OWN POSITION IN — at the claim, or when a position
     * is back-filled onto a claim that had none. It is a fix and not a ping:
     * it counts toward the nearest and the newest, never toward the tally. It
     * is the newest only when strictly newer than what the row holds, so a
     * ping on the same second keeps its place.
     */
    public function foldClaimFix(CheckIn $checkIn): void
    {
        if (null === $checkIn->getId()) {
            return;
        }

        $newer = 'c.last_fix_at IS NULL OR COALESCE(c.position_at, c.occurred_at) > c.last_fix_at';

        $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE duty_checkin c SET'
            .'  closest_m = LEAST(c.closest_m, (SELECT ST_Distance(c.position::geography, s.point::geography) FROM station s WHERE s.id = ('.self::WATCH_STATION.'))),'
            .'  last_fix = CASE WHEN '.$newer.' THEN c.position ELSE c.last_fix END,'
            .'  last_fix_accuracy_m = CASE WHEN '.$newer.' THEN c.accuracy_m ELSE c.last_fix_accuracy_m END,'
            .'  last_fix_battery_pct = CASE WHEN '.$newer.' THEN NULL ELSE c.last_fix_battery_pct END,'
            .'  last_fix_at = CASE WHEN '.$newer.' THEN COALESCE(c.position_at, c.occurred_at) ELSE c.last_fix_at END'
            .' WHERE c.id = :checkin AND c.position IS NOT NULL',
            ['checkin' => $checkIn->getId()],
        );

        $this->deriveFromTheNewestFix('c.id = :checkin', ['checkin' => $checkIn->getId()]);
    }

    /**
     * RECOMPUTE EVERY FACT FROM THE KEPT PINGS, for the rows the filters name
     * — the one reader of the raw positions besides a person's own trail.
     * Idempotent: the same pings give the same row, however often it runs.
     *
     * @return int how many check-in rows were recomputed
     */
    public function rebuildFacts(?AreaOfInterest $area = null, ?\DateTimeImmutable $from = null, ?\DateTimeImmutable $until = null, ?CheckIn $checkIn = null): int
    {
        $where = ['TRUE'];
        $parameters = [];
        if (null !== $area) {
            $where[] = 'c.area_id = :area';
            $parameters['area'] = $area->getId();
        }
        if (null !== $from) {
            $where[] = 'c.local_date >= :from';
            $parameters['from'] = $from->format('Y-m-d');
        }
        if (null !== $until) {
            $where[] = 'c.local_date <= :until';
            $parameters['until'] = $until->format('Y-m-d');
        }
        if (null !== $checkIn) {
            $where[] = 'c.id = :checkin';
            $parameters['checkin'] = $checkIn->getId();
        }
        $predicate = implode(' AND ', $where);

        $rows = $this->getEntityManager()->getConnection()->executeStatement(
            'WITH w AS ('
            .'  SELECT c.id, ('.self::WATCH_STATION.') AS station_id FROM duty_checkin c WHERE '.$predicate
            .'), fixes AS ('
            .'  SELECT p.checkin_id AS id, p.position, p.recorded_at AS at, p.accuracy_m AS accuracy, p.battery_pct AS battery, 0 AS claim, p.id AS seq'
            .'  FROM duty_position p JOIN w ON w.id = p.checkin_id'
            .'  UNION ALL'
            .'  SELECT c.id, c.position, COALESCE(c.position_at, c.occurred_at), c.accuracy_m, NULL::int, 1, 0'
            .'  FROM duty_checkin c JOIN w ON w.id = c.id WHERE c.position IS NOT NULL'
            .'), tally AS ('
            .'  SELECT f.id, COUNT(*) FILTER (WHERE f.claim = 0) AS n,'
            .'         MIN(f.at) FILTER (WHERE f.claim = 0) AS first_at, MAX(f.at) FILTER (WHERE f.claim = 0) AS last_at,'
            .'         MIN(ST_Distance(f.position::geography, s.point::geography)) AS closest'
            .'  FROM fixes f JOIN w ON w.id = f.id LEFT JOIN station s ON s.id = w.station_id'
            .'  GROUP BY f.id'
            .'), latest AS ('
            .'  SELECT DISTINCT ON (f.id) f.id, f.position, f.at, f.accuracy, f.battery'
            .'  FROM fixes f ORDER BY f.id, f.at DESC, f.claim ASC, f.seq DESC'
            .')'
            .' UPDATE duty_checkin c SET'
            .'  ping_count = COALESCE(t.n, 0), first_ping_at = t.first_at, last_ping_at = t.last_at, closest_m = t.closest,'
            .'  last_fix = l.position, last_fix_at = l.at, last_fix_accuracy_m = l.accuracy, last_fix_battery_pct = l.battery'
            .' FROM w LEFT JOIN tally t ON t.id = w.id LEFT JOIN latest l ON l.id = w.id'
            .' WHERE c.id = w.id',
            $parameters,
        );

        $this->deriveFromTheNewestFix($predicate, $parameters);

        return (int) $rows;
    }

    /**
     * WHAT FOLLOWS FROM THE NEWEST FIX: how far it lies from the watch's post,
     * the zone it falls in and the working post nearest to it.
     *
     * THE ZONE IS ASKED THE WAY A STATION'S IS — `ST_Covers`, the boundary
     * included, and on an edge two zones share the one whose name sorts
     * first, then the lowest id ({@see StationRepository::updateDerivedZones()}).
     *
     * THE NEAREST POST IS ONE NEAREST-NEIGHBOUR LOOKUP on the stations' point
     * index: "the <-> operator … when used in the ORDER BY clause provides
     * index-assisted nearest-neighbor result sets". On geometry that ordering
     * is by degrees, so it shortlists eight and the spheroid decides between
     * them, as the same page recommends for a true-distance answer.
     *
     * @see https://postgis.net/docs/geometry_distance_knn.html
     *
     * @param array<string, mixed> $parameters
     */
    private function deriveFromTheNewestFix(string $predicate, array $parameters): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE duty_checkin c SET'
            .'  last_fix_m = (SELECT ST_Distance(c.last_fix::geography, s.point::geography) FROM station s WHERE s.id = ('.self::WATCH_STATION.')),'
            .'  last_fix_zone_id = (SELECT z.id FROM zone z WHERE z.area_id = c.area_id AND ST_Covers(z.geom, c.last_fix) ORDER BY z.name ASC, z.id ASC LIMIT 1),'
            .'  nearest_station_id = CASE WHEN c.last_fix IS NULL THEN NULL ELSE ('
            .'    SELECT n.id FROM ('
            .'      SELECT s.id, s.point FROM station s WHERE s.area_id = c.area_id AND s.active ORDER BY s.point <-> c.last_fix LIMIT 8'
            .'    ) n ORDER BY ST_Distance(n.point::geography, c.last_fix::geography) ASC, n.id ASC LIMIT 1'
            .'  ) END'
            .' WHERE '.$predicate,
            $parameters,
        );
    }

    /**
     * A CLAIM WITH EVERYTHING A READING NEEDS, in the one statement: the
     * person it belongs to, the post and the status it named, and the
     * corrections that may have overtaken it with theirs.
     */
    private function withEverythingAReadingNeeds(): QueryBuilder
    {
        return $this->createQueryBuilder('c')
            ->addSelect('person', 'corrections', 'correctionStatus', 'correctionStation', 'station', 'status')
            ->leftJoin('c.person', 'person')
            ->leftJoin('c.corrections', 'corrections')
            ->leftJoin('corrections.status', 'correctionStatus')
            ->leftJoin('corrections.station', 'correctionStation')
            ->leftJoin('c.station', 'station')
            ->leftJoin('c.status', 'status');
    }
}
