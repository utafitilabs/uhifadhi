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

use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * THE DRIFT LOCK: what the migrations build IS what the entities describe.
 *
 * An installer's proof that a release is whole is `doctrine:migrations:diff`
 * saying it has nothing to write, so that is the command this asks — not a
 * private comparison of two schemas that could disagree with it.
 *
 * @see vendor/doctrine/migrations/src/Tools/Console/Command/DiffCommand.php
 *      — a diff with nothing to generate is `NoChangesDetected`, reported as
 *      "No changes detected in your mapping information." and exit status 0.
 *
 * An entity gains a column and nobody writes the migration: this test fails.
 */
#[CoversNothing]
final class MigrationsCoverSchemaTest extends MigrationsTestCase
{
    public function testAnEmptyDatabaseMigratedLeavesNothingForDiffToWrite(): void
    {
        $this->console('doctrine:migrations:migrate', ['--no-interaction' => true, 'version' => 'latest']);

        $before = $this->shippedVersionFiles();

        // `--allow-empty-diff` turns "there is nothing to write" from a thrown
        // exception into a message and exit status 0. Without it the command an
        // installer runs to CONFIRM a release is whole exits non-zero when it
        // is — which is worth knowing, and is why the flag is named in the
        // installation notes as well as here.
        // @see vendor/doctrine/migrations/src/Tools/Console/Command/DiffCommand.php
        $diff = $this->console('doctrine:migrations:diff', [
            '--no-interaction' => true,
            '--allow-empty-diff' => true,
            '--namespace' => 'Uhifadhi\\Bundle\\AreaBundle\\Migrations',
        ]);

        // A diff that found work WROTE it. Take the file back out before
        // asserting, so a drift failure leaves the repository as it was and the
        // SQL it would have written is in the message instead.
        $written = array_diff($this->shippedVersionFiles(), $before);
        $drift = '';
        foreach ($written as $file) {
            $drift .= (string) file_get_contents($file);
            unlink($file);
        }

        self::assertStringContainsString(
            'No changes detected in your mapping information.',
            $diff,
            'the shipped migrations and the shipped entities have drifted apart; diff wanted to write:'.\PHP_EOL.$drift,
        );
    }

    /**
     * @return list<string>
     */
    private function shippedVersionFiles(): array
    {
        $files = glob(\dirname(__DIR__, 2).'/src/Uhifadhi/Bundle/*/migrations/*.php') ?: [];
        sort($files);

        return $files;
    }

    public function testTheMigrationsBuildEveryTableTheCoreOwnsAndNoOther(): void
    {
        $this->console('doctrine:migrations:migrate', ['--no-interaction' => true, 'version' => 'latest']);

        self::assertSame(
            [
                'area_module',
                'area_of_interest',
                'doctrine_migration_versions',
                'duty_checkin',
                'duty_checkin_correction',
                'duty_checkin_status',
                'duty_position',
                'module',
                // PostGIS's own, brought by the extension migration zero
                // creates — not the core's, and not something a diff will ever
                // offer to drop: the schema filter hides it.
                'posting',
                'spatial_ref_sys',
                'station',
                'station_event',
                'team_api_token',
                'team_department',
                'team_department_goal',
                'team_department_kind',
                'team_department_module',
                'team_department_period_figure',
                'team_department_scope_change',
                'team_period_figure',
                'team_placement',
                'team_placement_area',
                'team_placement_department',
                'team_position',
                'team_settings',
                'team_user',
                'widget_custom_preset',
                'widget_preference',
                'zone',
                'zone_event',
                'zone_import',
            ],
            $this->tableNames(),
        );
    }

    public function testMigrationZeroPutsPostgisThereBeforeAGeometryColumnNeedsIt(): void
    {
        $plan = $this->plannedVersions();

        self::assertNotSame([], $plan);
        self::assertSame(
            'Uhifadhi\\Bundle\\AreaBundle\\Migrations\\Version20260101000000',
            $plan[0],
            'the extension has to exist before geometry(MULTIPOLYGON,4326) can be declared',
        );

        $this->console('doctrine:migrations:migrate', ['--no-interaction' => true, 'version' => 'latest']);

        self::assertSame(
            ['postgis'],
            $this->connection->fetchFirstColumn("SELECT extname FROM pg_extension WHERE extname = 'postgis'"),
        );
    }

    /**
     * The order the versions run in is not the order their timestamps suggest
     * unless somebody makes it so: a version's identity is its FULL CLASS NAME,
     * and the shipped comparator is a `strcmp` over that name.
     *
     * @see vendor/doctrine/migrations/src/FilesystemMigrationsRepository.php
     *      — `new Version($migrationClassName)`
     * @see vendor/doctrine/migrations/src/Version/AlphabeticalComparator.php
     *
     * Left alone, `Uhifadhi\Bundle\ShellBundle\…` sorts before
     * `Uhifadhi\Bundle\TeamBundle\…`, and `widget_preference.user_id` references
     * a `team_user` that does not exist yet. This states the order the foreign
     * keys actually demand.
     */
    public function testTheVersionsAreOrderedByTheirTimestampAndNotByTheirNamespace(): void
    {
        self::assertSame(
            [
                'Uhifadhi\\Bundle\\AreaBundle\\Migrations\\Version20260101000000',
                'Uhifadhi\\Bundle\\AreaBundle\\Migrations\\Version20260101000100',
                'Uhifadhi\\Bundle\\AreaBundle\\Migrations\\Version20260101000110',
                'Uhifadhi\\Bundle\\AreaBundle\\Migrations\\Version20260101000120',
                'Uhifadhi\\Bundle\\AreaBundle\\Migrations\\Version20260101000130',
                'Uhifadhi\\Bundle\\AreaBundle\\Migrations\\Version20260101000140',
                'Uhifadhi\\Bundle\\AreaBundle\\Migrations\\Version20260101000160',
                'Uhifadhi\\Bundle\\RegistryBundle\\Migrations\\Version20260101000200',
                'Uhifadhi\\Bundle\\TeamBundle\\Migrations\\Version20260101000300',
                'Uhifadhi\\Bundle\\TeamBundle\\Migrations\\Version20260101000310',
                'Uhifadhi\\Bundle\\ShellBundle\\Migrations\\Version20260101000400',
                'Uhifadhi\\Bundle\\AreaBundle\\Migrations\\Version20260101000500',
                'Uhifadhi\\Bundle\\RegistryBundle\\Migrations\\Version20260919000100',
                'Uhifadhi\\Bundle\\TeamBundle\\Migrations\\Version20260920000100',
                'Uhifadhi\\Bundle\\TeamBundle\\Migrations\\Version20260920000200',
                'Uhifadhi\\Bundle\\TeamBundle\\Migrations\\Version20260920000300',
                'Uhifadhi\\Bundle\\AreaBundle\\Migrations\\Version20260921000100',
                'Uhifadhi\\Bundle\\AreaBundle\\Migrations\\Version20260921000200',
                'Uhifadhi\\Bundle\\TeamBundle\\Migrations\\Version20260921000300',
                'Uhifadhi\\Bundle\\TeamBundle\\Migrations\\Version20260921000400',
                'Uhifadhi\\Bundle\\TeamBundle\\Migrations\\Version20260921000500',
                'Uhifadhi\\Bundle\\TeamBundle\\Migrations\\Version20260921001000',
                'Uhifadhi\\Bundle\\TeamBundle\\Migrations\\Version20260921002000',
                'Uhifadhi\\Bundle\\TeamBundle\\Migrations\\Version20260921003000',
                'Uhifadhi\\Bundle\\TeamBundle\\Migrations\\Version20260921004000',
                'Uhifadhi\\Bundle\\TeamBundle\\Migrations\\Version20260922000100',
                'Uhifadhi\\Bundle\\RegistryBundle\\Migrations\\Version20260924000100',
                'Uhifadhi\\Bundle\\TeamBundle\\Migrations\\Version20260924000100',
                'Uhifadhi\\Bundle\\TeamBundle\\Migrations\\Version20260924000200',
            ],
            $this->plannedVersions(),
        );
    }
}
