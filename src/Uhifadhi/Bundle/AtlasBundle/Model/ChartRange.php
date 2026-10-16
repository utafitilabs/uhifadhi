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
 * A SHADED RANGE OF THE VALUE AXIS, WITH A WORD.
 *
 * A RANGE IS A FACT ABOUT THE SCALE, not a decoration: "above +0.5 °C is
 * El Niño", "below 60 % is short". It is drawn behind the series as a faint
 * band in the colour it names, and its word is written once at the band's
 * edge, so the reader learns what a reading there means without a legend.
 *
 * OPEN AT EITHER END. A null `from` runs down to the bottom of the scale, a
 * null `to` up to its top; a range with neither is the whole chart and is
 * refused where it is stated.
 *
 * A RANGE IS A CATEGORY OR THE ACCENT, LIKE A SERIES: the colour is a token
 * resolved where the chart is drawn, never a value a module picked.
 */
final readonly class ChartRange
{
    /**
     * @param float|null  $from   the lower bound, or null for the bottom of the scale
     * @param float|null  $to     the upper bound, or null for the top of the scale
     * @param string      $label  the word written at the band's edge
     * @param string|null $swatch a plate token where the range wears one (`var(--plate-fail)`)
     * @param int|null    $cat    the category this range wears, 1 to 18
     * @param bool        $accent the range wears the house accent
     */
    public function __construct(
        public ?float $from,
        public ?float $to,
        public string $label,
        public ?string $swatch = null,
        public ?int $cat = null,
        public bool $accent = false,
    ) {
        if (null === $from && null === $to) {
            throw new \InvalidArgumentException(\sprintf('A range is bounded at one end at least; "%s" is bounded at neither.', $label));
        }
        if (null !== $from && null !== $to && $from >= $to) {
            throw new \InvalidArgumentException(\sprintf('A range runs upward; "%s" runs from %s to %s.', $label, $from, $to));
        }
        if ($accent && (null !== $cat || null !== $swatch)) {
            throw new \InvalidArgumentException(\sprintf('A range wears a category or the accent, never both; "%s" states both.', $label));
        }
        if (null !== $cat && ($cat < 1 || $cat > PlatePalette::CATEGORIES)) {
            throw new \InvalidArgumentException(\sprintf('A category is its position in a declared order, 1 to %d; "%d" is not one.', PlatePalette::CATEGORIES, $cat));
        }
    }
}
