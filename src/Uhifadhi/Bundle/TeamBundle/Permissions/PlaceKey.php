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

namespace Uhifadhi\Bundle\TeamBundle\Permissions;

use Uhifadhi\Bundle\TeamBundle\Enum\PlaceKindEnum;

/**
 * THE KEY A PAGE NAMES A PLACE BY: `organization`, or `area-<id>` with the
 * area's id as a run stores it.
 */
final class PlaceKey
{
    public const string ORGANIZATION = 'organization';

    public static function of(PlaceKindEnum|string $kind, ?int $id): string
    {
        $kind = $kind instanceof PlaceKindEnum ? $kind : PlaceKindEnum::from($kind);

        return PlaceKindEnum::Organization === $kind || null === $id ? self::ORGANIZATION : 'area-'.$id;
    }

    /** @return array{PlaceKindEnum, int|null}|null null for a key that names no place */
    public static function parse(string $key): ?array
    {
        if (self::ORGANIZATION === $key) {
            return [PlaceKindEnum::Organization, null];
        }
        if (1 === preg_match('/^area-(\d+)$/', $key, $match)) {
            return [PlaceKindEnum::Area, (int) $match[1]];
        }

        return null;
    }

    /**
     * A RUN'S PLACES, each with its key.
     *
     * @param list<array{kind: string, id: int|null, name: string}> $places
     *
     * @return list<array{key: string, kind: string, id: int|null, name: string}>
     */
    public static function places(array $places): array
    {
        return array_map(static fn (array $p): array => ['key' => self::of($p['kind'], $p['id']), 'kind' => $p['kind'], 'id' => $p['id'], 'name' => $p['name']], $places);
    }
}
