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

/**
 * WHAT THE CORE ASKS A FACT PROVIDER TO COMPUTE: one period, the figures
 * wanted in it, and optionally one subject.
 *
 * A MONTH ASKS FOR EVERY FIGURE; a quarter or a year asks only for the
 * figures that are not additive, because the others are read as the sum of
 * their months and are never stored at that length.
 */
final readonly class FactRequest
{
    /**
     * @param list<string> $figureKeys  the figures to compute, each one the provider declared
     * @param string|null  $subjectUuid one subject only, or null for every subject the provider files under
     */
    public function __construct(
        public FactPeriod $period,
        public array $figureKeys,
        public ?string $subjectUuid = null,
    ) {
    }

    public function asks(string $figureKey): bool
    {
        return \in_array($figureKey, $this->figureKeys, true);
    }

    /** Whether a subject is one this request is about. */
    public function covers(string $subjectUuid): bool
    {
        return null === $this->subjectUuid || $this->subjectUuid === $subjectUuid;
    }
}
