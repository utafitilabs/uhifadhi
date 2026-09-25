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

namespace Uhifadhi\Bundle\ShellBundle\Twig;

use Psr\Clock\ClockInterface;
use Twig\Environment;
use Uhifadhi\Bundle\ShellBundle\Model\TimeShape;
use Uhifadhi\Contracts\Facts\Fact;

/**
 * `shell_as_of()` — WHEN A STORED FIGURE IS TRUE AS OF, as the house fragment
 * "as of 13:00", printed beside the figure.
 *
 * A figure read from the facts ledger was computed by the worker, not for
 * this request, and when the worker lags the page shows the last one it
 * filed. The page never computes a fallback, so it says how old the figure
 * is instead.
 *
 * THE SHELL'S, NOT THE ATLAS'S. The atlas draws figures — plates, charts,
 * calendars; this is a caption, chrome around any figure on any page, and
 * it is an instant, which the shell alone prints: through its `<time
 * datetime data-localtime-format>` idiom, localised to the reader's zone by
 * the frame, and checked by the shell's time conformance test (see
 * docs/theming.md, "A time reads in the reader's zone"). The fragment is a
 * template, `@Shell/_as_of.html.twig`, so that test reads it like any other.
 *
 * THREE ANSWERS.
 *  - A figure computed less than a day ago: "as of 13:00" — the `clock` shape.
 *    An older one carries its day — "as of 23 sep · 20:00", the `stamp` shape
 *    — because a bare clock reads as today's. The day's length is measured
 *    in elapsed time, not by a calendar the server would have to pick a zone
 *    for.
 *  - A figure whose period has closed and was written after it closed is
 *    FINAL: it is the period's figure, and nothing is printed beside it.
 *  - No figure yet (null): "not computed yet". The page prints no number
 *    for it; the next scheduled run fills it.
 */
final readonly class AsOfRuntime
{
    /** How recent a figure is for its clock alone to say when. */
    private const int CLOCK_ALONE_SECONDS = 20 * 3600;

    public function __construct(
        private ClockInterface $clock,
    ) {
    }

    public function asOf(Environment $twig, Fact|\DateTimeInterface|null $subject): string
    {
        if ($subject instanceof Fact && $subject->isFinal()) {
            return '';
        }

        $instant = $subject instanceof Fact ? $subject->asOf : $subject;
        $shape = null;

        if (null !== $instant) {
            $age = $this->clock->now()->getTimestamp() - $instant->getTimestamp();
            $shape = $age < self::CLOCK_ALONE_SECONDS ? TimeShape::Clock : TimeShape::Stamp;
        }

        return $twig->render('@Shell/_as_of.html.twig', [
            'instant' => $instant,
            'shape' => $shape?->value,
        ]);
    }
}
