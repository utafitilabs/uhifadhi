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

namespace Uhifadhi\Core\Tests\Core\Authority;

/**
 * ONE REQUEST THAT WOULD SUCCEED for somebody allowed: a route, the method it
 * is sent with and the real identifiers its address takes. The address is
 * generated from the route, so a probe cannot drift from the path it names.
 */
final readonly class Probe
{
    /**
     * @param array<string, string> $parameters the route's parameters, with identifiers from the world
     * @param list<string>          $asks       the pairs its controller asks where no attribute shows them
     */
    public function __construct(
        public string $route,
        public string $method,
        public array $parameters = [],
        public array $asks = [],
    ) {
    }

    /**
     * @param array<string, string> $parameters
     * @param list<string>          $asks
     */
    public static function get(string $route, array $parameters = [], array $asks = []): self
    {
        return new self($route, 'GET', $parameters, $asks);
    }

    public function key(): string
    {
        return $this->method.' '.$this->route;
    }
}
