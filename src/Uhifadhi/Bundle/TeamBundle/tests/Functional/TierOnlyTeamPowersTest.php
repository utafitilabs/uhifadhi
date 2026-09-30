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

use PHPUnit\Framework\Attributes\DataProvider;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Bundle\TeamBundle\Enum\TeamRoleEnum;

/**
 * THE TEAM IS ADMINISTERED BY THE TIERS ALONE (ruled 30 Sep, #67, option b).
 *
 * Who holds which seat, a person's address and sign-in, the positions and
 * the ranks are Admins' and Super Admins' work. No position carries it, so no
 * seat can raise itself, hand on more than it holds, or take over somebody
 * else's account. The person these tests send is the strongest a position can
 * make: placed across the whole organization, in a seat that still stores all
 * four pairs, as a seat written before the ruling would. Every door refuses
 * them with a 403 before it reads a form, and nothing changes.
 */
final class TierOnlyTeamPowersTest extends WebTestCaseWithSchema
{
    /** @return iterable<string, array{string, string}> */
    public static function doors(): iterable
    {
        yield 'open somebody for changes' => ['GET', '/team/{person}/configure'];
        yield 'seat somebody, or themselves' => ['POST', '/team/{person}/position'];
        yield 'change somebody\'s email and details' => ['POST', '/team/{person}'];
        yield 'change a tier' => ['POST', '/team/{person}/tier'];
        yield 'deactivate somebody' => ['POST', '/team/{person}/deactivate'];
        yield 'reactivate somebody' => ['POST', '/team/{person}/reactivate'];
        yield 'send the invitation again' => ['POST', '/team/{person}/invite-again'];
        yield 'issue a one-time password' => ['POST', '/team/{person}/one-time-password'];
        yield 'add somebody' => ['POST', '/team'];
        yield 'invite somebody' => ['GET', '/team/invite'];
        yield 'write a position\'s grants' => ['POST', '/team/positions/{position}/permissions'];
        yield 'create a position' => ['POST', '/team/positions'];
        yield 'rename a position' => ['POST', '/team/positions/{position}/rename'];
        yield 'retire a position' => ['POST', '/team/positions/{position}/retire'];
        yield 'configure the positions' => ['GET', '/team/configure/positions'];
        yield 'configure the ranks' => ['GET', '/team/configure/ranks'];
    }

    #[DataProvider('doors')]
    public function testTheStrongestSeatIsRefusedAtEveryTeamDoor(string $method, string $path): void
    {
        [$coordinator, $grace] = $this->theStrongestSeatAndSomebody();
        $position = $coordinator->getPosition();
        self::assertNotNull($position);

        $this->client->request($method, strtr($path, [
            '{person}' => $grace->getUuidString(),
            '{position}' => $position->getUuidString(),
        ]), 'POST' === $method ? ['position' => $position->getUuidString(), 'grants' => ['directory.manage'], 'name' => 'Warden', 'email' => 'mine@example.test'] : []);

        self::assertResponseStatusCodeSame(403, \sprintf('%s %s let a position through.', $method, $path));

        $this->em->clear();
        $stored = $this->em->getRepository(User::class)->findOneBy(['email' => 'g.ndosi@example.test']);
        self::assertInstanceOf(User::class, $stored);
        self::assertNull($stored->getPosition(), 'nothing changed');
        self::assertTrue($stored->isActive(), 'nothing changed');
    }

    /** The seat cannot aim the door at itself either. */
    public function testTheStrongestSeatCannotSeatItselfSomewhereStronger(): void
    {
        [$coordinator] = $this->theStrongestSeatAndSomebody();
        $stronger = $this->position('Head of operations', ['directory.read', 'departments.configure', 'stations.configure']);
        $this->em->flush();

        $this->client->request('POST', '/team/'.$coordinator->getUuidString().'/position', ['position' => $stronger->getUuidString()]);

        self::assertResponseStatusCodeSame(403);
        $this->em->clear();
        $stored = $this->em->getRepository(User::class)->findOneBy(['email' => 't.ndlovu@example.test']);
        self::assertSame('Coordinator', $stored?->getPosition()?->getName());
    }

    /** An Admin opens the same door: it is the tiers' work, not nobody's. */
    public function testAnAdminOpensTheDoorTheSeatCannot(): void
    {
        $grace = $this->person('Grace', 'Ndosi');
        $admin = $this->person('Desta', 'Haile', TeamRoleEnum::Admin);
        $this->em->flush();
        $this->client->loginUser($admin);

        $this->client->request('GET', '/team/'.$grace->getUuidString().'/configure');

        self::assertResponseIsSuccessful();
    }

    /**
     * A Staff member whose seat still stores every team pair, placed across
     * the whole organization, signed in; and somebody for them to act on.
     *
     * @return array{User, User}
     */
    private function theStrongestSeatAndSomebody(): array
    {
        $coordinator = $this->person('Thabo', 'Ndlovu');
        $seat = $this->position('Coordinator', ['directory.read', 'personal-details.read', 'positions.read', 'ranks.read']);
        // THE SEAT AS IT WAS STORED BEFORE THE RULING: written straight to the
        // column, because no screen or service will write these pairs now.
        $grants = new \ReflectionProperty($seat, 'grants');
        $grants->setValue($seat, [...$seat->getGrantValues(), 'directory.manage', 'personal-details.manage', 'positions.configure', 'ranks.configure']);
        $coordinator->setPosition($seat);
        $this->place($coordinator);

        $grace = $this->person('Grace', 'Ndosi');
        $this->place($grace);
        $this->em->flush();
        $this->client->loginUser($coordinator);

        return [$coordinator, $grace];
    }
}
