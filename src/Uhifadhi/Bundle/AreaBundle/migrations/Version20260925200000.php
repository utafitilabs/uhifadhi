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

namespace Uhifadhi\Bundle\AreaBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * THE FACTS OF A WATCH, ON ITS CHECK-IN ROW — and the index of the open ones.
 *
 * EXPAND, BACKFILL, IN ONE MIGRATION. The columns are added nullable (the
 * count with its default of nought), then filled from the pings already
 * stored — the same statement `area:presence:rebuild` runs — so no reader
 * of an upgraded installation meets a row whose pings arrived before the
 * release. Nothing existing is altered and nothing is dropped.
 *
 * THE OPEN WATCHES GET A PARTIAL INDEX: every live read asks for the rows
 * nobody checked out of, and "a partial index … contains entries only for
 * those table rows that satisfy the predicate".
 *
 * @see https://www.postgresql.org/docs/current/indexes-partial.html
 * @see https://postgis.net/docs/geometry_distance_knn.html — the nearest post, shortlisted by `<->` and decided on the spheroid
 */
final class Version20260925200000 extends AbstractMigration
{
    /** The post a watch is measured against: its last correction's, or the claim's own. */
    private const string WATCH_STATION = <<<'SQL'
        CASE WHEN EXISTS (SELECT 1 FROM duty_checkin_correction k WHERE k.checkin_id = c.id)
             THEN (SELECT k.station_id FROM duty_checkin_correction k WHERE k.checkin_id = c.id ORDER BY k.effective_from DESC, k.id DESC LIMIT 1)
             ELSE c.station_id END
        SQL;

    public function getDescription(): string
    {
        return 'duty_checkin: the watch facts (pings, newest fix, distances, zone, nearest post), backfilled; idx_duty_checkin_open';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE duty_checkin ADD ping_count INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE duty_checkin ADD first_ping_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE duty_checkin ADD last_ping_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE duty_checkin ADD last_fix geometry(POINT,4326) DEFAULT NULL');
        $this->addSql('ALTER TABLE duty_checkin ADD last_fix_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE duty_checkin ADD last_fix_accuracy_m DOUBLE PRECISION DEFAULT NULL');
        $this->addSql('ALTER TABLE duty_checkin ADD last_fix_battery_pct INT DEFAULT NULL');
        $this->addSql('ALTER TABLE duty_checkin ADD last_fix_m DOUBLE PRECISION DEFAULT NULL');
        $this->addSql('ALTER TABLE duty_checkin ADD closest_m DOUBLE PRECISION DEFAULT NULL');
        $this->addSql('ALTER TABLE duty_checkin ADD last_fix_zone_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE duty_checkin ADD nearest_station_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE duty_checkin ADD CONSTRAINT FK_8C5F1D407B1FDD53 FOREIGN KEY (last_fix_zone_id) REFERENCES zone (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE duty_checkin ADD CONSTRAINT FK_8C5F1D4026386119 FOREIGN KEY (nearest_station_id) REFERENCES station (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX idx_duty_checkin_open ON duty_checkin (area_id, occurred_at) WHERE (ended_at IS NULL)');
        $this->addSql('CREATE INDEX IDX_8C5F1D407B1FDD53 ON duty_checkin (last_fix_zone_id)');
        $this->addSql('CREATE INDEX IDX_8C5F1D4026386119 ON duty_checkin (nearest_station_id)');
        $this->addSql('CREATE INDEX idx_duty_checkin_last_fix_sp ON duty_checkin USING gist (last_fix)');

        // THE BACKFILL: every row's facts from the pings it already has.
        $this->addSql(
            'WITH w AS ('
            .'  SELECT c.id, ('.self::WATCH_STATION.') AS station_id FROM duty_checkin c'
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
        );
        $this->addSql(
            'UPDATE duty_checkin c SET'
            .'  last_fix_m = (SELECT ST_Distance(c.last_fix::geography, s.point::geography) FROM station s WHERE s.id = ('.self::WATCH_STATION.')),'
            .'  last_fix_zone_id = (SELECT z.id FROM zone z WHERE z.area_id = c.area_id AND ST_Covers(z.geom, c.last_fix) ORDER BY z.name ASC, z.id ASC LIMIT 1),'
            .'  nearest_station_id = CASE WHEN c.last_fix IS NULL THEN NULL ELSE ('
            .'    SELECT n.id FROM ('
            .'      SELECT s.id, s.point FROM station s WHERE s.area_id = c.area_id AND s.active ORDER BY s.point <-> c.last_fix LIMIT 8'
            .'    ) n ORDER BY ST_Distance(n.point::geography, c.last_fix::geography) ASC, n.id ASC LIMIT 1'
            .'  ) END',
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE duty_checkin DROP CONSTRAINT FK_8C5F1D407B1FDD53');
        $this->addSql('ALTER TABLE duty_checkin DROP CONSTRAINT FK_8C5F1D4026386119');
        $this->addSql('DROP INDEX idx_duty_checkin_open');
        $this->addSql('DROP INDEX IDX_8C5F1D407B1FDD53');
        $this->addSql('DROP INDEX IDX_8C5F1D4026386119');
        $this->addSql('DROP INDEX idx_duty_checkin_last_fix_sp');
        $this->addSql('ALTER TABLE duty_checkin DROP ping_count');
        $this->addSql('ALTER TABLE duty_checkin DROP first_ping_at');
        $this->addSql('ALTER TABLE duty_checkin DROP last_ping_at');
        $this->addSql('ALTER TABLE duty_checkin DROP last_fix');
        $this->addSql('ALTER TABLE duty_checkin DROP last_fix_at');
        $this->addSql('ALTER TABLE duty_checkin DROP last_fix_accuracy_m');
        $this->addSql('ALTER TABLE duty_checkin DROP last_fix_battery_pct');
        $this->addSql('ALTER TABLE duty_checkin DROP last_fix_m');
        $this->addSql('ALTER TABLE duty_checkin DROP closest_m');
        $this->addSql('ALTER TABLE duty_checkin DROP last_fix_zone_id');
        $this->addSql('ALTER TABLE duty_checkin DROP nearest_station_id');
    }
}
