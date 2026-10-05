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

namespace Uhifadhi\Contracts\Access;

/**
 * ONE ACTION THAT CONFERS POWER — "make Admin", "send a reset link" — and the
 * questions the app asks before it allows one. All of them must be granted.
 * Each question is asked with a subject its source gives for the target
 * ({@see PowerSourceInterface::subject()}).
 */
final readonly class Power
{
    /**
     * @param list<string>      $questions the attributes asked, all of which must be granted
     * @param list<PowerTarget> $targets   who or what it is asked about
     */
    public function __construct(
        public string $key,
        public PowerGroup $group,
        public string $label,
        public array $questions,
        public array $targets,
    ) {
    }
}
