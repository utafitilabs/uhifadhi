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

namespace Uhifadhi\Bundle\RegistryBundle\Tests\Integration\Sync;

use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;
use Uhifadhi\Bundle\RegistryBundle\Tests\Integration\Fixtures\RoutedHostKernel;
use Uhifadhi\Bundle\RegistryBundle\Tests\Integration\InstallationTestCase;

/**
 * A WEB REQUEST RECONCILES NOTHING AND OPENS NO CONNECTION ON THE REGISTRY'S
 * ACCOUNT. The catalogue is filled by `registry:sync` in the deploy that
 * migrated the tables; a request that arrives before that — a proxy's liveness
 * probe on a route that reads no database — reaches its controller without the
 * registry asking the database anything.
 */
final class RequestReconcilesNothingTest extends InstallationTestCase
{
    /**
     * The host with routes on it: what a request does is only observable through
     * a router that has something to match.
     */
    protected static function getKernelClass(): string
    {
        return RoutedHostKernel::class;
    }

    public function testAWebRequestNeitherReconcilesNorOpensTheConnection(): void
    {
        $this->install(['sightings']);

        $metadata = $this->em()->getMetadataFactory()->getAllMetadata();
        new SchemaTool($this->em())->dropSchema($metadata);

        $connection = $this->em()->getConnection();
        $connection->close();

        $kernel = self::$kernel;
        \assert(null !== $kernel);
        $response = $kernel->handle(Request::create('/areas/'.Uuid::v7()->toRfc4122()));

        self::assertSame(200, $response->getStatusCode(), 'the page a request came for was answered');
        self::assertFalse($connection->isConnected(), 'the request opened the registry a connection');
    }
}
