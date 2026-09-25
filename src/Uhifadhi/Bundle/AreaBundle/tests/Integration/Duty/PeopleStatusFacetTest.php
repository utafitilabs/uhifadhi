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

namespace Uhifadhi\Bundle\AreaBundle\Tests\Integration\Duty;

use PHPUnit\Framework\Attributes\CoversClass;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\Entity\CheckIn;
use Uhifadhi\Bundle\AreaBundle\Entity\CheckInStatus;
use Uhifadhi\Bundle\AreaBundle\People\AreaPeopleStatus;
use Uhifadhi\Bundle\AreaBundle\Service\CheckInStatusService;
use Uhifadhi\Bundle\AreaBundle\Tests\Integration\Fixtures\HostPerson;
use Uhifadhi\Bundle\AreaBundle\Tests\Integration\IntegrationTestCase;
use Uhifadhi\Contracts\People\PeopleFacet;
use Uhifadhi\Contracts\People\PeopleFacetOption;

/**
 * THE STATUS DROPDOWN THE AREA PUTS ON THE PEOPLE REGISTER — today's
 * check-in status of everybody, in the areas' own words, read through the
 * one presence derivation and merged by key across areas.
 */
#[CoversClass(AreaPeopleStatus::class)]
final class PeopleStatusFacetTest extends IntegrationTestCase
{
    /** The fixture day: the kernel's clock is pinned to its last minute. */
    private const string TODAY = '2026-09-19';

    public function testTheFacetOffersTheAreasWordsInTheirOrderWithWhoCarriesEach(): void
    {
        $area = $this->anArea();
        $asha = $this->somebody('Asha', 'Mollel');
        $baraka = $this->somebody('Baraka', 'Kileo');
        $chiku = $this->somebody('Chiku', 'Sanka');
        $this->claim($area, $asha, 'at_post', self::TODAY, '06:08');
        $this->claim($area, $baraka, 'unfit', self::TODAY, '06:10');

        $facet = $this->facet()->facetFor([$asha, $baraka, $chiku]);

        self::assertInstanceOf(PeopleFacet::class, $facet);
        self::assertSame('status', $facet->key);
        self::assertSame('status', $facet->label);
        self::assertCount(2, $facet->groups, 'the words, then the absence');
        self::assertNull($facet->groups[0]->head, 'one run wants no head');
        self::assertSame(
            ['at_post' => 'At post', 'unfit' => 'Unfit for duty', 'outside' => 'Outside the park', 'special' => 'Special assignment'],
            $this->labels($facet->groups[0]->options),
        );
        self::assertSame([$asha], $facet->peopleWith('at_post'));
        self::assertSame([$baraka], $facet->peopleWith('unfit'));
        self::assertSame([], $facet->peopleWith('outside'));
        self::assertSame(['none' => 'No check-in today'], $this->labels($facet->groups[1]->options));
        self::assertSame([$chiku], $facet->peopleWith('none'));
    }

    public function testTheDayReadsAsTheLastWatchAndYesterdayIsNotToday(): void
    {
        $area = $this->anArea();
        $asha = $this->somebody('Asha', 'Mollel');
        $baraka = $this->somebody('Baraka', 'Kileo');
        $this->claim($area, $asha, 'at_post', self::TODAY, '06:08');
        $this->claim($area, $asha, 'outside', self::TODAY, '13:30');
        $this->claim($area, $baraka, 'at_post', '2026-09-18', '06:08');

        $facet = $this->facet()->facetFor([$asha, $baraka]);

        self::assertInstanceOf(PeopleFacet::class, $facet);
        self::assertSame([$asha], $facet->peopleWith('outside'));
        self::assertSame([], $facet->peopleWith('at_post'));
        self::assertSame([$baraka], $facet->peopleWith('none'));
    }

    public function testTwoAreasMergeTheSameWordAndKeepTheirOwn(): void
    {
        $one = $this->anArea('Sample Area');
        $two = $this->anArea('Other Area');
        $asha = $this->somebody('Asha', 'Mollel');
        $baraka = $this->somebody('Baraka', 'Kileo');
        $this->claim($one, $asha, 'at_post', self::TODAY, '06:08');
        $this->claim($two, $baraka, 'at_post', self::TODAY, '06:09');
        // A WORD ONLY THE SECOND AREA HAS.
        $leave = new CheckInStatus()->setArea($two)->setKey('leave')->setLabel('On leave')->setPosition(9);
        $this->em->persist($leave);
        $this->em->flush();

        $facet = $this->facet()->facetFor([$asha, $baraka]);

        self::assertInstanceOf(PeopleFacet::class, $facet);
        self::assertSame(['at_post', 'unfit', 'outside', 'special', 'leave'], array_keys($this->labels($facet->groups[0]->options)));
        self::assertSame([$asha, $baraka], $facet->peopleWith('at_post'));
        self::assertSame([], $facet->peopleWith('leave'));
    }

    public function testOnlyThePeopleAskedAboutAreCounted(): void
    {
        $area = $this->anArea();
        $asha = $this->somebody('Asha', 'Mollel');
        $baraka = $this->somebody('Baraka', 'Kileo');
        $this->claim($area, $asha, 'at_post', self::TODAY, '06:08');
        $this->claim($area, $baraka, 'at_post', self::TODAY, '06:09');

        $facet = $this->facet()->facetFor([$baraka]);

        self::assertInstanceOf(PeopleFacet::class, $facet);
        self::assertSame([$baraka], $facet->peopleWith('at_post'));
        self::assertSame([], $facet->peopleWith('none'));
    }

    public function testWithoutAnAreaThereIsNothingToFilterBy(): void
    {
        $asha = $this->somebody('Asha', 'Mollel');

        self::assertNull($this->facet()->facetFor([$asha]));
        self::assertNull($this->facet()->facetFor([]));
    }

    private function facet(): AreaPeopleStatus
    {
        $facet = static::getContainer()->get('test_public.area.people_status');
        \assert($facet instanceof AreaPeopleStatus);

        return $facet;
    }

    private function somebody(string $first, string $last): string
    {
        $person = new HostPerson()->named($first, $last);
        $this->em->persist($person);
        $this->em->flush();

        return (string) $person->getUuidString();
    }

    private function claim(AreaOfInterest $area, string $personUuid, string $statusKey, string $day, string $time): void
    {
        /** @var CheckInStatusService $statuses */
        $statuses = static::getContainer()->get('test_public.area.checkin_statuses');
        $status = null;
        foreach ($statuses->offeredBy($area) as $one) {
            if ($statusKey === $one->getKey()) {
                $status = $one;
            }
        }
        self::assertInstanceOf(CheckInStatus::class, $status);
        $person = $this->em->getRepository(HostPerson::class)->findOneBy(['uuid' => $personUuid]);
        self::assertInstanceOf(HostPerson::class, $person);

        $checkIn = new CheckIn()
            ->setArea($area)
            ->setPerson($person)
            ->setClientRef(bin2hex(random_bytes(8)))
            ->setLocalDate(new \DateTimeImmutable($day))
            ->setStatus($status)
            ->setOccurredAt(new \DateTimeImmutable($day.'T'.$time.':00+03:00'))
            ->setDeviceId('0f9ca41e')
            ->setAppVersion('0.1.0');
        $this->em->persist($checkIn);
        $this->em->flush();
    }

    /**
     * @param list<PeopleFacetOption> $options
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
