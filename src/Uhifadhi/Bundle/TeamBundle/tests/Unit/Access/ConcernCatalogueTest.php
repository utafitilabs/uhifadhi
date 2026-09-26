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

namespace Uhifadhi\Bundle\TeamBundle\Tests\Unit\Access;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Uhifadhi\Bundle\TeamBundle\Access\ConcernCatalogue;
use Uhifadhi\Contracts\Access\Concern;
use Uhifadhi\Contracts\Access\ConcernSourceInterface;
use Uhifadhi\Contracts\Access\Grant;
use Uhifadhi\Contracts\Access\ScopeKind;
use Uhifadhi\Contracts\Access\Verb;

/**
 * EVERYTHING THERE IS TO HAVE A PERMISSION ABOUT, folded together from
 * whoever declared it.
 *
 * The catalogue is the only thing that knows what exists, so what it refuses
 * matters more than what it returns: a pair nothing declares must not be
 * grantable, a verb a concern does not support must not be a cell, and two
 * packages claiming one key must not resolve quietly to whichever registered
 * first.
 */
#[CoversClass(ConcernCatalogue::class)]
final class ConcernCatalogueTest extends TestCase
{
    public function testItGroupsEveryConcernUnderWhoeverDeclaredIt(): void
    {
        $catalogue = new ConcernCatalogue([
            self::source('Areas', [self::concern('zones', [Verb::Read, Verb::Configure])]),
            self::source('Roster', [self::concern('watches', [Verb::Read], 'roster')]),
        ]);

        self::assertSame(['Areas', 'Roster'], array_keys($catalogue->grouped()));
        self::assertSame('Areas', $catalogue->declarerOf('zones'));
        self::assertSame('Roster', $catalogue->declarerOf('watches'));
        self::assertNull($catalogue->declarerOf('nothing-declares-this'));
    }

    /**
     * A GROUP IS DRAWN EVEN WHERE ITS PACKAGE DECLARED NOTHING, because the
     * caption is the honest answer to "what did installing this give me?" and
     * silence reads as a missing module rather than a module with no powers.
     */
    public function testAPackageThatDeclaresNothingStillHasItsGroup(): void
    {
        $catalogue = new ConcernCatalogue([self::source('Storage', [])]);

        self::assertSame(['Storage' => []], $catalogue->grouped());
        self::assertSame([], $catalogue->all());
    }

    public function testEveryPairIsTheConcernsDeclaredVerbsAndNoOther(): void
    {
        $catalogue = new ConcernCatalogue([
            self::source('Areas', [self::concern('zones', [Verb::Read, Verb::Configure, Verb::Delete])]),
        ]);

        self::assertSame(['zones.read', 'zones.configure', 'zones.delete'], $catalogue->pairs());
    }

    /**
     * THE ORDER IS THE MATRIX'S, and it is the verbs' fixed order rather than
     * the order somebody happened to type them: two readings of the catalogue
     * that disagreed about column order would be two matrices.
     */
    public function testThePairsFollowTheFixedVerbOrderAndNotTheDeclarationOrder(): void
    {
        $catalogue = new ConcernCatalogue([
            self::source('Areas', [self::concern('zones', [Verb::Delete, Verb::Read])]),
        ]);

        self::assertSame(['zones.read', 'zones.delete'], $catalogue->pairs());
    }

    public function testAPairIsRecognisedOnlyWhenTheConcernSupportsThatVerb(): void
    {
        $catalogue = new ConcernCatalogue([
            self::source('Areas', [self::concern('zones', [Verb::Read])]),
        ]);

        self::assertTrue($catalogue->has(Grant::of('zones', Verb::Read)));
        self::assertFalse($catalogue->has(Grant::of('zones', Verb::Delete)), 'a cell the matrix would not draw is not a pair that can be granted.');
        self::assertFalse($catalogue->has(Grant::of('watches', Verb::Read)), 'a concern nobody declared is not a concern.');
    }

    /**
     * TWO PACKAGES CLAIMING ONE KEY IS REFUSED RATHER THAN MERGED. Keeping
     * the first would make which one wins depend on registration order — a
     * difference nobody can see and everybody would eventually depend on.
     */
    public function testTwoPackagesDeclaringOneKeyIsRefusedAndBothAreNamed(): void
    {
        $catalogue = new ConcernCatalogue([
            self::source('Areas', [self::concern('zones', [Verb::Read])]),
            self::source('Roster', [self::concern('zones', [Verb::Read])]),
        ]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/Areas and Roster/');

        $catalogue->all();
    }

    public function testItAnswersWhichModuleAConcernBelongsToAndWhetherItIsSensitive(): void
    {
        $catalogue = new ConcernCatalogue([
            self::source('Areas', [self::concern('zones', [Verb::Read])]),
            self::source('Roster', [self::concern('live-positions', [Verb::Read], 'roster', sensitive: true)]),
        ]);

        self::assertNull($catalogue->moduleOf('zones'), "a core bundle's concern belongs to no module, so the department question does not arise for it.");
        self::assertSame('roster', $catalogue->moduleOf('live-positions'));
        self::assertTrue($catalogue->isSensitive('live-positions'));
        self::assertFalse($catalogue->isSensitive('zones'));
        self::assertFalse($catalogue->isSensitive('nothing-declares-this'));
    }

    /**
     * AN EXCEPTION IS KNOWN BY THE RULE IT LIFTS, and its pairs are the ones
     * the matrix never draws and only a Super Admin writes.
     */
    public function testItNamesTheRuleAConcernLiftsAndListsTheExceptionPairs(): void
    {
        $catalogue = new ConcernCatalogue([
            self::source('Areas', [
                self::concern('zones', [Verb::Read, Verb::Configure]),
                new Concern('locations', 'Live locations', 'Every live position.', [Verb::Read], [ScopeKind::Area], sensitive: true, lifts: 'the rank rule'),
            ]),
        ]);

        self::assertSame('the rank rule', $catalogue->lifts('locations'));
        self::assertNull($catalogue->lifts('zones'));
        self::assertNull($catalogue->lifts('nothing-declares-this'));
        self::assertSame(['locations.read'], $catalogue->exceptionPairs());
        self::assertContains('locations.read', $catalogue->pairs(), 'an exception is still a pair the installation offers; it is written elsewhere, not undeclared');
    }

    /** An installation with nothing installed declares nothing, rather than failing. */
    public function testAnInstallationThatDeclaresNothingHasNoPairs(): void
    {
        $catalogue = new ConcernCatalogue();

        self::assertSame([], $catalogue->pairs());
        self::assertFalse($catalogue->has(Grant::of('zones', Verb::Read)));
    }

    /**
     * @param list<Verb> $verbs
     */
    private static function concern(string $key, array $verbs, ?string $module = null, bool $sensitive = false): Concern
    {
        return new Concern(
            key: $key,
            label: ucfirst($key),
            description: 'What '.$key.' is about.',
            verbs: $verbs,
            scopeKinds: [ScopeKind::Organization, ScopeKind::Area],
            sensitive: $sensitive,
            moduleSlug: $module,
        );
    }

    /** @param list<Concern> $concerns */
    private static function source(string $declarer, array $concerns): ConcernSourceInterface
    {
        return new class($declarer, $concerns) implements ConcernSourceInterface {
            /** @param list<Concern> $concerns */
            public function __construct(private readonly string $declarer, private readonly array $concerns)
            {
            }

            public function declaredBy(): string
            {
                return $this->declarer;
            }

            public function concerns(): iterable
            {
                return $this->concerns;
            }
        };
    }
}
