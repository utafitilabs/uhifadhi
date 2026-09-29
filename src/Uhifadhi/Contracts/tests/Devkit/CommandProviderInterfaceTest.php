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

namespace Uhifadhi\Contracts\Tests\Devkit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Uhifadhi\Contracts\Devkit\CommandDescriptor;
use Uhifadhi\Contracts\Devkit\CommandIo;
use Uhifadhi\Contracts\Devkit\CommandProviderInterface;

/**
 * The command contract has a single verb: commands(), a bag of descriptors the
 * module wants runnable at the console in a dev install. The load-bearing thing
 * to prove is what a descriptor is — data plus a closure, NOT a
 * Symfony\Component\Console\Command — because that is what keeps this package
 * free of a console dependency and keeps the machinery behind the require-dev
 * firewall.
 */
final class CommandProviderInterfaceTest extends TestCase
{
    /**
     * THE SURFACE, TYPED OUT BY HAND. One method, one bag; if a later change
     * widens it, this list disagrees before the release does.
     *
     * @return list<array{string, string}>
     */
    public static function surface(): array
    {
        return [
            ['commands', 'array'],
        ];
    }

    public function testTheContractPublishesExactlyTheMeasuredSurface(): void
    {
        $reflection = new \ReflectionClass(CommandProviderInterface::class);

        self::assertTrue($reflection->isInterface(), 'The command contract is an interface, not a class.');

        $declared = array_map(
            static fn (\ReflectionMethod $method): string => $method->getName(),
            $reflection->getMethods(),
        );
        sort($declared);

        $expected = array_column(self::surface(), 0);
        sort($expected);

        self::assertSame($expected, $declared);
    }

    /**
     * @param non-empty-string $method
     */
    #[DataProvider('surface')]
    public function testEveryMethodTakesNoArgumentsAndReturnsItsDeclaredType(string $method, string $returnType): void
    {
        $reflection = new \ReflectionMethod(CommandProviderInterface::class, $method);

        self::assertSame([], $reflection->getParameters(), \sprintf('%s() takes no arguments.', $method));
        self::assertSame($returnType, (string) $reflection->getReturnType());
    }

    /**
     * FRAMEWORK-FREE, AND THE TEST SAYS SO — and here it is the whole point. A
     * command contract that returned Symfony Commands would drag symfony/console
     * into every module that ships one, and would build Command objects in a
     * production container where devkit is absent. Descriptors keep the console
     * dependency in devkit, where it belongs.
     */
    public function testTheContractImportsNothing(): void
    {
        $file = (new \ReflectionClass(CommandProviderInterface::class))->getFileName();
        self::assertIsString($file);

        $source = file_get_contents($file);
        self::assertIsString($source);

        self::assertSame(0, preg_match('/^use /m', $source), 'The command contract imports nothing: no console, no framework. CommandDescriptor is same-namespace.');
    }

    /**
     * A module contributes a bag of descriptors. devkit wraps each in a real
     * console Command — in dev only — forwarding the tokens and the exit code.
     */
    public function testAProviderContributesDescriptorsDevkitCanRun(): void
    {
        $provider = new class implements CommandProviderInterface {
            public function commands(): array
            {
                return [
                    new CommandDescriptor(
                        'demo:reset',
                        'Wipe and reseed the seed content for a clean slate.',
                        static fn (array $arguments, CommandIo $io): int => [] === $arguments ? 0 : 1,
                    ),
                ];
            }
        };

        $commands = $provider->commands();
        self::assertCount(1, $commands);
        self::assertInstanceOf(CommandDescriptor::class, $commands[0]);
        self::assertSame('demo:reset', $commands[0]->name);

        // devkit's wrapper hands the token tail and the console's streams to the
        // handler, and returns its exit code as the command's exit code.
        $io = new class implements CommandIo {
            public function write(string $line): void
            {
            }

            public function error(string $line): void
            {
            }

            public function readLine(): ?string
            {
                return null;
            }

            public function readSecret(): ?string
            {
                return null;
            }
        };

        $handler = $commands[0]->handler;
        self::assertSame(0, $handler([], $io), 'The handler returns a POSIX exit code — 0 for success.');
        self::assertSame(1, $handler(['--unexpected'], $io));
    }
}
