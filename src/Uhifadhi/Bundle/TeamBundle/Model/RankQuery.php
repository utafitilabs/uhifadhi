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

namespace Uhifadhi\Bundle\TeamBundle\Model;

use Symfony\Component\HttpFoundation\Request;

/**
 * WHAT THE RANKS REGISTER IS SHOWING — a search and, once there are several
 * scales, one scale. The address is the state: the chips and the export door
 * are links built from it.
 */
final readonly class RankQuery
{
    public const string SEARCH = 'q';
    public const string SCALE = 'scale';

    public function __construct(
        public ?string $q = null,
        public ?string $scale = null,
    ) {
    }

    public static function fromRequest(Request $request): self
    {
        $string = static function (string $key) use ($request): ?string {
            $value = $request->query->get($key);
            $value = \is_string($value) ? trim($value) : '';

            return '' !== $value ? $value : null;
        };

        return new self($string(self::SEARCH), $string(self::SCALE));
    }

    /** @return array<string, string> the address with one key set, or cleared by null */
    public function with(string $key, ?string $value): array
    {
        $params = array_filter([self::SEARCH => $this->q, self::SCALE => $this->scale], static fn (?string $v): bool => null !== $v);
        if (null === $value) {
            unset($params[$key]);
        } else {
            $params[$key] = $value;
        }

        return $params;
    }
}
