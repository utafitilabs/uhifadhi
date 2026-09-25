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
use Uhifadhi\Bundle\TeamBundle\Controller\RankConfigureController;
use Uhifadhi\Bundle\TeamBundle\Controller\RankController;
use Uhifadhi\Bundle\TeamBundle\Entity\Rank;
use Uhifadhi\Bundle\TeamBundle\Entity\RankScale;
use Uhifadhi\Bundle\TeamBundle\Entity\TeamSettings;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Bundle\TeamBundle\Service\RankService;
use Uhifadhi\Bundle\TeamBundle\Shell\TeamSectionConfiguration;
use Uhifadhi\Bundle\TeamBundle\Shell\TeamSectionTabs;

/**
 * TEAM › RANKS AND TEAM CONFIGURE › RANKS — the register of the
 * organization's ranks and who holds each, and the section that writes them.
 *
 * THE DOM IS THE PROOF: every assertion reads what the page drew, row by row.
 */
#[CoversClass(RankController::class)]
#[CoversClass(RankConfigureController::class)]
#[CoversClass(TeamSectionTabs::class)]
#[CoversClass(TeamSectionConfiguration::class)]
final class RanksTest extends WebTestCaseWithSchema
{
    // ---- the register ----------------------------------------------------

    public function testTheRegisterListsTheOneScaleInSeniorityOrderWithItsHolders(): void
    {
        $this->administrator();
        [$one] = $this->ladder();
        $this->holds('Joseph', 'Mollel', $one);
        $this->holds('Anna', 'Sanka', $one);
        $this->em->flush();

        $crawler = $this->client->request('GET', '/team/ranks');

        self::assertResponseIsSuccessful();
        self::assertSame(['Rank', 'Holders', 'Since', ''], $crawler->filter('table.tbl thead th')->each(static fn (Crawler $th): string => trim($th->text())));
        $rows = $crawler->filter('table.tbl tbody tr');
        self::assertCount(3, $rows);
        self::assertSame('1', $rows->eq(0)->filter('.rk-ord')->text());
        self::assertSame('Conservation Ranger I', $rows->eq(0)->filter('.who b')->text());
        self::assertSame('CR I', $rows->eq(0)->filter('td')->first()->filter('.tm-code')->text());
        self::assertSame('2 people', preg_replace('/\s+/', ' ', trim($rows->eq(0)->filter('a.tm-holders')->text())));
        self::assertSame('/team?rank='.$one->getUuidString(), $rows->eq(0)->filter('a.tm-holders')->attr('href'));
        self::assertSame('0 people', preg_replace('/\s+/', ' ', trim($rows->eq(1)->filter('a.tm-holders')->text())));
        self::assertSame('/team/configure/ranks#scale-'.$one->getScale()->getUuidString(), $rows->eq(0)->filter('a.open-btn')->attr('href'));
        self::assertCount(0, $crawler->filter('tr.tm-bandrow'), 'one scale draws no band');
        self::assertStringContainsString('3 ranks', $crawler->filter('.rdf-foot')->text());
        self::assertStringContainsString('showing 3 of 3', preg_replace('/\s+/', ' ', $crawler->filter('.tm-shown')->text()) ?? '');
        self::assertSame('Ranks', trim($crawler->filter('.atabs a.on')->text()));
    }

    /**
     * THE HOUSE IN-COLUMN SORT CARET, exactly as the Positions register
     * draws it: the sorted header carries `.sorted` and `aria-sort`, every
     * sortable header is a link that turns the direction over, and the
     * default is seniority.
     */
    public function testTheRegisterSortsByRankAndByHoldersThroughTheColumnCaret(): void
    {
        $this->administrator();
        [$one, $two] = $this->ladder();
        $this->holds('Joseph', 'Mollel', $one);
        $this->holds('Anna', 'Sanka', $two);
        $this->holds('Desta', 'Haile', $two);
        $this->em->flush();

        $crawler = $this->client->request('GET', '/team/ranks');
        $rank = $crawler->filter('table.tbl thead th')->eq(0);
        $holders = $crawler->filter('table.tbl thead th')->eq(1);
        self::assertSame('sorted', $rank->attr('class'));
        self::assertSame('ascending', $rank->attr('aria-sort'));
        self::assertSame('/team/ranks?dir=desc', $rank->filter('a')->attr('href'));
        self::assertSame('none', $holders->attr('aria-sort'));
        self::assertSame('/team/ranks?sort=holders', $holders->filter('a')->attr('href'));
        self::assertCount(0, $crawler->filter('table.tbl thead th')->eq(2)->filter('a'), 'Since is not a sort');
        self::assertSame(['1', '2', '3'], $crawler->filter('tbody .rk-ord')->each(static fn (Crawler $o): string => $o->text()));

        $byHolders = $this->client->request('GET', '/team/ranks?sort=holders&dir=desc');
        self::assertSame(['2', '1', '3'], $byHolders->filter('tbody .rk-ord')->each(static fn (Crawler $o): string => $o->text()), 'most held first, ties by seniority');
        $holders = $byHolders->filter('table.tbl thead th')->eq(1);
        self::assertSame('sorted', $holders->attr('class'));
        self::assertSame('descending', $holders->attr('aria-sort'));
        self::assertSame('/team/ranks?sort=holders', $holders->filter('a')->attr('href'), 'clicked again, it turns over');
        self::assertSame('none', $byHolders->filter('table.tbl thead th')->eq(0)->attr('aria-sort'));

        $fromTheTop = $this->client->request('GET', '/team/ranks?dir=desc');
        self::assertSame(['3', '2', '1'], $fromTheTop->filter('tbody .rk-ord')->each(static fn (Crawler $o): string => $o->text()));
        self::assertSame('descending', $fromTheTop->filter('table.tbl thead th')->eq(0)->attr('aria-sort'));

        // THE SEARCH KEEPS THE SORT, and the export follows the same order.
        $searched = $this->client->request('GET', '/team/ranks?sort=holders&dir=desc&q=ranger');
        self::assertSame('desc', $searched->filter('form.tm-tools input[name="dir"]')->attr('value'));
        self::assertSame('holders', $searched->filter('form.tm-tools input[name="sort"]')->attr('value'));
        $this->client->request('GET', '/team/ranks.csv?sort=holders&dir=desc');
        $lines = array_values(array_filter(explode("\n", (string) $this->client->getInternalResponse()->getContent())));
        self::assertStringStartsWith('2,', $lines[1]);
        self::assertStringStartsWith('1,', $lines[2]);
    }

    public function testTheSearchNarrowsByNameOrCode(): void
    {
        $this->administrator();
        $this->ladder();
        $this->em->flush();

        $rows = $this->client->request('GET', '/team/ranks?q=scr')->filter('table.tbl tbody tr');

        self::assertCount(1, $rows);
        self::assertSame('Senior Conservation Ranger', $rows->filter('.who b')->text());
    }

    public function testASecondScaleDrawsItsBandItsColumnAndItsChip(): void
    {
        $this->administrator();
        $this->ladder();
        $civil = $this->ranks()->addScale('Civil', 'Uniformed');
        $this->ranks()->addRank($civil, 'Officer I', 'O I');

        $crawler = $this->client->request('GET', '/team/ranks');

        self::assertSame(['Rank', 'Scale', 'Holders', 'Since', ''], $crawler->filter('table.tbl thead th')->each(static fn (Crawler $th): string => trim($th->text())));
        self::assertSame(['Uniformed', 'Civil'], $crawler->filter('tr.tm-bandrow .tm-band b')->each(static fn (Crawler $b): string => $b->text()));
        self::assertSame(['All 4', 'Uniformed 3', 'Civil 1'], $crawler->filter('.tm-tools a.fchip')->each(static fn (Crawler $a): string => preg_replace('/\s+/', ' ', trim($a->text())) ?? ''));

        $filtered = $this->client->request('GET', '/team/ranks?scale='.$civil->getUuidString());
        self::assertCount(1, $filtered->filter('tr.tm-memrow'));
        self::assertSame('Officer I', $filtered->filter('tr.tm-memrow .who b')->text());
    }

    public function testTheExportDoorAndTheCsvCarryTheFilteredRows(): void
    {
        $this->administrator();
        [$one] = $this->ladder();
        $this->holds('Joseph', 'Mollel', $one);
        $this->em->flush();

        $door = $this->client->request('GET', '/team/ranks?q=cr+i')->filter('.tm-tools a.tm-export');
        self::assertSame('export 2 rows · CSV', preg_replace('/\s+/', ' ', trim($door->text())));
        self::assertSame('/team/ranks.csv?q=cr%20i', $door->attr('href'));

        $this->client->request('GET', '/team/ranks.csv?q=cr+i');
        self::assertResponseIsSuccessful();
        self::assertStringStartsWith('text/csv', (string) $this->client->getResponse()->headers->get('Content-Type'));
        self::assertStringContainsString('attachment', (string) $this->client->getResponse()->headers->get('Content-Disposition'));
        $lines = array_values(array_filter(explode("\n", (string) $this->client->getInternalResponse()->getContent())));
        self::assertSame('Order,Rank,"Short code",Scale,Holders,Since', $lines[0]);
        self::assertCount(3, $lines);
        self::assertStringStartsWith('1,"Conservation Ranger I","CR I",,1,', $lines[1]);
    }

    public function testAReaderWithoutTheExportVerbSeesNoDoorAndIsRefusedTheFile(): void
    {
        $this->reader(['directory.read', 'ranks.read']);
        $this->ladder();
        $this->em->flush();

        self::assertCount(0, $this->client->request('GET', '/team/ranks')->filter('a.tm-export'));
        $this->client->request('GET', '/team/ranks.csv');
        self::assertResponseStatusCodeSame(403);
    }

    public function testWithoutRanksReadTheTabIsWithheldAndThePageRefused(): void
    {
        $this->reader(['directory.read']);
        $this->em->flush();

        self::assertNotContains('Ranks', $this->client->request('GET', '/team')->filter('.atabs a')->each(static fn (Crawler $a): string => trim($a->text())));
        $this->client->request('GET', '/team/ranks');
        self::assertResponseStatusCodeSame(403);
    }

    public function testAnOrganizationThatDoesNotUseRanksHasNoRanksPage(): void
    {
        $this->administrator();
        $this->em->persist((new TeamSettings())->setUsesRanks(false));
        $this->em->flush();

        self::assertNotContains('Ranks', $this->client->request('GET', '/team')->filter('.atabs a')->each(static fn (Crawler $a): string => trim($a->text())));
        $this->client->request('GET', '/team/ranks');
        self::assertResponseStatusCodeSame(404);
    }

    // ---- configure -------------------------------------------------------

    public function testTheSectionStandsFourthInTheStripWithTheSwitchAndTheList(): void
    {
        $this->administrator();
        $this->ladder();
        $this->em->flush();

        $crawler = $this->client->request('GET', '/team/configure/ranks');

        self::assertResponseIsSuccessful();
        self::assertSame(['People', 'Positions', 'Assignments', 'Ranks'], $crawler->filter('.atabs a')->each(static fn (Crawler $a): string => $a->text()));
        self::assertSame('Ranks', $crawler->filter('.atabs a.on')->text());

        $switch = $crawler->filter('form#uses-ranks');
        self::assertStringStartsWith('Uses ranks', trim($switch->filter('.tab')->text()));
        self::assertSame('on', $switch->filter('input[name="usesRanks"]')->attr('value'));
        self::assertSame('On', trim($switch->filter('.fyn button.on')->text()));

        $list = $crawler->filter('form[data-rank-scale]');
        self::assertCount(1, $list, 'one scale, one card');
        self::assertCount(0, $list->filter('input[name="scaleName"]'), 'one scale is not named');
        $rows = $list->filter('.rkl-row');
        self::assertCount(3, $rows);
        self::assertSame(['1', '2', '3'], $rows->filter('.rkl-n')->each(static fn (Crawler $n): string => $n->text()));
        self::assertSame('Conservation Ranger I', $rows->eq(0)->filter('input.fld:not(.code)')->attr('value'));
        self::assertSame('CR I', $rows->eq(0)->filter('input.fld.code')->attr('value'));
        self::assertSame('0 hold it', trim($rows->eq(0)->filter('.zfrag')->text()));
        self::assertCount(1, $list->filter('.rkl-add input[name="newName"]'));
        self::assertSame('Add a second scale', trim($list->filter('.rkl-scale a')->text()));
        self::assertSame('Save ranks', trim($list->filter('.save-row .cta')->text()));
        self::assertCount(0, $crawler->filter('#add-scale'), 'the create card waits behind its door');
    }

    public function testSavingTheSwitchTurnsRanksOff(): void
    {
        $this->administrator();
        $token = $this->tokenFrom('/team/configure/ranks', 'form#uses-ranks input[name="_token"]');

        $this->client->request('POST', '/team/configure/ranks/switch', ['_token' => $token, 'usesRanks' => 'off']);

        self::assertResponseRedirects('/team/configure/ranks');
        $this->em->clear();
        self::assertFalse($this->em->find(TeamSettings::class, TeamSettings::ONE)?->usesRanks());
    }

    public function testSavingTheListRenamesReordersAndAddsInOneWrite(): void
    {
        $this->administrator();
        [$one, $two] = $this->ladder();
        $this->em->flush();
        $token = $this->tokenFrom('/team/configure/ranks', 'form[data-rank-scale] input[name="_token"]');

        $this->client->request('POST', '/team/configure/ranks/'.$one->getScale()->getUuidString(), [
            '_token' => $token,
            'ranks' => [
                (string) $two->getUuidString() => ['name' => 'Conservation Ranger II', 'code' => 'CR II'],
                (string) $one->getUuidString() => ['name' => 'Ranger I', 'code' => 'R I'],
            ],
            'newName' => 'Ranger Inspector',
            'newCode' => 'RI',
        ]);

        self::assertResponseRedirects('/team/configure/ranks#scale-'.$one->getScale()->getUuidString());
        $crawler = $this->client->followRedirect();
        self::assertSame(['CR II', 'R I', 'SCR', 'RI'], $crawler->filter('.rkl-row input.fld.code')->each(static fn (Crawler $i): string => (string) $i->attr('value')));
    }

    public function testRemovingARankSomebodyHoldsRetiresIt(): void
    {
        $this->administrator();
        [$one] = $this->ladder();
        $this->holds('Joseph', 'Mollel', $one);
        $this->em->flush();
        $token = $this->tokenFrom('/team/configure/ranks', 'form[data-rank-scale] input[name="_token"]');

        $this->client->request('POST', '/team/configure/ranks/rank/'.$one->getUuidString().'/remove', ['_token' => $token]);

        self::assertResponseRedirects();
        $this->em->clear();
        $kept = $this->em->getRepository(Rank::class)->findOneBy(['shortCode' => 'CR I']);
        self::assertInstanceOf(Rank::class, $kept);
        self::assertTrue($kept->isRetired());
        self::assertCount(2, $this->client->request('GET', '/team/configure/ranks')->filter('.rkl-row'));
    }

    public function testTheSecondScaleDoorOpensTheCreateCardAndAddingOneNamesBoth(): void
    {
        $this->administrator();
        $this->ladder();
        $this->em->flush();

        $crawler = $this->client->request('GET', '/team/configure/ranks?add=scale');
        $card = $crawler->filter('#add-scale');
        self::assertSame('Add a scale', trim($card->filter('.dcaddhd b')->text()));
        self::assertCount(1, $card->filter('input[name="firstScaleName"]'), 'the scale the organization has is named at the same moment');
        $token = (string) $card->filter('input[name="_token"]')->attr('value');

        $this->client->request('POST', '/team/configure/ranks/scales', ['_token' => $token, 'name' => 'Civil', 'firstScaleName' => 'Uniformed']);
        self::assertResponseRedirects();
        $crawler = $this->client->followRedirect();

        $cards = $crawler->filter('form[data-rank-scale]');
        self::assertCount(2, $cards);
        self::assertSame('Uniformed', $cards->eq(0)->filter('input[name="scaleName"]')->attr('value'));
        self::assertStringStartsWith('Uniformed scale', trim($cards->eq(0)->filter('.tab')->text()));
        self::assertSame('Save the Civil scale', trim($cards->eq(1)->filter('.save-row .cta')->text()));
        self::assertCount(0, $crawler->filter('.rkl-scale'), 'the second-scale door is gone once there are two');
        self::assertCount(1, $crawler->filter('#add-scale'), 'with several scales the create card stays');
        self::assertCount(0, $crawler->filter('#add-scale input[name="firstScaleName"]'));
        self::assertCount(2, $this->em->getRepository(RankScale::class)->findAll());
    }

    public function testWithoutRanksConfigureTheSectionIsWithheldAndRefused(): void
    {
        $this->reader(['directory.read', 'directory.manage', 'ranks.read']);
        $this->em->flush();

        self::assertNotContains('Ranks', $this->client->request('GET', '/team/configure/people')->filter('.atabs a')->each(static fn (Crawler $a): string => $a->text()));
        $this->client->request('GET', '/team/configure/ranks');
        self::assertResponseStatusCodeSame(403);
    }

    /** @return list<Rank> */
    public function testWithSeveralScalesEveryRowCarriesAMoveArrowThatMovesTheRank(): void
    {
        $this->administrator();
        [$one] = $this->ladder();
        $civil = $this->ranks()->addScale('Civil', 'Uniformed');
        $this->em->flush();

        $crawler = $this->client->request('GET', '/team/configure/ranks');
        $cards = $crawler->filter('form[data-rank-scale]');
        $arrow = $cards->eq(0)->filter('.rkl-row')->eq(0)->filter('button.rkl-mv');
        self::assertCount(1, $arrow, 'one arrow per other scale');
        self::assertSame('Move Conservation Ranger I to the Civil scale', $arrow->attr('aria-label'));
        self::assertSame('/team/configure/ranks/rank/'.$one->getUuidString().'/move/'.$civil->getUuidString(), $arrow->attr('formaction'));
        $token = (string) $cards->eq(0)->filter('input[name="_token"]')->attr('value');

        $this->client->request('POST', (string) $arrow->attr('formaction'), ['_token' => $token]);

        self::assertResponseRedirects('/team/configure/ranks#scale-'.$civil->getUuidString());
        $crawler = $this->client->followRedirect();
        $cards = $crawler->filter('form[data-rank-scale]');
        self::assertSame(['CR II', 'SCR'], $cards->eq(0)->filter('.rkl-row input.fld.code')->each(static fn (Crawler $i): string => (string) $i->attr('value')));
        self::assertSame(['CR I'], $cards->eq(1)->filter('.rkl-row input.fld.code')->each(static fn (Crawler $i): string => (string) $i->attr('value')), 'the moved rank is the only one on its new scale');
        self::assertStringContainsString('moved to the Civil scale', $crawler->filter('.flashes')->text());
    }

    public function testRemoveScaleWakesOnlyWhenTheScaleHoldsNoLiveRank(): void
    {
        $this->administrator();
        $this->ladder();
        $civil = $this->ranks()->addScale('Civil', 'Uniformed');
        $oi = $this->ranks()->addRank($civil, 'Officer I', 'O I');
        $this->em->flush();

        $crawler = $this->client->request('GET', '/team/configure/ranks');
        $doors = $crawler->filter('form[data-rank-scale] .save-row button.rkl-drop');
        self::assertCount(2, $doors, 'every scale card carries the door once there are several');
        self::assertNotNull($doors->eq(0)->attr('disabled'), 'the first scale carries ranks');
        self::assertNotNull($doors->eq(1)->attr('disabled'), 'so does the second');
        self::assertSame('Move or remove its ranks first', $doors->eq(1)->attr('title'));
        self::assertSame('/team/configure/ranks/scales/'.$civil->getUuidString().'/remove', $doors->eq(1)->attr('formaction'));

        $this->ranks()->remove($oi);
        $this->em->flush();
        $door = $this->client->request('GET', '/team/configure/ranks')->filter('form[data-rank-scale]')->eq(1)->filter('button.rkl-drop');
        self::assertNull($door->attr('disabled'), 'emptied, the door wakes');
    }

    public function testRemoveScaleTakesAnEmptiedScaleAway(): void
    {
        $this->administrator();
        $this->ladder();
        $civil = $this->ranks()->addScale('Civil', 'Uniformed');
        $this->em->flush();

        $crawler = $this->client->request('GET', '/team/configure/ranks');
        $door = $crawler->filter('form[data-rank-scale]')->eq(1)->filter('button.rkl-drop');
        self::assertNull($door->attr('disabled'));
        self::assertSame('/team/configure/ranks/scales/'.$civil->getUuidString().'/remove', $door->attr('formaction'));
        $token = (string) $crawler->filter('form[data-rank-scale]')->eq(1)->filter('input[name="_token"]')->attr('value');

        $this->client->request('POST', (string) $door->attr('formaction'), ['_token' => $token]);

        self::assertResponseRedirects('/team/configure/ranks');
        $crawler = $this->client->followRedirect();
        self::assertCount(1, $crawler->filter('form[data-rank-scale]'));
        self::assertNull($this->em->getRepository(RankScale::class)->findOneBy(['name' => 'Civil']));
    }

    public function testWithRanksOffTheLadderIsDrawnReadOnly(): void
    {
        $this->administrator();
        $this->ladder();
        $this->em->flush();
        $token = $this->tokenFrom('/team/configure/ranks', 'form#uses-ranks input[name="_token"]');
        $this->client->request('POST', '/team/configure/ranks/switch', ['_token' => $token, 'usesRanks' => 'off']);
        self::assertResponseRedirects();

        $crawler = $this->client->request('GET', '/team/configure/ranks');
        $card = $crawler->filter('form[data-rank-scale]');
        self::assertStringContainsString('off', (string) $card->attr('class'));
        self::assertCount(6, $card->filter('.rkl-row input.fld[disabled]'), 'three rows, name and code each');
        self::assertCount(0, $card->filter('.rkl-add'), 'no add row');
        self::assertCount(0, $card->filter('.save-row'), 'no save row');
        self::assertCount(0, $card->filter('.rkl-scale'), 'no second-scale door');
        self::assertSame('The ladder is kept · switch ranks on to edit it', trim($card->filter('.rkl-kept')->text()));
        self::assertCount(0, $card->filter('button.rkl-x:not([disabled])'), 'remove is asleep too');
    }

    public function testTheLadderReadsTheHighestRankFirst(): void
    {
        $this->administrator();
        $this->ladder();
        $this->em->flush();

        $crawler = $this->client->request('GET', '/team/configure/ranks');
        self::assertSame('Rank', trim($crawler->filter('.rkl-hd span')->eq(2)->text()));
        self::assertStringContainsString('highest first', $crawler->filter('form[data-rank-scale] .tab .src')->text());
        self::assertSame('1', trim($crawler->filter('.rkl-row .rkl-n')->first()->text()));
        self::assertSame('CR I', $crawler->filter('.rkl-row input.fld.code')->first()->attr('value'), 'the first rank added is row 1, the highest; every later one joins below');
        $this->ranks()->addRank($this->ranks()->defaultScale(), 'Ranger Recruit', 'RR');
        $this->em->flush();
        self::assertSame('RR', $this->client->request('GET', '/team/configure/ranks')->filter('.rkl-row input.fld.code')->last()->attr('value'), 'a new rank joins at the junior end');
    }

    private function ladder(): array
    {
        $scale = $this->ranks()->defaultScale();

        return [
            $this->ranks()->addRank($scale, 'Conservation Ranger I', 'CR I'),
            $this->ranks()->addRank($scale, 'Conservation Ranger II', 'CR II'),
            $this->ranks()->addRank($scale, 'Senior Conservation Ranger', 'SCR'),
        ];
    }

    private function holds(string $first, string $last, Rank $rank): User
    {
        $person = $this->person($first, $last);
        $this->em->flush();
        $this->ranks()->assign($person, $rank, new \DateTimeImmutable('2024-01-14'), null);

        return $person;
    }

    /** @param list<string> $grants */
    private function reader(array $grants): User
    {
        $reader = $this->person('Wera', 'Mwita');
        $reader->setPosition($this->position('Reader', $grants));
        $this->place($reader);
        $this->em->flush();
        $this->client->loginUser($reader);

        return $reader;
    }

    private function ranks(): RankService
    {
        $ranks = static::getContainer()->get('test_public.'.RankService::class);
        \assert($ranks instanceof RankService);

        return $ranks;
    }
}
