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

namespace Uhifadhi\Contracts\Facts;

use Uhifadhi\Contracts\Queue\AsyncMessageInterface;

/**
 * A MODULE ASKING FOR ITS OWN FIGURES TO BE FILED AGAIN — what a module's
 * worker handler sends the moment an event changes a figure, so the pages
 * read the new number within the worker's next turn rather than at the
 * schedule's next run.
 *
 * It names the module and the months concerned; the core computes those
 * months, and the quarters and years they fall in, for that module's
 * provider alone, and files the answers on the ledger. A month may be closed:
 * a record uploaded late into last month is exactly the case this exists
 * for, and the schedule would never touch that month again on its own.
 *
 * It is queued (the core's marker), so it rides the same worker and failure
 * transport as the schedule's own recompute, and a message sent from inside a
 * request costs the request nothing.
 *
 * @see FactProviderInterface the provider whose figures are recomputed
 * @see https://symfony.com/doc/current/messenger.html#dispatching-the-message
 */
final readonly class RecomputeFacts implements AsyncMessageInterface
{
    /** @var list<string> */
    public array $monthKeys;

    /**
     * @param string       $moduleSlug  the provider's {@see FactProviderInterface::moduleSlug()}
     * @param list<string> $monthKeys   months as the ledger keys them, `2026-09`; at least one
     * @param string|null  $subjectUuid one subject to recompute, or null for every subject of the module
     */
    public function __construct(
        public string $moduleSlug,
        array $monthKeys,
        public ?string $subjectUuid = null,
    ) {
        if ('' === $moduleSlug) {
            throw new \InvalidArgumentException('A recompute names the module whose figures it asks for.');
        }
        $keys = array_values(array_unique($monthKeys));
        if ([] === $keys) {
            throw new \InvalidArgumentException('A recompute names at least one month.');
        }
        foreach ($keys as $key) {
            if (1 !== preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $key)) {
                throw new \InvalidArgumentException(\sprintf('"%s" is not a month key; the ledger keys months as 2026-09.', $key));
            }
        }
        $this->monthKeys = $keys;
    }

    /** The months of one instant: the month it falls in. */
    public static function forMonthOf(string $moduleSlug, \DateTimeImmutable $when, ?string $subjectUuid = null): self
    {
        return new self($moduleSlug, [FactPeriod::month($when)->key], $subjectUuid);
    }
}
