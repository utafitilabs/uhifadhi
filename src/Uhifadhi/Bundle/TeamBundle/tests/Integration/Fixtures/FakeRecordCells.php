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

namespace Uhifadhi\Bundle\TeamBundle\Tests\Integration\Fixtures;

use Uhifadhi\Contracts\People\PersonRecordCellProviderInterface;

/**
 * A CARD ON A PERSON'S RECORD, DRAWN BY A FIXTURE — for the one person a
 * suite names, and silence for everybody else.
 */
final class FakeRecordCells implements PersonRecordCellProviderInterface
{
    private static ?string $speaksAbout = null;

    public static function speakAbout(?string $personUuid): void
    {
        self::$speaksAbout = $personUuid;
    }

    public function cellFor(string $personUuid): ?string
    {
        if ($personUuid !== self::$speaksAbout) {
            return null;
        }

        return '<div class="c" data-fixture-cell="'.$personUuid.'"><span class="tab">Handsets<span class="src">&middot; a fixture</span></span><div class="rln"><span>Carrying</span><span class="mono">one</span></div></div>';
    }
}
