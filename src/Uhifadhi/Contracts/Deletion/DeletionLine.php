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

namespace Uhifadhi\Contracts\Deletion;

/**
 * ONE KIND OF THING THAT GOES WITH A RECORD, OR STAYS BEHIND IT - counted,
 * and where it helps, named item by item (ruled 28 Sep, #48: everything under
 * a record goes in the same delete, and the count is shown first).
 */
final readonly class DeletionLine
{
    /**
     * @param string                            $label    what is counted, plural ("observations")
     * @param int                               $count    how many
     * @param string|null                       $detail   the small ink after the count ("18.4 MB")
     * @param list<array{0: string, 1: string}> $items    named rows: what it is, and one fact about it
     * @param string|null                       $singular the label for one, where adding nothing reads wrong ("patrol")
     */
    public function __construct(
        public string $label,
        public int $count,
        public ?string $detail = null,
        public array $items = [],
        public ?string $singular = null,
    ) {
        if ($count < 0) {
            throw new \InvalidArgumentException(\sprintf('"%s" cannot be counted below nothing.', $label));
        }
    }

    /** "3 observations", "1 patrol": the line as the audit keeps it. */
    public function phrase(): string
    {
        return $this->count.' '.(1 === $this->count && null !== $this->singular ? $this->singular : $this->label);
    }
}
