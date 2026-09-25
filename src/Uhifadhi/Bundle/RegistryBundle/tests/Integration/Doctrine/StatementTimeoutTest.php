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

namespace Uhifadhi\Bundle\RegistryBundle\Tests\Integration\Doctrine;

use Composer\Autoload\ClassLoader;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use Uhifadhi\Bundle\RegistryBundle\Doctrine\StatementTimeoutMiddleware;
use Uhifadhi\Bundle\RegistryBundle\RegistryBundle;
use Uhifadhi\Bundle\RegistryBundle\Tests\Integration\Fixtures\StatementTimeoutKernel;
use Uhifadhi\Bundle\RegistryBundle\Tests\Integration\RegistryKernelTestCase;
use Uhifadhi\Bundle\RegistryBundle\Tests\Integration\TestKernel;

/**
 * THE STATEMENT TIMEOUT, ASKED OF POSTGRESQL: a web request's connection
 * carries it, a console process's connection does not, and a kernel that never
 * set `registry.statement_timeout_ms` has no middleware at all.
 *
 * The web side is a real web server API — PHP's built-in server, `cli-server`
 * — serving a front controller that boots the same kernel, because this suite
 * itself runs under the console's, which is the other half of the rule.
 *
 * @see https://www.php.net/manual/en/features.commandline.webserver.php
 * @see https://www.postgresql.org/docs/current/sql-show.html
 */
#[CoversClass(StatementTimeoutMiddleware::class)]
#[CoversClass(RegistryBundle::class)]
final class StatementTimeoutTest extends RegistryKernelTestCase
{
    private const string LIMIT = '10000';

    private ?string $previousLimit = null;

    protected static function getKernelClass(): string
    {
        return StatementTimeoutKernel::class;
    }

    protected function setUp(): void
    {
        $previous = getenv('DATABASE_STATEMENT_TIMEOUT_MS');
        $this->previousLimit = false === $previous ? null : $previous;

        putenv('DATABASE_STATEMENT_TIMEOUT_MS='.self::LIMIT);
        $_ENV['DATABASE_STATEMENT_TIMEOUT_MS'] = $_SERVER['DATABASE_STATEMENT_TIMEOUT_MS'] = self::LIMIT;
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        if (null === $this->previousLimit) {
            putenv('DATABASE_STATEMENT_TIMEOUT_MS');
            unset($_ENV['DATABASE_STATEMENT_TIMEOUT_MS'], $_SERVER['DATABASE_STATEMENT_TIMEOUT_MS']);
        } else {
            putenv('DATABASE_STATEMENT_TIMEOUT_MS='.$this->previousLimit);
            $_ENV['DATABASE_STATEMENT_TIMEOUT_MS'] = $_SERVER['DATABASE_STATEMENT_TIMEOUT_MS'] = $this->previousLimit;
        }
    }

    public function testTheConnectionCarriesTheMiddleware(): void
    {
        self::assertContains(StatementTimeoutMiddleware::class, $this->middlewareClasses());
    }

    public function testAConsoleProcesssConnectionRunsWithoutTheLimit(): void
    {
        self::assertSame('cli', \PHP_SAPI);
        self::assertSame('0', $this->connection()->fetchOne('SHOW statement_timeout'));
    }

    public function testAWebRequestsConnectionCarriesTheLimit(): void
    {
        // Compile the container here, so the server boots the cache this
        // process wrote rather than racing it.
        self::bootKernel();

        self::assertSame('cli-server 10s', $this->serve());
    }

    public function testAKernelThatNeverSetTheLimitHasNoMiddleware(): void
    {
        $kernel = new TestKernel('test', true);
        $kernel->boot();

        $connection = $kernel->getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);

        $classes = array_map(get_class(...), $connection->getConfiguration()->getMiddlewares());
        $kernel->shutdown();

        self::assertNotContains(StatementTimeoutMiddleware::class, $classes);
    }

    private static function databaseUrl(): string
    {
        $url = $_ENV['UHIFADHI_TEST_DATABASE_URL'] ?? getenv('UHIFADHI_TEST_DATABASE_URL');
        self::assertIsString($url, 'UHIFADHI_TEST_DATABASE_URL is not set');

        return $url;
    }

    private function connection(): Connection
    {
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);

        return $connection;
    }

    /**
     * @return list<class-string>
     */
    private function middlewareClasses(): array
    {
        return array_values(array_map(get_class(...), $this->connection()->getConfiguration()->getMiddlewares()));
    }

    /**
     * One GET through PHP's built-in server, started for this request and
     * stopped after it.
     */
    private function serve(): string
    {
        $probe = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        self::assertNotFalse($probe, (string) $error);
        $address = (string) stream_socket_get_name($probe, false);
        fclose($probe);

        $autoload = \dirname((string) new \ReflectionClass(ClassLoader::class)->getFileName(), 2).'/autoload.php';

        $server = proc_open(
            [\PHP_BINARY, '-S', $address, \dirname(__DIR__).'/Fixtures/web/index.php'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            [
                'UHIFADHI_TEST_AUTOLOAD' => $autoload,
                'UHIFADHI_TEST_DATABASE_URL' => self::databaseUrl(),
                'DATABASE_STATEMENT_TIMEOUT_MS' => self::LIMIT,
                'PATH' => (string) getenv('PATH'),
            ],
        );
        self::assertIsResource($server);

        try {
            $deadline = microtime(true) + 10;
            while (false === $socket = @stream_socket_client('tcp://'.$address, $errno, $error, 0.2)) {
                self::assertLessThan($deadline, microtime(true), 'the built-in server did not start: '.$error);
                usleep(50_000);
            }
            fclose($socket);

            $body = @file_get_contents('http://'.$address.'/', false, stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 30]]));

            return trim((string) $body);
        } finally {
            proc_terminate($server);
            foreach ($pipes as $pipe) {
                fclose($pipe);
            }
            proc_close($server);
        }
    }
}
