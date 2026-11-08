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
 * WHERE A SANKEY'S FLOWS END: an outcome, named, wearing a category or a
 * status token — the sources are the chart's labels and wear their parts.
 */
final readonly class ChartFlowTarget
{
    public function __construct(
        public string $label,
        public ?string $swatch = null,
        public ?int $cat = null,
    ) {
        if (null !== $cat && null !== $swatch) {
            throw new \InvalidArgumentException('A target wears a category or a token, never both.');
        }
    }
}
