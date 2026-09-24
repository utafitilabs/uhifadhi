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

namespace Uhifadhi\Contracts;

/**
 * Sensible defaults for the optional parts of {@see ModuleProviderInterface},
 * so a concrete provider usually only has to define slug(), name() and
 * category(). Override any of these to opt out of the default.
 *
 * Defaults describe the common case: a live, unpinned, generically-rendered
 * module with no provenance line and the host's default icon.
 */
trait ModuleProviderTrait
{
    public function status(): string
    {
        return 'live';
    }

    public function description(): ?string
    {
        return null;
    }

    public function dataSource(): ?string
    {
        return null;
    }

    public function pinned(): bool
    {
        return false;
    }

    public function base(): bool
    {
        return false;
    }

    public function position(): int
    {
        return 0;
    }

    public function icon(): ?string
    {
        return null;
    }

    public function entryRoute(): ?string
    {
        return null;
    }
}
