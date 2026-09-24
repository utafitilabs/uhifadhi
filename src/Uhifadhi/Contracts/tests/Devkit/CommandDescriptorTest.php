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

use PHPUnit\Framework\TestCase;
use Uhifadhi\Contracts\Devkit\CommandDescriptor;
use Uhifadhi\Contracts\Devkit\CommandIo;

/**
 * A descriptor is the console-free unit a module hands to devkit: a name, a help
 * line, and the closure that does the work. Like every declared row,
 * every field is required — a maintenance command with no name cannot be
 * registered and one with no help line is a blank row in `list` — so a
 * descriptor that has not thought about them does not compile.
 */
final class CommandDescriptorTest extends TestCase
{
    public function testItCarriesTheNameHelpAndHandler(): void
    {
        $descriptor = new CommandDescriptor(
            'patrol:demo:reset',
            'Wipe and reseed the patrol demo content.',
            static fn (array $arguments, CommandIo $io): int => 0,
        );

        self::assertSame('patrol:demo:reset', $descriptor->name);
        self::assertSame('Wipe and reseed the patrol demo content.', $descriptor->description);

        $handler = $descriptor->handler;
        self::assertSame(0, $handler([], self::io()));
    }

    /**
     * The handler is a closure over the token tail returning an exit code — the
     * Unix process contract, and nothing from symfony/console. devkit passes the
     * arguments a person typed and uses the returned int as the command's exit
     * status.
     */
    public function testTheHandlerReceivesTheArgumentTailAndReturnsAnExitCode(): void
    {
        $descriptor = new CommandDescriptor(
            'demo:seed',
            'Seed demo content, optionally scaled by a --count argument.',
            static fn (array $arguments, CommandIo $io): int => \count($arguments),
        );

        $handler = $descriptor->handler;
        self::assertSame(0, $handler([], self::io()));
        self::assertSame(2, $handler(['--count=10', '--fresh'], self::io()));
    }

    /**
     * AND IT RECEIVES THE STREAMS ALONGSIDE THE TAIL. Without them a handler's
     * only way to say what it did is \STDOUT directly — output that ignores
     * `--quiet` and that nothing can capture — so the channel belongs to the
     * process contract rather than being something devkit hands out by
     * convention.
     */
    public function testTheHandlerAlsoReceivesTheStreamsItSpeaksThrough(): void
    {
        $descriptor = new CommandDescriptor(
            'demo:seed',
            'Seed demo content and say what was seeded.',
            static function (array $arguments, CommandIo $io): int {
                $io->write('seeded');
                $io->error('one slice was already there');

                return 0;
            },
        );

        $io = self::io();
        $handler = $descriptor->handler;

        self::assertSame(0, $handler([], $io));
        self::assertSame(['seeded'], $io->out);
        self::assertSame(['one slice was already there'], $io->err);
    }

    public function testEveryFieldIsRequired(): void
    {
        $reflection = new \ReflectionClass(CommandDescriptor::class);
        $constructor = $reflection->getConstructor();

        self::assertNotNull($constructor);
        self::assertSame(3, $constructor->getNumberOfParameters());
        self::assertSame(3, $constructor->getNumberOfRequiredParameters(), 'No field of a command descriptor is optional.');
    }

    /**
     * The streams, written down — what devkit wires to a real console, standing
     * in here as two arrays.
     *
     * @return CommandIo&object{out: list<string>, err: list<string>}
     */
    private static function io(): CommandIo
    {
        return new class implements CommandIo {
            /** @var list<string> */
            public array $out = [];

            /** @var list<string> */
            public array $err = [];

            public function write(string $line): void
            {
                $this->out[] = $line;
            }

            public function error(string $line): void
            {
                $this->err[] = $line;
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
    }

    public function testAnEmptyNameIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new CommandDescriptor('   ', 'A help line.', static fn (array $arguments, CommandIo $io): int => 0);
    }

    public function testAnEmptyDescriptionIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new CommandDescriptor('demo:reset', '   ', static fn (array $arguments, CommandIo $io): int => 0);
    }
}
