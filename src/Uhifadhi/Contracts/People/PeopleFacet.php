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

namespace Uhifadhi\Contracts\People;

/**
 * ONE DROPDOWN A MODULE HANDS THE PEOPLE REGISTER — its query key, the word
 * its menu is headed with, and its option runs.
 *
 * THE KEY IS A QUERY PARAMETER NAME, because the register's address is its
 * state: `?status=at_post` is a link somebody can send. It is therefore
 * spelt the way a parameter is, and it must not collide with a key the
 * register already owns — the register refuses one that does.
 *
 * VALUES ARE UNIQUE ACROSS THE GROUPS, so a value in the address names one
 * option and one set of people.
 */
final readonly class PeopleFacet
{
    /** @param list<PeopleFacetGroup> $groups the option runs, each under a head when it has one */
    public function __construct(
        public string $key,
        public string $label,
        public array $groups,
    ) {
        if (1 !== preg_match('/^[a-z][a-z0-9_]*$/', $key)) {
            throw new \InvalidArgumentException(\sprintf('A people facet\'s key is a query parameter name, lower-case letters, digits and underscores: "%s" is not.', $key));
        }
        if ('' === trim($label)) {
            throw new \InvalidArgumentException('A people facet is headed with a word.');
        }

        $seen = [];
        foreach ($groups as $group) {
            foreach ($group->options as $option) {
                if (isset($seen[$option->value])) {
                    throw new \InvalidArgumentException(\sprintf('Two options of the "%s" facet share the value "%s".', $key, $option->value));
                }
                $seen[$option->value] = true;
            }
        }
    }

    /** The option the address names, or null when no option carries that value. */
    public function option(string $value): ?PeopleFacetOption
    {
        foreach ($this->groups as $group) {
            foreach ($group->options as $option) {
                if ($option->value === $value) {
                    return $option;
                }
            }
        }

        return null;
    }

    /**
     * THE PEOPLE AN OPTION LEAVES — the filter. Null when no option carries
     * the value: a stale address filters to nobody, which the register
     * decides, rather than to everybody, which nothing should.
     *
     * @return list<string>|null
     */
    public function peopleWith(string $value): ?array
    {
        return $this->option($value)?->userUuids;
    }
}
