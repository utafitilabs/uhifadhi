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

namespace Uhifadhi\Contracts\Tests\Access;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Uhifadhi\Contracts\Access\Concern;
use Uhifadhi\Contracts\Access\ConcernInterface;
use Uhifadhi\Contracts\Access\ConcernSourceInterface;
use Uhifadhi\Contracts\Access\ScopeKind;
use Uhifadhi\Contracts\Access\Verb;

/**
 * THE VOCABULARY EVERY PERMISSION IS SPELLED IN.
 *
 * Six verbs, four scope kinds, and a concern that exists only because
 * somebody declared it. These are the words the matrix is generated from, so
 * the test holds their number and their spelling: a seventh verb or a
 * renamed scope is a change to every position ever written, and it should
 * cost a failing test to make.
 */
final class AccessContractTest extends TestCase
{
    public function testThereAreSixVerbsAndTheyAreTheRuledOnes(): void
    {
        self::assertSame(
            ['read', 'record', 'manage', 'configure', 'delete', 'export'],
            array_map(static fn (Verb $v): string => $v->value, Verb::cases()),
        );
    }

    #[DataProvider('everyVerb')]
    public function testEveryVerbSaysWhatItIsAndWhatItMeans(Verb $verb): void
    {
        self::assertNotSame('', trim($verb->label()));
        self::assertNotSame('', trim($verb->meaning()));
    }

    /** @return iterable<string, array{Verb}> */
    public static function everyVerb(): iterable
    {
        foreach (Verb::cases() as $verb) {
            yield $verb->value => [$verb];
        }
    }

    public function testThereAreFourScopeKinds(): void
    {
        self::assertSame(
            ['organization', 'area', 'department', 'own'],
            array_map(static fn (ScopeKind $s): string => $s->value, ScopeKind::cases()),
        );
    }

    #[DataProvider('everyScopeKind')]
    public function testEveryScopeKindSaysHowFarItReaches(ScopeKind $kind): void
    {
        self::assertNotSame('', trim($kind->label()));
        self::assertNotSame('', trim($kind->reach()));
    }

    /** @return iterable<string, array{ScopeKind}> */
    public static function everyScopeKind(): iterable
    {
        foreach (ScopeKind::cases() as $kind) {
            yield $kind->value => [$kind];
        }
    }

    public function testAConcernCarriesItsKeyLabelSentenceVerbsAndScopes(): void
    {
        $concern = new Concern(
            key: 'zones',
            label: 'Zones',
            description: 'Work with the zones an area is divided into.',
            verbs: [Verb::Read, Verb::Configure],
            scopeKinds: [ScopeKind::Organization, ScopeKind::Area],
        );

        self::assertInstanceOf(ConcernInterface::class, $concern);
        self::assertSame('zones', $concern->key());
        self::assertSame('Zones', $concern->label());
        self::assertSame('Work with the zones an area is divided into.', $concern->description());
        self::assertSame([Verb::Read, Verb::Configure], $concern->verbs());
        self::assertSame([ScopeKind::Organization, ScopeKind::Area], $concern->scopeKinds());
        self::assertFalse($concern->isSensitive());
        self::assertNull($concern->ownWords());
        self::assertNull($concern->moduleSlug());
    }

    public function testAConcernAnswersWhetherItSupportsAVerbAndOffersAScope(): void
    {
        $concern = new Concern(
            key: 'zones',
            label: 'Zones',
            description: 'Work with the zones an area is divided into.',
            verbs: [Verb::Read, Verb::Configure],
            scopeKinds: [ScopeKind::Organization, ScopeKind::Area],
        );

        self::assertTrue($concern->supports(Verb::Read));
        self::assertFalse($concern->supports(Verb::Delete));
        self::assertTrue($concern->offers(ScopeKind::Area));
        self::assertFalse($concern->offers(ScopeKind::Own));
    }

    public function testAConcernWithoutASentenceIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/without a description/');

        new Concern('zones', 'Zones', '  ', [Verb::Read], [ScopeKind::Area]);
    }

    public function testAConcernSupportingNoVerbIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/at least one verb/');

        new Concern('zones', 'Zones', 'Zones of an area.', [], [ScopeKind::Area]);
    }

    public function testAConcernOfferingNoScopeIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/at least one scope/');

        new Concern('zones', 'Zones', 'Zones of an area.', [Verb::Read], []);
    }

    public function testAConcernRepeatingAVerbIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/twice/');

        new Concern('zones', 'Zones', 'Zones of an area.', [Verb::Read, Verb::Read], [ScopeKind::Area]);
    }

    public function testOwnScopeWithoutTheModulesOwnWordsIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/own words/');

        new Concern('watches', 'Watches', 'The roster watches.', [Verb::Read], [ScopeKind::Own]);
    }

    public function testOwnWordsWithoutOwnScopeAreRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/does not offer/');

        new Concern(
            key: 'watches',
            label: 'Watches',
            description: 'The roster watches.',
            verbs: [Verb::Read],
            scopeKinds: [ScopeKind::Area],
            ownWords: 'Own watch',
        );
    }

    public function testAKeyThatIsNotASlugIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/lowercase/');

        new Concern('Personal Details', 'Personal details', 'Contact details.', [Verb::Read], [ScopeKind::Organization]);
    }

    public function testASensitiveConcernOfAModuleSaysBoth(): void
    {
        $concern = new Concern(
            key: 'live-positions',
            label: 'Live positions',
            description: 'See where a ranger is now, and their ping history.',
            verbs: [Verb::Read],
            scopeKinds: [ScopeKind::Organization, ScopeKind::Area, ScopeKind::Department, ScopeKind::Own],
            sensitive: true,
            ownWords: 'Own team',
            moduleSlug: 'roster',
        );

        self::assertTrue($concern->isSensitive());
        self::assertSame('Own team', $concern->ownWords());
        self::assertSame('roster', $concern->moduleSlug());
    }

    public function testAnOrdinaryConcernLiftsNoRule(): void
    {
        self::assertNull(new Concern('zones', 'Zones', 'The zones an area is divided into.', [Verb::Read], [ScopeKind::Area])->lifts());
    }

    /**
     * AN EXCEPTION NAMES THE RULE IT LIFTS. A grant that overrides a rule the
     * product applies to everybody - the rank rule for live positions - is
     * not a box among boxes, and the words a screen draws it apart with are
     * the declarer's.
     */
    public function testAConcernThatLiftsARuleNamesTheRule(): void
    {
        $concern = new Concern(
            key: 'locations',
            label: 'Live locations',
            description: 'See every live position, whatever the rank.',
            verbs: [Verb::Read],
            scopeKinds: [ScopeKind::Organization, ScopeKind::Area],
            sensitive: true,
            lifts: 'the rank rule',
        );

        self::assertSame('the rank rule', $concern->lifts());
    }

    /** What lifts a rule is by definition a fact somebody may want withheld. */
    public function testAnExceptionThatIsNotSensitiveIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/sensitive/');

        new Concern('locations', 'Live locations', 'See every live position.', [Verb::Read], [ScopeKind::Area], lifts: 'the rank rule');
    }

    public function testAnExceptionThatNamesNoRuleIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/which rule/');

        new Concern('locations', 'Live locations', 'See every live position.', [Verb::Read], [ScopeKind::Area], sensitive: true, lifts: '  ');
    }

    public function testTheTagIsTheOneTheCoreCollects(): void
    {
        self::assertSame('uhifadhi.access.concerns', ConcernSourceInterface::TAG);
    }
}
