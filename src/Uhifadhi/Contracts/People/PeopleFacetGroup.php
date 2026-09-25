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

namespace Uhifadhi\Contracts\People;

/** A RUN OF OPTIONS in a contributed dropdown, under a head when the run has one. */
final readonly class PeopleFacetGroup
{
    /** @param list<PeopleFacetOption> $options */
    public function __construct(
        public ?string $head,
        public array $options,
    ) {
    }
}
