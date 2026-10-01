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

use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Uhifadhi\Bundle\AreaBundle\Entity\PersonPosition;
use Uhifadhi\Bundle\AreaBundle\Repository\CheckInRepository;
use Uhifadhi\Bundle\AreaBundle\Repository\PersonPositionRepository;
use Uhifadhi\Bundle\AtlasBundle\Model\AtlasSheet;
use Uhifadhi\Bundle\AtlasBundle\Model\LiveMarks;

/**
 * WHO A LIVE MARK IS (ruled 30 Sep, #16 C): the sheet a click on the mark
 * opens - who, their seat, today's check-in, the last pings, and the doors to
 * their record.
 *
 * NOBODY THE VIEWER COULD NOT SEE. The person's own live reading is asked of
 * {@see PresenceService::liveOf()} and narrowed by {@see LiveVisibility},
 * the filter every plate is drawn through, and the area is asked of the
 * viewer's `areas.read` - so the sheet answers exactly for the marks the plate
 * drew, and a uuid typed by hand answers nothing more.
 */
final readonly class LiveSheet
{
    private const int PINGS = 3;

    public function __construct(
        private CheckInRepository $checkIns,
        private PersonPositionRepository $pings,
        private PresenceService $presence,
        private LiveVisibility $visibility,
        private PersonFacetService $facets,
        private AuthorizationCheckerInterface $authorization,
        private UrlGeneratorInterface $urls,
    ) {
    }

    public function for(string $personUuid, \DateTimeImmutable $asOf): ?AtlasSheet
    {
        $checkIn = $this->checkIns->findOpenAnywhereFor($personUuid);
        $area = $checkIn?->getArea();
        if (null === $checkIn || null === $area || !$this->authorization->isGranted('areas.read', $area)) {
            return null;
        }

        // liveOf() is the publisher's own read and is NOT narrowed to a viewer
        // (the hub's topics do that on the wire), so the plates' filter is
        // applied here - the one rule, asked the same way a plate asks it.
        $position = $this->visibility->visibleIn($area, $this->presence->liveOf((string) $area->getUuidString(), $personUuid, $asOf)->positions)[0] ?? null;
        if (null === $position) {
            return null;
        }

        $seat = $this->facets->facetsFor([$personUuid])[$personUuid]->position ?? null;
        $checkedIn = $checkIn->getOccurredAt();
        $pings = $this->pings->latestFor($checkIn, self::PINGS);

        $rows = [[
            'label' => 'Today',
            'value' => $position->state->label().(null === $checkedIn ? '' : ' · checked in {0}'),
            'at' => null === $checkedIn ? [] : [self::iso($checkedIn)],
        ]];
        if ([] !== $pings) {
            $rows[] = [
                'label' => 'Last pings',
                'value' => implode(' · ', array_map(
                    static fn (PersonPosition $ping): string => LiveMarks::age(max(0, $asOf->getTimestamp() - (int) $ping->getRecordedAt()?->getTimestamp())),
                    $pings,
                )),
            ];
        }

        $doors = [];
        if ($this->authorization->isGranted('directory.read') && null !== $record = $this->pathOrNull('team_member', ['uuid' => $personUuid])) {
            $doors[] = ['label' => 'Open record', 'url' => $record, 'primary' => true];
        }

        return new AtlasSheet(
            title: $position->personName,
            subtitle: implode(' · ', array_filter([$seat, $position->stationName], static fn (?string $part): bool => null !== $part && '' !== $part)),
            initials: LiveMarks::initials($position->personName),
            rows: $rows,
            doors: $doors,
        );
    }

    private static function iso(\DateTimeImmutable $at): string
    {
        return $at->setTimezone(new \DateTimeZone('UTC'))->format(\DateTimeInterface::ATOM);
    }

    /** @param array<string, string> $parameters */
    private function pathOrNull(string $route, array $parameters): ?string
    {
        try {
            return $this->urls->generate($route, $parameters);
        } catch (RouteNotFoundException) {
            return null;
        }
    }
}
