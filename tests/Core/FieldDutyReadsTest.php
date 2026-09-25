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

namespace Uhifadhi\Core\Tests\Core;

use Symfony\Component\HttpFoundation\Response;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\Service\DutyRosterService;
use Uhifadhi\Bundle\AreaBundle\Service\PresenceService;
use Uhifadhi\Bundle\TeamBundle\Entity\Placement;
use Uhifadhi\Bundle\TeamBundle\Entity\Position;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Bundle\TeamBundle\Enum\TeamRoleEnum;

/**
 * THE TWO READS THE DUTY TAB LIVES ON — API-CONTRACT.md §13D and §13E.
 *
 * BOTH MUST WORK FROM CACHE, which is why both are small whole
 * documents: nothing on the Duty tab waits on a network, including the
 * month, so neither read is paged and neither can be.
 *
 * WHAT EACH SPECIFICATION HERE IS ABOUT:
 *
 *  * "ME" IS THE TOKEN'S ACCOUNT. There is no person in either URI and
 *    there must not be: a handset reading somebody else's month would be
 *    reading where that person sleeps.
 *  * A DAY WITH NO WATCH IS A REST DAY. An installation with no roster
 *    module answers no watches at all — an absence, not an error and not
 *    an empty screen.
 *  * THE INTERVAL IS ALWAYS SENT. A host that omitted it would leave the
 *    phone on the last value it was given, so the area's setting — or
 *    the product's default where it set none — travels on every read.
 *  * NULL CATCHMENT IS A REAL STATE. A post with no ring has no inside,
 *    and nothing here substitutes a radius of its own.
 */
final class FieldDutyReadsTest extends FieldApiTestCase
{
    private const float POST_LON = -65.0;
    private const float POST_LAT = -3.2;

    public function testTheRosterWithNoTokenIsUnauthorized(): void
    {
        $area = $this->area('Northern Conservation Reserve');

        $body = $this->get($this->roster($area));

        self::assertSame(Response::HTTP_UNAUTHORIZED, $this->client->getResponse()->getStatusCode());
        self::assertSame('unauthorized', self::leaf($body, 'code'));
    }

    /** Reading the park is not reporting a day, and the month is part of reporting one. */
    public function testAnAccountWithoutTheDutyPermissionIsForbidden(): void
    {
        $area = $this->area('Northern Conservation Reserve');

        $body = $this->get($this->roster($area), $this->tokenFor($this->ranger('sl-0199')));

        self::assertSame(Response::HTTP_FORBIDDEN, $this->client->getResponse()->getStatusCode());
        self::assertSame('forbidden', self::leaf($body, 'code'));
    }

    /** §13D: the document, key for key. */
    public function testTheRosterIsTheContractsExactDocument(): void
    {
        $area = $this->area('Northern Conservation Reserve');

        $body = $this->get($this->roster($area).'?from=2026-09-01&to=2026-09-30', $this->tokenFor($this->onDuty()));

        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        self::assertSame(['pingIntervalMinutes', 'rostered', 'watches', 'checkInStatuses', 'updatedAt'], array_keys($body));
    }

    /**
     * §13D: A WATCH REACHES THE HANDSET THROUGH THE SEAM. The area asks
     * and does not keep: who is on which watch belongs to the roster
     * module, and this document is the area's answer with that module's
     * words folded into it.
     */
    public function testAWatchTheAreaDoesNotOwnReachesTheHandset(): void
    {
        $area = $this->area('Northern Conservation Reserve');

        $body = $this->get($this->roster($area).'?from=2026-09-01&to=2026-09-30', $this->tokenFor($this->onDuty()));

        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        self::assertCount(1, self::nested($body, 'watches'));

        $watch = self::nested($body, 'watches', 0);
        self::assertSame(['localDate', 'startsAt', 'endsAt', 'stationUuid', 'label'], array_keys($watch));
        self::assertSame(FakeRoster::THE_DAY, $watch['localDate']);
        self::assertSame('Day watch', $watch['label']);
        // A watch that names no post is a real watch, not a broken one.
        self::assertNull($watch['stationUuid']);
    }

    /**
     * §13D: A DAY WITH NO WATCH IS A REST DAY. The app then shows no row,
     * no dot and no amber and schedules no reminder — an absence here is
     * the answer, not a gap, which is why nothing stands in for it.
     */
    public function testAWindowWithNoWatchInItAnswersAnEmptyList(): void
    {
        $area = $this->area('Northern Conservation Reserve');

        $body = $this->get($this->roster($area).'?from=2026-10-01&to=2026-10-31', $this->tokenFor($this->onDuty()));

        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        self::assertSame([], self::nested($body, 'watches'));
    }

    /** The interval is the area's, and half an hour where it set none. */
    public function testTheIntervalIsTheProductsDefaultUntilTheAreaSetsOne(): void
    {
        $area = $this->area('Northern Conservation Reserve');

        $body = $this->get($this->roster($area), $this->tokenFor($this->onDuty()));

        self::assertSame(DutyRosterService::DEFAULT_PING_INTERVAL_MINUTES, self::leaf($body, 'pingIntervalMinutes'));
    }

    /**
     * A BATTERY BUDGET THE ORGANIZATION OWNS. An area that works long
     * patrols out of radio range says so once and every handset in it
     * reads the new figure at the next sync, with no release.
     */
    public function testAnAreaThatSetsAnIntervalIsObeyed(): void
    {
        $area = $this->area('Northern Conservation Reserve');
        $area->setPingIntervalMinutes(10);
        $this->em->flush();

        $body = $this->get($this->roster($area), $this->tokenFor($this->onDuty()));

        self::assertSame(10, self::leaf($body, 'pingIntervalMinutes'));
    }

    /**
     * THE AREA SETTINGS' ONE WRITE IS WHAT THE HANDSET READS. Somebody with
     * `areas.configure` types "2 hours" on Edit area, and the next roster
     * read tells the phone 120 — and the live reading judges freshness
     * against the same number.
     */
    public function testSavingTheAreaSettingsChangesWhatTheHandsetReads(): void
    {
        $area = $this->area('Northern Conservation Reserve');
        $uuid = (string) $area->getUuidString();
        $ranger = $this->onDuty();
        $token = $this->tokenFor($ranger);

        $this->client->loginUser($this->configurer());
        $this->client->request('GET', '/areas/'.$uuid.'/edit');
        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        $matched = preg_match('#name="_token" value="([^"]+)"[^>]*data-token="area_edit"#', (string) $this->client->getResponse()->getContent(), $m);
        self::assertSame(1, $matched, 'The edit screen carries its identity token.');

        $this->client->request('POST', '/areas/'.$uuid.'/edit', [
            'name' => 'Northern Conservation Reserve',
            'pingEvery' => '2',
            'pingEveryUnit' => 'hours',
            '_token' => $m[1],
        ]);
        self::assertSame(Response::HTTP_FOUND, $this->client->getResponse()->getStatusCode());

        $body = $this->get($this->roster($area), $token);
        self::assertSame(120, self::leaf($body, 'pingIntervalMinutes'));

        $presence = static::getContainer()->get('area.presence');
        self::assertInstanceOf(PresenceService::class, $presence);
        self::assertSame(120, $presence->liveIn($uuid, new \DateTimeImmutable('2026-09-25 10:30'))->pingIntervalMinutes);
    }

    /**
     * §13D: THE WORDS THE AREA PUBLISHES, seeded on first use so that an
     * installation which has never opened the roster's settings still has
     * a working check-in.
     */
    public function testTheAreasOwnStatusesTravelWithTheRoster(): void
    {
        $area = $this->area('Northern Conservation Reserve');

        $body = $this->get($this->roster($area), $this->tokenFor($this->onDuty()));

        $statuses = self::nested($body, 'checkInStatuses');
        self::assertCount(4, $statuses);
        self::assertSame(['at_post', 'unfit', 'outside', 'special'], array_map(
            static fn (mixed $row): mixed => \is_array($row) ? ($row['key'] ?? null) : null,
            $statuses,
        ));

        self::assertSame(['key', 'label', 'kind', 'takesStation', 'order'], array_keys(self::nested($body, 'checkInStatuses', 0)));
    }

    /**
     * THE KIND TRAVELS AND THE HANDSET NEVER GUESSES IT. The phone asks
     * for a post because the status said it takes one, not because it
     * recognised a word.
     */
    public function testOnlyAtPostTakesAStation(): void
    {
        $area = $this->area('Northern Conservation Reserve');

        $body = $this->get($this->roster($area), $this->tokenFor($this->onDuty()));

        $takes = [];
        foreach (array_keys(self::nested($body, 'checkInStatuses')) as $index) {
            $key = self::leaf($body, 'checkInStatuses', $index, 'key');
            // THE KEY IS A STRING, and that is part of what is being said:
            // it is the stable word the handset sends back on a claim.
            self::assertIsString($key);
            $takes[$key] = self::leaf($body, 'checkInStatuses', $index, 'takesStation');
        }

        self::assertTrue($takes['at_post']);
        self::assertFalse($takes['unfit']);
        self::assertFalse($takes['outside']);
        self::assertFalse($takes['special']);
        self::assertSame('working_elsewhere', self::leaf($body, 'checkInStatuses', 2, 'kind'));
    }

    /** So a phone can tell whether the list it holds is the list the area publishes. */
    public function testTheWordsCarryWhenTheyLastChanged(): void
    {
        $area = $this->area('Northern Conservation Reserve');

        $body = $this->get($this->roster($area), $this->tokenFor($this->onDuty()));

        $updatedAt = self::leaf($body, 'updatedAt');
        self::assertIsString($updatedAt);
        self::assertInstanceOf(\DateTimeImmutable::class, new \DateTimeImmutable($updatedAt));
    }

    /** A window the caller mistyped is refused by name, never quietly widened. */
    public function testAMalformedWindowIsRefused(): void
    {
        $area = $this->area('Northern Conservation Reserve');

        $body = $this->get($this->roster($area).'?from=september&to=2026-09-30', $this->tokenFor($this->onDuty()));

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $this->client->getResponse()->getStatusCode());
        self::assertSame('invalid_payload', self::leaf($body, 'code'));
        self::assertSame('from', self::leaf($body, 'details', 'field'));
    }

    /** §13E: the posts, and the ring each one carries. */
    public function testTheStationsAreTheContractsExactDocument(): void
    {
        $area = $this->area('Northern Conservation Reserve');
        $post = $this->station($area, 'North Gate Post', self::POST_LON, self::POST_LAT, 300, 'ST-01');

        $body = $this->get($this->stations($area), $this->tokenFor($this->onDuty()));

        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        self::assertSame(['stations'], array_keys($body));
        self::assertCount(1, self::nested($body, 'stations'));

        $sent = self::nested($body, 'stations', 0);
        self::assertSame(['uuid', 'name', 'code', 'lat', 'lon', 'catchmentM'], array_keys($sent));
        self::assertSame($post->getUuidString(), $sent['uuid']);
        self::assertSame('North Gate Post', $sent['name']);
        // §13E: WHAT THE RANGER SAYS OUT LOUD. The picker and the confirm
        // print it beside the name; a uuid is what nobody says at all.
        self::assertSame('ST-01', $sent['code']);
        // A WHOLE-NUMBER DEGREE DECODES AS AN INT, which is JSON and not a
        // contract change: the wire carries a number and the app reads a
        // Double either way.
        self::assertEqualsWithDelta(self::POST_LAT, $sent['lat'], 0.000001);
        self::assertEqualsWithDelta(self::POST_LON, $sent['lon'], 0.000001);
        self::assertSame(300, $sent['catchmentM']);
    }

    /**
     * §13D: ROSTERED AND A REST DAY ARE DIFFERENT ANSWERS, and an empty
     * `watches` cannot tell them apart. Somebody rostered with no watch
     * today is resting; somebody the roster has never heard of must
     * still be offered a check-in, because a ranger called in for one
     * shift cannot be refused the screen for want of a plan.
     */
    public function testTheRosterSaysWhetherThisPersonIsRosteredAtAll(): void
    {
        $area = $this->area('Northern Conservation Reserve');
        $token = $this->tokenFor($this->onDuty());

        // A window the fake roster has a watch in: rostered, and working.
        $working = $this->get($this->roster($area).'?from=2026-09-01&to=2026-09-30', $token);
        self::assertTrue(self::leaf($working, 'rostered'));

        // A window with no watch in it: the seam plans nothing here, so
        // the phone offers a check-in rather than drawing a rest day.
        $quiet = $this->get($this->roster($area).'?from=2026-10-01&to=2026-10-31', $token);
        self::assertFalse(self::leaf($quiet, 'rostered'));
        self::assertSame([], self::nested($quiet, 'watches'));
    }

    /**
     * §13E: NULL IS A REAL STATE. A post with no ring has no inside, and
     * the handset says so rather than picking a radius of its own — so
     * nothing here substitutes a default.
     */
    public function testAPostWithNoRingSendsNullAndNotADefault(): void
    {
        $area = $this->area('Northern Conservation Reserve');
        $this->station($area, 'Anvil Post', self::POST_LON + 0.1, self::POST_LAT, null);

        $body = $this->get($this->stations($area), $this->tokenFor($this->onDuty()));

        self::assertNull(self::leaf($body, 'stations', 0, 'catchmentM'));
    }

    /**
     * `near` IS A HINT THE ORDER MAY FOLLOW. The app sorts by the distance
     * it measures itself, because the picker has to work with no network;
     * this is a courtesy for a client reading the endpoint fresh.
     */
    public function testNearOrdersTheAnswerWithoutNarrowingIt(): void
    {
        $area = $this->area('Northern Conservation Reserve');
        $this->station($area, 'Far Post', self::POST_LON + 2.0, self::POST_LAT, 300);
        $this->station($area, 'Near Post', self::POST_LON, self::POST_LAT, 300);

        $near = \sprintf('?near=%s,%s', self::POST_LAT, self::POST_LON);
        $body = $this->get($this->stations($area).$near, $this->tokenFor($this->onDuty()));

        self::assertCount(2, self::nested($body, 'stations'), 'near orders the list, it never trims it');
        self::assertSame('Near Post', self::leaf($body, 'stations', 0, 'name'));
    }

    /** A hint that does not parse orders nothing and refuses nothing. */
    public function testAMalformedNearIsIgnoredRatherThanRefused(): void
    {
        $area = $this->area('Northern Conservation Reserve');
        $this->station($area, 'North Gate Post', self::POST_LON, self::POST_LAT, 300);

        $body = $this->get($this->stations($area).'?near=somewhere', $this->tokenFor($this->onDuty()));

        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        self::assertCount(1, self::nested($body, 'stations'));
    }

    /** An area the URI names that this installation does not have. */
    public function testAnUnknownAreaIsRefusedOnBothReads(): void
    {
        $token = $this->tokenFor($this->onDuty());
        $missing = '/api/areas/ffffffff-0000-4000-8000-000000000000';

        $body = $this->get($missing.'/me/roster', $token);
        self::assertSame(Response::HTTP_NOT_FOUND, $this->client->getResponse()->getStatusCode());
        self::assertSame('unknown_area', self::leaf($body, 'code'));

        $body = $this->get($missing.'/stations', $token);
        self::assertSame(Response::HTTP_NOT_FOUND, $this->client->getResponse()->getStatusCode());
        self::assertSame('unknown_area', self::leaf($body, 'code'));
    }

    // ─── The cast ──────────────────────────────────────────────────────────

    private function onDuty(): User
    {
        return $this->ranger('sl-0142', [...self::READS_THE_PARK, 'duty.record']);
    }

    /** Somebody who may change how an area runs: the pair the settings' write asks. */
    private function configurer(): User
    {
        $position = new Position()->setName('Area Warden');
        $pairs = [...self::READS_THE_PARK, 'areas.configure'];
        $position->setGrantValues($pairs, $pairs);
        $this->em->persist($position);

        $placement = new Placement()->acrossTheOrganization()->acrossAllDepartments();
        $this->em->persist($placement);

        $user = new User()
            ->setEmail('warden@example.test')
            ->setFirstName('Asha')
            ->setLastName('Kombo')
            ->setPassword('x')
            ->setTeamRole(TeamRoleEnum::Staff)
            ->setVerified(true);
        $user->setPosition($position);
        $user->setPlacement($placement);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function roster(AreaOfInterest $area): string
    {
        return \sprintf('/api/areas/%s/me/roster', (string) $area->getUuidString());
    }

    private function stations(AreaOfInterest $area): string
    {
        return \sprintf('/api/areas/%s/stations', (string) $area->getUuidString());
    }
}
