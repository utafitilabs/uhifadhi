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
use Uhifadhi\Bundle\TeamBundle\Access\ConcernCatalogue;
use Uhifadhi\Bundle\TeamBundle\Enum\TeamRoleEnum;
use Uhifadhi\Contracts\Access\Grant;

/**
 * `GET /api/me` — who this token belongs to, and what the account may do.
 *
 * The same facts sign-in already answered, asked again: months pass between
 * sign-ins, and a permission granted in the web app has to reach the handset
 * without a sign-out or a re-install. A client calls it at the start of every
 * sync run and from the controls it offers somebody who has been refused, so it
 * is read far more often than it is interesting.
 *
 * AN EMPTY PERMISSION ARRAY IS A REFUSAL and a MISSING one reads as "an older
 * installation, therefore permitted". The field is therefore always sent, which
 * is asserted here and not merely intended.
 */
final class FieldAccountEndpointTest extends FieldApiTestCase
{
    private const string ENDPOINT = '/api/me';

    public function testNoTokenIsUnauthorized(): void
    {
        $this->get(self::ENDPOINT);

        self::assertSame(Response::HTTP_UNAUTHORIZED, $this->client->getResponse()->getStatusCode());
    }

    /** A refusal at this door is the one failure document everything under /api answers in. */
    public function testARefusalCarriesTheApiErrorDocument(): void
    {
        $body = $this->get(self::ENDPOINT);

        self::assertSame(['code', 'message', 'retryable', 'details'], array_keys($body));
        self::assertSame('unauthorized', self::leaf($body, 'code'));
        self::assertFalse(self::leaf($body, 'retryable'));
    }

    public function testATokenNamingNobodyIsUnauthorized(): void
    {
        $this->get(self::ENDPOINT, 'nothing-was-ever-issued-for-this');

        self::assertSame(Response::HTTP_UNAUTHORIZED, $this->client->getResponse()->getStatusCode());
    }

    public function testTheBearerAccountIsAnsweredInTheContractsExactDocument(): void
    {
        $ranger = $this->ranger();

        $body = $this->get(self::ENDPOINT, $this->tokenFor($ranger));

        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        self::assertSame(['ranger', 'permissions'], array_keys($body));
        self::assertSame(
            ['id' => 'sl-0142', 'name' => 'Witness Mbise', 'role' => 'Field Ranger'],
            self::nested($body, 'ranger'),
        );
        self::assertSame(self::VIEW_AN_AREA, self::nested($body, 'permissions'));
    }

    /**
     * `ranger.role` is load-bearing rather than cosmetic: a refusal screen names
     * the POSITION that lacks the permission, because that is what an
     * administrator has to change. Somebody with no position still has a role —
     * their tier — so the field is never blank.
     */
    public function testSomebodyWithNoPositionIsNamedByTheirTier(): void
    {
        $office = $this->officeStaff('Naomi', 'Kileo');

        $body = $this->get(self::ENDPOINT, $this->tokenFor($office));

        self::assertSame(TeamRoleEnum::Staff->label(), self::leaf($body, 'ranger', 'role'));
    }

    /**
     * The address stands in where there is no service number: office staff are
     * never issued one, and the identifier here is the one that comes back in
     * every roster and on every record.
     */
    public function testTheAddressIdentifiesSomebodyWithNoServiceNumber(): void
    {
        $office = $this->officeStaff('Naomi', 'Kileo');

        $body = $this->get(self::ENDPOINT, $this->tokenFor($office));

        self::assertSame('n.kileo@example.test', self::leaf($body, 'ranger', 'id'));
    }

    /** A refusal, said out loud: the field is present and empty, never absent. */
    public function testAnAccountHoldingNothingIsSentAnEmptyArrayRatherThanNoField(): void
    {
        $nobody = $this->ranger('sl-0777', grants: []);

        $body = $this->get(self::ENDPOINT, $this->tokenFor($nobody));

        self::assertArrayHasKey('permissions', $body);
        self::assertSame([], self::nested($body, 'permissions'));
    }

    /**
     * THE WHOLE SET CROSSES, catalogue values and nothing else, and a client
     * reads the one member it understands. This installation learns nothing
     * about what any of them mean.
     */
    public function testEveryPermissionTheAccountHoldsIsSent(): void
    {
        $ranger = $this->ranger('sl-0143', [...self::READS_THE_PARK, 'modules.configure']);

        $body = $this->get(self::ENDPOINT, $this->tokenFor($ranger));

        self::assertSame(
            [...self::VIEW_AN_AREA, 'modules.configure'],
            self::nested($body, 'permissions'),
        );
    }

    /**
     * THE GRANTS, NOT THE OLD NAMES. What the web enforces is a position's
     * `<concern>.<verb>` grants, read by the GrantVoter; what a field client
     * reads is the same array. The old permission names were sent here long
     * after the positions screen stopped writing them, so a position that
     * granted `patrols.record` on screen reached the handset as nothing, and
     * the handset refused to record.
     */
    public function testAGrantMadeOnThePositionsScreenReachesTheHandset(): void
    {
        $ranger = $this->ranger('sl-0144', grants: [], declared: ['patrols.record']);
        $ranger->getPosition()?->setGrantValues(['duty.record'], ['duty.record']);
        $this->em->flush();

        $body = $this->get(self::ENDPOINT, $this->tokenFor($ranger));

        self::assertSame(['duty.record'], self::nested($body, 'permissions'));
    }

    /** A tier that manages content holds every grant the catalogue knows. */
    public function testAnAdministratorHoldsEveryGrantTheCatalogueKnows(): void
    {
        $admin = $this->officeStaff('Ada', 'Mwangi');
        $admin->setTeamRole(TeamRoleEnum::Admin);
        $this->em->flush();

        $body = $this->get(self::ENDPOINT, $this->tokenFor($admin));

        /** @var ConcernCatalogue $catalogue */
        $catalogue = static::getContainer()->get(ConcernCatalogue::class);
        self::assertSame($catalogue->pairs(), self::nested($body, 'permissions'));
        self::assertContains('areas.configure', self::nested($body, 'permissions'));
    }

    /**
     * THE HANDSET'S FIXTURE IS WHAT THIS ENDPOINT SENDS — the same pinning as
     * the areas document: `Fixtures/field/me.json` is copied verbatim into the
     * handset's test resources, where its client reads the two grants it acts
     * on. Every permission in it must be a grant this vocabulary can spell.
     */
    public function testTheHandsetFixtureIsWhatThisEndpointSends(): void
    {
        $text = (string) file_get_contents(__DIR__.'/Fixtures/field/me.json');
        self::assertSame(self::HANDSET_e4b66ce4949260f5191664585665fa81a5a06071497a6536c0cf594c28fa8adc256, hash('sha256', $text), 'the fixture changed: re-pin on BOTH sides');

        /** @var array{ranger: array<string, string>, permissions: list<string>} $fixture */
        $fixture = json_decode($text, true, flags: \JSON_THROW_ON_ERROR);

        $body = $this->get(self::ENDPOINT, $this->tokenFor($this->ranger()));

        self::assertSame(array_keys($fixture), array_keys($body));
        self::assertSame(array_keys($fixture['ranger']), array_keys(self::nested($body, 'ranger')));
        foreach ($fixture['permissions'] as $permission) {
            self::assertNotNull(Grant::tryParse($permission), $permission.' must be a <concern>.<verb> grant');
        }
    }

    /**
     * The grants `area.view` used to mean, in the order the position holds
     * them — minus the pairs this catalogue cannot spell (assignments and duty
     * have no `read` verb), which are not sent as if they were live.
     */
    private const array VIEW_AN_AREA = ['areas.read', 'zones.read', 'stations.read', 'modules.read'];

    /** Pinned on both sides; the handset's `FieldContractTest` holds the same value. */
    private const string HANDSET_e4b66ce4949260f5191664585665fa81a5a06071497a6536c0cf594c28fa8adc256 = 'e4b66ce4949260f5191664585665fa81a5a06071497a6536c0cf594c28fa8adc';
}
