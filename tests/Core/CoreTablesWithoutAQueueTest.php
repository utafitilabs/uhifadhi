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
use Uhifadhi\Core\Tests\Application\NoQueueKernel;

/**
 * THE CORE'S TABLES ARE THE SCHEMA'S WHATEVER THE INSTALLATION CONFIGURES.
 *
 * The registry's migrations create `messenger_messages`, `registry_schedule_state`
 * and `registry_schedule_lock`. A kernel with no Doctrine transport — every
 * module's test kernel — still has to see them in the schema the ORM
 * generates, or `doctrine:schema:update` and `doctrine:migrations:diff` offer
 * to drop them.
 *
 * @see vendor/doctrine/orm/src/Tools/Console/Command/SchemaTool/UpdateCommand.php — "Nothing to update" when the generated schema and the database agree
 */
#[CoversNothing]
final class CoreTablesWithoutAQueueTest extends MigrationsTestCase
{
    protected static function getKernelClass(): string
    {
        return NoQueueKernel::class;
    }

    public function testAFreshDatabaseMigratedWithoutAQueueLeavesSchemaUpdateNothingToDo(): void
    {
        self::assertFalse(self::getContainer()->has('messenger.transport.async'), 'this installation configures no transport');

        $this->console('doctrine:migrations:migrate', ['--no-interaction' => true, 'version' => 'latest']);

        $sql = $this->console('doctrine:schema:update', ['--dump-sql' => true]);

        self::assertStringNotContainsString('messenger_messages', $sql);
        self::assertStringNotContainsString('registry_schedule_', $sql);
        self::assertStringContainsString('Nothing to update', $sql, $sql);
    }
}
