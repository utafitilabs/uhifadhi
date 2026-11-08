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
 * WHAT ONE PART OF A WHOLE WEARS — a slice of a doughnut, a pie or a gauge.
 *
 * A whole's parts are its labels, not its series, so each label states its
 * own: a category (its position in the palette), or a token where the part
 * is a status (`var(--ok)`, `var(--dim)`), and the hatch over it where its
 * hue alone sits too near a neighbour. Never a colour.
 */
final readonly class ChartPart
{
    public function __construct(
        public ?int $cat = null,
        public ?string $swatch = null,
        public ?ChartTexture $texture = null,
    ) {
        if (null !== $cat && null !== $swatch) {
            throw new \InvalidArgumentException('A part wears a category or a token, never both.');
        }
        if (null !== $cat && ($cat < 1 || $cat > PlatePalette::CATEGORIES)) {
            throw new \InvalidArgumentException(\sprintf('A category is its position in a declared order, 1 to %d; "%d" is not one.', PlatePalette::CATEGORIES, $cat));
        }
    }
}
