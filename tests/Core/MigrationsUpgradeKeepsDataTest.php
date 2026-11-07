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
use Uhifadhi\Bundle\TeamBundle\Devkit\TeamContentProvider;
use Uhifadhi\Contracts\Devkit\ContentProviderInterface;

/**
 * THE UPGRADE LOCK: a release can be applied to a database that already has
 * people in it, and every `down()` is real.
 *
 * Two separate promises, and they are not the same promise:
 *
 * 1. **`up()` keeps what is there.** Rows written through the bundles' own
 *    services survive every further `migrate`, which is the only thing an
 *    installation upgrading actually does.
 * 2. **`down()` round-trips the SCHEMA, and only the schema.** These versions
 *    create tables, so their inverse drops them, and dropping a table drops its
 *    rows — a `down()` that pretended otherwise would be a lie. What is proven
 *    here is that the whole history can be unwound to nothing and re-applied
 *    without error, which is what makes `down()` worth shipping: it is the
 *    rehearsal an operator does before an upgrade, not a data-safe undo.
 *
 * The rows are seeded through `TeamContentProvider`, the same declaration devkit
 * materialises in a development installation — real services, real validation,
 * no hand-written INSERT.
 *
 * @see src/Uhifadhi/Bundle/TeamBundle/Devkit/TeamContentProvider.php
 */
#[CoversNothing]
final class MigrationsUpgradeKeepsDataTest extends MigrationsTestCase
{
    public function testSeededRowsSurviveEveryFurtherMigration(): void
    {
        $this->console('doctrine:migrations:migrate', ['--no-interaction' => true, 'version' => 'latest']);

        $this->seedTheTeam();

        self::assertSame(6 + TeamContentProvider::FIELD_STAFF + 4, $this->countPeople());
        self::assertSame(3, $this->countIn('team_department'));
        self::assertSame(7, $this->countIn('team_position'));

        // What an installation taking a new core does. Nothing is outstanding,
        // so nothing runs — and nothing may be lost by asking.
        $this->asAFreshProcess();
        $this->console('doctrine:migrations:migrate', ['--no-interaction' => true, 'version' => 'latest']);

        self::assertSame(6 + TeamContentProvider::FIELD_STAFF + 4, $this->countPeople());
        self::assertSame(3, $this->countIn('team_department'));
        self::assertSame(7, $this->countIn('team_position'));
    }

    public function testTheWholeHistoryUnwindsToNothingAndComesBack(): void
    {
        $this->console('doctrine:migrations:migrate', ['--no-interaction' => true, 'version' => 'latest']);
        $this->seedTheTeam();

        $afterFirstUp = $this->tableNames();

        // Every down(), in reverse. A version whose down() is missing or wrong
        // fails here and nowhere else.
        $this->asAFreshProcess();
        $this->console('doctrine:migrations:migrate', ['--no-interaction' => true, 'version' => '0']);

        self::assertSame(
            ['doctrine_migration_versions'],
            $this->tableNames(),
            'the ledger is the only table a fully unwound installation keeps',
        );

        $this->asAFreshProcess();
        $this->console('doctrine:migrations:migrate', ['--no-interaction' => true, 'version' => 'latest']);

        self::assertSame($afterFirstUp, $this->tableNames());

        // Stated so nobody reads promise 2 as a data guarantee: the tables came
        // back and they came back empty.
        self::assertSame(0, $this->countPeople());
    }

    private function seedTheTeam(): void
    {
        $provider = static::getContainer()->get('test_public.team.devkit.content');
        self::assertInstanceOf(ContentProviderInterface::class, $provider);

        $provider->load();
    }

    private function countPeople(): int
    {
        return $this->countIn('team_user');
    }

    private function countIn(string $table): int
    {
        /** @var list<int|string> $rows */
        $rows = $this->connection->fetchFirstColumn(\sprintf('SELECT count(*) FROM %s', $table));

        return (int) $rows[0];
    }
}
