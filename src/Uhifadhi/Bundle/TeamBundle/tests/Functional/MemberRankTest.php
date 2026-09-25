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
use Uhifadhi\Bundle\TeamBundle\Controller\MemberController;
use Uhifadhi\Bundle\TeamBundle\Entity\Rank;
use Uhifadhi\Bundle\TeamBundle\Entity\TeamSettings;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Bundle\TeamBundle\Repository\RankHoldingRepository;
use Uhifadhi\Bundle\TeamBundle\Service\MemberHistory;
use Uhifadhi\Bundle\TeamBundle\Service\RankService;

/**
 * A PERSON'S RANK ON THEIR RECORD — the band fact, the rank line and its
 * history rows inside the Position card — and the rank given on the person's
 * configure page as a dated fact.
 */
#[CoversClass(MemberController::class)]
#[CoversClass(MemberHistory::class)]
final class MemberRankTest extends WebTestCaseWithSchema
{
    public function testTheRecordStatesTheRankInTheBandAndInsideThePositionCard(): void
    {
        $naomi = $this->administrator();
        [$cr, $scr] = $this->ladder();
        $joseph = $this->person('Joseph', 'Mollel');
        $joseph->setPosition($this->position('Sergeant'));
        $this->em->flush();
        $this->ranks()->assign($joseph, $cr, new \DateTimeImmutable('2024-01-09'), $naomi);
        $this->ranks()->assign($joseph, $scr, new \DateTimeImmutable('2025-03-03'), $naomi);

        $crawler = $this->client->request('GET', '/team/'.$joseph->getUuidString());

        self::assertResponseIsSuccessful();
        $first = $crawler->filter('.factband .f')->first();
        self::assertSame('Rank', $first->filter('.k')->text());
        self::assertStringStartsWith('SCR', trim($first->filter('.v')->text()));
        self::assertStringContainsString('Senior Conservation Ranger · since 3 Mar 2025', $first->filter('.v em')->text());

        $card = $crawler->filter('#position');
        self::assertStringStartsWith('Position and rank', trim($card->filter('.tab')->text()));
        self::assertSame(['Assigned position', 'Rank', 'Where', 'Departments', 'Rank history'], $card->filter('.pcol-k')->each(static fn (Crawler $k): string => trim($k->text())));
        $rows = $card->filter('.rkh .rkh-row');
        self::assertCount(2, $rows);
        self::assertStringContainsString('now', $rows->eq(0)->attr('class') ?? '');
        self::assertSame('now', trim($rows->eq(0)->filter('.w.to')->text()));
        self::assertSame('SCR', $rows->eq(0)->filter('.r b')->text());
        self::assertSame('N. Kileo', trim($rows->eq(0)->filter('.by')->text()));
        self::assertSame('3 Mar 2025', trim($rows->eq(1)->filter('.w.to')->text()));
        self::assertStringContainsString('Change the position or rank', $card->filter('.pcard-foot')->text());

        $history = $crawler->filter('.hlist .hrow .t')->each(static fn (Crawler $t): string => $t->text());
        self::assertContains('Promoted to Senior Conservation Ranger (SCR)', $history);
        self::assertContains('Rank set to Conservation Ranger II (CR II)', $history);
    }

    public function testWithoutRanksTheRecordSaysNothingOfThem(): void
    {
        $this->administrator();
        $joseph = $this->person('Joseph', 'Mollel');
        $this->em->persist((new TeamSettings())->setUsesRanks(false));
        $this->em->flush();

        $crawler = $this->client->request('GET', '/team/'.$joseph->getUuidString());

        self::assertNotContains('Rank', $crawler->filter('.factband .f .k')->each(static fn (Crawler $k): string => $k->text()));
        self::assertCount(0, $crawler->filter('#position .rkh'));
        self::assertStringStartsNotWith('Position and rank', trim($crawler->filter('#position .tab')->text()));
        self::assertCount(0, $this->client->request('GET', '/team/'.$joseph->getUuidString().'/configure')->filter('form#rank'));
    }

    public function testTheConfigurePageGivesARankFromADay(): void
    {
        $naomi = $this->administrator();
        [, $scr] = $this->ladder();
        $joseph = $this->person('Joseph', 'Mollel');
        $this->em->flush();

        $crawler = $this->client->request('GET', '/team/'.$joseph->getUuidString().'/configure');
        $form = $crawler->filter('form#rank');
        self::assertSame(['— no rank —', 'CR II · Conservation Ranger II', 'SCR · Senior Conservation Ranger'], $form->filter('select[name="rank"] option')->each(static fn (Crawler $o): string => trim($o->text())));
        $token = (string) $form->filter('input[name="_token"]')->attr('value');

        $this->client->request('POST', '/team/'.$joseph->getUuidString().'/rank', ['_token' => $token, 'return' => 'configure', 'rank' => $scr->getUuidString(), 'since' => '2026-06-12']);

        self::assertResponseRedirects('/team/'.$joseph->getUuidString().'/configure');
        $this->em->clear();
        $person = $this->em->getRepository(User::class)->find((int) $joseph->getId());
        self::assertInstanceOf(User::class, $person);
        $held = $this->holdings()->findCurrentByPerson($person);
        self::assertSame('SCR', $held?->getRank()->getShortCode());
        self::assertSame('2026-06-12', $held->getSince()->format('Y-m-d'));
        self::assertSame($naomi->getId(), $held->getRecordedBy()?->getId());
    }

    public function testAReaderCannotGiveARank(): void
    {
        $reader = $this->person('Wera', 'Mwita');
        $reader->setPosition($this->position('Reader', ['directory.read', 'ranks.read']));
        $this->place($reader);
        [$cr] = $this->ladder();
        $joseph = $this->person('Joseph', 'Mollel');
        $this->em->flush();
        $this->client->loginUser($reader);

        $this->client->request('POST', '/team/'.$joseph->getUuidString().'/rank', ['rank' => $cr->getUuidString(), 'since' => '2026-06-12']);

        self::assertResponseStatusCodeSame(403);
    }

    /** @return list<Rank> */
    private function ladder(): array
    {
        $scale = $this->ranks()->defaultScale();

        return [
            $this->ranks()->addRank($scale, 'Conservation Ranger II', 'CR II'),
            $this->ranks()->addRank($scale, 'Senior Conservation Ranger', 'SCR'),
        ];
    }

    private function ranks(): RankService
    {
        $ranks = static::getContainer()->get('test_public.'.RankService::class);
        \assert($ranks instanceof RankService);

        return $ranks;
    }

    private function holdings(): RankHoldingRepository
    {
        $holdings = static::getContainer()->get('test_public.'.RankHoldingRepository::class);
        \assert($holdings instanceof RankHoldingRepository);

        return $holdings;
    }
}
