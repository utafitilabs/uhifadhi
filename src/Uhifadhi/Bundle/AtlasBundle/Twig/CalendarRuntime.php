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

namespace Uhifadhi\Bundle\AtlasBundle\Twig;

use Twig\Environment;
use Twig\Extension\RuntimeExtensionInterface;
use Uhifadhi\Bundle\AtlasBundle\Calendar\CalendarBuilder;
use Uhifadhi\Bundle\AtlasBundle\Model\AtlasCalendar;
use Uhifadhi\Contracts\Atlas\CalendarFeedInterface;
use Uhifadhi\Contracts\Atlas\CalendarMonth;
use Uhifadhi\Contracts\Atlas\PillHue;
use Uhifadhi\Contracts\Atlas\YearMonth;

/**
 * WHAT A SURFACE WRITES TO HAVE A MONTH: `{{ atlas_calendar(feed, month) }}`.
 *
 * THE PLATE'S AND THE CHART'S SIBLING, down to the shape of the call. A
 * surface names the feed it wants — as it names a plate's subject —
 * and this draws the grid the platform draws every month in.
 *
 * A FEED, A MONTH, OR A CALENDAR. The first is the ordinary case; the
 * second is for a caller that already has the month in hand (it fetched
 * it to count something); the third is for one that laid the grid out
 * itself, which is rare and deliberate. One function, because three
 * would make a page's author choose between three spellings of the same
 * picture.
 *
 * THE HEIGHT COMES THROUGH THE SAME DOOR THE PLATE'S AND THE CHART'S DO:
 * a custom property handed in attributes sizes the CELL, since that is
 * what a month's height is made of.
 *
 * @see https://symfony.com/doc/current/templating/twig_extension.html — a lazy-loaded extension's work lives in a RuntimeExtensionInterface class named from the extension as [Runtime::class, 'method']
 * @see vendor/symfony/twig-bundle/DependencyInjection/Compiler/RuntimeLoaderPass.php — the 'twig.runtime' tag AtlasBundle::loadExtension() writes by hand, collected into twig.runtime_loader
 */
final readonly class CalendarRuntime implements RuntimeExtensionInterface
{
    private const string TEMPLATE = '@Atlas/calendar.html.twig';

    /** A property handed in attributes sizes the GRID, not a cell's content. */
    public const string CUSTOM_PROPERTY_PREFIX = '--';

    /**
     * WHAT A HUE ROLE IS PAINTED WITH. The roles are the contracts'; the
     * paint is the atlas's.
     *
     * A COLOUR TOKEN AND NEVER A CHANNEL TOKEN. The shell publishes both:
     * `--c-acc` is the three numbers `62 217 168`, meant to be spent
     * inside `rgb(...)`, and `--acc` is the colour itself. This value is
     * handed straight to a `background`, so a channel token resolved to
     * a bare triple, painted nothing, and every pill dot on every
     * calendar in the product drew empty while the markup looked right.
     */
    private const array HUES = [
        PillHue::Subject->value => 'var(--acc)',
        PillHue::Good->value => 'var(--ok)',
        PillHue::Attention->value => 'var(--warn)',
        PillHue::Problem->value => 'var(--fail)',
        PillHue::Quiet->value => 'var(--fog)',
    ];

    public function __construct(
        private Environment $twig,
        private CalendarBuilder $calendars,
    ) {
    }

    /**
     * @param array<string, bool|string> $attributes attributes for the grid — an aria-label, a
     *                                               module's own data attribute; a key written as a
     *                                               custom property (`--cal-cell-height`) sizes it
     * @param string                     $controls   the surface's OWN control, drawn at the
     *                                               trailing end of the stepper row: the ranger
     *                                               picker on a roster month, the type filter on a
     *                                               patrol one. Already-rendered markup, printed
     *                                               UNESCAPED — render it with Twig and never build
     *                                               it out of anything somebody typed
     */
    public function renderCalendar(
        CalendarFeedInterface|CalendarMonth|AtlasCalendar $source,
        YearMonth|string|null $month = null,
        ?string $scope = null,
        string $title = '',
        string $caption = '',
        ?\DateTimeImmutable $today = null,
        ?string $previousUrl = null,
        ?string $nextUrl = null,
        array $attributes = [],
        string $controls = '',
    ): string {
        $calendar = $this->calendarOf($source, $month, $scope, $today, $previousUrl, $nextUrl);

        $gridStyle = [];
        foreach ($attributes as $property => $value) {
            if (str_starts_with($property, self::CUSTOM_PROPERTY_PREFIX) && \is_string($value)) {
                $gridStyle[$property] = $value;
                unset($attributes[$property]);
            }
        }

        return $this->twig->render(self::TEMPLATE, [
            'calendar' => $calendar,
            'weekdays' => AtlasCalendar::WEEKDAYS,
            'hues' => self::HUES,
            'title' => $title,
            'caption' => $caption,
            'empty' => $calendar->isEmpty(),
            'gridStyle' => self::style($gridStyle),
            'attributes' => $attributes,
            'controls' => $controls,
        ]);
    }

    private function calendarOf(
        CalendarFeedInterface|CalendarMonth|AtlasCalendar $source,
        YearMonth|string|null $month,
        ?string $scope,
        ?\DateTimeImmutable $today,
        ?string $previousUrl,
        ?string $nextUrl,
    ): AtlasCalendar {
        if ($source instanceof AtlasCalendar) {
            return $source;
        }

        if ($source instanceof CalendarMonth) {
            return $this->calendars->build($source, $today, previousUrl: $previousUrl, nextUrl: $nextUrl);
        }

        // A FEED IS ASKED FOR THE MONTH THE SURFACE NAMED. A caller that
        // named none means the month it is in, which is what every "open
        // the calendar" link means.
        $asked = $month instanceof YearMonth ? $month : self::monthIn($month, $today);

        return $this->calendars->build(
            $source->month($asked, $scope),
            $today,
            previousUrl: $previousUrl,
            nextUrl: $nextUrl,
        );
    }

    /** `2026-09`, or the month the viewer is in. */
    private static function monthIn(?string $month, ?\DateTimeImmutable $today): YearMonth
    {
        if (null === $month || 1 !== preg_match('/^(\d{4})-(\d{2})$/', $month, $parts)) {
            return YearMonth::of($today ?? new \DateTimeImmutable('today'));
        }

        return new YearMonth((int) $parts[1], (int) $parts[2]);
    }

    /** @param array<string, string> $properties */
    private static function style(array $properties): string
    {
        $declarations = [];
        foreach ($properties as $property => $value) {
            $declarations[] = $property.':'.$value;
        }

        return implode(';', $declarations);
    }
}
