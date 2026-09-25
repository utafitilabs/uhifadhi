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
 * configure page as a dated fact, inside the Position card's one save.
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
        self::assertSame('/team/'.$joseph->getUuidString().'/configure#position', $card->filter('.pcard-foot a.ov-open')->attr('href'), 'the door opens the card that writes both');

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
        $configure = $this->client->request('GET', '/team/'.$joseph->getUuidString().'/configure');
        self::assertCount(0, $configure->filter('#position select[name="rank"]'));
        self::assertCount(0, $configure->filter('#position input[name="since"]'));
        self::assertSame('Position', trim(explode('·', $configure->filter('#position .tab')->text())[0]));
    }

    /**
     * THE RANK IS SET INSIDE THE POSITION CARD (ruled 2026-09-25): the rank
     * select and its From date sit under the assigned position, and the one
     * save writes the seat, the placement and the rank together.
     */
    public function testTheConfigurePageGivesARankFromADayInsideThePositionCard(): void
    {
        $naomi = $this->administrator();
        [, $scr] = $this->ladder();
        $sergeant = $this->position('Sergeant');
        $joseph = $this->person('Joseph', 'Mollel');
        $this->em->flush();

        $crawler = $this->client->request('GET', '/team/'.$joseph->getUuidString().'/configure');
        $card = $crawler->filter('#position');
        self::assertSame('Position and rank', trim(explode('·', $card->filter('.tab')->text())[0]));
        $form = $card->filter('form[action$="/position"]');
        self::assertCount(1, $form, 'one form, one save');
        self::assertSame(['Assigned position', 'Rank', 'Organization', 'Areas', 'Departments · several allowed'], $card->filter('.pcol-k')->each(static fn (Crawler $k): string => trim($k->text())));
        self::assertSame(['— no rank —', 'CR II · Conservation Ranger II', 'SCR · Senior Conservation Ranger'], $form->filter('select[name="rank"] option')->each(static fn (Crawler $o): string => trim($o->text())));
        self::assertCount(1, $form->filter('input[type="date"][name="since"]'));
        self::assertCount(0, $crawler->filter('form[action$="/rank"]'), 'no card of its own');
        self::assertSame(['Save the position'], $card->filter('button.cta')->each(static fn (Crawler $b): string => trim($b->text())));
        $token = (string) $form->filter('input[name="_token"]')->attr('value');

        $this->client->request('POST', '/team/'.$joseph->getUuidString().'/position', [
            '_token' => $token,
            'return' => 'configure',
            'position' => $sergeant->getUuidString(),
            'where' => 'organization',
            'all_departments' => '1',
            'rank' => $scr->getUuidString(),
            'since' => '2026-06-12',
        ]);

        self::assertResponseRedirects('/team/'.$joseph->getUuidString().'/configure');
        $this->em->clear();
        $person = $this->em->getRepository(User::class)->find((int) $joseph->getId());
        self::assertInstanceOf(User::class, $person);
        self::assertSame('Sergeant', $person->getPosition()?->getName());
        $held = $this->holdings()->findCurrentByPerson($person);
        self::assertSame('SCR', $held?->getRank()->getShortCode());
        self::assertSame('2026-06-12', $held->getSince()->format('Y-m-d'));
        self::assertSame($naomi->getId(), $held->getRecordedBy()?->getId());
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flashes', 'holds Sergeant');
        self::assertSelectorTextContains('.flashes', 'Senior Conservation Ranger from 12 Jun 2026');
    }

    public function testARankIsGivenWithNoPositionAndClosedByNoRank(): void
    {
        $this->administrator();
        [$cr] = $this->ladder();
        $joseph = $this->person('Joseph', 'Mollel');
        $this->em->flush();
        $token = $this->tokenFrom('/team/'.$joseph->getUuidString().'/configure', 'form[action$="/position"] input[name="_token"]');

        $this->client->request('POST', '/team/'.$joseph->getUuidString().'/position', ['_token' => $token, 'return' => 'configure', 'position' => '', 'rank' => $cr->getUuidString(), 'since' => '2026-06-12']);
        $this->em->clear();
        $person = $this->em->getRepository(User::class)->find((int) $joseph->getId());
        self::assertInstanceOf(User::class, $person);
        self::assertNull($person->getPosition());
        self::assertSame('CR II', $this->holdings()->findCurrentByPerson($person)?->getRank()->getShortCode());

        $this->client->request('POST', '/team/'.$joseph->getUuidString().'/position', ['_token' => $token, 'return' => 'configure', 'position' => '', 'rank' => '', 'since' => '2026-07-01']);
        $this->em->clear();
        $person = $this->em->getRepository(User::class)->find((int) $joseph->getId());
        self::assertInstanceOf(User::class, $person);
        self::assertNull($this->holdings()->findCurrentByPerson($person));
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flashes', 'no rank from 1 Jul 2026');
    }

    public function testASaveThatNamesNoRankFieldKeepsTheRankAndARetiredRankIsRefused(): void
    {
        $naomi = $this->administrator();
        [$cr, $scr] = $this->ladder();
        $sergeant = $this->position('Sergeant');
        $joseph = $this->person('Joseph', 'Mollel');
        $this->em->flush();
        $this->ranks()->assign($joseph, $cr, new \DateTimeImmutable('2024-01-09'), $naomi);
        $token = $this->tokenFrom('/team/'.$joseph->getUuidString().'/configure', 'form[action$="/position"] input[name="_token"]');

        $this->client->request('POST', '/team/'.$joseph->getUuidString().'/position', ['_token' => $token, 'return' => 'configure', 'position' => $sergeant->getUuidString(), 'where' => 'organization', 'all_departments' => '1']);
        $this->em->clear();
        $person = $this->em->getRepository(User::class)->find((int) $joseph->getId());
        self::assertInstanceOf(User::class, $person);
        self::assertSame('CR II', $this->holdings()->findCurrentByPerson($person)?->getRank()->getShortCode(), 'a write that says nothing about the rank keeps it');
        self::assertSame('Sergeant', $person->getPosition()?->getName());

        // RETIRED IN THE TEST'S OWN MANAGER: the kernel rebooted with the
        // request above, so the service's manager is not this one.
        $retired = $this->em->getRepository(Rank::class)->find((int) $scr->getId());
        self::assertInstanceOf(Rank::class, $retired);
        $retired->setRetiredAt(new \DateTimeImmutable('2026-06-01'));
        $this->em->flush();
        $this->client->request('POST', '/team/'.$joseph->getUuidString().'/position', ['_token' => $token, 'return' => 'configure', 'position' => $sergeant->getUuidString(), 'rank' => $scr->getUuidString(), 'since' => '2026-06-12']);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flashes', 'retired');
        $this->em->clear();
        $person = $this->em->getRepository(User::class)->find((int) $joseph->getId());
        self::assertInstanceOf(User::class, $person);
        self::assertSame('CR II', $this->holdings()->findCurrentByPerson($person)?->getRank()->getShortCode(), 'a refused rank writes nothing');
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

        $this->client->request('POST', '/team/'.$joseph->getUuidString().'/position', ['position' => '', 'rank' => $cr->getUuidString(), 'since' => '2026-06-12']);
        self::assertResponseStatusCodeSame(403);

        // THERE IS NO RANK ROUTE: the position save is the one write.
        $this->client->request('POST', '/team/'.$joseph->getUuidString().'/rank', ['rank' => $cr->getUuidString(), 'since' => '2026-06-12']);
        self::assertResponseStatusCodeSame(404);
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
