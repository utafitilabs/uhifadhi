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
 * THE HOUSE FOOT PAGES WHAT IS LEFT: its size chooser (20 · 50 · 100 · All)
 * and its arrows are buttons this controller moves, and the count it states
 * is of the rows the filters left.
 *
 * NOTHING HERE KNOWS WHAT A MODULE IS. It reads the data attributes the
 * template stamped on each row, so a fact added to a row needs no change here.
 */
export default class extends Controller {
    static targets = ['search', 'list', 'count', 'dropdown', 'pager'];

    static values = { noun: { type: String, default: 'modules' }, one: { type: String, default: 'module' } };

    connect() {
        this.filters = {};
        this.per = '20';
        this.page = 1;
        this.searchTarget?.addEventListener('input', () => {
            this.page = 1;
            this.apply();
        });
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

        this.page = 1;
        this.apply();
    }

    /** A size of the foot's chooser was picked. */
    size(event) {
        const option = event.target.closest('[data-per]');
        if (!option) {
            return;
        }
        this.per = option.dataset.per;
        const chooser = option.closest('details');
        chooser.querySelectorAll('[data-per]').forEach((o) => {
            o.classList.toggle('on', o === option);
            o.setAttribute('aria-pressed', o === option ? 'true' : 'false');
        });
        const summary = chooser.querySelector('summary');
        const caret = summary.querySelector('.i-ddcaret')?.outerHTML ?? '';
        summary.innerHTML = ('all' === this.per ? 'all on one page' : `<span class="pg">${this.per}</span> per page`) + caret;
        chooser.open = false;
        this.page = 1;
        this.apply();
    }

    /** One of the foot's arrows. */
    step(event) {
        const arrow = event.target.closest('[data-go]');
        if (!arrow) {
            return;
        }
        this.page += Number(arrow.dataset.go);
        this.apply();
    }

    apply() {
        const needle = (this.hasSearchTarget ? this.searchTarget.value : '').trim().toLowerCase();
        const kept = [...this.rows()].filter((row) => ('' === needle || (row.dataset.name || '').includes(needle)) && this.matchesFilters(row));

        const per = 'all' === this.per ? kept.length || 1 : Number(this.per);
        const pages = Math.max(1, Math.ceil(kept.length / per));
        this.page = Math.min(Math.max(1, this.page), pages);
        const shown = kept.slice((this.page - 1) * per, this.page * per);
        for (const row of this.rows()) {
            row.hidden = !shown.includes(row);
        }

        if (this.hasCountTarget) {
            this.countTarget.textContent = `${kept.length} ${1 === kept.length ? this.oneValue : this.nounValue}`;
        }
        if (this.hasPagerTarget) {
            const back = this.page > 1 ? '<button type="button" data-go="-1" aria-label="Previous page">&lsaquo;</button>' : '';
            const on = this.page < pages ? '<button type="button" data-go="1" aria-label="Next page">&rsaquo;</button>' : '';
            this.pagerTarget.innerHTML = `page <span class="pg">${this.page}</span> of ${pages}${back}${on}`;
        }
    }

    matchesFilters(row) {
        return Object.entries(this.filters).every(([key, value]) => '' === value || row.dataset[key] === value);
    }

    rows() {
        return this.hasListTarget ? this.listTarget.querySelectorAll('[data-row]') : [];
    }
}
