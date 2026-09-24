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

namespace Uhifadhi\Contracts\Shell;

/**
 * THE VIEWER'S CARD IN THE TOP BAR, AS DATA: a name, its initials, and an
 * optional context line — the "UCA · operator" under the name.
 *
 * It carries PLAIN STRINGS and names no account class. The shell that draws it
 * requires no module and above all not the package that defines a user, so a
 * badge cannot be "a UserInterface, narrowed": it is the already-composed
 * reading a host or a team-aware source hands over through the contract. Who is
 * signed in, which organization they belong to and what role they hold are all
 * resolved by whoever knows the answer and folded into these three fields — the
 * shell only draws them.
 *
 * THE INITIALS ARE THE ONE THING COMPOSED FOR THE CARD, because turning a
 * printed name into two letters is presentation, not identity — see
 * {@see fromName()}. A source with richer knowledge (a chosen display avatar,
 * initials that differ from the name) builds the value object directly and the
 * shell defers to it.
 */
final readonly class UserBadge
{
    public function __construct(
        public string $name,
        public string $initials,
        public ?string $context = null,
    ) {
    }

    /**
     * A badge from the least a source can know — a printed name, and optionally
     * a context line. The initials are derived here so a source that has only a
     * name (straight off its account's full name, say) still gets the two-letter
     * avatar the design draws, without the shell having to see the account.
     */
    public static function fromName(string $name, ?string $context = null): self
    {
        return new self($name, self::initialsOf($name), $context);
    }

    /**
     * First letter of the first word and first letter of the last — "N. Kileo"
     * and "Naserian Ole Kileo" both read "NK". A single word gives one letter;
     * leading punctuation is stepped over; letters are upper-cased in a
     * multibyte-safe way so an accented name keeps its accent.
     */
    private static function initialsOf(string $name): string
    {
        $words = preg_split('/\s+/u', trim($name), -1, \PREG_SPLIT_NO_EMPTY);
        if (false === $words || [] === $words) {
            return '';
        }

        $first = self::firstLetter($words[0]);
        $last = \count($words) > 1 ? self::firstLetter($words[array_key_last($words)]) : '';
        $initials = $first.$last;

        // A name made entirely of punctuation has no letters to take; fall back
        // to its first character rather than an empty avatar.
        return '' !== $initials ? $initials : mb_strtoupper(mb_substr(trim($name), 0, 1));
    }

    private static function firstLetter(string $word): string
    {
        if (1 === preg_match('/[\p{L}\p{N}]/u', $word, $match)) {
            return mb_strtoupper($match[0]);
        }

        return '';
    }
}
