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

namespace Uhifadhi\Contracts\Performance;

/**
 * ONE OF A TOPIC'S FIVE HEADLINE FIGURES.
 *
 * FIVE, AND ALWAYS FIVE. A row of three where the design has five is a
 * different design, and a reader cannot tell a short row from a quiet
 * month — so a topic with less to say fills the slot with a figure that
 * states its own absence rather than dropping it.
 *
 * NULL IS NOT NOUGHT, HERE AS EVERYWHERE. A value nobody published reads
 * as "no figure yet"; a delta nobody can compute — because no period has
 * been written down yet — reads as "no history yet". The two are
 * different absences and the page says which.
 */
final readonly class TopicKpi
{
    /**
     * @param string           $key     the published name, `staffing.filled`
     * @param float|null       $value   null where nothing published it
     * @param float|null       $delta   the movement on the compared period, null where there is no history to compare with
     * @param list<float|null> $history six periods, oldest first, holes kept — what the sparkline is drawn over
     */
    public function __construct(
        public string $key,
        public string $label,
        public ?float $value,
        public string $unit = '',
        public ?float $delta = null,
        public array $history = [],
        public string $caption = '',
        public ColumnPolarity $polarity = ColumnPolarity::None,
        /**
         * WHAT THE HOST MAY DO WITH THIS ONE, where it has to find a
         * figure among somebody else's five — see {@see KpiRole}. Null is
         * the normal case: the figure is the topic's own and the host
         * draws it without making any claim about what it means.
         */
        public ?KpiRole $role = null,
        /**
         * WHEN THE FIGURE IS TRUE AS OF, for one read from the facts
         * ledger while its period is still open — the worker computed
         * it, not this request, and the page says how old it is. Null
         * for a period's final figure and for one nobody stored.
         */
        public ?\DateTimeImmutable $asOf = null,
    ) {
    }

    public function isKnown(): bool
    {
        return null !== $this->value;
    }

    /** Whether anything was ever written down to compare this with. */
    public function hasHistory(): bool
    {
        foreach ($this->history as $point) {
            if (null !== $point) {
                return true;
            }
        }

        return false;
    }
}
