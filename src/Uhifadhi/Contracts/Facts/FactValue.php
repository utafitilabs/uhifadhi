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
 * ONE FIGURE A PROVIDER COMPUTED, for one subject, in the period it was asked
 * about. The core stamps the period and the time and writes it.
 */
final readonly class FactValue
{
    /**
     * @param float|null $value null is UNKNOWN — "we did not measure" — never zero
     */
    public function __construct(
        public string $subjectKind,
        public string $subjectUuid,
        public string $figureKey,
        public ?float $value,
    ) {
        if ('' === $subjectKind || '' === $subjectUuid || '' === $figureKey) {
            throw new \InvalidArgumentException('A computed figure names its subject kind, its subject and its figure.');
        }
    }
}
