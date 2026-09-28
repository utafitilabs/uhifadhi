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

    public function testMyStationSaysSoWhenIAmPostedNowhere(): void
    {
        $this->boot(self::NO_AREAS);
        $this->signInAsPerson()->named('Paulo', 'Sanka');
        $this->em->flush();

        $crawler = $this->browser()->request('GET', '/me/station');

        self::assertSame(200, $this->browser()->getResponse()->getStatusCode());
        self::assertStringContainsString('posted at no station', $crawler->filter('.c')->text());
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
