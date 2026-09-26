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

namespace Uhifadhi\Bundle\AreaBundle\Service;

use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Uhifadhi\Bundle\AreaBundle\Access\AreaConcerns;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Contracts\Area\LivePosition;
use Uhifadhi\Contracts\Entity\UserInterface;
use Uhifadhi\Contracts\People\RankLadderInterface;

/**
 * WHO SEES WHOSE LIVE POSITION — ruled 2026-09-26, and the one place the rule
 * is written.
 *
 *  - a person sees the positions of those STRICTLY JUNIOR to them in rank:
 *    no peer, nobody senior;
 *  - a person WITHOUT A RANK sees nobody;
 *  - a seat holding `locations.read` on the area (the control room) sees
 *    everybody there, whatever its rank;
 *  - reading the area is asked separately, by whoever draws it; this only
 *    ever narrows.
 *
 * TWO ENFORCEMENTS OF ONE RULE. {@see visibleIn()} filters what a server-drawn
 * plate is handed; {@see streamTopicFor()} and {@see publishedTopicsFor()}
 * make the hub enforce it on the live stream — a position is published to
 * the topics of the places senior to its owner (and to the control room's),
 * and a viewer's subscription names exactly one topic, their own place's, so
 * a senior's position never reaches a junior's browser at all.
 *
 * NO SIGNED-IN PERSON, NO FILTER: a console command, the worker and the
 * publisher's own read are the system reading, not a viewer. Counts are not
 * this service's business — they are read whole by their callers.
 */
final readonly class LiveVisibility
{
    /** The control room's grant. */
    public const string PAIR = AreaConcerns::LOCATIONS.'.read';

    /** The topic suffix the control room follows. */
    public const string EVERYONE = 'all';

    public function __construct(
        private ?TokenStorageInterface $tokens,
        private ?AuthorizationCheckerInterface $checker,
        private ?RankLadderInterface $ladder,
    ) {
    }

    /**
     * The positions of an area the signed-in viewer may see.
     *
     * @param list<LivePosition> $positions
     *
     * @return list<LivePosition>
     */
    public function visibleIn(AreaOfInterest $area, array $positions): array
    {
        $viewer = $this->viewerUuid();
        if (null === $viewer || [] === $positions || $this->seesAll($area)) {
            return $positions;
        }
        if (null === $this->ladder) {
            return [];
        }

        $places = $this->ladder->placesOf([$viewer, ...array_map(static fn (LivePosition $p): string => $p->personUuid, $positions)]);
        $mine = $places[$viewer] ?? null;
        if (null === $mine) {
            return [];
        }

        return array_values(array_filter(
            $positions,
            static fn (LivePosition $p): bool => isset($places[$p->personUuid]) && $places[$p->personUuid] > $mine,
        ));
    }

    /** Whether the signed-in viewer holds the control room's grant on this area. */
    public function seesAll(AreaOfInterest $area): bool
    {
        return null !== $this->checker && $this->checker->isGranted(self::PAIR, $area);
    }

    /**
     * The one topic suffix the signed-in viewer may follow in an area:
     * `all` for the control room, `for/<place>` for a ranked person, null
     * for a person who may see nobody there.
     */
    public function streamTopicFor(AreaOfInterest $area): ?string
    {
        if ($this->seesAll($area)) {
            return self::EVERYONE;
        }
        $viewer = $this->viewerUuid();
        if (null === $viewer || null === $this->ladder) {
            return null;
        }
        $place = $this->ladder->placesOf([$viewer])[$viewer] ?? null;

        return null === $place ? null : self::forPlace($place);
    }

    /**
     * Every topic suffix a person's position is published under: the
     * control room's always, and one per place senior to theirs, since those
     * are exactly the viewers who may see them. A person without a rank is
     * seen by the control room alone.
     *
     * @return list<string>
     */
    public function publishedTopicsFor(string $personUuid): array
    {
        $topics = [self::EVERYONE];
        $place = null === $this->ladder ? null : ($this->ladder->placesOf([$personUuid])[$personUuid] ?? null);
        for ($senior = 1; null !== $place && $senior < $place; ++$senior) {
            $topics[] = self::forPlace($senior);
        }

        return $topics;
    }

    public static function forPlace(int $place): string
    {
        return 'for/'.$place;
    }

    private function viewerUuid(): ?string
    {
        $user = $this->tokens?->getToken()?->getUser();

        return $user instanceof UserInterface ? $user->getUuidString() : null;
    }
}
