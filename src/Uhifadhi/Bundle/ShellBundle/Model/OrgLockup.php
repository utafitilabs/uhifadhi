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

namespace Uhifadhi\Bundle\ShellBundle\Model;

use Uhifadhi\Contracts\Settings\OrganizationIdentity;

/**
 * WHAT THE TOP BAR DRAWS OF THE ORGANIZATION: the name in full, and the short
 * name beside it (ruled 2026-09-24, option C3).
 *
 * TWO STRINGS, BOTH ALWAYS PRESENT, which is the difference between this and
 * the identity it is made from. The setting's short name is optional — a fresh
 * installation has not been asked for one — and a bar with a hairline and
 * nothing after it is furniture pretending to work. So the lockup carries the
 * initials of the name until somebody sets a short name of their own.
 *
 * THE INITIALS ARE A STARTING GUESS, NOT AN ANSWER. A national parks authority
 * calls itself TANAPA, not TNP; the derivation exists so the field arrives
 * filled in rather than empty, and so the bar has something to draw in the
 * meantime. It is never what the short name is pinned to.
 *
 * A WORD THE WRITER DID NOT CAPITALISE IS NOT AN INITIAL. "Kilimani Crater and
 * Olkeju Highlands Conservation Authority" reads KCOHCA: the conjunctions and
 * prepositions an organization's name is strung together with are not part of
 * how anybody says it short, and the writer has already marked them by leaving
 * them lower case.
 */
final readonly class OrgLockup
{
    /**
     * How long a short name may be. It is drawn in a 22px mono box beside a
     * name that is already bounded, so a "short" name of paragraph length is a
     * name the bar cannot hold — the field says so rather than the sheet
     * clipping it silently.
     */
    public const int SHORT_MAX = 12;

    private function __construct(
        /** The organization in full, as the bar states it and the browser title ends with. */
        public string $name,
        /** What is drawn where the full name will not fit — the setting's, or the name's initials. */
        public string $shortName,
    ) {
    }

    public static function of(OrganizationIdentity $identity): self
    {
        $short = null === $identity->shortName ? '' : trim($identity->shortName);

        return new self($identity->name, '' === $short ? self::initials($identity->name) : $short);
    }

    /**
     * The initials a short name is prefilled from: the capitalised words'
     * first letters, in order, bounded by the field's own length.
     */
    public static function initials(string $name): string
    {
        $initials = '';
        foreach (preg_split('/[^\p{L}\p{N}]+/u', $name, -1, \PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            $first = mb_substr($word, 0, 1);
            if ($word !== mb_strtolower($word)) {
                $initials .= mb_strtoupper($first);
            }
        }

        // A name written all in lower case still has to be short-named,
        // and its own first letter is the only thing left to say.
        if ('' === $initials) {
            $initials = mb_strtoupper(mb_substr(trim($name), 0, 1));
        }

        return mb_substr($initials, 0, self::SHORT_MAX);
    }
}
