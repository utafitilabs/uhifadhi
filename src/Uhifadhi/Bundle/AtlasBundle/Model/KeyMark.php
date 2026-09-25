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
 * THE DOT A KEY ENTRY WEARS, as a class on `.sxdot` the sheet paints — the
 * same marks a bar's parts and a matrix's cells are drawn in, so the key and
 * the thing it reads cannot disagree.
 */
enum KeyMark: string
{
    /** The accent: a bar's fill, a present cell. */
    case Solid = '';

    /** The faded fail: the rest of a two-part bar. */
    case Rest = 'v';

    /** The accent at 42%: a soft fill. */
    case Soft = 'b';

    /** An outline in the accent: a cell inherited from somewhere wider. */
    case Inherited = 'inh';

    /** A dashed outline: an absence, drawn rather than left blank. */
    case Absent = 'no';

    /** The class the dot wears, beside `sxdot`. */
    public function className(): string
    {
        return '' === $this->value ? 'sxdot' : 'sxdot '.$this->value;
    }
}
