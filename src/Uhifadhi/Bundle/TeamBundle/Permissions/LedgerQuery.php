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

use Symfony\Component\HttpFoundation\Request;

/**
 * WHAT THE PERMISSIONS PAGE IS NARROWED TO, read off the address — so a
 * filtered ledger is a link somebody can send, as the People register's is.
 */
final readonly class LedgerQuery
{
    public const array FACETS = ['group', 'action', 'actor', 'target', 'answer'];
    public const int PER_PAGE = 25;

    /** How many changes the history rail shows at a time. */
    public const int CHANGES_PER_PAGE = 12;

    /**
     * @param array<string, string> $facets
     */
    public function __construct(
        public ?string $person = null,
        public array $facets = [],
        public int $page = 1,
        /** The main power whose changes the rail is opened on, by its key. */
        public ?string $changes = null,
        public int $changesPage = 1,
    ) {
    }

    public static function from(Request $request): self
    {
        $facets = [];
        foreach (self::FACETS as $key) {
            $value = trim($request->query->getString($key));
            if ('' !== $value) {
                $facets[$key] = $value;
            }
        }
        $person = trim($request->query->getString('person'));
        $changes = trim($request->query->getString('changes'));

        return new self(
            '' === $person ? null : $person,
            $facets,
            max(1, $request->query->getInt('page', 1)),
            '' === $changes ? null : $changes,
            max(1, $request->query->getInt('changes_page', 1)),
        );
    }

    /** The value a facet has chosen in the address. */
    public function chosen(string $key): ?string
    {
        return 'person' === $key ? $this->person : ($this->facets[$key] ?? null);
    }

    /**
     * The address with one facet changed, back on the first page.
     *
     * @return array<string, string|int>
     */
    public function with(string $key, ?string $value): array
    {
        return $this->params([$key => $value, 'page' => null, 'changes_page' => null]);
    }

    /** @return array<string, string|int> */
    public function onChangesPage(int $page): array
    {
        return $this->params(['changes_page' => $page > 1 ? $page : null]);
    }

    /** @return array<string, string|int> */
    public function onPage(int $page): array
    {
        return $this->params(['page' => $page > 1 ? $page : null]);
    }

    public function matches(Cell $cell): bool
    {
        foreach ($this->facets as $key => $value) {
            $actual = match ($key) {
                'group' => $cell->power->group->value,
                'action' => $cell->power->key,
                'actor' => (string) $cell->actor->getUuidString(),
                'target' => $cell->kind->value,
                'answer' => $cell->answer->value,
                default => null,
            };
            if ($actual !== $value) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, string|int|null> $overrides
     *
     * @return array<string, string|int>
     */
    private function params(array $overrides): array
    {
        $params = [
            'person' => $this->person,
            ...$this->facets,
            'page' => $this->page > 1 ? $this->page : null,
            'changes' => $this->changes,
            'changes_page' => $this->changesPage > 1 ? $this->changesPage : null,
            ...$overrides,
        ];

        return array_filter($params, static fn (string|int|null $value): bool => null !== $value && '' !== $value);
    }
}
