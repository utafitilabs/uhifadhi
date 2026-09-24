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
 * AN INSTALLATION THAT RUNS MODULES DOES NOT GO DARK ON THEM.
 *
 * THE UPGRADE THIS IS ABOUT. Before pairs, a module declared flat values —
 * `patrols.record`, `incidents.manage`, `roster.plan` — and they were stored
 * in `team_position.permissions` like everybody else's. `grants` is the CORE's
 * column, so no module can write a migration against it: if the core does not
 * carry those values across, an installation upgrades and every module screen
 * refuses everyone at once, with nothing saying why.
 *
 * WHAT IS ASSERTED IS THE RULING, not the SQL: the value is carried across
 * unchanged, `<slug>.read` arrives with it because reading is a declared power
 * now and was not one before, the core's own old values are left to the
 * backfill that already translated them, and running the thing twice writes
 * what running it once wrote.
 *
 * THE ROWS ARE WRITTEN AS AN OLD INSTALLATION'S, by hand and in SQL. That is
 * the one place in this repository where that is right: the shape being
 * restored is one no service can produce any more, because the model it
 * belonged to is gone.
 */
#[CoversNothing]
final class ModuleGrantsAreBackfilledTest extends MigrationsTestCase
{
    private const string VERSION = 'Uhifadhi\Bundle\TeamBundle\Migrations\Version20260921004000';

    /** The last version at which `team_position.permissions` still exists. */
    private const string LAST_WITH_THE_OLD_COLUMN = 'Uhifadhi\Bundle\TeamBundle\Migrations\Version20260922000100';

    /** Every module value is carried across, and brings its concern's read. */
    public function testAModuleValueIsCarriedAcrossAndBringsItsRead(): void
    {
        $this->anUpgradingInstallation([
            'Ranger' => ['patrols.record', 'incidents.manage', 'roster.plan'],
        ]);

        $this->backfill();

        self::assertSame(
            [
                'incidents.manage', 'incidents.read',
                'patrols.read', 'patrols.record',
                'roster.plan', 'roster.read',
            ],
            $this->grantsOf('Ranger'),
        );
    }

    /**
     * A CONCERN KEY MAY CARRY HYPHENS, and the verb is the LAST segment — so
     * `observation-kinds.configure` is one concern and one verb, and its read
     * is `observation-kinds.read` rather than anything shorter.
     */
    public function testTheVerbIsTheLastSegmentAndTheConcernIsTheRest(): void
    {
        $this->anUpgradingInstallation(['Ecologist' => ['observation-kinds.configure']]);

        $this->backfill();

        self::assertSame(
            ['observation-kinds.configure', 'observation-kinds.read'],
            $this->grantsOf('Ecologist'),
        );
    }

    /**
     * THE CORE'S OWN OLD VALUES ARE NOT TOUCHED HERE. They are the ones that
     * did not translate by shape, the backfill before this one has already
     * done them, and the shape rule would grant `area.view` — a pair nothing
     * declares — and `area.read`, which is not the concern's name.
     */
    public function testTheCoresOwnOldValuesAreLeftToTheBackfillThatTranslatedThem(): void
    {
        $this->anUpgradingInstallation(
            ['Coordinator' => ['area.view', 'team.manage']],
            ['Coordinator' => ['areas.read', 'directory.read']],
        );

        $this->backfill();

        self::assertSame(['areas.read', 'directory.read'], $this->grantsOf('Coordinator'));
    }

    /** What the first backfill wrote stays; this one only adds. */
    public function testItAddsToWhatIsAlreadyThereAndRemovesNothing(): void
    {
        $this->anUpgradingInstallation(
            ['Warden' => ['area.view', 'patrols.export']],
            ['Warden' => ['areas.read']],
        );

        $this->backfill();

        self::assertSame(['areas.read', 'patrols.export', 'patrols.read'], $this->grantsOf('Warden'));
    }

    /**
     * AND RUNNING IT TWICE WRITES WHAT RUNNING IT ONCE WROTE, which is what
     * lets an operator re-run a migration they are not sure completed.
     */
    public function testRunningItTwiceWritesTheSameSet(): void
    {
        $this->anUpgradingInstallation(['Ranger' => ['patrols.record']]);

        $this->backfill();
        $once = $this->grantsOf('Ranger');

        $this->asAFreshProcess();
        $this->backfill();

        self::assertSame($once, $this->grantsOf('Ranger'));
    }

    /**
     * A POSITION HOLDING NOTHING IS LEFT ALONE — not rewritten to an empty
     * array by a statement that ran over every row.
     */
    public function testAPositionHoldingNothingIsNotTouched(): void
    {
        $this->anUpgradingInstallation(['Volunteer' => []]);

        $this->backfill();

        self::assertSame([], $this->grantsOf('Volunteer'));
    }

    /**
     * The schema an installation already has, plus positions carrying what an
     * old one carried: flat values in `permissions`, and in `grants` whatever
     * the first backfill made of the core's own.
     *
     * @param array<string, list<string>> $permissions position name => its old flat values
     * @param array<string, list<string>> $grants      position name => the pairs it already holds
     */
    private function anUpgradingInstallation(array $permissions, array $grants = []): void
    {
        // UP TO THE LAST VERSION THAT STILL HAS THE OLD COLUMN, not to
        // latest: the version after it drops `team_position.permissions`, and
        // the shape being restored here is one that column holds. Migrating
        // past the drop and then writing the old rows is not a state any
        // installation was ever in.
        $this->console('doctrine:migrations:migrate', ['--no-interaction' => true, 'version' => self::LAST_WITH_THE_OLD_COLUMN]);

        foreach ($permissions as $name => $values) {
            $this->connection->insert('team_position', [
                'uuid' => $this->connection->fetchOne('SELECT gen_random_uuid()'),
                'name' => $name,
                'permissions' => json_encode($values, \JSON_THROW_ON_ERROR),
                'grants' => json_encode($grants[$name] ?? [], \JSON_THROW_ON_ERROR),
                'allowed_kinds' => json_encode(['organization', 'area'], \JSON_THROW_ON_ERROR),
                'created_at' => '2026-01-01 00:00:00',
                'locked' => 'false',
            ]);
        }

        // `migrate` above has already run this version once and the executor
        // freezes an instance after it runs; a real installation never meets
        // that, because each `migrate` is its own process. The base arranges
        // the same thing, and re-running the version here is precisely what
        // proves it only adds.
        $this->asAFreshProcess();
    }

    /**
     * THE ONE VERSION UNDER TEST, RUN AS THE UPGRADE RUNS IT.
     *
     * `migrate` in {@see anUpgradingInstallation()} brought the schema up to
     * date before the old rows were written, so the ledger already counts this
     * version as applied. Un-counting it is the installation being restored to
     * the state it upgrades FROM — the same hatch the upgrade guide names
     * (`migrations:version --delete`) — and then the version runs over the old
     * rows exactly as it would on the day.
     */
    private function backfill(): void
    {
        $this->connection->executeStatement(
            'DELETE FROM doctrine_migration_versions WHERE version = ?',
            [self::VERSION],
        );

        $this->console('doctrine:migrations:execute', [
            '--no-interaction' => true,
            '--up' => true,
            'versions' => [self::VERSION],
        ]);
    }

    /** @return list<string> */
    private function grantsOf(string $position): array
    {
        $stored = $this->connection->fetchOne('SELECT grants FROM team_position WHERE name = ?', [$position]);
        self::assertIsString($stored);

        /** @var list<string> $pairs */
        $pairs = json_decode($stored, true, 512, \JSON_THROW_ON_ERROR);

        return $pairs;
    }
}
