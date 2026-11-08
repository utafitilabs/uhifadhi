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
 * FOUR REGIONS BEHIND A SCATTER, split at a stated x and y.
 *
 * THE SPLIT IS A FACT, not a property of the points on screen: a mean of
 * what is drawn would move with every filter. The four parts run top-left,
 * top-right, bottom-right, bottom-left.
 */
final readonly class ChartQuadrants
{
    /**
     * @param list<ChartQuadrant> $parts top-left, top-right, bottom-right, bottom-left
     */
    public function __construct(
        public float $x,
        public float $y,
        public array $parts,
    ) {
        if (4 !== \count($parts)) {
            throw new \InvalidArgumentException(\sprintf('Quadrants state four parts: top-left, top-right, bottom-right, bottom-left; %d were stated.', \count($parts)));
        }
    }
}
