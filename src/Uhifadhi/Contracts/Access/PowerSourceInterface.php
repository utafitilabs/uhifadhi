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
 * HOW A BUNDLE OR A MODULE PUTS ITS POWERS ON THE PERMISSIONS PAGE.
 *
 * Whoever enforces a power declares it, as whoever enforces a concern
 * declares the concern: the team its tiers and accounts, the area its
 * check-ins, a module its own records. The page asks each question through
 * the authorization checker, as each account, and changes nothing.
 *
 * A reusable bundle is not autoconfigured, so the tag goes on by hand:
 *
 *     $services->set('area.access.powers', AreaPowers::class)
 *         ->tag(PowerSourceInterface::TAG);
 */
interface PowerSourceInterface
{
    public const string TAG = 'uhifadhi.access.powers';

    /** @return iterable<Power> */
    public function powers(): iterable;

    /**
     * WHAT A QUESTION IS ASKED OF, for a target the page found: the person, a
     * position, a record of theirs — or null for a question asked of nothing
     * in particular. The page resolves the target; the source knows what its
     * own voter expects.
     *
     * @param object|null $target the person or position the page found, null for a target with none
     */
    public function subject(Power $power, string $question, PowerTarget $kind, ?object $target): mixed;
}
