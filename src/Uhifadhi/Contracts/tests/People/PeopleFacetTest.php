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

namespace Uhifadhi\Contracts\Tests\People;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Uhifadhi\Contracts\People\PeopleFacet;
use Uhifadhi\Contracts\People\PeopleFacetGroup;
use Uhifadhi\Contracts\People\PeopleFacetOption;

/**
 * ONE DROPDOWN A MODULE HANDS THE PEOPLE REGISTER — its options carry the
 * people they apply to, so the count and the filter are one fact.
 */
#[CoversClass(PeopleFacet::class)]
#[CoversClass(PeopleFacetGroup::class)]
#[CoversClass(PeopleFacetOption::class)]
final class PeopleFacetTest extends TestCase
{
    public function testAnOptionCountsThePeopleItCarries(): void
    {
        $option = new PeopleFacetOption('at_post', 'At post', ['u1', 'u2']);

        self::assertSame(2, $option->count());
    }

    public function testTheFacetFindsAnOptionByItsValueAcrossGroups(): void
    {
        $facet = $this->facet();

        self::assertSame('Unfit for duty', $facet->option('unfit')?->label);
        self::assertSame(['u3'], $facet->peopleWith('none'));
        self::assertNull($facet->option('stale'), 'a value no option carries is nobody\'s, not everybody\'s');
        self::assertNull($facet->peopleWith('stale'));
    }

    public function testTheKeyIsAQueryParameterName(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new PeopleFacet('On duty', 'status', []);
    }

    public function testTheLabelIsNotBlank(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new PeopleFacet('status', ' ', []);
    }

    public function testTwoOptionsCannotShareAValue(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new PeopleFacet('status', 'status', [
            new PeopleFacetGroup(null, [new PeopleFacetOption('at_post', 'At post', [])]),
            new PeopleFacetGroup('Elsewhere', [new PeopleFacetOption('at_post', 'At the post', [])]),
        ]);
    }

    public function testAnOptionNamesItsValueAndLabel(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new PeopleFacetOption('', 'At post', []);
    }

    private function facet(): PeopleFacet
    {
        return new PeopleFacet('status', 'status', [
            new PeopleFacetGroup(null, [
                new PeopleFacetOption('at_post', 'At post', ['u1', 'u2']),
                new PeopleFacetOption('unfit', 'Unfit for duty', []),
            ]),
            new PeopleFacetGroup(null, [
                new PeopleFacetOption('none', 'No check-in today', ['u3']),
            ]),
        ]);
    }
}
