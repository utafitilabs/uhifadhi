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
 * A LONG DEVICE SERIES, THINNED BY THE LIBRARY as it is drawn: at most
 * `samples` points (by default about one per pixel of the plot).
 *
 * The points are stated as {@see ChartPoint}s, x in milliseconds, in time
 * order: the library thins only data it does not have to parse.
 *
 * @see https://www.chartjs.org/docs/latest/configuration/decimation.html
 */
final readonly class ChartDecimation
{
    public function __construct(
        public bool $enabled = true,
        public DecimationAlgorithm $algorithm = DecimationAlgorithm::Lttb,
        public ?int $samples = null,
    ) {
        if (null !== $samples && $samples < 1) {
            throw new \InvalidArgumentException(\sprintf('Decimation keeps at least one sample; %d is not a count of them.', $samples));
        }
    }
}
