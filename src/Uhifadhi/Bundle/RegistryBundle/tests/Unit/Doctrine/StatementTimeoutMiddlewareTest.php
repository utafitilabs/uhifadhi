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

namespace Uhifadhi\Bundle\RegistryBundle\Tests\Unit\Doctrine;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Uhifadhi\Bundle\RegistryBundle\Doctrine\StatementTimeoutMiddleware;

/**
 * A connection opened to serve a request starts with the statement timeout; a
 * connection opened by a console process, and any connection when the limit is
 * 0, is the driver's own.
 */
#[CoversClass(StatementTimeoutMiddleware::class)]
final class StatementTimeoutMiddlewareTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function webServerApis(): iterable
    {
        yield 'FrankenPHP' => ['frankenphp'];
        yield 'PHP-FPM' => ['fpm-fcgi'];
        yield 'the built-in server' => ['cli-server'];
        yield 'Apache' => ['apache2handler'];
    }

    #[DataProvider('webServerApis')]
    public function testARequestsConnectionOpensWithTheTimeout(string $sapi): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('exec')->with('SET statement_timeout = 10000');

        $driver = $this->createStub(Driver::class);
        $driver->method('connect')->willReturn($connection);

        $wrapped = new StatementTimeoutMiddleware(10000, $sapi)->wrap($driver);

        self::assertNotSame($driver, $wrapped);
        self::assertSame($connection, $wrapped->connect([]));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function consoleServerApis(): iterable
    {
        yield 'bin/console and the worker' => ['cli'];
        yield 'phpdbg' => ['phpdbg'];
    }

    #[DataProvider('consoleServerApis')]
    public function testAConsoleProcessKeepsTheDriverAsItIs(string $sapi): void
    {
        $driver = $this->createStub(Driver::class);

        self::assertSame($driver, new StatementTimeoutMiddleware(10000, $sapi)->wrap($driver));
    }

    public function testZeroSwitchesTheLimitOff(): void
    {
        $driver = $this->createStub(Driver::class);

        self::assertSame($driver, new StatementTimeoutMiddleware(0, 'frankenphp')->wrap($driver));
    }

    public function testANegativeLimitIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new StatementTimeoutMiddleware(-1, 'frankenphp');
    }

    public function testTheServerApiDefaultsToTheRunningProcesss(): void
    {
        // This suite runs under the console SAPI, so the default is the console.
        $driver = $this->createStub(Driver::class);

        self::assertSame(\PHP_SAPI, 'cli');
        self::assertSame($driver, new StatementTimeoutMiddleware(10000)->wrap($driver));
    }
}
