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
 * A PLATE THAT PICKS A POINT INTO A FORM — what a page states, and all it
 * states, to let a click on the ground fill a latitude and a longitude.
 *
 * THE PLATE OWNS THE REST: the pin (the design's, in the accent, and
 * draggable), the click, the point a form already holds, bringing the pin
 * into view, and the caption under the legend in its three states — at rest,
 * adding, moving. The page writes no JavaScript and extends no plate.
 *
 * `form` IS WHERE A CLICK AT REST WRITES — the id of a form on the page — and
 * `name` is what the caption calls that point. Any other control on the page
 * arms the plate for another form by wearing `data-atlas-pick="<form id>"`,
 * with `data-atlas-pick-mode` (`add`, which writes on every click, or `move`,
 * which proposes until "Use this point") and `data-atlas-pick-name`. An
 * element inside a form wearing `data-atlas-pick-note` is told the point
 * written into it.
 *
 * THE TYPED PAIR IS THE FALLBACK. The plate writes into the very inputs a
 * person could type into, so a page whose scripts never load loses the
 * clicking and keeps the form.
 */
final readonly class PointPick
{
    public function __construct(
        public string $form,
        public string $name = '',
        /** The `name` of the input the latitude is written into, in every form the plate writes into. */
        public string $latitude = 'lat',
        /** The `name` of the input the longitude is written into. */
        public string $longitude = 'lon',
        /** How many decimals are written: five is about a metre. */
        public int $precision = 5,
    ) {
        if ('' === $form) {
            throw new \InvalidArgumentException('A plate that picks a point names the form it writes into.');
        }
        if ($precision < 1) {
            throw new \InvalidArgumentException(\sprintf('A point is written to at least one decimal; %d was stated.', $precision));
        }
    }

    /** @return array{form: string, name: string, latitude: string, longitude: string, precision: int} */
    public function toArray(): array
    {
        return [
            'form' => $this->form,
            'name' => $this->name,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'precision' => $this->precision,
        ];
    }
}
