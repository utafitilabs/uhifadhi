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

namespace Uhifadhi\Bundle\AreaBundle\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\Service\LiveVisibility;
use Uhifadhi\Contracts\Area\DayState;
use Uhifadhi\Contracts\Area\LivePosition;
use Uhifadhi\Contracts\Entity\UserInterface;
use Uhifadhi\Contracts\People\RankLadderInterface;

/**
 * THE RULING AS A TABLE (2026-09-26): strictly downward by rank; no peer, no
 * senior; nobody for the rankless; everybody for the control room's grant.
 * The ladder here: the chief is place 1, the sergeant 5, two rangers share
 * place 9, the recruit is 12, and "nobody" holds no rank.
 */
#[CoversClass(LiveVisibility::class)]
final class LiveVisibilityTest extends TestCase
{
    private const array PLACES = ['chief' => 1, 'sergeant' => 5, 'ranger-a' => 9, 'ranger-b' => 9, 'recruit' => 12];

    /** @return iterable<string, array{string, bool, list<string>}> */
    public static function viewers(): iterable
    {
        yield 'the chief sees everybody junior' => ['chief', false, ['sergeant', 'ranger-a', 'ranger-b', 'recruit']];
        yield 'the sergeant sees the rangers and the recruit, not the chief' => ['sergeant', false, ['ranger-a', 'ranger-b', 'recruit']];
        yield 'a ranger sees the recruit and not their peer' => ['ranger-a', false, ['recruit']];
        yield 'the most junior sees nobody' => ['recruit', false, []];
        yield 'a person without a rank sees nobody' => ['nobody', false, []];
        yield 'the control room sees everybody, the rankless included' => ['recruit', true, ['chief', 'sergeant', 'ranger-a', 'ranger-b', 'recruit', 'nobody']];
    }

    /** @param list<string> $expected */
    #[DataProvider('viewers')]
    public function testWhatAViewerSees(string $viewer, bool $controlRoom, array $expected): void
    {
        $everybody = array_map(self::position(...), ['chief', 'sergeant', 'ranger-a', 'ranger-b', 'recruit', 'nobody']);

        $seen = self::visibility($viewer, $controlRoom)->visibleIn(new AreaOfInterest(), $everybody);

        self::assertSame($expected, array_map(static fn (LivePosition $p): string => $p->personUuid, $seen));
    }

    public function testWithNobodySignedInTheSystemReadsEverything(): void
    {
        $everybody = array_map(self::position(...), ['chief', 'nobody']);
        $visibility = new LiveVisibility(new TokenStorage(), self::checker(false), self::ladder());

        self::assertCount(2, $visibility->visibleIn(new AreaOfInterest(), $everybody));
    }

    /** The hub's half: a viewer follows their own place, and a position goes out to every place above its owner. */
    public function testTheStreamTopicIsTheViewersOwnPlaceOrTheControlRooms(): void
    {
        self::assertSame('for/5', self::visibility('sergeant', false)->streamTopicFor(new AreaOfInterest()));
        self::assertSame('all', self::visibility('sergeant', true)->streamTopicFor(new AreaOfInterest()));
        self::assertNull(self::visibility('nobody', false)->streamTopicFor(new AreaOfInterest()), 'the rankless follow nothing');
    }

    public function testAPositionIsPublishedToTheControlRoomAndEveryPlaceSeniorToItsOwner(): void
    {
        $visibility = self::visibility('chief', false);

        self::assertSame(['all', 'for/1', 'for/2', 'for/3', 'for/4'], $visibility->publishedTopicsFor('sergeant'));
        self::assertSame(['all'], $visibility->publishedTopicsFor('chief'), 'nobody is senior to the most senior');
        self::assertSame(['all'], $visibility->publishedTopicsFor('nobody'), 'the rankless are seen by the control room alone');
    }

    /** Together: a junior's topic is never among the topics a senior's position goes out on, and a peer's neither. */
    public function testAJuniorsTopicNeverCarriesASeniorsOrAPeersPosition(): void
    {
        $visibility = self::visibility('chief', false);
        $rangerFollows = self::visibility('ranger-a', false)->streamTopicFor(new AreaOfInterest());

        self::assertNotContains($rangerFollows, $visibility->publishedTopicsFor('sergeant'));
        self::assertNotContains($rangerFollows, $visibility->publishedTopicsFor('ranger-b'));
        self::assertContains($rangerFollows, $visibility->publishedTopicsFor('recruit'));
    }

    private static function visibility(string $viewer, bool $controlRoom): LiveVisibility
    {
        $tokens = new TokenStorage();
        $tokens->setToken(new UsernamePasswordToken(self::user($viewer), 'main', ['ROLE_USER']));

        return new LiveVisibility($tokens, self::checker($controlRoom), self::ladder());
    }

    private static function checker(bool $grant): AuthorizationCheckerInterface
    {
        return new class($grant) implements AuthorizationCheckerInterface {
            public function __construct(private readonly bool $grant)
            {
            }

            public function isGranted(mixed $attribute, mixed $subject = null, ?\Symfony\Component\Security\Core\Authorization\AccessDecision $accessDecision = null): bool
            {
                return LiveVisibility::PAIR === $attribute && $this->grant;
            }
        };
    }

    private static function ladder(): RankLadderInterface
    {
        return new class(self::PLACES) implements RankLadderInterface {
            /** @param array<string, int> $places */
            public function __construct(private readonly array $places)
            {
            }

            public function placesOf(array $personUuids): array
            {
                return array_intersect_key($this->places, array_flip($personUuids));
            }

            public function length(): int
            {
                return 12;
            }
        };
    }

    private static function position(string $uuid): LivePosition
    {
        return new LivePosition(
            personUuid: $uuid,
            personName: $uuid,
            clientRef: 'w-'.$uuid,
            state: DayState::AtPostVerified,
            latitude: -3.2,
            longitude: 35.5,
            recordedAt: new \DateTimeImmutable('2026-09-26 09:00:00'),
        );
    }

    private static function user(string $uuid): UserInterface&\Symfony\Component\Security\Core\User\UserInterface
    {
        return new class($uuid) implements UserInterface, \Symfony\Component\Security\Core\User\UserInterface {
            public function __construct(private readonly string $uuid)
            {
            }

            public function getId(): int
            {
                return 1;
            }

            public function getUuidString(): string
            {
                return $this->uuid;
            }

            public function getEmail(): string
            {
                return $this->uuid.'@example.org';
            }

            public function getFirstName(): string
            {
                return $this->uuid;
            }

            public function getLastName(): ?string
            {
                return null;
            }

            public function getFullName(): string
            {
                return $this->uuid;
            }

            public function getRangerCode(): ?string
            {
                return null;
            }

            public function getRoles(): array
            {
                return ['ROLE_USER'];
            }

            public function eraseCredentials(): void
            {
            }

            /** @return non-empty-string */
            public function getUserIdentifier(): string
            {
                return '' === $this->uuid ? 'nobody' : $this->uuid;
            }
        };
    }
}
