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

namespace Uhifadhi\Core\Tests\Core;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * ONE SPELLING, EVERYWHERE A READER CAN SEE IT: "organization" (ruled by the
 * owner, 2026-09-21).
 *
 * WHY A TEST AND NOT A STYLE NOTE. Nobody types the other spelling on
 * purpose; it arrives one string at a time, from whoever wrote that screen,
 * and it is invisible until somebody reads two screens side by side.
 *
 * WHAT IS SWEPT: every shipped template, where a reader meets the word, and
 * every PHP file the core ships, where the identifiers live. Route names
 * (`organization_dashboard`), service ids, class names
 * (`OrganizationIdentity`), enum cases and the `/settings/organization`
 * address carry the same spelling as the copy, so the letters are flagged
 * wherever they appear rather than only between word boundaries.
 * Translation catalogues are swept too the day this product grows one — the
 * glob is here already, so the first `.xlf` anybody adds is covered without
 * a change to this file.
 *
 * THE ONE PLACE THE OTHER SPELLING BELONGS is a test fixture that asserts it
 * is refused — {@see \Uhifadhi\Contracts\Tests\Shell\NavGroupTest}'s
 * near-miss list. Test files are therefore outside the PHP sweep; shipped
 * code is not.
 */
#[CoversNothing]
final class OneSpellingOfOrganizationTest extends TestCase
{
    /** The one spelling the product uses. */
    private const string RIGHT = 'organization';

    /** The one it does not. */
    private const string WRONG = 'organisation';

    /**
     * Every template this repository ships, and every translation catalogue
     * it grows.
     *
     * @return \Generator<string, array{string}>
     */
    public static function userFacingFiles(): \Generator
    {
        $root = \dirname(__DIR__, 2);

        $files = [];
        foreach (['*.twig', '*.xlf', '*.yaml'] as $pattern) {
            foreach (glob($root.'/src/Uhifadhi/Bundle/*/templates', \GLOB_ONLYDIR) ?: [] as $templates) {
                $files = [...$files, ...self::under($templates, $pattern)];
            }
            foreach (glob($root.'/src/Uhifadhi/Bundle/*/translations', \GLOB_ONLYDIR) ?: [] as $catalogue) {
                $files = [...$files, ...self::under($catalogue, $pattern)];
            }
        }

        self::assertNotEmpty($files, 'The sweep found no templates at all, which means it is sweeping nothing.');

        foreach ($files as $file) {
            yield substr($file, \strlen($root) + 1) => [$file];
        }
    }

    /**
     * A SHIPPED TEMPLATE SPELLS IT ONE WAY.
     *
     * The comparison is case-insensitive because the heading, the tab and
     * the sentence each capitalise it differently and all three are the same
     * word.
     */
    #[DataProvider('userFacingFiles')]
    public function testNoShippedTemplateUsesTheOtherSpelling(string $file): void
    {
        self::assertSame(
            [],
            self::wordsIn((string) file_get_contents($file)),
            \sprintf(
                '%s spells it "%s" where a reader can see it. The product spells it "%s" (ruled 2026-09-21) — '
                .'one spelling, everywhere, identifiers included.',
                basename($file),
                self::WRONG,
                self::RIGHT,
            ),
        );
    }

    /**
     * THE LETTERS, WHEREVER THEY SIT — in a sentence, in a class name, in a
     * route name or in an address. One spelling means one spelling.
     *
     * @return list<string>
     */
    private static function wordsIn(string $contents): array
    {
        preg_match_all('/'.self::WRONG.'/i', $contents, $found);

        return $found[0];
    }

    /**
     * AND THE CODE SPELLS IT THE SAME WAY. Every PHP file the core ships —
     * tests excluded, because the near-miss fixture that proves the other
     * spelling is refused has to be able to write it.
     */
    public function testNoShippedSourceFileUsesTheOtherSpelling(): void
    {
        $root = \dirname(__DIR__, 2);

        $offenders = [];
        foreach (self::under($root.'/src', '*.php') as $file) {
            if (str_contains($file, '/tests/')) {
                continue;
            }

            if ([] !== self::wordsIn((string) file_get_contents($file))) {
                $offenders[] = substr($file, \strlen($root) + 1);
            }
        }

        self::assertSame([], $offenders, 'The product spells it "'.self::RIGHT.'" (ruled 2026-09-21), identifiers included.');
    }

    /**
     * AND THE SWEEP IS REALLY LOOKING. A test that would pass over an empty
     * set, or whose needle never matched anything, is a test that stops
     * catching the thing it was written for — so the right spelling is
     * asserted to be present somewhere, which it is on every organization
     * surface the core ships.
     */
    public function testTheSweepIsActuallyReadingTheTemplates(): void
    {
        $found = 0;
        foreach (self::userFacingFiles() as [$file]) {
            if (str_contains(strtolower((string) file_get_contents($file)), self::RIGHT)) {
                ++$found;
            }
        }

        self::assertGreaterThan(0, $found, 'No template uses the word at all, so this suite proves nothing.');
    }

    /**
     * @return list<string>
     */
    private static function under(string $directory, string $pattern): array
    {
        $found = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
        );

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if (fnmatch($pattern, $file->getFilename())) {
                $found[] = $file->getPathname();
            }
        }

        sort($found);

        return $found;
    }
}
