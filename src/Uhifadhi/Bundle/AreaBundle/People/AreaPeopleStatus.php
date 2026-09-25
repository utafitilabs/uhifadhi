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

namespace Uhifadhi\Bundle\AreaBundle\People;

use Psr\Clock\ClockInterface;
use Uhifadhi\Bundle\AreaBundle\Repository\AreaOfInterestRepository;
use Uhifadhi\Bundle\AreaBundle\Repository\CheckInStatusRepository;
use Uhifadhi\Contracts\Area\PresenceProviderInterface;
use Uhifadhi\Contracts\People\PeopleFacet;
use Uhifadhi\Contracts\People\PeopleFacetGroup;
use Uhifadhi\Contracts\People\PeopleFacetOption;
use Uhifadhi\Contracts\People\PeopleFacetProviderInterface;

/**
 * THE STATUS DROPDOWN THE AREA PUTS ON THE PEOPLE REGISTER — what everybody
 * reported today, in the areas' own words.
 *
 * THE WORDS ARE THE AREAS' CHECK-IN VOCABULARY, in each area's own order:
 * "At post", "Unfit for duty", whatever an area added. A word two areas
 * share is one option — the register is the organization's, and somebody
 * at post is at post whichever area they stand in — and a word only one
 * area has is offered too. The label is the first area's, in area order.
 *
 * THE DAY IS READ THROUGH THE ONE PRESENCE DERIVATION, never re-derived
 * here: a person's status is the status of their LAST watch today, exactly
 * as the day board colours their name, so the two cannot disagree.
 *
 * "NO CHECK-IN TODAY" IS THE LAST OPTION, and it is a real answer: somebody
 * who reported nothing is a fact to filter by, not an absence to hide. With
 * it every option sums to the whole set.
 *
 * TODAY IS THE CLOCK'S DAY. The register asks about now, and the clock is
 * something a test can set.
 *
 * NOTHING TO SAY WITHOUT A VOCABULARY: an installation with no area yet has
 * no words to offer, and answers null rather than an empty menu.
 */
final readonly class AreaPeopleStatus implements PeopleFacetProviderInterface
{
    public const string KEY = 'status';
    public const string NO_CHECK_IN = 'none';

    public function __construct(
        private ClockInterface $clock,
        private AreaOfInterestRepository $areas,
        private CheckInStatusRepository $statuses,
        private PresenceProviderInterface $presence,
    ) {
    }

    public function facetFor(array $userUuids): ?PeopleFacet
    {
        $areas = $this->areas->findAllOrdered();

        /** @var array<string, array{label: string, position: int}> $words key → the word, first area's spelling */
        $words = [];
        foreach ($areas as $area) {
            foreach ($this->statuses->activeFor($area) as $status) {
                $words[$status->getKey()] ??= ['label' => $status->getLabel(), 'position' => $status->getPosition()];
            }
        }
        if ([] === $words) {
            return null;
        }

        $today = $this->clock->now()->format('Y-m-d');
        $asked = array_flip($userUuids);

        /** @var array<string, array{key: string, at: \DateTimeImmutable|null}> $state each asked person's last watch today */
        $state = [];
        foreach ($areas as $area) {
            foreach ($this->presence->dayIn((string) $area->getUuidString(), $today) as $day) {
                if (!isset($asked[$day->personUuid])) {
                    continue;
                }
                $watch = $day->lastWatch();
                if (null === $watch?->statusKey) {
                    continue;
                }
                $known = $state[$day->personUuid] ?? null;
                if (null === $known || null === $known['at'] || (null !== $watch->occurredAt && $watch->occurredAt > $known['at'])) {
                    $state[$day->personUuid] = ['key' => $watch->statusKey, 'at' => $watch->occurredAt];
                    // A WORD THE DAY USES THAT NO AREA OFFERS ANY MORE is still
                    // what somebody reported, so it is offered after the rest.
                    $words[$watch->statusKey] ??= ['label' => $watch->statusLabel ?? $watch->statusKey, 'position' => \PHP_INT_MAX];
                }
            }
        }

        uasort($words, static fn (array $a, array $b): int => $a['position'] <=> $b['position'] ?: strcmp($a['label'], $b['label']));

        $carrying = [];
        $nobody = [];
        foreach ($userUuids as $uuid) {
            $key = $state[$uuid]['key'] ?? null;
            null === $key ? $nobody[] = $uuid : $carrying[$key][] = $uuid;
        }

        $options = [];
        foreach ($words as $key => $word) {
            $options[] = new PeopleFacetOption($key, $word['label'], $carrying[$key] ?? []);
        }

        return new PeopleFacet(self::KEY, 'status', [
            new PeopleFacetGroup(null, $options),
            new PeopleFacetGroup(null, [new PeopleFacetOption(self::NO_CHECK_IN, 'No check-in today', $nobody)]),
        ]);
    }
}
