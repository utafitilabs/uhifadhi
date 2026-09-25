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

namespace Uhifadhi\Core\Tests\Core;

/**
 * A STATION THAT STOOD BEFORE ITS ROW SAID WHERE ITS POINT CAME FROM READS AS
 * SURVEYED AFTER THE UPGRADE: every point already stored was placed by
 * somebody, on the form or on the configure page.
 */
final class MigrationBackfillsStationPositionSourceTest extends MigrationsTestCase
{
    private const string SOURCE_VERSION = 'Uhifadhi\\Bundle\\AreaBundle\\Migrations\\Version20260925220000';

    public function testEveryStationAlreadyStoredIsSurveyed(): void
    {
        $this->console('doctrine:migrations:migrate', ['--no-interaction' => true, 'version' => 'latest']);

        $this->asAFreshProcess();
        $this->console('doctrine:migrations:execute', ['versions' => [self::SOURCE_VERSION], '--down' => true, '--no-interaction' => true]);

        $this->connection->executeStatement(
            "INSERT INTO area_of_interest (uuid, name, source, geom, created_at, updated_at) VALUES (gen_random_uuid(), 'Sample Reserve', 'upload',"
            ." ST_GeomFromText('MULTIPOLYGON(((-30 -3.6,-29 -3.6,-29 -2.8,-30 -2.8,-30 -3.6)))', 4326), NOW(), NOW())",
        );
        $this->connection->executeStatement(
            'INSERT INTO station (uuid, name, area_id, point, active, created_at, updated_at)'
            .' SELECT gen_random_uuid(), n, a.id, ST_SetSRID(ST_MakePoint(-29.75, -3.2), 4326), true, NOW(), NOW()'
            ." FROM area_of_interest a, (VALUES ('Eastgate Post'), ('Westgate Post')) v(n)",
        );

        $this->asAFreshProcess();
        $this->console('doctrine:migrations:migrate', ['--no-interaction' => true, 'version' => 'latest']);

        self::assertSame(['surveyed', 'surveyed'], $this->connection->fetchFirstColumn('SELECT position_source FROM station ORDER BY name'));
        self::assertSame('NO', $this->connection->fetchOne(
            "SELECT is_nullable FROM information_schema.columns WHERE table_name = 'station' AND column_name = 'position_source'",
        ), 'the column is contracted to NOT NULL once every row has a value');
    }
}
