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

namespace Uhifadhi\Contracts\Area;

/**
 * WHICH OF A STATION'S TWO SURFACES A SECTION IS FOR.
 *
 * A POST IS READ IN ONE PLACE AND SET UP IN ANOTHER, and what a module has
 * to say differs between them: the record answers "what is happening at
 * this post", the configure card asks "what should happen at it". The same
 * module contributes to both, so one seam carries both and the surface says
 * which is being drawn.
 *
 * AND A THIRD, THE PERSON'S OWN (28 Sep 2026, #19): `/me/station`, the post
 * the signed-in person is posted at, read by somebody who may read nothing
 * else of the area — so a module says there only what a person posted at the
 * post should see (its watches, the patrols that went out from it).
 *
 * THE ENUM IS WHY THERE ARE NOT THREE INTERFACES. A contributor that only
 * speaks to some of them answers the others with nothing, which is a
 * legitimate answer and costs it one line.
 */
enum StationSurface: string
{
    /** `/areas/{uuid}/stations/{station}` — the post's own page. */
    case Record = 'record';

    /** The post's card on the area's Stations configure page. */
    case Configure = 'configure';

    /** `/me/station` — the post as the person posted at it reads it (#19). */
    case Mine = 'mine';
}
