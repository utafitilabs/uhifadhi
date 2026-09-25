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
 * An area's running modules move by the shell's reorder control now,
 * `uhifadhi--shell-bundle--reorder`: a drag with a visible slot where the row
 * will land, up and down carets, and the arrow keys on the grip. No template
 * names this controller.
 *
 * THE FILE STAYS FOR ONE RELEASE because an installation's
 * `assets/controllers.json` may still enable `module-order`, and
 * StimulusBundle refuses to render a page whose enabled controller has no
 * file. Remove that entry (see UPGRADE-1.0.md, "Rows move by the shell's
 * reorder control").
 */
export default class extends Controller {}
