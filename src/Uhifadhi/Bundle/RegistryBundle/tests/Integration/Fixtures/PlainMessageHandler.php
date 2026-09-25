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

namespace Uhifadhi\Bundle\RegistryBundle\Tests\Integration\Fixtures;

/** Counts what it handled, so a specification can see where a message ran. */
final class PlainMessageHandler
{
    public static int $handled = 0;

    public function __invoke(PlainMessage $message): void
    {
        ++self::$handled;
    }
}
