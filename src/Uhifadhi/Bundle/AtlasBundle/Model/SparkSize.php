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
 * THE TWO BOXES A SPARKLINE IS DRAWN IN, as the design draws them.
 *
 * A figure on a card carries a wider, taller line than the same figure in a
 * matrix cell; the rule that places the points and breaks the line is one,
 * and only the box differs. The value is the class the sheet sizes it by.
 */
enum SparkSize: string
{
    /** Under a KPI or topic card's figure: 100 by 26, stretched to the card's width. */
    case Card = 'sk';

    /** Beside a matrix cell's movement: 70 by 18. */
    case Cell = 'spark';

    public function width(): float
    {
        return match ($this) {
            self::Card => 100.0,
            self::Cell => 70.0,
        };
    }

    public function height(): float
    {
        return match ($this) {
            self::Card => 26.0,
            self::Cell => 18.0,
        };
    }
}
