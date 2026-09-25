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

namespace Uhifadhi\Bundle\AreaBundle\Service;

use Uhifadhi\Contracts\Area\LivePresence;

/**
 * ONE PERSON'S LIVE READING IN ONE AREA — what the frame a ping publishes is
 * made of.
 *
 * The same reading {@see \Uhifadhi\Contracts\Area\LivePositionsInterface}
 * gives for a whole area, narrowed to one person before it is read rather
 * than after: a ping moves one ranger's mark, and building it must cost one
 * ranger's row, not the area's.
 */
interface PersonLivePositionsInterface
{
    /**
     * The person's positions on open watches — none when they are not on the
     * ground — with the area's ping interval, as {@see LivePresence} states it
     * for a whole area.
     */
    public function liveOf(string $areaUuid, string $personUuid, \DateTimeImmutable $asOf): LivePresence;
}
