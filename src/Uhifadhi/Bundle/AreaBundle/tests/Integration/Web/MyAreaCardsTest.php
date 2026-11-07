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

namespace Uhifadhi\Bundle\AreaBundle\Tests\Integration\Web;

use Symfony\Component\DomCrawler\Crawler;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\Entity\CheckIn;
use Uhifadhi\Bundle\AreaBundle\Entity\CheckInStatus;
use Uhifadhi\Bundle\AreaBundle\Entity\Station;
use Uhifadhi\Bundle\AreaBundle\Enum\PostingSource;
use Uhifadhi\Bundle\AreaBundle\Service\CheckInStatusService;
use Uhifadhi\Bundle\AreaBundle\Service\PostingService;
use Uhifadhi\Bundle\AreaBundle\Service\StationService;
use Uhifadhi\Bundle\AreaBundle\Tests\Integration\Web\Fixtures\HostUser;

/**
 * THE AREA'S CARDS ON A PERSON'S OWN DASHBOARD (#19, option A ruled 28 Sep
 * 2026): my watch, my station — who leads it, how many are posted — my
 * check-ins, and the doors to My station and My duty log. Only the person's
 * own records, whatever their grants: a ranger with no read on the areas
 * still sees the post they are posted at.
 */
final class MyAreaCardsTest extends WebTestCase
{
    use HouseRegisterAssertions;

    /** @var list<string> */
    private const array NO_AREAS = ['duty.read', 'duty.record'];

    /** @return array{0: HostUser, 1: Station} */
    private function aPostedRangerOnWatch(): array
    {
        $this->boot(self::NO_AREAS);
        $me = $this->signInAsPerson()->named('Naserian', 'Lekishon');
        $this->em->flush();

        $area = $this->anArea();
        $this->aZone($area, 'Western Sector', self::A_WEST_HALF);
        $station = $this->stations()->add($area, 'Eastgate Post', -29.75, -3.2);
        $lead = $this->postings()->post($station, $this->aPerson('J.', 'Mollel'), PostingSource::WrittenHere);
        $this->postings()->appointLeader($lead);
        $this->postings()->post($station, $me, PostingSource::WrittenHere);

        $today = new \DateTimeImmutable('today');
        $this->aClaim($area, $me, $station, $today->setTime(6, 0), 'today');
        $this->aClaim($area, $me, $station, $today->modify('-1 day')->setTime(6, 0), 'yesterday', $today->modify('-1 day')->setTime(14, 4));

        return [$me, $station];
    }

    public function testMyWatchIsTheFirstFigure(): void
    {
        $this->aPostedRangerOnWatch();

        $crawler = $this->browser()->request('GET', '/');

        self::assertSame(200, $this->browser()->getResponse()->getStatusCode());
        $figure = $crawler->filter('.md-figures [data-me="watch"]');
        self::assertCount(1, $figure);
        self::assertStringContainsString('on since 06:00', $figure->text());
    }

    public function testMyStationNamesItsHeadAndHowManyArePostedThere(): void
    {
        $this->aPostedRangerOnWatch();

        $card = $this->browser()->request('GET', '/')->filter('[data-me="station"]');

        self::assertCount(1, $card);
        self::assertStringContainsString('Eastgate Post', $card->text());
        self::assertStringContainsString('J. Mollel', $card->text());
        self::assertStringContainsString('2', $card->filter('[data-me="posted"]')->text());
    }

    public function testMyCheckInsListTheDaysNewestFirst(): void
    {
        $this->aPostedRangerOnWatch();

        $rows = $this->browser()->request('GET', '/')->filter('[data-me="checkins"] .rln');

        self::assertGreaterThanOrEqual(2, $rows->count());
        self::assertStringContainsString('on watch', $rows->eq(0)->text(), 'today, still open, first');
        self::assertStringContainsString('14:04', $rows->eq(1)->text());
    }

    public function testTheDoorsLeadToMyStationAndMyDutyLog(): void
    {
        $this->aPostedRangerOnWatch();

        $doors = $this->browser()->request('GET', '/')->filter('.md-pages a')->each(static fn ($a): string => (string) $a->attr('href'));

        self::assertContains('/me/station', $doors);
        self::assertContains('/me/duty-log', $doors);
    }

    public function testMyStationPageNamesTheHeadAndEverybodyPostedThere(): void
    {
        $this->aPostedRangerOnWatch();

        $crawler = $this->browser()->request('GET', '/me/station');

        self::assertSame(200, $this->browser()->getResponse()->getStatusCode());
        self::assertStringContainsString('Eastgate Post', $crawler->filter('h1')->text());
        self::assertStringContainsString('J. Mollel', $crawler->filter('[data-me="head"]')->text());
        $posted = $crawler->filter('[data-me="posted"]')->text();
        self::assertStringContainsString('Naserian Lekishon', $posted);
        self::assertStringContainsString('on watch', $posted, 'who is on watch now');
    }

    public function testMyDutyLogListsEveryCheckInOfTheMonth(): void
    {
        $this->aPostedRangerOnWatch();

        $crawler = $this->browser()->request('GET', '/me/duty-log');

        self::assertSame(200, $this->browser()->getResponse()->getStatusCode());
        self::assertGreaterThanOrEqual(1, $crawler->filter('[data-me="log"] tbody tr')->count());
        self::assertStringContainsString('on watch', $crawler->filter('[data-me="log"] tbody tr')->eq(0)->text());
    }

    public function testMyDutyLogIsAHouseRegisterWithTheMonthAsAChip(): void
    {
        [$me, $eastgate] = $this->aPostedRangerOnWatch();
        $area = $eastgate->getArea();
        self::assertNotNull($area);
        $springs = $this->stations()->add($area, 'Springs Post', -29.7, -3.1);
        $march = new \DateTimeImmutable('2026-03-10 06:00');
        $this->aClaim($area, $me, $eastgate, $march, 'm1', $march->modify('+8 hours'));
        $this->aClaim($area, $me, $eastgate, $march->modify('+1 day'), 'm2', $march->modify('+1 day +8 hours'));
        $this->aClaim($area, $me, $springs, $march->modify('+2 days'), 'm3', $march->modify('+2 days +8 hours'));
        $log = '/me/duty-log?month=2026-03';

        $body = $this->html($log);
        self::assertHouseBar($body, '[data-me="log"]', 3);
        self::assertHouseTable($body, '[data-me="log"]');
        self::assertHouseFoot($body, '[data-me="log"]', '3 check-ins');
        self::assertCount(0, new Crawler($body)->filter('[data-me="log"] .sxfoot'), 'The months are the chip, not a caption line.');

        $chips = self::chips($body, '[data-me="log"]');
        self::assertSame(['march 2026', 'every status · 3', 'every post · 3'], array_column($chips, 'chip'));
        self::assertContains(['label' => 'March 2026', 'count' => null, 'href' => $log, 'on' => true], $chips[0]['options']);
        self::assertContains('/me/duty-log?month=2026-02', array_column($chips[0]['options'], 'href'));
        self::assertSame(['Every post', 'Eastgate Post', 'Springs Post'], array_column($chips[2]['options'], 'label'));
        self::assertSame(['3', '2', '1'], array_column($chips[2]['options'], 'count'));
        self::assertSame($log.'&post='.$springs->getUuidString(), $chips[2]['options'][2]['href']);

        $atSprings = $this->html($log.'&post='.$springs->getUuidString());
        self::assertCount(1, new Crawler($atSprings)->filter('[data-me="log"] tbody tr'));
        self::assertHouseFoot($atSprings, '[data-me="log"]', '1 check-in');
        self::assertSame('springs post · 1', self::chips($atSprings, '[data-me="log"]')[2]['chip']);

        self::assertHouseFoot($this->html($log.'&q=springs'), '[data-me="log"]', '1 check-in');
        self::assertStringContainsString('No check-in matches', $this->html($log.'&q=nowhere-at-all'));
    }

    public function testMyStationSaysSoWhenIAmPostedNowhere(): void
    {
        $this->boot(self::NO_AREAS);
        $this->signInAsPerson()->named('Paulo', 'Sanka');
        $this->em->flush();

        $crawler = $this->browser()->request('GET', '/me/station');

        self::assertSame(200, $this->browser()->getResponse()->getStatusCode());
        self::assertStringContainsString('posted at no station', $crawler->filter('.c')->text());
    }

    private function html(string $url): string
    {
        $this->browser()->request('GET', $url);
        self::assertSame(200, $this->browser()->getResponse()->getStatusCode(), $url);

        return (string) $this->browser()->getResponse()->getContent();
    }

    private function aClaim(AreaOfInterest $area, HostUser $person, Station $post, \DateTimeImmutable $at, string $ref, ?\DateTimeImmutable $ended = null): CheckIn
    {
        /** @var CheckInStatusService $statuses */
        $statuses = static::getContainer()->get('test_public.area.checkin_statuses');
        $status = null;
        foreach ($statuses->offeredBy($area) as $one) {
            if ('at_post' === $one->getKey()) {
                $status = $one;
            }
        }
        self::assertInstanceOf(CheckInStatus::class, $status);

        $checkIn = new CheckIn()
            ->setArea($area)
            ->setPerson($person)
            ->setClientRef('claim-'.$ref)
            ->setLocalDate($at->setTime(0, 0))
            ->setStatus($status)
            ->setStation($post)
            ->setOccurredAt($at)
            ->setDeviceId('0f9ca41e')
            ->setAppVersion('0.5.5');
        if (null !== $ended) {
            $checkIn->setEndedAt($ended);
        }
        $this->em->persist($checkIn);
        $this->em->flush();

        return $checkIn;
    }

    private function aPerson(string $first, string $last): HostUser
    {
        $person = new HostUser()->named($first, $last);
        $this->em->persist($person);
        $this->em->flush();

        return $person;
    }

    private function stations(): StationService
    {
        /** @var StationService $stations */
        $stations = static::getContainer()->get('test_public.area.stations');

        return $stations;
    }

    private function postings(): PostingService
    {
        /** @var PostingService $postings */
        $postings = static::getContainer()->get('test_public.area.postings');

        return $postings;
    }
}
