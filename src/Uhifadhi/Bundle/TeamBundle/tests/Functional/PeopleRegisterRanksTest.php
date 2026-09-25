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
use Uhifadhi\Bundle\TeamBundle\Entity\Rank;
use Uhifadhi\Bundle\TeamBundle\Entity\TeamSettings;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Bundle\TeamBundle\Service\RankService;

/**
 * THE PEOPLE REGISTER WITH RANKS — the Rank column after Position, the Rank
 * facet (grouped by scale only when there are several), the scale tag on the
 * code, and the export door carrying the rows the filter shows.
 */
#[CoversClass(TeamController::class)]
final class PeopleRegisterRanksTest extends WebTestCaseWithSchema
{
    public function testTheRankColumnFollowsPositionWithTheCodeOverTheName(): void
    {
        $this->administrator();
        [$one] = $this->ladder();
        $this->holds('Joseph', 'Mollel', $one);
        $this->em->flush();

        $crawler = $this->client->request('GET', '/team');

        self::assertSame(['Person', 'Tier', 'Position', 'Rank', 'Ranger code', 'Account', ''], $crawler->filter('table.tbl thead th')->each(static fn (Crawler $th): string => trim($th->text())));
        $row = $this->rowOf($crawler, 'Joseph Mollel');
        self::assertSame('CR I', $row->filter('.tm-rk b')->text());
        self::assertSame('Conservation Ranger I', $row->filter('.tm-rk em')->text());
        self::assertCount(0, $row->filter('.tm-rk .ld'), 'one scale carries no tag');
        self::assertCount(1, $this->rowOf($crawler, 'Naomi Kileo')->filter('.tm-rk.none'));
    }

    public function testTheRankFacetIsFlatWithOneScaleAndCountsTheWholeSet(): void
    {
        $this->administrator();
        [$one] = $this->ladder();
        $this->holds('Joseph', 'Mollel', $one);
        $this->em->flush();

        $facet = $this->client->request('GET', '/team?q=naomi')->filter('.tm-tools details.i-dd[data-facet="rank"]');

        self::assertSame('any rank', trim($facet->filter('.i-ddval')->text()));
        self::assertSame(['rank'], $facet->filter('.i-ddhead')->each(static fn (Crawler $h): string => $h->text()));
        self::assertSame(
            ['Any 2', 'CR I · Conservation Ranger I 1', 'CR II · Conservation Ranger II 0', 'No rank 1'],
            $facet->filter('a.i-ddopt')->each(self::option(...)),
        );
    }

    public function testSeveralScalesGroupTheFacetAndTagTheCode(): void
    {
        $this->administrator();
        [$one] = $this->ladder();
        $civil = $this->ranks()->addScale('Civil', 'Uniformed');
        $officer = $this->ranks()->addRank($civil, 'Officer I', 'O I');
        $this->holds('Joseph', 'Mollel', $one);
        $this->holds('Anna', 'Sanka', $officer);
        $this->em->flush();

        $crawler = $this->client->request('GET', '/team');

        self::assertSame(['rank', 'Uniformed', 'Civil'], $crawler->filter('details[data-facet="rank"] .i-ddhead')->each(static fn (Crawler $h): string => $h->text()));
        self::assertSame('Uniformed', $this->rowOf($crawler, 'Joseph Mollel')->filter('.tm-rk .ld')->text());
        self::assertSame('Civil', $this->rowOf($crawler, 'Anna Sanka')->filter('.tm-rk .ld')->text());
    }

    public function testTheRankFacetFiltersAndNoRankFindsThoseWithout(): void
    {
        $this->administrator();
        [$one] = $this->ladder();
        $this->holds('Joseph', 'Mollel', $one);
        $this->em->flush();

        $crawler = $this->client->request('GET', '/team?rank='.$one->getUuidString());
        self::assertSame(['Joseph Mollel'], $crawler->filter('table.tbl tbody .who b')->each(static fn (Crawler $b): string => $b->text()));
        self::assertSame('CR I · Conservation Ranger I', trim($crawler->filter('details[data-facet="rank"] .i-ddval')->text()));
        self::assertStringContainsString('on', (string) $crawler->filter('details[data-facet="rank"] summary')->attr('class'));

        $none = $this->client->request('GET', '/team?rank=none');
        self::assertSame(['Naomi Kileo'], $none->filter('table.tbl tbody .who b')->each(static fn (Crawler $b): string => $b->text()));
    }

    public function testThePositionAndAccountFacetsAreGroupedDropdowns(): void
    {
        $this->administrator();
        $this->position('Ranger');
        $this->person('Anna', 'Sanka')->setVerified(false);
        $this->em->flush();

        $crawler = $this->client->request('GET', '/team');

        self::assertSame(['position', 'department', 'station', 'rank', 'account'], $crawler->filter('.tm-tools details.i-dd')->each(static fn (Crawler $d): string => (string) $d->attr('data-facet')));
        self::assertCount(0, $crawler->filter('.tm-tools select'), 'no select is left in the bar');
        self::assertSame(
            ['Any 2', 'Ranger 0', 'No position 2'],
            $crawler->filter('details[data-facet="position"] a.i-ddopt')->each(self::option(...)),
        );
        self::assertSame(
            ['Any 2', 'Active 2', 'Deactivated 0', 'Never signed in 1'],
            $crawler->filter('details[data-facet="account"] a.i-ddopt')->each(self::option(...)),
        );
    }

    public function testTheExportDoorCarriesTheFilterAndTheFileItsRows(): void
    {
        $this->administrator();
        [$one] = $this->ladder();
        $this->holds('Joseph', 'Mollel', $one)->setRangerCode('R-101');
        $this->holds('Anna', 'Sanka', $one);
        $this->em->flush();

        $door = $this->client->request('GET', '/team?rank='.$one->getUuidString())->filter('.tm-tools a.tm-export');
        self::assertSame('export 2 rows · CSV', preg_replace('/\s+/', ' ', trim($door->text())));
        self::assertSame('/team/people.csv?rank='.$one->getUuidString(), $door->attr('href'));

        $this->client->request('GET', '/team/people.csv?rank='.$one->getUuidString());
        self::assertResponseIsSuccessful();
        self::assertStringStartsWith('text/csv', (string) $this->client->getResponse()->headers->get('Content-Type'));
        $lines = array_values(array_filter(explode("\n", (string) $this->client->getInternalResponse()->getContent())));
        self::assertSame('Person,Email,Tier,Position,Rank,"Rank name",Scale,"Ranger code",Account', $lines[0]);
        self::assertCount(3, $lines);
        self::assertStringStartsWith('"Anna Sanka",a.sanka@example.test,Staff,,"CR I","Conservation Ranger I",,,', $lines[1]);
        self::assertStringContainsString(',R-101,', $lines[2]);
    }

    public function testWithoutTheExportVerbThereIsNoDoorAndNoFile(): void
    {
        $reader = $this->person('Wera', 'Mwita');
        $reader->setPosition($this->position('Reader', ['directory.read']));
        $this->place($reader);
        $this->em->flush();
        $this->client->loginUser($reader);

        self::assertCount(0, $this->client->request('GET', '/team')->filter('a.tm-export'));
        $this->client->request('GET', '/team/people.csv');
        self::assertResponseStatusCodeSame(403);
    }

    public function testAnOrganizationWithoutRanksHasNoRankColumnFacetOrExportColumn(): void
    {
        $this->administrator();
        $this->person('Anna', 'Sanka');
        $this->em->persist((new TeamSettings())->setUsesRanks(false));
        $this->em->flush();

        $crawler = $this->client->request('GET', '/team');
        self::assertNotContains('Rank', $crawler->filter('table.tbl thead th')->each(static fn (Crawler $th): string => trim($th->text())));
        self::assertCount(0, $crawler->filter('details[data-facet="rank"]'));

        $this->client->request('GET', '/team/people.csv');
        $lines = explode("\n", (string) $this->client->getInternalResponse()->getContent());
        self::assertSame('Person,Email,Tier,Position,"Ranger code",Account', $lines[0]);
    }

    /** @return list<Rank> */
    private function ladder(): array
    {
        $scale = $this->ranks()->defaultScale();

        return [
            $this->ranks()->addRank($scale, 'Conservation Ranger I', 'CR I'),
            $this->ranks()->addRank($scale, 'Conservation Ranger II', 'CR II'),
        ];
    }

    private function holds(string $first, string $last, Rank $rank): User
    {
        $person = $this->person($first, $last);
        $this->em->flush();
        $this->ranks()->assign($person, $rank, new \DateTimeImmutable('2024-01-14'), null);

        return $person;
    }

    private function rowOf(Crawler $crawler, string $name): Crawler
    {
        return $crawler->filter('table.tbl tbody tr')->reduce(static fn (Crawler $tr): bool => str_contains($tr->filter('.who')->text(''), $name));
    }

    private static function option(Crawler $a): string
    {
        return trim($a->filter('.i-ddopt-l')->text()).' '.trim($a->filter('.i-ddopt-n')->text());
    }

    private function ranks(): RankService
    {
        $ranks = static::getContainer()->get('test_public.'.RankService::class);
        \assert($ranks instanceof RankService);

        return $ranks;
    }
}
