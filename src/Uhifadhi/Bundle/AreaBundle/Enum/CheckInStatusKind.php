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

namespace Uhifadhi\Bundle\AreaBundle\Enum;

/**
 * WHAT A CHECK-IN STATUS *MEANS*, as against what it is called.
 *
 * THE WORDS ARE THE ORGANIZATION'S AND THE MEANINGS ARE THE PLATFORM'S.
 * An area writes its own statuses — "At post", "On escort", "Court
 * appearance", "Sick" — and every one of them is one of these four
 * kinds. The label is what a ranger taps; the kind is what the product
 * can reason about: whether a station is required, and whether the
 * person counts as on duty.
 *
 * FOUR, BECAUSE FOUR IS WHAT CHANGES AN ANSWER. Anything finer is a
 * reason, and a reason is a note or a status of its own with the same
 * kind: "on escort" and "at a hearing" are both working elsewhere, and
 * nothing downstream treats them differently.
 *
 * A KIND IS NEVER SENT BY THE HANDSET. The phone sends the KEY of a
 * status the area published; the kind travels the other way, in the
 * roster read, so the app knows which status needs a post.
 *
 * @see API-CONTRACT.md §13A, §13D
 */
enum CheckInStatusKind: string
{
    /** At a named post — the only kind that takes a station. */
    case AtPost = 'at_post';

    /** On duty, away from a post: outside the park, escorting, in court. */
    case WorkingElsewhere = 'working_elsewhere';

    /** Not on duty: unfit, on leave, stood down. */
    case NotWorking = 'not_working';

    /** On duty inside the park, on an assignment that is not a post. */
    case Special = 'special';

    /** Whether a status of this kind names a post. */
    public function takesStation(): bool
    {
        return self::AtPost === $this;
    }

    /**
     * WHETHER A CLAIM OF THIS KIND CARRIES A NOTE.
     *
     * A post needs no explaining and neither does being off duty — a
     * reason for being unfit is medical, and this product is not where
     * that is recorded. The two kinds that DO take one are the two that
     * describe something the office cannot see from the roster: where
     * somebody went, and what they were sent to do.
     */
    public function takesNote(): bool
    {
        return self::WorkingElsewhere === $this || self::Special === $this;
    }

    /**
     * WHETHER SOMEBODY OF THIS KIND IS ON DUTY TODAY.
     *
     * Derived from the kind and never stored on the status: an area that
     * could tick "counts as present" on "Sick" would have a roster that
     * says one thing and a payroll that says another.
     */
    public function countsAsPresent(): bool
    {
        return self::NotWorking !== $this;
    }

    /** The platform's word for the kind, the one the editor and the roster say. */
    public function label(): string
    {
        return match ($this) {
            self::AtPost => 'At a station',
            self::WorkingElsewhere => 'Working elsewhere',
            self::NotWorking => 'Not working',
            self::Special => 'Special assignment',
        };
    }

    /** What choosing a status of this kind says about the person, in one line. */
    public function meaning(): string
    {
        return match ($this) {
            self::AtPost => 'On duty at a named station · the only kind that takes a station',
            self::WorkingElsewhere => 'On duty away from a station · outside the park, escorting, in court · the ranger adds a note',
            self::NotWorking => 'Not on duty · unfit, on leave, stood down',
            self::Special => 'On duty inside the park, on an assignment away from a station · the ranger adds a note',
        };
    }
}
