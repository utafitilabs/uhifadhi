/*
 * This file is part of the Uhifadhi core.
 *
 * (c) Ezekiel Mjema <https://github.com/eemjema>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

import { Controller } from '@hotwired/stimulus';

/*
 * DEPRECATED, AND DOES NOTHING. Removed in the next release.
 *
 * Picking a station's point is the atlas plate's own pick mode now: the
 * stations section states `AtlasMap::pickPoint()` and its controls wear
 * `data-atlas-pick`, and no template names this controller.
 *
 * THE FILE STAYS FOR ONE RELEASE because an installation's
 * `assets/controllers.json` still enables `station-point`, and StimulusBundle
 * refuses to render a page whose enabled controller has no file. Remove the
 * `@uhifadhi/area-bundle` → `station-point` entry from that file (see
 * UPGRADE-1.0.md, "A station's point is picked by the atlas plate").
 */
export default class extends Controller {}
