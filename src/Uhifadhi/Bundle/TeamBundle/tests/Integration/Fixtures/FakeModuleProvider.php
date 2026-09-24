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

namespace Uhifadhi\Bundle\TeamBundle\Tests\Integration\Fixtures;

use Uhifadhi\Contracts\ModuleProviderInterface;
use Uhifadhi\Contracts\ModuleProviderTrait;

/**
 * A MODULE THIS INSTALLATION HAS, by name only.
 *
 * The catalogue is rows AND registered providers — a row whose code is
 * gone is a capability the machine no longer has — so a fixture that
 * needs a module to be REAL registers one of these beside the row.
 */
final class FakeModuleProvider implements ModuleProviderInterface
{
    use ModuleProviderTrait;

    public function __construct(private readonly string $slug)
    {
    }

    public function slug(): string
    {
        return $this->slug;
    }

    public function name(): string
    {
        return ucfirst($this->slug);
    }

    public function category(): string
    {
        return 'operations';
    }
}
