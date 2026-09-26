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

use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\Entity\CheckIn;
use Uhifadhi\Bundle\AreaBundle\Entity\CheckInStatus;
use Uhifadhi\Bundle\AreaBundle\Entity\PersonPosition;
use Uhifadhi\Bundle\AreaBundle\Entity\Station;
use Uhifadhi\Bundle\AreaBundle\Enum\PositionSourceEnum;
use Uhifadhi\Bundle\AreaBundle\Service\CheckInStatusService;
use Uhifadhi\Bundle\AreaBundle\Service\LiveVisibility;
use Uhifadhi\Bundle\AreaBundle\Service\PresenceFactsService;
use Uhifadhi\Bundle\AreaBundle\Service\PresenceService;
use Uhifadhi\Bundle\AreaBundle\Service\StationService;
use Uhifadhi\Bundle\AreaBundle\Tests\Integration\Web\Fixtures\FakeRankLadder;
use Uhifadhi\Bundle\AreaBundle\Tests\Integration\Web\Fixtures\HostUser;
use Uhifadhi\Bundle\AreaBundle\Tests\Integration\Web\Fixtures\SignedInPerson;
use Uhifadhi\Contracts\Area\LivePosition;

/**
 * THE RANK RULE ON REAL PAGES AND THE REAL READ (ruled 2026-09-26).
 *
 * Five people are on the ground with a fix each, every fix at coordinates no
 * other thing on the page carries, so a coordinate on the page is that
 * person's mark and nothing else: the chief (place 1), the sergeant (5), a
 * ranger (9), a recruit (12), and a volunteer with no rank.
 *
 * What is proven, on the organization dashboard (the page this kernel draws
 * live marks on) and on the per-area read every area plate uses: the sergeant is shown the ranger's and the recruit's
 * marks and not the chief's, not the volunteer's and not their own; the
 * volunteer, having no rank, is drawn nobody; the control room is drawn
 * everybody; and the day's reading — the source of every count — is whole
 * whatever the viewer.
 */
#[CoversClass(LiveVisibility::class)]
#[CoversClass(PresenceService::class)]
final class RankFilteredPresenceTest extends WebTestCase
{
    private const string DAY = '2026-09-19';

    /** Each person's longitude: unique on the page. */
    private const array LON = ['chief' => -29.7111, 'sergeant' => -29.7222, 'ranger' => -29.7333, 'recruit' => -29.7444, 'volunteer' => -29.7555];

    public function testTheSergeantsPageDrawsOnlyThoseJuniorToThem(): void
    {
        [, $people] = $this->aGroundWithFivePeopleOnIt();
        $this->viewAs($people['sergeant']);

        self::assertSame(['ranger', 'recruit'], self::drawnOn($this->body('/')));
    }

    public function testTheRangerIsDrawnOnlyTheRecruitAndNeverAPeerOrASenior(): void
    {
        [, $people] = $this->aGroundWithFivePeopleOnIt();
        $this->viewAs($people['ranger']);

        self::assertSame(['recruit'], self::drawnOn($this->body('/')));
    }

    public function testAPersonWithoutARankIsDrawnNobody(): void
    {
        [, $people] = $this->aGroundWithFivePeopleOnIt();
        $this->viewAs($people['volunteer']);

        self::assertSame([], self::drawnOn($this->body('/')));
    }

    public function testTheMostJuniorIsDrawnNobody(): void
    {
        [, $people] = $this->aGroundWithFivePeopleOnIt();
        $this->viewAs($people['recruit']);

        self::assertSame([], self::drawnOn($this->body('/')));
    }

    public function testTheControlRoomIsDrawnEverybodyWhateverItsRank(): void
    {
        [, $people] = $this->aGroundWithFivePeopleOnIt([...self::ALL_AREA_PERMISSIONS, 'locations.read']);
        $this->viewAs($people['recruit']);

        self::assertSame(['chief', 'sergeant', 'ranger', 'recruit', 'volunteer'], self::drawnOn($this->body('/')));
    }

    /** The per-area read every area plate is drawn from follows the same rule. */
    public function testThePerAreaReadHandsTheRangerOnlyTheRecruit(): void
    {
        [$area, $people] = $this->aGroundWithFivePeopleOnIt();
        $this->asTheSignedInViewer($people['ranger']);

        $live = $this->presence()->liveIn((string) $area->getUuidString(), new \DateTimeImmutable(WebKernel::CLOCK));

        self::assertSame(
            [(string) $people['recruit']->getUuidString()],
            array_map(static fn (LivePosition $p): string => $p->personUuid, $live->positions),
        );
    }

    /**
     * COUNTS STAY WHOLE: the day's reading, which every "on duty" figure is
     * counted from, names all five whoever is looking — while the live read
     * the sergeant is handed carries two.
     */
    public function testTheDaysReadingIsWholeWhileTheLiveReadIsNarrowed(): void
    {
        [$area, $people] = $this->aGroundWithFivePeopleOnIt();
        $this->asTheSignedInViewer($people['sergeant']);
        $presence = $this->presence();

        $live = $presence->liveIn((string) $area->getUuidString(), new \DateTimeImmutable(WebKernel::CLOCK));
        $day = $presence->dayIn((string) $area->getUuidString(), self::DAY);

        self::assertCount(2, $live->positions);
        self::assertCount(5, $day);
    }

    /** The publisher's one-person read is the system's, not a viewer's, and is never narrowed. */
    public function testThePublishersReadOfOnePersonIsNotNarrowedByWhoeverIsSignedIn(): void
    {
        [$area, $people] = $this->aGroundWithFivePeopleOnIt();
        $this->asTheSignedInViewer($people['recruit']);

        $chief = $this->presence()->liveOf((string) $area->getUuidString(), (string) $people['chief']->getUuidString(), new \DateTimeImmutable(WebKernel::CLOCK));

        self::assertCount(1, $chief->positions);
    }

    /**
     * @param list<string> $grants
     *
     * @return array{AreaOfInterest, array<string, HostUser>}
     */
    private function aGroundWithFivePeopleOnIt(array $grants = self::ALL_AREA_PERMISSIONS): array
    {
        $this->boot($grants);
        $area = $this->aLiveArea('Sample Reserve');
        $post = $this->stations()->add($area, 'Eastgate Post', -29.75, -3.2, 'ST-01');
        $this->em->flush();

        $places = ['chief' => 1, 'sergeant' => 5, 'ranger' => 9, 'recruit' => 12];
        $people = [];
        foreach (self::LON as $who => $lon) {
            $person = new HostUser()->named(ucfirst($who), 'Example');
            $this->em->persist($person);
            $this->em->flush();
            $people[$who] = $person;
            if (isset($places[$who])) {
                $this->ladder()->place((string) $person->getUuidString(), $places[$who]);
            }
            $this->aPing($this->aClaim($area, $person, $post), $lon, -3.2, '11:30:00');
        }

        return [$area, $people];
    }

    private function viewAs(HostUser $person): void
    {
        $principal = static::getContainer()->get(SignedInPerson::class);
        self::assertInstanceOf(SignedInPerson::class, $principal);
        $principal->is($person);
    }

    private function asTheSignedInViewer(HostUser $person): void
    {
        /** @var TokenStorageInterface $tokens */
        $tokens = static::getContainer()->get('security.token_storage');
        $tokens->setToken(new UsernamePasswordToken($person, 'main', ['ROLE_USER']));
    }

    /**
     * Whose marks a page carries, read off their unique coordinates.
     *
     * @return list<string>
     */
    private static function drawnOn(string $body): array
    {
        $drawn = [];
        foreach (self::LON as $who => $lon) {
            if (str_contains($body, (string) $lon)) {
                $drawn[] = $who;
            }
        }

        return $drawn;
    }

    private function body(string $url): string
    {
        $this->browser()->request('GET', $url);
        self::assertSame(200, $this->browser()->getResponse()->getStatusCode(), $url);

        return (string) $this->browser()->getResponse()->getContent();
    }

    private function aClaim(AreaOfInterest $area, HostUser $person, Station $post): CheckIn
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
            ->setClientRef('claim-'.$person->getFirstName())
            ->setLocalDate(new \DateTimeImmutable(self::DAY))
            ->setStatus($status)
            ->setStation($post)
            ->setOccurredAt(new \DateTimeImmutable(self::DAY.'T06:00:00+00:00'))
            ->setDeviceId('0f9ca41e')
            ->setAppVersion('0.1.0');
        $this->em->persist($checkIn);
        $this->em->flush();

        return $checkIn;
    }

    private function aPing(CheckIn $checkIn, float $lon, float $lat, string $time): void
    {
        $area = $checkIn->getArea();
        $person = $checkIn->getPerson();
        self::assertInstanceOf(AreaOfInterest::class, $area);
        self::assertNotNull($person);

        $this->em->persist($ping = new PersonPosition()
            ->setArea($area)
            ->setPerson($person)
            ->setCheckIn($checkIn)
            ->setClientRef('ping-'.$checkIn->getClientRef().'-'.$time)
            ->setRecordedAt(new \DateTimeImmutable(self::DAY.'T'.$time.'+00:00'))
            ->setPosition(\sprintf('{"type":"Point","coordinates":[%s,%s]}', $lon, $lat))
            ->setAccuracyM(8.0)
            ->setSource(PositionSourceEnum::Gps));
        $this->em->flush();

        $facts = static::getContainer()->get('test_public.area.presence_facts');
        self::assertInstanceOf(PresenceFactsService::class, $facts);
        $facts->recordPings([$ping]);
    }

    private function ladder(): FakeRankLadder
    {
        $ladder = static::getContainer()->get(FakeRankLadder::class);
        self::assertInstanceOf(FakeRankLadder::class, $ladder);

        return $ladder;
    }

    private function presence(): PresenceService
    {
        $presence = static::getContainer()->get('test_public.area.presence');
        self::assertInstanceOf(PresenceService::class, $presence);

        return $presence;
    }

    private function stations(): StationService
    {
        /** @var StationService $service */
        $service = static::getContainer()->get('test_public.area.stations');

        return $service;
    }
}
