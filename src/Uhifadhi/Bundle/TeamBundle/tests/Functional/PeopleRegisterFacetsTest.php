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

namespace Uhifadhi\Bundle\TeamBundle\Tests\Functional;

use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\DomCrawler\Crawler;
use Uhifadhi\Bundle\TeamBundle\Controller\TeamController;
use Uhifadhi\Bundle\TeamBundle\Service\PeopleFacetService;
use Uhifadhi\Bundle\TeamBundle\Tests\Integration\Fixtures\FakePeopleFacet;
use Uhifadhi\Bundle\TeamBundle\Tests\Integration\Fixtures\FakePersonPostings;
use Uhifadhi\Contracts\People\PeopleFacet;
use Uhifadhi\Contracts\People\PeopleFacetGroup;
use Uhifadhi\Contracts\People\PeopleFacetOption;
use Uhifadhi\Contracts\People\PersonPosting;

/**
 * THE PEOPLE REGISTER'S STATION AND STATUS FACETS — the station dropdown
 * read through the posting seam, grouped by area once there are several,
 * with Not stationed last; the dropdown a module contributes, drawn after
 * Rank and before Account; and both narrowing the rows and the export.
 */
#[CoversClass(TeamController::class)]
#[CoversClass(PeopleFacetService::class)]
final class PeopleRegisterFacetsTest extends WebTestCaseWithSchema
{
    private const string LAKE = '019a0000-0000-7000-8000-00000000f1e2';
    private const string OTHER_AREA = '019a0000-0000-7000-8000-00000000a0eb';

    protected function setUp(): void
    {
        parent::setUp();
        FakePersonPostings::$at = [];
        FakePeopleFacet::$facet = null;
    }

    protected function tearDown(): void
    {
        FakePersonPostings::$at = [];
        FakePeopleFacet::$facet = null;
        parent::tearDown();
    }

    public function testTheStationFacetListsWhereEverybodyStandsAndCountsTheWholeSet(): void
    {
        $this->administrator();
        $joseph = $this->person('Joseph', 'Mollel');
        $anna = $this->person('Anna', 'Sanka');
        $this->em->flush();
        FakePersonPostings::$at = [
            (string) $joseph->getUuidString() => [FakePersonPostings::eastgate()],
            (string) $anna->getUuidString() => [FakePersonPostings::eastgate(false)],
        ];

        $crawler = $this->client->request('GET', '/team?q=naomi');

        self::assertSame(['position', 'department', 'station', 'rank', 'account'], $crawler->filter('.tm-tools details.i-dd')->each(static fn (Crawler $d): string => (string) $d->attr('data-facet')));
        $facet = $crawler->filter('details.i-dd[data-facet="station"]');
        self::assertStringNotContainsString(' r', (string) $facet->attr('class'), 'the station menu opens to the right, as the design draws it');
        self::assertSame('any station', trim($facet->filter('.i-ddval')->text()));
        self::assertSame(['station'], $facet->filter('.i-ddhead')->each(static fn (Crawler $h): string => $h->text()), 'one area draws no area head');
        self::assertSame(['Any 3', 'Eastgate Post 2', 'Not stationed 1'], $facet->filter('a.i-ddopt')->each(self::option(...)));
    }

    public function testSeveralAreasHeadTheirStationsWithTheAreaName(): void
    {
        $this->administrator();
        $joseph = $this->person('Joseph', 'Mollel');
        $anna = $this->person('Anna', 'Sanka');
        $this->em->flush();
        FakePersonPostings::$at = [
            (string) $joseph->getUuidString() => [$this->lakePost()],
            (string) $anna->getUuidString() => [FakePersonPostings::eastgate()],
        ];

        $facet = $this->client->request('GET', '/team')->filter('details.i-dd[data-facet="station"]');

        // AREAS IN NAME ORDER, their stations in name order under each.
        self::assertSame(['station', 'Other Area', 'Sample Area'], $facet->filter('.i-ddhead')->each(static fn (Crawler $h): string => $h->text()));
        self::assertSame(['Any 3', 'Lake Post 1', 'Eastgate Post 1', 'Not stationed 1'], $facet->filter('a.i-ddopt')->each(self::option(...)));
    }

    public function testTheStationFacetNarrowsTheRowsAndTheExport(): void
    {
        $this->administrator();
        $joseph = $this->person('Joseph', 'Mollel');
        $anna = $this->person('Anna', 'Sanka');
        $this->em->flush();
        FakePersonPostings::$at = [
            (string) $joseph->getUuidString() => [FakePersonPostings::eastgate()],
            (string) $anna->getUuidString() => [$this->lakePost()],
        ];

        $crawler = $this->client->request('GET', '/team?station='.FakePersonPostings::STATION);
        self::assertSame(['Joseph Mollel'], $this->names($crawler));
        self::assertSame('Eastgate Post', trim($crawler->filter('details[data-facet="station"] .i-ddval')->text()));
        self::assertStringContainsString('on', (string) $crawler->filter('details[data-facet="station"] summary')->attr('class'));
        self::assertSame('/team/people.csv?station='.FakePersonPostings::STATION, $crawler->filter('a.tm-export')->attr('href'));
        self::assertSame('export 1 rows · CSV', preg_replace('/\s+/', ' ', trim($crawler->filter('a.tm-export')->text())));

        self::assertSame(['Naomi Kileo'], $this->names($this->client->request('GET', '/team?station=none')));
        self::assertSame([], $this->names($this->client->request('GET', '/team?station='.self::OTHER_AREA)), 'a stale value matches nobody');

        $this->client->request('GET', '/team/people.csv?station='.FakePersonPostings::STATION);
        $lines = array_values(array_filter(explode("\n", (string) $this->client->getInternalResponse()->getContent())));
        self::assertSame('Person,Email,Tier,Position,Rank,"Rank name",Scale,"Ranger code",Account', $lines[0], 'the columns are the table\'s; a facet adds none');
        self::assertCount(2, $lines);
        self::assertStringStartsWith('"Joseph Mollel"', $lines[1]);
    }

    public function testAContributedFacetIsDrawnAfterRankAndBeforeAccountAndOpensLeft(): void
    {
        $this->administrator();
        $joseph = $this->person('Joseph', 'Mollel');
        $anna = $this->person('Anna', 'Sanka');
        $this->em->flush();
        FakePeopleFacet::$facet = $this->statusFacet([(string) $joseph->getUuidString()], [(string) $anna->getUuidString()]);

        $crawler = $this->client->request('GET', '/team');

        self::assertSame(['position', 'department', 'station', 'rank', 'status', 'account'], $crawler->filter('.tm-tools details.i-dd')->each(static fn (Crawler $d): string => (string) $d->attr('data-facet')));
        $facet = $crawler->filter('details.i-dd[data-facet="status"]');
        self::assertStringContainsString('r', (string) $facet->attr('class'));
        self::assertSame('any status', trim($facet->filter('.i-ddval')->text()));
        self::assertSame(['status'], $facet->filter('.i-ddhead')->each(static fn (Crawler $h): string => $h->text()));
        self::assertSame(['Any 3', 'At post 1', 'Unfit for duty 0', 'No check-in today 1'], $facet->filter('a.i-ddopt')->each(self::option(...)));
        self::assertCount(3, FakePeopleFacet::$askedFor, 'the provider is asked about everybody, so the counts are the whole set\'s');
    }

    public function testAContributedFacetNarrowsTheRowsAndTheExportAndKeepsTheOtherFilters(): void
    {
        $this->administrator();
        $joseph = $this->person('Joseph', 'Mollel');
        $anna = $this->person('Anna', 'Sanka');
        $this->em->flush();
        FakePeopleFacet::$facet = $this->statusFacet([(string) $joseph->getUuidString(), (string) $anna->getUuidString()], []);
        FakePersonPostings::$at = [(string) $anna->getUuidString() => [FakePersonPostings::eastgate()]];

        $crawler = $this->client->request('GET', '/team?status=at_post');
        self::assertSame(['Anna Sanka', 'Joseph Mollel'], $this->names($crawler));
        self::assertSame('At post', trim($crawler->filter('details[data-facet="status"] .i-ddval')->text()));
        self::assertStringContainsString('showing 2 of 2', preg_replace('/\s+/', ' ', $crawler->filter('.tm-shown')->text()) ?? '');

        // TWO SEAM FACETS AT ONCE intersect, and every option keeps the other's choice.
        $both = $this->client->request('GET', '/team?status=at_post&station='.FakePersonPostings::STATION);
        self::assertSame(['Anna Sanka'], $this->names($both));
        self::assertSame('/team?station=none&status=at_post', $both->filter('details[data-facet="station"] a.i-ddopt')->last()->attr('href'));
        self::assertSame('/team/people.csv?station='.FakePersonPostings::STATION.'&status=at_post', $both->filter('a.tm-export')->attr('href'));

        self::assertSame([], $this->names($this->client->request('GET', '/team?status=stale')), 'a value no option carries matches nobody');

        $this->client->request('GET', '/team/people.csv?status=at_post');
        $lines = array_values(array_filter(explode("\n", (string) $this->client->getInternalResponse()->getContent())));
        self::assertCount(3, $lines);
    }

    public function testAProviderWithNothingToSayDrawsNoDropdownAndItsKeyIsIgnored(): void
    {
        $this->administrator();
        $this->em->flush();

        $crawler = $this->client->request('GET', '/team?status=at_post');

        self::assertCount(0, $crawler->filter('details[data-facet="status"]'));
        self::assertSame(['Naomi Kileo'], $this->names($crawler));
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

    private function lakePost(): PersonPosting
    {
        return new PersonPosting(self::LAKE, 'Lake Post', 'ST-02', self::OTHER_AREA, 'Other Area', null, new \DateTimeImmutable('2025-02-01'));
    }

    /** @return list<string> */
    private function names(Crawler $crawler): array
    {
        return $crawler->filter('table.tbl tbody .who b')->each(static fn (Crawler $b): string => $b->text());
    }

    private static function option(Crawler $a): string
    {
        return trim($a->filter('.i-ddopt-l')->text()).' '.trim($a->filter('.i-ddopt-n')->text());
    }
}
