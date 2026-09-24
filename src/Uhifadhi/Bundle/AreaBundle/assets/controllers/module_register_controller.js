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
 * THE MODULES REGISTER'S WORKING CONTROLS — the search, the grouped filters and
 * the line that says what is shown, over the rows already on the page.
 *
 * CLIENT-SIDE ON PURPOSE. A catalogue is a dozen modules, not a thousand: a
 * round trip per keystroke would be slower than narrowing in place and would
 * lose the typed text on every reload.
 *
 * A ROW THAT DOES NOT MATCH LEAVES; IT IS NEVER GREYED. A dimmed row still
 * reads as data — "this module is somehow lesser" rather than "not in your
 * search". The order of the rows is never touched here: it is the area's, set
 * by dragging, and a filter that re-sorted would show two answers to one
 * question.
 *
 * A FILTER IS A DROPDOWN OPTION, not a pill: the house filter chip. Picking one
 * lights it, writes its label on the closed chip and closes the panel. The
 * counts are printed by the template and are of the whole set, so an option
 * says what picking it would do.
 *
 * NOTHING HERE KNOWS WHAT A MODULE IS. It reads the data attributes the
 * template stamped on each row, so a fact added to a row needs no change here.
 */
export default class extends Controller {
    static targets = ['search', 'list', 'count', 'dropdown'];

    connect() {
        this.filters = {};
        this.searchTarget?.addEventListener('input', () => this.apply());
        this.apply();
    }

    /** One option of one grouped filter was picked. */
    pick(event) {
        const option = event.currentTarget;
        const key = option.dataset.filter;
        this.filters[key] = option.dataset.value || '';

        const dropdown = option.closest('details');
        dropdown.querySelectorAll('.i-ddopt').forEach((o) => o.classList.toggle('on', o === option));
        const chip = dropdown.querySelector('.i-ddval');
        if (chip) {
            chip.textContent = option.dataset.label || '';
        }
        dropdown.querySelector('summary')?.classList.toggle('on', '' !== this.filters[key]);
        dropdown.open = false;

        this.apply();
    }

    apply() {
        const needle = (this.hasSearchTarget ? this.searchTarget.value : '').trim().toLowerCase();

        let shown = 0;
        for (const row of this.rows()) {
            const matchesText = '' === needle || (row.dataset.name || '').includes(needle);
            const visible = matchesText && this.matchesFilters(row);

            row.hidden = !visible;
            if (visible) {
                shown += 1;
            }
        }

        if (this.hasCountTarget) {
            this.countTarget.textContent = String(shown);
        }
    }

    matchesFilters(row) {
        return Object.entries(this.filters).every(([key, value]) => '' === value || row.dataset[key] === value);
    }

    rows() {
        return this.hasListTarget ? this.listTarget.querySelectorAll('[data-row]') : [];
    }
}
