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

namespace Uhifadhi\Bundle\TeamBundle\Test;

use PHPUnit\Framework\TestCase;
use Uhifadhi\Contracts\Access\ConcernInterface;
use Uhifadhi\Contracts\Access\ConcernSourceInterface;
use Uhifadhi\Contracts\Access\Grant;
use Uhifadhi\Contracts\Access\ScopeKind;
use Uhifadhi\Contracts\Access\Verb;

/**
 * THE CONFORMANCE A MODULE RUNS OVER ITS OWN DECLARATIONS.
 *
 * The core holds its own routes, doors and concerns together with build
 * tests of its own. A module's routes and declarations are its own business
 * and its own CI, so the rules travel as a base class: a module points this
 * at its concern source and its templates, and gets the same refusals the
 * core gets, in its own suite, before anything is released.
 *
 * WHAT IT HOLDS, and why each one is a mistake that looks like working code:
 *
 *   - A CONCERN IS DECLARED ONCE, with a key that is a slug. Two declarations
 *     of one key is an installation that cannot say what the key means.
 *   - EVERY CONCERN SAYS WHAT IT IS ABOUT, in one sentence. A matrix half of
 *     whose rows explain themselves is one an administrator stops reading.
 *   - A MODULE'S CONCERN NAMES ITS MODULE. That is how the third question —
 *     does the placement cover the department — is answerable at all, since
 *     a department runs modules; a module concern that named none would be
 *     silently exempt from it.
 *   - `own` COMES WITH THE MODULE'S OWN WORDS, because the core has none.
 *   - A FACT ABOUT A PERSON OR A CASE IS ITS OWN CONCERN, declared sensitive,
 *     so it can be withheld without withholding the page it sits on.
 *   - EVERY DOOR GOES THROUGH THE HELPER, so the module's doors are
 *     enumerable the same way the core's are.
 *   - EVERY VOTER SAYS WHICH QUESTIONS IT ANSWERS, through
 *     `supportsAttribute()`. One that does not is asked every question in
 *     the installation, and under the affirmative strategy a voter that
 *     answers a question another already answers can only widen it: a rule
 *     meant to narrow the grant voter is outvoted by it and does nothing.
 *
 * Usage:
 *
 *     final class AccessConformanceTest extends AccessConformanceTestCase
 *     {
 *         protected static function source(): ConcernSourceInterface
 *         {
 *             return new RosterConcerns();
 *         }
 *
 *         protected static function bundlePath(): string
 *         {
 *             return \dirname(__DIR__, 2);
 *         }
 *
 *         protected static function moduleSlug(): ?string
 *         {
 *             return 'roster';
 *         }
 *     }
 */
abstract class AccessConformanceTestCase extends TestCase
{
    /** The declaration under test. */
    abstract protected static function source(): ConcernSourceInterface;

    /** The bundle's root, so its templates can be read. */
    abstract protected static function bundlePath(): string;

    /**
     * The slug of the module these concerns belong to, or null for a package
     * that is not a module (a core bundle, or infrastructure).
     */
    protected static function moduleSlug(): ?string
    {
        return null;
    }

    /**
     * The concerns this package declares that are facts about a person or a
     * case. Naming them here is a second statement of the same thing the
     * declaration makes, on purpose: a fact that quietly stopped being
     * sensitive would otherwise be a silent widening.
     *
     * @return list<string>
     */
    protected static function sensitiveConcerns(): array
    {
        return [];
    }

    public function testEveryConcernIsDeclaredOnceAndKeyedBySlug(): void
    {
        $seen = [];

        foreach (self::concerns() as $concern) {
            $key = $concern->key();

            self::assertMatchesRegularExpression(
                '/^[a-z0-9]+(-[a-z0-9]+)*$/',
                $key,
                \sprintf('"%s" is not a slug. A concern key is the word a route, a door and a grant all name it by.', $key),
            );

            self::assertArrayNotHasKey($key, $seen, \sprintf('"%s" is declared twice by this package.', $key));
            $seen[$key] = true;
        }

        self::assertNotSame([], $seen, 'a package that declares no concern should not run this conformance.');
    }

    public function testEveryConcernSaysWhatItIsAboutAndSupportsAVerb(): void
    {
        foreach (self::concerns() as $concern) {
            self::assertNotSame('', trim($concern->label()), $concern->key().' has no label.');
            self::assertNotSame('', trim($concern->description()), \sprintf('"%s" has no sentence. The matrix prints one under every row, and a row without one is the single row an administrator cannot read.', $concern->key()));
            self::assertNotSame([], $concern->verbs(), \sprintf('"%s" supports no verb, so it is a row with no cell.', $concern->key()));
            self::assertNotSame([], $concern->scopeKinds(), \sprintf('"%s" offers no scope, so a grant on it reaches nowhere.', $concern->key()));
        }
    }

    public function testAModulesConcernNamesItsModule(): void
    {
        $slug = static::moduleSlug();

        foreach (self::concerns() as $concern) {
            if (null === $slug) {
                // A package that is not a module declares concerns belonging
                // to none, and claiming one would make the department
                // question ask about a module nobody can install.
                self::assertNull($concern->moduleSlug(), \sprintf('"%s" names a module, but this package is not one.', $concern->key()));
                continue;
            }

            self::assertSame(
                $slug,
                $concern->moduleSlug(),
                \sprintf(
                    '"%s" names no module, so the third question a check asks — does the placement cover the department — cannot be asked about it, and it would be silently exempt.',
                    $concern->key(),
                ),
            );
        }
    }

    public function testOwnScopeComesWithTheModulesOwnWordsAndNothingElseCarriesThem(): void
    {
        foreach (self::concerns() as $concern) {
            if ($concern->offers(ScopeKind::Own)) {
                self::assertNotNull($concern->ownWords(), \sprintf('"%s" offers the "own" scope and says nothing about what own means. The core has no word for it.', $concern->key()));
                self::assertNotSame('', trim((string) $concern->ownWords()));

                continue;
            }

            // The other half, so a package offering "own" nowhere is still
            // held to something rather than passing without an assertion.
            self::assertNull(
                $concern->ownWords(),
                \sprintf('"%s" gives words for "own" and does not offer it, so the words name nothing.', $concern->key()),
            );
        }
    }

    public function testTheSensitiveConcernsAreTheOnesThisPackageNames(): void
    {
        $declared = [];
        foreach (self::concerns() as $concern) {
            if ($concern->isSensitive()) {
                $declared[] = $concern->key();
            }
        }

        sort($declared);
        $expected = static::sensitiveConcerns();
        sort($expected);

        self::assertSame($expected, $declared, 'a fact about a person or a case is declared sensitive so it can be withheld without withholding the page it sits on; a change here is a change to what an organization can hold back.');
    }

    /**
     * A VOTER THAT DOES NOT NAME ITS QUESTIONS CLAIMS EVERY ONE. The decision
     * manager asks a voter only where its `supportsAttribute()` says yes;
     * the base class's answer is yes to everything, and a voter that is not
     * cacheable at all is asked every time.
     *
     * @see https://symfony.com/doc/current/security/voters.html#improving-voter-performance
     * @see vendor/symfony/security-core/Authorization/AccessDecisionManager.php — getVoters()
     */
    public function testEveryVoterInThisPackageSaysWhichQuestionsItAnswers(): void
    {
        $silent = [];

        foreach (self::shippedSource() as $path) {
            $code = (string) file_get_contents($path);

            $voter = 1 === preg_match('/\bextends\s+Voter\b/', $code)
                || 1 === preg_match('/\bimplements\b[^{]*\bCacheableVoterInterface\b/', $code);
            $bare = 1 === preg_match('/\bimplements\b[^{]*\bVoterInterface\b/', $code);

            if ($voter && !str_contains($code, 'function supportsAttribute(')) {
                $silent[] = basename($path).' does not override supportsAttribute()';
            } elseif (!$voter && $bare) {
                $silent[] = basename($path).' is a VoterInterface the decision manager cannot skip; extend Voter and override supportsAttribute()';
            }
        }

        self::assertSame([], $silent, implode("\n", $silent)."\nA voter answers only the questions it names, so no rule beside it can be outvoted.");
    }

    /**
     * Every pair this package declares, for a module's own router walk to
     * hold its routes against.
     *
     * @return list<string>
     */
    final protected static function declaredPairs(): array
    {
        $pairs = [];
        foreach (self::concerns() as $concern) {
            foreach (Verb::cases() as $verb) {
                if ($concern->supports($verb)) {
                    $pairs[] = (string) Grant::of($concern->key(), $verb);
                }
            }
        }

        return $pairs;
    }

    public function testEveryDoorInThisPackageGoesThroughTheHelper(): void
    {
        $direct = [];

        foreach (self::templates() as $path) {
            $markup = (string) preg_replace('/\{#.*?#\}/s', '', (string) file_get_contents($path));

            if (1 === preg_match('/\bis_granted\s*\(/', $markup)) {
                $direct[] = basename($path);
            }
        }

        sort($direct);

        self::assertSame([], $direct, \sprintf(
            "These templates ask the authorization checker directly [%s].\n".
            'Every door goes through `door(\'<concern>.<verb>\', subject)`, which is what lets a test walk them '.
            'all and hold them against the routes.',
            implode(', ', $direct),
        ));
    }

    public function testEveryDoorInThisPackageNamesAPairThisPackageOrTheCoreDeclares(): void
    {
        $mine = self::declaredPairs();
        $strangers = [];

        foreach (self::templates() as $path) {
            $markup = (string) preg_replace('/\{#.*?#\}/s', '', (string) file_get_contents($path));

            preg_match_all("/door\(\s*'([^']*)'/", $markup, $matches);
            foreach ($matches[1] as $pair) {
                if (1 !== preg_match('/^[a-z0-9]+(-[a-z0-9]+)*\.[a-z]+$/', $pair)) {
                    $strangers[] = basename($path).' -> '.$pair.' (not a pair)';
                    continue;
                }

                // A module may legitimately draw a door on a core concern —
                // reading an area, say — so only a pair that is neither its
                // own nor plausibly the core's is reported. What it cannot do
                // is name a verb of its OWN concern that it never declared.
                $grant = Grant::parse($pair);
                $ours = null !== self::concern($grant->concern);
                if ($ours && !\in_array($pair, $mine, true)) {
                    $strangers[] = basename($path).' -> '.$pair.' (this package declares the concern but not that verb)';
                }
            }
        }

        sort($strangers);

        self::assertSame([], $strangers, \sprintf(
            'These doors name a pair this package does not declare [%s].',
            implode(', ', $strangers),
        ));
    }

    /**
     * EVERY ROUTE UNDER AN AREA THAT GATES A PER-AREA CONCERN PASSES IT.
     *
     * `#[IsGranted('watches.read')]` with no `subject:` asks the voter with a
     * null subject, and a null subject means "no area in context" — which any
     * placement reaching some ground at all satisfies. On a route whose path
     * names an area that is the second question being asked and always
     * answered yes: somebody placed at one area opens another area's page.
     *
     * ONLY THIS PACKAGE'S OWN CONCERNS ARE JUDGED, and only the ones that
     * offer {@see ScopeKind::Area}: a concern about people offers
     * organization or department and is not about the ground its page is
     * drawn under, so requiring a subject there would be requiring a scope
     * the declaration does not offer.
     *
     * IT READS THE SOURCE rather than the router, because a module's
     * conformance runs without a kernel. A gate written in code passes its
     * subject as an argument and is out of reach here — sweep those by hand.
     */
    public function testEveryRouteUnderAnAreaPassesItToItsPerAreaGates(): void
    {
        $blind = [];

        foreach (self::shippedSource() as $path) {
            foreach (self::gatesUnderAnArea((string) file_get_contents($path)) as $pair) {
                $concern = self::concern(explode('.', $pair)[0]);
                if (null !== $concern && $concern->offers(ScopeKind::Area)) {
                    $blind[] = basename($path).' -> '.$pair;
                }
            }
        }

        sort($blind);

        self::assertSame([], $blind, \sprintf(
            "These routes name an area in their path and ask a per-area pair without it [%s].\n".
            'Pass the area: `#[IsGranted(\'<pair>\', subject: \'area\')]`, with the controller taking the '.
            'resolved area rather than a bare uuid.',
            implode(', ', $blind),
        ));
    }

    /**
     * The pairs gated WITHOUT a subject on routes whose path names an area,
     * read off one shipped file.
     *
     * @return list<string>
     */
    private static function gatesUnderAnArea(string $source): array
    {
        $pairs = [];

        // One chunk per route: from its `#[Route(` to the signature it sits
        // above, so a gate is only ever credited to the route it belongs to.
        foreach (\array_slice(explode('#[Route(', $source), 1) as $chunk) {
            $head = explode('function ', $chunk, 2)[0];

            if (1 !== preg_match("/'(\/[^']*)'/", $head, $path)) {
                continue;
            }

            if (!str_starts_with($path[1], '/areas/') || 1 !== preg_match('/\{\w*[Uu]uid\}/', $path[1])) {
                continue;
            }

            preg_match_all("/#\[IsGranted\(\s*'([a-z0-9]+(?:-[a-z0-9]+)*\.[a-z]+)'\s*\)\]/", $head, $gates);
            foreach ($gates[1] as $pair) {
                $pairs[] = $pair;
            }
        }

        return $pairs;
    }

    /**
     * Every PHP file this package ships, so a gate can be found wherever it
     * is written. A gate inside a test is a fixture, not an enforcement.
     *
     * @return list<string>
     */
    private static function shippedSource(): array
    {
        $directory = static::bundlePath().'/src';
        if (!is_dir($directory)) {
            $directory = static::bundlePath();
        }

        $paths = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
        );

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if ('php' === $file->getExtension() && !str_contains($file->getPathname(), '/tests/')) {
                $paths[] = $file->getPathname();
            }
        }

        sort($paths);

        return $paths;
    }

    /** @return list<ConcernInterface> */
    private static function concerns(): array
    {
        return array_values([...static::source()->concerns()]);
    }

    private static function concern(string $key): ?ConcernInterface
    {
        foreach (self::concerns() as $concern) {
            if ($key === $concern->key()) {
                return $concern;
            }
        }

        return null;
    }

    /** @return list<string> */
    private static function templates(): array
    {
        $directory = static::bundlePath().'/templates';
        if (!is_dir($directory)) {
            return [];
        }

        $paths = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
        );

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if ('twig' === $file->getExtension()) {
                $paths[] = $file->getPathname();
            }
        }

        sort($paths);

        return $paths;
    }
}
