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
 * WHAT A CLICK ON A FEATURE OPENS (ruled 30 Sep, #16 C): a bounded sheet at
 * the foot of the plate - a mark, a title and a subtitle, a few rows, and the
 * doors onward. The plate draws this shape and nothing else, so the owner of
 * the feature says what it is about and the atlas never learns.
 *
 * A ROW'S INSTANTS ARE MACHINE TIME. Each `at` is an ISO instant the frame
 * localises in the viewer's zone, the way every `<time>` on the platform is,
 * so the sheet never prints the server's wall-clock.
 */
final readonly class AtlasSheet
{
    /**
     * @param list<array{label: string, value: string, at?: list<string>}> $rows  a row's value, and the instants it names, in order
     * @param list<array{label: string, url: string, primary?: bool}>      $doors where the reader goes next; the first primary one is the call to action
     */
    public function __construct(
        public string $title,
        public string $subtitle = '',
        public string $initials = '',
        public array $rows = [],
        public array $doors = [],
    ) {
    }

    /** @return array{title: string, subtitle: string, initials: string, rows: list<array{label: string, value: string, at?: list<string>}>, doors: list<array{label: string, url: string, primary?: bool}>} */
    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'subtitle' => $this->subtitle,
            'initials' => $this->initials,
            'rows' => $this->rows,
            'doors' => $this->doors,
        ];
    }
}
