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

namespace Uhifadhi\Contracts\Me;

/**
 * ONE CARD ON A PERSON'S OWN DASHBOARD (ruled 28 Sep 2026, open item #19 —
 * option A of variants-my-dashboard): its finished markup, and where the
 * approved layout puts it.
 *
 * The slots are the design's positions, named rather than numbered so a
 * package says what its card is, not where on a grid it happens to fall:
 * a figure in the row of four; a door to a page of the person's own (My
 * roster, My station, My duty log) in the row under the figures; the plate
 * (the map) or what stands beside it; the left or right of the two-column
 * band; or the three-across row at the foot. Within a slot, cards stand in
 * `order`, lowest first.
 */
final readonly class MyCard
{
    public const string FIGURE = 'figure';
    public const string DOOR = 'door';
    public const string PLATE = 'plate';
    public const string BESIDE_PLATE = 'beside-plate';
    public const string LEFT = 'left';
    public const string RIGHT = 'right';
    public const string ROW = 'row';

    public function __construct(
        /** One of the slot constants. */
        public string $slot,
        public int $order,
        /** The card, rendered. */
        public string $html,
    ) {
    }
}
