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

namespace Uhifadhi\Bundle\TeamBundle\Model;

/** A RUN OF OPTIONS IN A GROUPED DROPDOWN, under a head when the run has one. */
final readonly class FacetGroup
{
    /** @param list<FilterOption> $options */
    public function __construct(
        public ?string $head,
        public array $options,
    ) {
    }
}
