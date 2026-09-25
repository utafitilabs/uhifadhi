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

namespace Uhifadhi\Bundle\TeamBundle\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Uhifadhi\Bundle\TeamBundle\Model\PeopleFacetSet;
use Uhifadhi\Bundle\TeamBundle\Model\RosterQuery;
use Uhifadhi\Bundle\TeamBundle\Service\PeopleFacetService;
use Uhifadhi\Contracts\People\PeopleFacet;
use Uhifadhi\Contracts\People\PeopleFacetGroup;
use Uhifadhi\Contracts\People\PeopleFacetOption;
use Uhifadhi\Contracts\People\PeopleFacetProviderInterface;
use Uhifadhi\Contracts\People\PersonPosting;
use Uhifadhi\Contracts\People\PersonPostingProviderInterface;

/**
 * THE PEOPLE REGISTER'S SEAM FACETS — the station dropdown built from the
 * posting seam, the dropdowns modules contribute, and the one narrowing
 * both write into the query.
 */
#[CoversClass(PeopleFacetService::class)]
#[CoversClass(PeopleFacetSet::class)]
final class PeopleFacetServiceTest extends TestCase
{
    private const string EASTGATE = '019a0000-0000-7000-8000-00000000f1e1';
    private const string LAKE = '019a0000-0000-7000-8000-00000000f1e2';

    public function testTheStationFacetIsFlatWithOneAreaAndEndsWithNotStationed(): void
    {
        $set = $this->service(['u1' => [$this->eastgate()], 'u2' => [$this->eastgate()]])->read(['u1', 'u2', 'u3']);

        $facet = $set->station;
        self::assertSame('station', $facet->key);
        self::assertSame('any station', $facet->any);
        self::assertSame(3, $facet->total);
        self::assertFalse($facet->opensLeft);
        self::assertCount(1, $facet->groups);
        self::assertNull($facet->groups[0]->head);
        self::assertSame([self::EASTGATE => 'Eastgate Post'], $this->labels($facet->groups[0]->options));
        self::assertSame(2, $facet->groups[0]->options[0]->count);
        self::assertSame('Not stationed', $facet->absent?->label);
        self::assertSame(RosterQuery::NO_STATION, $facet->absent->value);
        self::assertSame(1, $facet->absent->count);
    }

    public function testSeveralAreasHeadTheirStationsInNameOrder(): void
    {
        $set = $this->service(['u1' => [$this->lake()], 'u2' => [$this->eastgate()]])->read(['u1', 'u2']);

        self::assertSame(['Other Area', 'Sample Area'], array_map(static fn ($g): ?string => $g->head, $set->station->groups));
        self::assertSame(0, $set->station->absent?->count);
    }

    public function testAContributedFacetBecomesAGroupedDropdownThatOpensLeft(): void
    {
        $set = $this->service([], [$this->statusFacet(['u1'], ['u2'])])->read(['u1', 'u2', 'u3']);

        self::assertSame(['status'], $set->keys());
        self::assertCount(1, $set->contributed);
        $facet = $set->contributed[0];
        self::assertSame('status', $facet->key);
        self::assertSame('status', $facet->label);
        self::assertSame('any status', $facet->any);
        self::assertSame(3, $facet->total);
        self::assertTrue($facet->opensLeft);
        self::assertNull($facet->absent, 'a contributed facet draws its own absence option as a run');
        self::assertSame(['at_post' => 'At post', 'unfit' => 'Unfit for duty'], $this->labels($facet->groups[0]->options));
        self::assertSame(1, $facet->groups[0]->options[0]->count);
        self::assertSame(['none' => 'No check-in today'], $this->labels($facet->groups[1]->options));
    }

    public function testAProviderAnsweringNullContributesNothing(): void
    {
        $set = $this->service([], [null])->read(['u1']);

        self::assertSame([], $set->keys());
        self::assertSame([], $set->contributed);
    }

    public function testAKeyTheRegisterOwnsIsRefused(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/"rank"/');

        $this->service([], [new PeopleFacet('rank', 'rank', [])])->read(['u1']);
    }

    public function testNarrowingWritesTheChosenPeopleIntoTheQuery(): void
    {
        $set = $this->service(['u1' => [$this->eastgate()], 'u2' => [$this->lake()]], [$this->statusFacet(['u1', 'u2'], ['u3'])])->read(['u1', 'u2', 'u3']);

        self::assertNull($set->narrow(new RosterQuery())->only, 'nothing chosen narrows nothing');
        self::assertSame(['u1'], $set->narrow(new RosterQuery(station: self::EASTGATE))->only);
        self::assertSame(['u3'], $set->narrow(new RosterQuery(station: RosterQuery::NO_STATION))->only);
        self::assertSame(['u1', 'u2'], $set->narrow(new RosterQuery(facets: ['status' => 'at_post']))->only);
        self::assertSame(['u1'], $set->narrow(new RosterQuery(station: self::EASTGATE, facets: ['status' => 'at_post']))->only);
        self::assertSame([], $set->narrow(new RosterQuery(station: 'stale'))->only, 'a stale station matches nobody');
        self::assertSame([], $set->narrow(new RosterQuery(facets: ['status' => 'stale']))->only, 'a stale option matches nobody');
        self::assertSame([], $set->narrow(new RosterQuery(station: self::LAKE, facets: ['status' => 'none']))->only, 'two choices intersect');
    }

    /**
     * @param array<string, list<PersonPosting>> $postings
     * @param list<PeopleFacet|null>             $facets
     */
    private function service(array $postings, array $facets = []): PeopleFacetService
    {
        $postingProvider = new class($postings) implements PersonPostingProviderInterface {
            /** @param array<string, list<PersonPosting>> $postings */
            public function __construct(private readonly array $postings)
            {
            }

            public function postingsFor(array $userUuids): array
            {
                return array_intersect_key($this->postings, array_flip($userUuids));
            }
        };

        $facetProviders = [];
        foreach ($facets as $facet) {
            $facetProviders[] = new class($facet) implements PeopleFacetProviderInterface {
                public function __construct(private readonly ?PeopleFacet $facet)
                {
                }

                public function facetFor(array $userUuids): ?PeopleFacet
                {
                    return $this->facet;
                }
            };
        }

        return new PeopleFacetService([$postingProvider], $facetProviders);
    }

    /**
     * @param list<string> $atPost
     * @param list<string> $none
     */
    private function statusFacet(array $atPost, array $none): PeopleFacet
    {
        return new PeopleFacet('status', 'status', [
            new PeopleFacetGroup(null, [
                new PeopleFacetOption('at_post', 'At post', $atPost),
                new PeopleFacetOption('unfit', 'Unfit for duty', []),
            ]),
            new PeopleFacetGroup(null, [new PeopleFacetOption('none', 'No check-in today', $none)]),
        ]);
    }

    private function eastgate(): PersonPosting
    {
        return new PersonPosting(self::EASTGATE, 'Eastgate Post', 'ST-01', '019a0000-0000-7000-8000-00000000a0ea', 'Sample Area', 'Crater', new \DateTimeImmutable('2024-01-14'), true);
    }

    private function lake(): PersonPosting
    {
        return new PersonPosting(self::LAKE, 'Lake Post', 'ST-02', '019a0000-0000-7000-8000-00000000a0eb', 'Other Area', null, new \DateTimeImmutable('2025-02-01'));
    }

    /**
     * @param list<\Uhifadhi\Bundle\TeamBundle\Model\FilterOption> $options
     *
     * @return array<string, string>
     */
    private function labels(array $options): array
    {
        $out = [];
        foreach ($options as $option) {
            $out[$option->value] = $option->label;
        }

        return $out;
    }
}
