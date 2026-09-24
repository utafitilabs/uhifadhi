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

namespace Uhifadhi\Contracts\Devkit;

/**
 * One dev/maintenance command a module offers, described WITHOUT naming
 * symfony/console: a name, a help line, and the closure that does the work.
 *
 * This is the object that keeps the whole devkit contract framework-free. A module
 * that wanted to contribute a command could have handed devkit a
 * {@see \Symfony\Component\Console\Command\Command}, and devkit would only have
 * had to register it — but the price is one this package refuses to pay twice
 * over. First, it would put symfony/console in this package's `require`, and a
 * package of promises whose whole claim is that depending on it costs nothing
 * cannot drag a console runtime behind it. Second, and worse, the inert provider
 * that returns these ships inside an ALWAYS-installed module (patrol, incidents),
 * while devkit is require-dev; a provider returning Command objects would build
 * those objects in a production container where devkit — and the point of them —
 * is absent. A descriptor is inert data in production and becomes a real command
 * only when devkit, which legitimately requires symfony/console, wraps it in
 * dev.
 *
 * THE HANDLER IS THE PROCESS CONTRACT, NOT THE CONSOLE ONE. It is a closure that
 * takes the argument tail a person typed — a `list<string>`, everything after
 * the command name — plus the {@see CommandIo} it may speak through, and returns
 * a POSIX exit code (0 = success). Tail in, streams to talk on, status out: that
 * is the lowest common denominator of "run a command", and it needs nothing from
 * a framework. devkit's generated wrapper collects the raw tokens into that
 * array, passes an io wired to the real console, calls the handler, and uses the
 * returned int as the command's exit status. A command that wants richer input
 * parses the tail itself, or injects what it needs through the service the
 * closure closes over; the contract deliberately does not model options and
 * arguments, because doing so would mean reimplementing an InputDefinition here
 * — exactly the console coupling this object exists to avoid.
 *
 * THE IO IS PART OF THAT PROCESS CONTRACT, NOT A CONCESSION TO THE CONSOLE. A
 * handler given only a tail and an exit code has nowhere to say what it did, so
 * its only recourse is \STDOUT and \STDIN directly — and a handler writing there
 * has escaped the process it was handed: its output ignores `--quiet`, is
 * invisible to a caller capturing the command's output, and turns up uninvited
 * in a test run. Three verbs on {@see CommandIo} close that hole while importing
 * nothing.
 *
 * Like every declared row, every field is required
 * and validated in the constructor: a command with no name cannot be registered,
 * and one with no help line is a blank row in `list` that tells an operator
 * nothing. A descriptor that has not thought about them does not compile.
 */
final readonly class CommandDescriptor
{
    /**
     * @param string                                $name        the console name devkit registers,
     *                                                           namespaced by convention
     *                                                           (e.g. "patrol:demo:reset")
     * @param string                                $description one line of help, shown in `list`
     *                                                           and `--help`
     * @param \Closure(list<string>, CommandIo):int $handler     does the work: receives the argument
     *                                                           tail and the streams to
     *                                                           speak through, and returns
     *                                                           a POSIX exit code
     *                                                           (0 = success)
     */
    public function __construct(
        public string $name,
        public string $description,
        public \Closure $handler,
    ) {
        // Refused rather than stored: an unnamed command cannot be registered,
        // and a nameless row is the one thing devkit's wrapper cannot paper over.
        if ('' === trim($name)) {
            throw new \InvalidArgumentException('A command descriptor was declared without a name. Give it the console name devkit should register it under, e.g. "patrol:demo:reset".');
        }

        // The same rule the permission catalogue enforces: a name is what the
        // module chose to call it, the description is what it does, and `list`
        // prints the second next to the first.
        if ('' === trim($description)) {
            throw new \InvalidArgumentException(\sprintf('The command "%s" was declared without a description. Say in one line what it does — it is printed next to the name in the console listing.', $name));
        }
    }
}
