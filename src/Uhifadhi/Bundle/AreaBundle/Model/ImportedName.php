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

namespace Uhifadhi\Bundle\AreaBundle\Model;

/**
 * A NAME AS IT ARRIVES IN A FILE, AND AS THE PRODUCT WILL SAY IT.
 *
 * GIS exports shout. A shapefile's attribute table is very often upper-case
 * throughout — CRATER, HIGHLANDS, LAKESHORE — because that is how the tool
 * that wrote it writes, not because anybody decided the zone is called that.
 * Imported straight, those names shout everywhere the product says them: in
 * the register, on the plate's key, in the sidebar's menu, in a sentence in
 * the middle of a page. The owner's question was exactly that: why are zone
 * names all caps, even their menu items.
 *
 * SO A NAME THAT IS ENTIRELY UPPER-CASE IS TITLE-CASED, and nothing else is
 * touched. A mixed-case name has been written BY somebody — "Forest Edge",
 * "du Toit's Kloof", "NCA Highlands" — and the one thing worse than a
 * shouting name is a corrected one that is now wrong. The test is whether the
 * name contains a lower-case letter: one is enough to say a person chose the
 * casing.
 *
 * A NAME WITH NO LETTERS AT ALL is left exactly as it is: "12", "A-4" and
 * "π" are not shouting, they are just short.
 */
final readonly class ImportedName
{
    /**
     * The name the product will say, which is the one that arrived unless it
     * was shouted.
     */
    public static function of(string $name): string
    {
        return self::isShouted($name) ? self::titleCased($name) : $name;
    }

    /**
     * Whether the file shouted this name: letters, and not one of them lower
     * case. `mb_strtolower` rather than `ctype_*`, because a name is as often
     * Kiswahili or Maa as it is ASCII.
     */
    public static function isShouted(string $name): bool
    {
        $lowered = mb_strtolower($name);

        return $lowered !== $name && mb_strtoupper($name) === $name;
    }

    /**
     * FIRST LETTER OF EVERY WORD, and a word begins after a space, a hyphen
     * or an apostrophe — "OL DOINYO" is two words, "RIDGE-ESCARPMENT" is two, and
     * "O'BRIEN" is one word with two capitals in it.
     *
     * `mb_convert_case` with MB_CASE_TITLE would not do: it treats an
     * apostrophe as a word break in some locales and not others, and gives
     * "O'brien" in the ones that matter here.
     */
    private static function titleCased(string $name): string
    {
        $out = '';
        $startOfWord = true;
        foreach (mb_str_split($name) as $character) {
            $out .= $startOfWord ? mb_strtoupper($character) : mb_strtolower($character);
            $startOfWord = \in_array($character, [' ', '-', "'", '’', '(', '/'], true);
        }

        return $out;
    }
}
