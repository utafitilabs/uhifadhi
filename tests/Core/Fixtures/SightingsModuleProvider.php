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

namespace Uhifadhi\Core\Tests\Core\Fixtures;

use Uhifadhi\Contracts\ModuleProviderInterface;
use Uhifadhi\Contracts\ModuleProviderTrait;

/** A MODULE WITH SETTINGS OF ITS OWN, as a module bundle declares one: fictional, so nothing here names a real module. */
final class SightingsModuleProvider implements ModuleProviderInterface
{
    use ModuleProviderTrait;

    public const string SLUG = 'sightings';

    public function slug(): string
    {
        return self::SLUG;
    }

    public function name(): string
    {
        return 'Sightings';
    }

    public function description(): string
    {
        return 'Every animal seen, where and when.';
    }

    public function category(): string
    {
        return 'biodiversity';
    }
}
