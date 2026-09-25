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

namespace Uhifadhi\Bundle\RegistryBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * THE `default` SCHEDULE'S STATE AND LOCK, AS TABLES: `registry_schedule_state`
 * for the registry's cache pool on the Doctrine DBAL adapter (when the
 * schedule last ran, so a worker that was down runs the last missed run once),
 * and `registry_schedule_lock` for its lock store on the Doctrine DBAL store (so
 * one worker at a time runs the schedule). Both are rows in the installation's
 * database, so they outlive a redeploy and a second worker container shares
 * them. The statements are the ones `doctrine:migrations:diff` writes for the
 * two tables' own schema definitions on PostgreSQL:
 *
 * @see vendor/symfony/cache/Adapter/DoctrineDbalAdapter.php — configureSchema() / configureSchemaTable()
 * @see vendor/symfony/lock/Store/DoctrineDbalStore.php — configureSchema() / configureSchemaTable()
 * https://symfony.com/doc/current/components/cache/adapters/doctrine_dbal_adapter.html
 * https://symfony.com/doc/current/lock.html
 *
 * IF NOT EXISTS, as the queue's table: a table the adapter or the store
 * created itself on first use is already there.
 */
final class Version20260925220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'registry_schedule_state and registry_schedule_lock';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE IF NOT EXISTS registry_schedule_state (item_id VARCHAR(255) NOT NULL, item_data BYTEA NOT NULL, item_lifetime INT DEFAULT NULL, item_time INT NOT NULL, PRIMARY KEY (item_id))');
        $this->addSql('CREATE TABLE IF NOT EXISTS registry_schedule_lock (key_id VARCHAR(64) NOT NULL, key_token VARCHAR(44) NOT NULL, key_expiration INT NOT NULL, PRIMARY KEY (key_id))');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE registry_schedule_lock');
        $this->addSql('DROP TABLE registry_schedule_state');
    }
}
