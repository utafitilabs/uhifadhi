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

namespace Uhifadhi\Bundle\AtlasBundle\Model;

/**
 * ONE ROW OF RANKED BARS: the thing, how much of it there is, and the figure
 * read off the end of the bar rather than off an axis.
 *
 * THE CALLER STATES THE READING AND THE WORDS; how long the bar is drawn is
 * the atlas's ({@see RankedBars::rows()}), worked out once for every card.
 */
final readonly class Bar
{
    /**
     * @param string      $label  the thing the row is, in the 162px column
     * @param float       $value  the filled part
     * @param float       $rest   the second part, drawn beside the fill — what the whole still lacks
     * @param string|null $figure the bold figure at the end of the row; null draws none
     * @param string      $note   the words after the figure, as they are to be read
     * @param bool|null   $quiet  dim the row; null dims it where it holds nothing
     * @param float|null  $of     this row's own whole; null reads it against the largest row
     */
    public function __construct(
        public string $label,
        public float $value,
        public float $rest = 0.0,
        public ?string $figure = null,
        public string $note = '',
        public ?bool $quiet = null,
        public ?float $of = null,
    ) {
        if ($value < 0 || $rest < 0 || (null !== $of && $of < 0)) {
            throw new \InvalidArgumentException(\sprintf('A bar is a length; "%s" states a negative one.', $label));
        }
    }

    /** The fill and the rest together: what the row is scaled by when it states no whole of its own. */
    public function whole(): float
    {
        return $this->value + $this->rest;
    }

    /** A row with nothing in it is dimmed, and a caller may dim one that is not a peer of the others. */
    public function isQuiet(): bool
    {
        return $this->quiet ?? 0.0 === $this->whole();
    }
}
