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

namespace Uhifadhi\Bundle\RegistryBundle\Doctrine;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;

/**
 * THE LONGEST ONE SQL STATEMENT OF A WEB REQUEST MAY RUN.
 *
 * A request PHP gives up on leaves its query running in PostgreSQL, burning the
 * database for a page nobody waits for any more. A connection opened to serve a
 * request therefore starts with
 *
 *     SET statement_timeout = <registry.statement_timeout_ms>
 *
 * and PostgreSQL cancels a statement that runs past it: "Abort any statement
 * that takes more than the specified amount of time. If this value is
 * specified without units, it is taken as milliseconds. A value of zero (the
 * default) disables the timeout." It is set per session, because "Setting
 * statement_timeout in postgresql.conf is not recommended because it would
 * affect all sessions."
 * https://www.postgresql.org/docs/current/runtime-config-client.html#GUC-STATEMENT-TIMEOUT
 *
 * A DRIVER MIDDLEWARE, the place DBAL documents for a statement every
 * connection runs first, and the shape of DBAL's own session initialiser:
 * https://www.doctrine-project.org/projects/doctrine-dbal/en/current/reference/architecture.html#middlewares
 * https://symfony.com/bundles/DoctrineBundle/current/middlewares.html
 *
 * @see vendor/doctrine/dbal/src/Driver/OCI8/Middleware/InitializeSession.php — the same wrap(), with its own statement
 * @see vendor/doctrine/doctrine-bundle/src/Middleware/IdleConnectionMiddleware.php — a bundle middleware registered as an abstract service tagged `doctrine.middleware`
 *
 * THE CONSOLE IS LEFT OUT. bin/console and the web share the connection and
 * the environment, and the console's statements are the ones that must run to
 * the end: migrations, the queue worker, a rebuild over the whole history.
 * What tells them apart is the server API PHP runs under — `cli` for the
 * console, the web server's own for a request — read when the connection is
 * opened, not when the container is compiled: the image warms its cache from
 * the console and serves requests from that same cache.
 */
final readonly class StatementTimeoutMiddleware implements Middleware
{
    /** The server APIs of a console process; every other one serves requests. */
    private const array CONSOLE_SAPIS = ['cli', 'phpdbg'];

    /**
     * @param int    $milliseconds the limit; 0 switches it off
     * @param string $sapi         the server API of this process
     */
    public function __construct(
        private int $milliseconds,
        private string $sapi = \PHP_SAPI,
    ) {
        if ($milliseconds < 0) {
            throw new \InvalidArgumentException(\sprintf('The statement timeout is a number of milliseconds, 0 or more; %d is not.', $milliseconds));
        }
    }

    public function wrap(Driver $driver): Driver
    {
        if (0 === $this->milliseconds || \in_array($this->sapi, self::CONSOLE_SAPIS, true)) {
            return $driver;
        }

        return new class($driver, $this->milliseconds) extends AbstractDriverMiddleware {
            public function __construct(Driver $wrappedDriver, private readonly int $milliseconds)
            {
                parent::__construct($wrappedDriver);
            }

            public function connect(
                #[\SensitiveParameter]
                array $params,
            ): Connection {
                $connection = parent::connect($params);

                $connection->exec(\sprintf('SET statement_timeout = %d', $this->milliseconds));

                return $connection;
            }
        };
    }
}
