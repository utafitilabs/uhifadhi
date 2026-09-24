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

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Uhifadhi\Core\Tests\Application\Kernel;

/**
 * THE LIVENESS ROUTE READS NO DATABASE, AND THE CORE KEEPS IT THAT WAY.
 *
 * An installation puts `/up` behind its proxy's healthcheck: it answers out of
 * the container alone, so a probe for it reports on the process. The registry
 * is reconciled by `registry:sync`, a command, and never on a request; a bundle
 * that reached for the database on `kernel.request` would turn that promise
 * into its opposite — the healthcheck starts depending on a database the route never
 * reads, and it fails on an installation that has migrated nothing yet, which is
 * exactly when a probe is being watched.
 *
 * The state here is the one before the first migration: an empty schema, and a
 * request for the route that needs none of it. The measurement is the default
 * connection, which must still be closed when the response is ready.
 */
final class LivenessTest extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        while (true) {
            $previous = set_exception_handler(static fn () => null);
            restore_exception_handler();
            if (null === $previous) {
                break;
            }
            restore_exception_handler();
        }
    }

    public function testAProbeForTheLivenessRouteOpensNoConnection(): void
    {
        $kernel = self::bootKernel();

        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        \assert($connection instanceof Connection);

        // No tables at all, the registry's two among them: what an installation
        // is before `doctrine:migrations:migrate`. Emptying it opens the
        // connection, so the measurement starts from a closed one.
        $connection->executeStatement('DROP SCHEMA IF EXISTS public CASCADE');
        $connection->executeStatement('CREATE SCHEMA public');
        $connection->close();

        $response = $kernel->handle(Request::create('/up'));

        self::assertSame(Response::HTTP_NO_CONTENT, $response->getStatusCode());
        self::assertFalse($connection->isConnected(), 'the liveness route was answered out of the database');
    }
}
