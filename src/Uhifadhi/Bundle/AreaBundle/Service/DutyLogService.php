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

use Uhifadhi\Bundle\AreaBundle\Entity\CheckIn;
use Uhifadhi\Bundle\AreaBundle\Model\FilterOption;

/**
 * MY DUTY LOG, NARROWED — a month of one person's check-ins under the house
 * bar: the status chip, the post chip and the search over the post's name.
 * The chips' counts are of the whole month; a value not offered is no
 * filter. The month itself is a chip of its own: the twelve months up to
 * this one, newest first, and the month being read when it is older.
 */
final readonly class DutyLogService
{
    public const string MONTH = 'month';

    public const string STATUS = 'status';

    public const string POST = 'post';

    public const string SEARCH = 'q';

    /**
     * @param list<CheckIn> $claims the month's check-ins, newest first
     *
     * @return array{rows: list<CheckIn>, statuses: list<FilterOption>, posts: list<FilterOption>, status: ?string, post: ?string, search: string}
     */
    public function narrow(array $claims, ?string $status, ?string $post, string $search): array
    {
        $statuses = [];
        $posts = [];
        foreach ($claims as $claim) {
            if (null !== $claim->getStatus()) {
                $key = $claim->getStatus()->getKey();
                $statuses[$key] ??= ['label' => $claim->getStatus()->getLabel(), 'count' => 0];
                ++$statuses[$key]['count'];
            }
            if (null !== $claim->getStation()) {
                $uuid = (string) $claim->getStation()->getUuidString();
                $posts[$uuid] ??= ['label' => (string) $claim->getStation()->getName(), 'count' => 0];
                ++$posts[$uuid]['count'];
            }
        }

        $status = null !== $status && isset($statuses[$status]) ? $status : null;
        $post = null !== $post && isset($posts[$post]) ? $post : null;
        $search = trim($search);
        $needle = mb_strtolower($search);

        $rows = array_values(array_filter($claims, static fn (CheckIn $claim): bool => (null === $status || $status === $claim->getStatus()?->getKey())
            && (null === $post || $post === $claim->getStation()?->getUuidString())
            && ('' === $needle || str_contains(mb_strtolower((string) $claim->getStation()?->getName()), $needle))));

        return [
            'rows' => $rows,
            'statuses' => self::options($statuses),
            'posts' => self::options($posts),
            'status' => $status,
            'post' => $post,
            'search' => $search,
        ];
    }

    /**
     * The month chip's options, newest first: `Y-m` and its name.
     *
     * @return list<array{value: string, label: string, count: null}>
     */
    public function months(\DateTimeImmutable $reading, \DateTimeImmutable $now): array
    {
        $months = [];
        $month = $now->modify('first day of this month')->setTime(0, 0);
        for ($i = 0; $i < 12; ++$i) {
            $months[$month->format('Y-m')] = $month->format('F Y');
            $month = $month->modify('-1 month');
        }
        $months[$reading->format('Y-m')] ??= $reading->format('F Y');

        return array_map(static fn (string $value, string $label): array => ['value' => $value, 'label' => $label, 'count' => null], array_keys($months), $months);
    }

    /**
     * @param array<string, array{label: string, count: int}> $found
     *
     * @return list<FilterOption>
     */
    private static function options(array $found): array
    {
        uasort($found, static fn (array $a, array $b): int => strcasecmp($a['label'], $b['label']));

        return array_map(static fn (string $value, array $o): FilterOption => new FilterOption($value, $o['label'], $o['count']), array_keys($found), $found);
    }
}
