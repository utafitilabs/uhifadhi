import { Controller } from '@hotwired/stimulus';

/*
 * TABLE FILTER — the house filter bar and register foot over rows the server
 * wrote: each chip of the bar (`details.i-dd[data-axis]`) narrows the rows to
 * those whose `data-<axis>` holds the chosen option (a space-separated list),
 * the search narrows them to those whose `data-find` holds every word typed,
 * and the foot pages what is left by the size chosen in it.
 *
 * Wired as
 *   data-controller="uhifadhi--team-bundle--table-filter"
 *   data-uhifadhi--team-bundle--table-filter-noun-value="people"
 *   data-uhifadhi--team-bundle--table-filter-one-value="person"
 * with targets `row`, `chip`, `search`, `none`, `count`, `pager`, the chips'
 * menus on `click->…#choose`, the foot's sizes on `click->…#size` and its
 * arrows on `click->…#step`. Over a table it did not write, `rows` names
 * the rows by selector and `band` the selector of the rows that head a
 * group: each row then takes the `scope` of the head above it, and a head
 * shows while one of its rows does.
 */
export default class extends Controller {
    static targets = ['row', 'chip', 'search', 'none', 'count', 'pager', 'size'];

    static values = { noun: String, one: String, per: { type: String, default: '20' }, rows: String, band: String };

    connect() {
        if (this.bandValue) {
            let scope = '';
            this.element.querySelectorAll(`${this.bandValue}, ${this.rowsValue}`).forEach((row) => {
                if (row.matches(this.bandValue)) {
                    scope = row.dataset.band ?? row.querySelector('.sg')?.firstChild?.textContent.trim() ?? '';
                } else {
                    row.dataset.scope = scope;
                }
            });
        }
        this.chosen = {};
        this.page = 1;
        this.render();
    }

    choose(event) {
        const option = event.target.closest('[data-opt]');
        const chip = event.target.closest('details[data-axis]');
        if (!option || !chip) {
            return;
        }
        this.chosen[chip.dataset.axis] = option.dataset.opt;
        chip.querySelectorAll('[data-opt]').forEach((o) => {
            o.classList.toggle('on', o === option);
            o.setAttribute('aria-pressed', o === option ? 'true' : 'false');
        });
        const value = chip.querySelector('.i-ddval');
        if (value) {
            value.textContent = option.dataset.chip ?? option.textContent.trim();
        }
        chip.open = false;
        this.page = 1;
        this.render();
    }

    search() {
        this.page = 1;
        this.render();
    }

    size(event) {
        const option = event.target.closest('[data-per]');
        if (!option) {
            return;
        }
        this.perValue = option.dataset.per;
        option.closest('details')?.querySelectorAll('[data-per]').forEach((o) => {
            o.classList.toggle('on', o === option);
            o.setAttribute('aria-pressed', o === option ? 'true' : 'false');
        });
        const summary = option.closest('details')?.querySelector('summary');
        if (summary) {
            const caret = summary.querySelector('.i-ddcaret')?.outerHTML ?? '';
            summary.innerHTML = ('all' === this.perValue ? 'all on one page' : `<span class="pg">${this.perValue}</span> per page`) + caret;
        }
        option.closest('details').open = false;
        this.page = 1;
        this.render();
    }

    step(event) {
        const arrow = event.target.closest('[data-go]');
        if (!arrow) {
            return;
        }
        this.page += Number(arrow.dataset.go);
        this.render();
    }

    get rows() {
        return this.rowsValue ? [...this.element.querySelectorAll(this.rowsValue)] : this.rowTargets;
    }

    render() {
        const words = (this.hasSearchTarget ? this.searchTarget.value : '').toLowerCase().split(/\s+/).filter(Boolean);
        const kept = this.rows.filter((row) => {
            for (const [axis, value] of Object.entries(this.chosen)) {
                if ('' !== value && !(row.dataset[axis] ?? '').split(' ').includes(value)) {
                    return false;
                }
            }
            const find = (row.dataset.find ?? row.textContent).toLowerCase();

            return words.every((word) => find.includes(word));
        });
        const per = 'all' === this.perValue ? kept.length || 1 : Number(this.perValue);
        const pages = Math.max(1, Math.ceil(kept.length / per));
        this.page = Math.min(Math.max(1, this.page), pages);
        this.rows.forEach((row) => { row.hidden = true; });
        kept.slice((this.page - 1) * per, this.page * per).forEach((row) => { row.hidden = false; });
        if (this.bandValue) {
            this.element.querySelectorAll(this.bandValue).forEach((band) => {
                let next = band.nextElementSibling;
                let shown = false;
                while (next && !next.matches(this.bandValue)) {
                    shown = shown || !next.hidden;
                    next = next.nextElementSibling;
                }
                band.hidden = !shown;
            });
        }
        if (this.hasNoneTarget) {
            this.noneTarget.hidden = kept.length > 0;
        }
        if (this.hasCountTarget) {
            this.countTarget.textContent = `${kept.length.toLocaleString('en')} ${1 === kept.length ? this.oneValue : this.nounValue}`;
        }
        if (this.hasPagerTarget) {
            const back = this.page > 1 ? '<button type="button" data-go="-1" aria-label="Previous page">&lsaquo;</button>' : '';
            const on = this.page < pages ? '<button type="button" data-go="1" aria-label="Next page">&rsaquo;</button>' : '';
            this.pagerTarget.innerHTML = `page <span class="pg">${this.page}</span> of ${pages}${back}${on}`;
        }
    }
}
