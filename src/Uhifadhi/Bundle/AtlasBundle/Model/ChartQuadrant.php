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

use Uhifadhi\Contracts\Atlas\PlatePalette;

/**
 * ONE OF FOUR REGIONS BEHIND A SCATTER: a word, and the token or category
 * it is shaded in at a range's strength — or no shade, where the word alone
 * says it. A status fill is never stated without its word.
 */
final readonly class ChartQuadrant
{
    public function __construct(
        public string $label = '',
        public ?string $swatch = null,
        public ?int $cat = null,
    ) {
        if (null !== $cat && null !== $swatch) {
            throw new \InvalidArgumentException(\sprintf('A quadrant wears a category or a token, never both; "%s" states both.', $label));
        }
        if (null !== $cat && ($cat < 1 || $cat > PlatePalette::CATEGORIES)) {
            throw new \InvalidArgumentException(\sprintf('A category is its position in a declared order, 1 to %d; "%d" is not one.', PlatePalette::CATEGORIES, $cat));
        }
    }
}
