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
 * ONE OPTION of a contributed dropdown: what the address says, what the
 * reader sees, and WHO IT APPLIES TO.
 *
 * THE PEOPLE ARE THE OPTION'S PAYLOAD rather than a count, so the number the
 * register draws and the rows it leaves when picked are one derivation. A
 * zero is a real answer — an option nobody carries is still offered, since
 * an option that vanished when it emptied could not be told from one that
 * never existed.
 */
final readonly class PeopleFacetOption
{
    /** @param list<string> $userUuids the people this option applies to, among those asked about */
    public function __construct(
        public string $value,
        public string $label,
        public array $userUuids,
    ) {
        if ('' === trim($value) || '' === trim($label)) {
            throw new \InvalidArgumentException('A people facet option has a value and a label.');
        }
    }

    public function count(): int
    {
        return \count($this->userUuids);
    }
}
