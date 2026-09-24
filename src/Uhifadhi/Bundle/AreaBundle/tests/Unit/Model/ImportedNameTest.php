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

namespace Uhifadhi\Bundle\AreaBundle\Tests\Unit\Model;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Uhifadhi\Bundle\AreaBundle\Model\ImportedName;

/**
 * A FILE THAT SHOUTS IS NOT AN INSTALLATION THAT DECIDED.
 *
 * GIS exports write their attribute tables in upper case, so zone names
 * arrived as CRATER and HIGHLANDS and then shouted everywhere the product
 * said them — the register, the plate's key, the sidebar's own menu items,
 * the middle of a sentence. The owner asked why, and the answer was that
 * nobody had decided it; a tool had.
 *
 * SO: entirely upper-case is title-cased, and nothing else is touched. One
 * lower-case letter is enough to say a person chose the casing, and a
 * corrected name that is now wrong is worse than a shouting one.
 */
final class ImportedNameTest extends TestCase
{
    /** @return \Generator<string, array{string, string}> */
    public static function names(): \Generator
    {
        yield 'a shouted one word' => ['CRATER', 'Crater'];
        yield 'a shouted several' => ['OL DOINYO LENGAI', 'Ol Doinyo Lengai'];
        yield 'a hyphen is a word break' => ['RIDGE-ESCARPMENT', 'Ridge-Escarpment'];
        yield 'and so is an apostrophe' => ["O'BRIEN PLAIN", "O'Brien Plain"];
        yield 'the typographic one too' => ['CRATER’S RIM', 'Crater’S Rim'];
        yield 'a bracket opens a word' => ['HIGHLANDS (SOUTH)', 'Highlands (South)'];

        // Untouched: somebody wrote these.
        yield 'mixed case is a decision' => ['Forest Edge', 'Forest Edge'];
        yield 'an initialism inside one' => ['NCA Highlands', 'NCA Highlands'];
        yield 'a lower-case particle' => ["du Toit's Kloof", "du Toit's Kloof"];
        yield 'all lower case' => ['crater', 'crater'];

        // Not shouting, just short.
        yield 'digits only' => ['12', '12'];
        yield 'a code with no letters' => ['4-7', '4-7'];
        yield 'nothing at all' => ['', ''];
    }

    #[DataProvider('names')]
    public function testANameIsTitleCasedOnlyWhenTheFileShoutedIt(string $arrived, string $expected): void
    {
        self::assertSame($expected, ImportedName::of($arrived));
    }

    /**
     * NON-ASCII SHOUTS TOO. A name is as often Kiswahili or Maa as it is
     * English, and a rule written with `ctype_upper` would have left every
     * accented one alone.
     */
    public function testTheRuleReadsLettersAndNotBytes(): void
    {
        self::assertTrue(ImportedName::isShouted('MTO WA MBÚ'));
        self::assertSame('Mto Wa Mbú', ImportedName::of('MTO WA MBÚ'));
    }

    /** And applying it twice changes nothing — it is a reading, not an edit. */
    public function testTheRuleIsStable(): void
    {
        self::assertSame('Ol Doinyo Lengai', ImportedName::of(ImportedName::of('OL DOINYO LENGAI')));
    }
}
