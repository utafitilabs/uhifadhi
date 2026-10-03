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

namespace Uhifadhi\Test\Authority;

/**
 * ONE REQUEST THAT WOULD SUCCEED for somebody allowed: a route, the method it
 * is sent with and the real identifiers its address takes. The address is
 * generated from the route, so a probe cannot drift from the path it names.
 */
final readonly class Probe
{
    /** A parameter or field filled in at sending with the sender's own identifier. */
    public const string OWN = '{own}';

    /** A field filled in at sending with the sender's own full name. */
    public const string OWN_NAME = '{own name}';

    /** The kinds of target a request about a person is sent about, as the table names them. */
    public const string OWN_RECORD = 'own record';
    public const string COLLEAGUE = 'a colleague in reach';
    public const string OUT_OF_REACH = 'out of reach';
    public const string AN_ADMIN = "an Admin's";
    public const string A_SUPER_ADMIN = "a Super Admin's";

    /**
     * @param array<string, string> $parameters the route's parameters, with identifiers from the world
     * @param list<string>          $asks       the pairs its controller asks where no attribute shows them
     * @param array<string, mixed>  $body       the form a write sends
     * @param ?string               $token      the id of the CSRF token it carries, minted in the sender's own session
     * @param array<string, string> $files      uploaded files, field => path
     * @param string                $tokenField the form field the token goes in, or the header when the write is JSON
     * @param ?string               $json       a JSON body, sent instead of the form
     */
    public function __construct(
        public string $route,
        public string $method,
        public array $parameters = [],
        public array $asks = [],
        public array $body = [],
        public ?string $token = null,
        public array $files = [],
        public string $tokenField = '_token',
        public ?string $json = null,
        public ?string $target = null,
    ) {
    }

    /**
     * ONE REQUEST ABOUT A PERSON, sent about each kind of target: the
     * sender's own record, a colleague their placement reaches, somebody it
     * does not, an Admin and a Super Admin.
     *
     * @param ?string $nameField a field that types the target's full name, as a delete page asks
     *
     * @return list<self>
     */
    public static function aboutEachPerson(self $probe, string $parameter, World $world, ?string $nameField = null): array
    {
        $named = static fn (string $name): array => null === $nameField ? [] : [$nameField => $name];

        return [
            $probe->about(self::OWN_RECORD, $parameter, self::OWN, $named(self::OWN_NAME)),
            $probe->about(self::COLLEAGUE, $parameter, $world->member, $named(World::NAMES['member'])),
            $probe->about(self::OUT_OF_REACH, $parameter, $world->outOfReach, $named(World::NAMES['outOfReach'])),
            $probe->about(self::AN_ADMIN, $parameter, $world->admin, $named(World::NAMES['admin'])),
            $probe->about(self::A_SUPER_ADMIN, $parameter, $world->superAdmin, $named(World::NAMES['superAdmin'])),
        ];
    }

    /**
     * The same request about another person: the route's person parameter
     * set to them, and the row named for whom it is about.
     *
     * @param array<string, mixed> $body
     */
    public function about(string $target, string $parameter, string $person, array $body = []): self
    {
        return new self($this->route, $this->method, [$parameter => $person] + $this->parameters, $this->asks, $body + $this->body, $this->token, $this->files, $this->tokenField, $this->json, $target);
    }

    /**
     * A JSON write, its token carried in a header.
     *
     * @param array<string, string> $parameters
     */
    public static function json(string $route, array $parameters, string $token, string $json, string $tokenHeader): self
    {
        return new self($route, 'POST', $parameters, [], [], $token, [], $tokenHeader, $json);
    }

    /**
     * @param array<string, string> $parameters
     * @param array<string, mixed>  $body
     * @param array<string, string> $files
     */
    public static function post(string $route, array $parameters, ?string $token, array $body = [], array $files = [], string $tokenField = '_token'): self
    {
        return new self($route, 'POST', $parameters, [], $body, $token, $files, $tokenField);
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
