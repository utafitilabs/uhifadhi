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

namespace Uhifadhi\Bundle\ShellBundle\Tests\Unit\Assets;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * A BARE GRID IS ONE BOUNDED COLUMN. A `.grid` without a column class is a
 * stack of cards, and its one track must let a card shrink to the page:
 * `minmax(0, 1fr)`. A track left unstated sizes to its content, and a card
 * holding a plate then runs off the page by the plate's intrinsic width —
 * which is what the Stations tab did with fifty-seven stations on its plate.
 */
#[CoversNothing]
final class BareGridTest extends TestCase
{
    public function testABareGridStatesItsOneBoundedTrack(): void
    {
        $sheet = (string) file_get_contents(\dirname(__DIR__, 3).'/public/shell.css');

        self::assertMatchesRegularExpression(
            '/^\.grid \{[^}]*grid-template-columns: minmax\(0, 1fr\);[^}]*\}/m',
            $sheet,
            'the bare .grid rule carries grid-template-columns: minmax(0, 1fr)',
        );
    }
}
