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

/** A message that does not carry the queue marker. */
final class PlainMessage implements \Stringable
{
    public function __toString(): string
    {
        return 'the installation’s weekly task';
    }
}
