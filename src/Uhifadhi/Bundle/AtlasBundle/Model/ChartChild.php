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
 * ONE PART OF A PART — a subcategory on a nested doughnut's outer ring,
 * stated with the position of the label it belongs to (`of`).
 */
final readonly class ChartChild
{
    public function __construct(
        public string $label,
        public float $value,
        public int $of,
    ) {
        if ($of < 0) {
            throw new \InvalidArgumentException(\sprintf('A child names its parent by position; "%s" names %d.', $label, $of));
        }
    }
}
