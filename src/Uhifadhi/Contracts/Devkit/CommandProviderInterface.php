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
 * How a module offers dev/maintenance commands that exist ONLY in a dev install.
 *
 * devkit-module (evolving from fixtures-module) is the dev-only collector: it
 * installs through `require-dev`, so it and its machinery are in no production
 * build. A module ships an INERT provider implementing this interface — an
 * ordinary tagged service that names some commands — and devkit `tagged_iterator`s
 * every one of them and turns each descriptor into a real Symfony console
 * command. Because devkit is require-dev, that registration happens only where
 * devkit is installed: the commands appear on a developer's machine and in CI,
 * and are absent from production entirely.
 *
 * THE INTERFACE LIVES HERE, NOT IN DEVKIT, and that is the crux of it. The
 * inert provider classes ship inside always-installed modules (patrol, incidents),
 * so their `implements` clause must resolve at runtime even when devkit — being
 * require-dev — is absent. A contract those modules point at therefore has to
 * live in a package they always have: this one. devkit depends on the same
 * interface to collect the providers, so the arrows point at the promise from
 * both sides and neither module needs to know devkit exists.
 *
 *     patrol-module ──implements──▶ CommandProviderInterface ◀──reads── devkit-module
 *
 * This is the harder of the two devkit contracts precisely because a command has
 * a natural framework form — {@see \Symfony\Component\Console\Command\Command} —
 * and using it would make devkit's job trivial. It is refused: see
 * {@see CommandDescriptor} for why returning descriptors keeps symfony/console
 * out of this package's `require` and keeps command objects from being built in a
 * production container that has no devkit to run them.
 *
 * A provider is a collector of commands rather than a single command, so it has
 * no identity of its own — each {@see CommandDescriptor} carries its own name.
 * For a module's seed/sample CONTENT, which is ordered and seeded rather than
 * invoked, use {@see ContentProviderInterface} instead.
 */
interface CommandProviderInterface
{
    /**
     * The dev/maintenance commands this module offers. devkit registers each as
     * a console command in a dev install and in no other; most modules return a
     * short handful, and a module with none does not implement this interface at
     * all.
     *
     * @return list<CommandDescriptor>
     */
    public function commands(): array;
}
