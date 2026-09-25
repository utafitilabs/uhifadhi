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

namespace Uhifadhi\Bundle\RegistryBundle\EventListener;

use Doctrine\DBAL\Connection as DBALConnection;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;
use Symfony\Bridge\Doctrine\SchemaListener\AbstractSchemaListener;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\Connection;

/**
 * THE QUEUE'S TABLE IS THE SCHEMA'S, WHATEVER THE INSTALLATION CONFIGURES.
 *
 * The registry's migration creates `messenger_messages`. The schema the ORM
 * generates — what `doctrine:schema:update` and `doctrine:migrations:diff`
 * compare the database with — only carries it where a Doctrine transport is
 * configured, because the bridge's own listener adds the table per transport.
 * A kernel with none (every module's test kernel, an installation that has not
 * written the recipe's messenger block) would be offered `DROP TABLE
 * messenger_messages`. So the registry, which owns the table, declares it.
 *
 * THE DEFINITION IS THE TRANSPORT'S, never restated: a transport connection
 * for `table_name: messenger_messages` on the default connection is asked for
 * its own configureSchema(), which leaves a schema that already has the table
 * — the bridge's listener got there first — as it was.
 *
 * A `postGenerateSchema` listener, registered by hand and tagged
 * `doctrine.event_listener` as DoctrineBundle registers the bridge's schema
 * listeners, and built on the bridge's AbstractSchemaListener so the
 * installation's schema filter applies to the table exactly as to theirs.
 * https://symfony.com/bundles/DoctrineBundle/current/event_listeners.html
 *
 * @see vendor/symfony/doctrine-bridge/SchemaListener/MessengerTransportDoctrineSchemaListener.php — the listener this mirrors, one transport at a time
 * @see vendor/doctrine/doctrine-bundle/config/orm.php — the schema listeners, each tagged `doctrine.event_listener` with `event: postGenerateSchema`
 * @see vendor/doctrine/doctrine-bundle/src/DependencyInjection/Compiler/CacheSchemaSubscriberPass.php — a schema listener fed what the container has, not what the configuration names
 * @see vendor/symfony/doctrine-messenger/Transport/Connection.php — configureSchema(), the table and its index
 */
final class QueueTableSchemaListener extends AbstractSchemaListener
{
    public const string TABLE = 'messenger_messages';

    public function __construct(
        private readonly DBALConnection $connection,
    ) {
    }

    public function postGenerateSchema(GenerateSchemaEventArgs $event): void
    {
        if (!class_exists(Connection::class)) {
            return;
        }

        $schema = $event->getSchema();
        if ($schema->hasTable(self::TABLE)) {
            return;
        }

        $forConnection = $event->getEntityManager()->getConnection();
        $transport = new Connection(['table_name' => self::TABLE], $this->connection);
        $isSameDatabase = $this->getIsSameDatabaseChecker($forConnection);

        $configured = $this->filterSchemaChanges($schema, $forConnection, static fn () => $transport->configureSchema($schema, $forConnection, $isSameDatabase));

        // With a DBAL whose schema is edited into a new object, the event is
        // handed the new one, as the bridge's own listener does; with this
        // one, configureSchema() added the table to the same object.
        if ($configured !== $schema) {
            $event->setSchema($configured);
        }
    }
}
