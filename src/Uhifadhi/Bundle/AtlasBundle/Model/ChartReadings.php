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

namespace Uhifadhi\Bundle\AtlasBundle\Model;

/**
 * EVERY READING AT ONE LABEL, for a boxplot or a violin: the library works
 * out the quartiles (or the density) and the whiskers from the list, so the
 * caller states the readings, never the figures drawn from them.
 */
final readonly class ChartReadings
{
    /** @param list<float> $readings */
    public function __construct(public array $readings)
    {
    }
}
