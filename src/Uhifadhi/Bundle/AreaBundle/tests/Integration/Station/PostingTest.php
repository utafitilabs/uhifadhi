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

namespace Uhifadhi\Bundle\AreaBundle\Tests\Integration\Station;

use PHPUnit\Framework\Attributes\CoversClass;
use Uhifadhi\Bundle\AreaBundle\Entity\Posting;
use Uhifadhi\Bundle\AreaBundle\Entity\Station;
use Uhifadhi\Bundle\AreaBundle\Enum\PostingSource;
use Uhifadhi\Bundle\AreaBundle\Exception\PostingException;
use Uhifadhi\Bundle\AreaBundle\Service\PostingService;
use Uhifadhi\Bundle\AreaBundle\Service\StationService;
use Uhifadhi\Bundle\AreaBundle\Tests\Integration\Fixtures\HostPerson;
use Uhifadhi\Bundle\AreaBundle\Tests\Integration\IntegrationTestCase;

/**
 * A PERSON WORKS OUT OF A STATION, AND THAT IS WHAT A POSTING IS.
 *
 * PEOPLE ARE NOT POSTED TO ZONES. A zone is ground; a station is a place with
 * a door. The area can say who covers where only because a station sits inside
 * a zone by geometry and a person is posted to the station — two joins, each
 * of which is a fact somebody recorded, and neither of which anybody has to
 * keep in step by hand.
 *
 * A POSTING ENDS, IT IS NOT DELETED. Who was posted where last year is how a
 * patrol from last year has a crew, so the row stays and `endedAt` is what
 * takes it out of the standing set. Deleting one would rewrite a past nobody
 * asked to rewrite.
 *
 * ONE LEADER PER STATION, AMONG THE STANDING POSTINGS. Appointing a second is
 * not a refusal — it is what somebody means when they say "she leads now" — so
 * it stands the new one up and the old one down, in one transaction.
 *
 * WHERE THE ROW WAS WRITTEN IS PART OF IT. The station page and the person
 * page both post somebody, and the design prints which — "written here" versus
 * "from their page" — because two people looking at the same posting from two
 * places should be able to tell how it got there.
 */
#[CoversClass(Posting::class)]
#[CoversClass(PostingService::class)]
final class PostingTest extends IntegrationTestCase
{
    public function testSomebodyIsPostedToAStationAndTheRowSaysHowItWasWritten(): void
    {
        $station = $this->aStation();
        $person = $this->aPerson('J. Mollel');

        $posting = $this->postings()->post($station, $person, PostingSource::WrittenHere);

        self::assertSame($station->getId(), $posting->getStation()?->getId());
        self::assertSame($person->getUuidString(), $posting->getPerson()?->getUuidString());
        self::assertSame(PostingSource::WrittenHere, $posting->getSource());
        self::assertNull($posting->getEndedAt());
        self::assertFalse($posting->isLeader());
    }

    public function testAPostingFromThePersonsOwnPageSaysSo(): void
    {
        $posting = $this->postings()->post($this->aStation(), $this->aPerson('T. Ndosi'), PostingSource::FromTheirPage);

        self::assertSame(PostingSource::FromTheirPage, $posting->getSource());
    }

    /** Ended, not deleted: last year's crew is how last year's patrol has one. */
    public function testEndingAPostingKeepsTheRowAndTakesItOutOfTheStandingSet(): void
    {
        $station = $this->aStation();
        $posting = $this->postings()->post($station, $this->aPerson('M. Kisanga'), PostingSource::WrittenHere);

        $this->postings()->end($posting);

        self::assertNotNull($posting->getEndedAt());
        self::assertCount(1, $this->em->getRepository(Posting::class)->findAll());
        self::assertSame([], $this->postings()->standingAt($station));
    }

    public function testTheStandingPostingsAreTheOnesThatHaveNotEnded(): void
    {
        $station = $this->aStation();
        $this->postings()->post($station, $this->aPerson('J. Mollel'), PostingSource::WrittenHere);
        $left = $this->postings()->post($station, $this->aPerson('M. Kisanga'), PostingSource::WrittenHere);
        $this->postings()->end($left);

        $standing = $this->postings()->standingAt($station);

        self::assertCount(1, $standing);
        self::assertSame('J. Mollel', $standing[0]->getPerson()?->getFullName());
    }

    /** The same person twice at one station is a mistake, not two postings. */
    public function testSomebodyAlreadyPostedThereIsNotPostedTwice(): void
    {
        $station = $this->aStation();
        $person = $this->aPerson('J. Mollel');
        $this->postings()->post($station, $person, PostingSource::WrittenHere);

        $this->expectException(PostingException::class);
        $this->expectExceptionMessageMatches('/already stationed/');

        $this->postings()->post($station, $person, PostingSource::WrittenHere);
    }

    /**
     * ONE POSTING A PERSON — one station, one area (ruled).
     *
     * A posting is where somebody WORKS, and they work in one place. Two
     * standing postings make a roll nobody can read, a head count that
     * double-counts the same ranger, and a check-in the handset cannot say
     * which post it is against.
     */
    public function testSomebodyStandingAtOnePostIsNotPostedToASecond(): void
    {
        $area = $this->anArea();
        $first = $this->stations()->add($area, 'Eastgate Post', -29.75, -3.2);
        $second = $this->stations()->add($area, 'Fig Tree Ranger Station', -29.7, -3.2);
        $person = $this->aPerson('J. Mollel');
        $this->postings()->post($first, $person, PostingSource::WrittenHere);

        $this->expectException(PostingException::class);
        // The refusal names where they already stand: the person asking has
        // almost always forgotten rather than meant it.
        $this->expectExceptionMessageMatches('/posted to Eastgate Post/');

        $this->postings()->post($second, $person, PostingSource::WrittenHere);
    }

    /** AND NOT TO A POST IN ANOTHER AREA EITHER — one posting is one posting. */
    public function testSomebodyStandingInOneAreaIsNotPostedInAnother(): void
    {
        $north = $this->stations()->add($this->anArea('Northern Reserve'), 'Eastgate Post', -29.75, -3.2);
        $south = $this->stations()->add($this->anArea('Southern Reserve'), 'Riverbend Ranger Station', -29.6, -3.4);
        $person = $this->aPerson('A. Sanka');
        $this->postings()->post($north, $person, PostingSource::WrittenHere);

        $this->expectException(PostingException::class);

        $this->postings()->post($south, $person, PostingSource::WrittenHere);
    }

    /**
     * MOVING SOMEBODY IS TWO ACTS, and that is the point rather than a cost:
     * ending the posting they have is what leaves last year's patrol with a
     * crew, and what the roll reads back.
     */
    public function testEndingThePostingFreesThePersonForTheNextOne(): void
    {
        $area = $this->anArea();
        $first = $this->stations()->add($area, 'Eastgate Post', -29.75, -3.2);
        $second = $this->stations()->add($area, 'Fig Tree Ranger Station', -29.7, -3.2);
        $person = $this->aPerson('M. Kisanga');
        $this->postings()->end($this->postings()->post($first, $person, PostingSource::WrittenHere));

        $moved = $this->postings()->post($second, $person, PostingSource::WrittenHere);

        self::assertNull($moved->getEndedAt());
        self::assertCount(1, $this->postings()->standingAt($second));
        self::assertCount(0, $this->postings()->standingAt($first));
        // And the row that ended is still there, because who was posted where
        // last year is how last year's patrol has a crew.
        self::assertCount(2, $this->em->getRepository(Posting::class)->findAll());
    }

    /** Somebody whose posting ended may be posted again — people come back. */
    public function testSomebodyWhosePostingEndedMayBePostedAgain(): void
    {
        $station = $this->aStation();
        $person = $this->aPerson('M. Kisanga');
        $this->postings()->end($this->postings()->post($station, $person, PostingSource::WrittenHere));

        $again = $this->postings()->post($station, $person, PostingSource::WrittenHere);

        self::assertNull($again->getEndedAt());
        self::assertCount(2, $this->em->getRepository(Posting::class)->findAll());
    }

    // ------------------------------------------------------- one leader

    public function testAppointingALeaderMarksThatPosting(): void
    {
        $station = $this->aStation();
        $posting = $this->postings()->post($station, $this->aPerson('J. Mollel'), PostingSource::WrittenHere);

        $this->postings()->appointLeader($posting);

        self::assertTrue($posting->isLeader());
        self::assertSame('J. Mollel', $this->postings()->leaderAt($station)?->getPerson()?->getFullName());
    }

    /** "She leads now" stands one up and the other down, in one act. */
    public function testAppointingASecondLeaderStandsTheFirstDown(): void
    {
        $station = $this->aStation();
        $first = $this->postings()->post($station, $this->aPerson('J. Mollel'), PostingSource::WrittenHere);
        $second = $this->postings()->post($station, $this->aPerson('A. Sanka'), PostingSource::WrittenHere);

        $this->postings()->appointLeader($first);
        $this->postings()->appointLeader($second);

        self::assertFalse($first->isLeader());
        self::assertTrue($second->isLeader());
        self::assertCount(1, array_filter(
            $this->postings()->standingAt($station),
            static fn (Posting $p): bool => $p->isLeader(),
        ));
    }

    /** Two stations each have their own leader; appointing at one leaves the other. */
    public function testEachStationHasItsOwnLeader(): void
    {
        $area = $this->anArea();
        $first = $this->stations()->add($area, 'Eastgate Post', -29.75, -3.2);
        $second = $this->stations()->add($area, 'Fig Tree Ranger Station', -29.7, -3.2);

        $a = $this->postings()->post($first, $this->aPerson('J. Mollel'), PostingSource::WrittenHere);
        $b = $this->postings()->post($second, $this->aPerson('A. Sanka'), PostingSource::WrittenHere);
        $this->postings()->appointLeader($a);
        $this->postings()->appointLeader($b);

        self::assertTrue($a->isLeader());
        self::assertTrue($b->isLeader());
    }

    /** A leader whose posting ends leaves the station without one, not with a ghost. */
    public function testEndingTheLeadersPostingLeavesTheStationWithoutALeader(): void
    {
        $station = $this->aStation();
        $posting = $this->postings()->post($station, $this->aPerson('J. Mollel'), PostingSource::WrittenHere);
        $this->postings()->appointLeader($posting);

        $this->postings()->end($posting);

        self::assertNull($this->postings()->leaderAt($station));
    }

    /** An ended posting cannot be handed the lead. */
    public function testAnEndedPostingCannotBeAppointedLeader(): void
    {
        $posting = $this->postings()->post($this->aStation(), $this->aPerson('J. Mollel'), PostingSource::WrittenHere);
        $this->postings()->end($posting);

        $this->expectException(PostingException::class);
        $this->expectExceptionMessageMatches('/ended/');

        $this->postings()->appointLeader($posting);
    }

    // ---------------------------------------------------------------- fixtures

    /**
     * CLOSING A POST STANDS EVERYBODY DOWN. A post that has closed has
     * nobody at it, and people left posted to it would appear on next week's
     * staffing list; the postings END rather than disappear, so last season's
     * patrol still has its crew.
     */
    public function testDeactivatingAPostEndsThePostingsStandingAtIt(): void
    {
        $station = $this->aStation();
        $lead = $this->postings()->post($station, $this->aPerson('J. Mollel'), PostingSource::WrittenHere);
        $this->postings()->appointLeader($lead);
        $this->postings()->post($station, $this->aPerson('T. Ndosi'), PostingSource::FromTheirPage);

        $this->stations()->deactivate($station);

        self::assertSame([], $this->postings()->standingAt($station));
        self::assertNotNull($lead->getEndedAt());
    }

    /** Reopening a post does not repost anybody: who works there is a new fact. */
    public function testReactivatingAPostDoesNotBringThePostingsBack(): void
    {
        $station = $this->aStation();
        $this->postings()->post($station, $this->aPerson('J. Mollel'), PostingSource::WrittenHere);
        $this->stations()->deactivate($station);

        $this->stations()->reactivate($station);

        self::assertSame([], $this->postings()->standingAt($station));
    }

    private function postings(): PostingService
    {
        /** @var PostingService $service */
        $service = static::getContainer()->get('test_public.area.postings');

        return $service;
    }

    private function stations(): StationService
    {
        /** @var StationService $service */
        $service = static::getContainer()->get('test_public.area.stations');

        return $service;
    }

    private function aStation(): Station
    {
        return $this->stations()->add($this->anArea(), 'Eastgate Post', -29.75, -3.2, 'ST-01');
    }

    private function aPerson(string $name): HostPerson
    {
        [$first, $last] = explode(' ', $name, 2);
        $person = new HostPerson()->named($first, $last);
        $this->em->persist($person);
        $this->em->flush();

        return $person;
    }
}
