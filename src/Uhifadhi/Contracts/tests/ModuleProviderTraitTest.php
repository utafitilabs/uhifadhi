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

namespace Uhifadhi\Contracts\Tests;

use PHPUnit\Framework\TestCase;
use Uhifadhi\Contracts\ModuleProviderInterface;
use Uhifadhi\Contracts\ModuleProviderTrait;

/**
 * The contract's only behaviour is the defaults trait: a provider that defines
 * just the three required methods is a valid ModuleProviderInterface with the
 * documented common-case defaults.
 */
final class ModuleProviderTraitTest extends TestCase
{
    private function minimalProvider(): ModuleProviderInterface
    {
        return new class implements ModuleProviderInterface {
            use ModuleProviderTrait;

            public function slug(): string
            {
                return 'example';
            }

            public function name(): string
            {
                return 'Example';
            }

            public function category(): string
            {
                return 'pressure';
            }
        };
    }

    public function testTraitSuppliesTheCommonCaseDefaults(): void
    {
        $provider = $this->minimalProvider();

        self::assertSame('example', $provider->slug());
        self::assertSame('Example', $provider->name());
        self::assertSame('pressure', $provider->category());
        self::assertSame('live', $provider->status());
        self::assertNull($provider->description(), 'A module says nothing beyond its name until it says otherwise.');
        self::assertNull($provider->dataSource());
        self::assertFalse($provider->pinned());
        self::assertFalse($provider->base(), 'A module is installable until it says otherwise — base is the exception.');
        self::assertSame(0, $provider->position());
        self::assertNull($provider->icon());
        self::assertNull($provider->entryRoute(), 'A generically-rendered module has no own entry route.');
    }
}
