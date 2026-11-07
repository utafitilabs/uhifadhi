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

use Uhifadhi\Bundle\AreaBundle\Entity\StationEvent;
use Uhifadhi\Bundle\AreaBundle\Entity\ZoneEvent;
use Uhifadhi\Bundle\AreaBundle\Enum\StationEventKind;
use Uhifadhi\Bundle\AreaBundle\Enum\ZoneEventKind;
use Uhifadhi\Bundle\AreaBundle\Model\FilterOption;

/**
 * WHAT HAPPENED HERE, NARROWED — a record's log under the house bar: the
 * kind chip and the search. Every kind is an option, counted over the whole
 * log and drawn at nought, so an option says what picking it would leave;
 * the search reads the headline, the detail and who did it. Both arrive
 * from the address as typed, so a kind not offered is no filter.
 */
final readonly class EventLogService
{
    /** The log's own keys, beside the page's other tables. */
    public const string KIND = 'lkind';

    public const string SEARCH = 'lq';

    /**
     * @template T of StationEvent|ZoneEvent
     *
     * @param list<T>                                    $events every line, newest first
     * @param list<StationEventKind>|list<ZoneEventKind> $kinds  the kinds the chip offers, in its order
     *
     * @return array{rows: list<T>, kinds: list<FilterOption>, kind: ?string, search: string}
     */
    public function narrow(array $events, array $kinds, ?string $kind, string $search): array
    {
        $values = array_map(static fn (StationEventKind|ZoneEventKind $k): string => $k->value, $kinds);
        $kind = \in_array($kind, $values, true) ? $kind : null;
        $search = trim($search);
        $needle = mb_strtolower($search);

        $counts = array_fill_keys($values, 0);
        foreach ($events as $event) {
            $value = $event->getKind()?->value;
            if (null !== $value && isset($counts[$value])) {
                ++$counts[$value];
            }
        }

        $rows = array_values(array_filter($events, static function (StationEvent|ZoneEvent $event) use ($kind, $needle): bool {
            if (null !== $kind && $kind !== $event->getKind()?->value) {
                return false;
            }

            return '' === $needle || str_contains(mb_strtolower(implode(' ', [$event->getHeadline(), $event->getDetail(), $event->getActor()])), $needle);
        }));

        return [
            'rows' => $rows,
            'kinds' => array_map(static fn (StationEventKind|ZoneEventKind $k): FilterOption => new FilterOption($k->value, $k->label(), $counts[$k->value]), $kinds),
            'kind' => $kind,
            'search' => $search,
        ];
    }
}
