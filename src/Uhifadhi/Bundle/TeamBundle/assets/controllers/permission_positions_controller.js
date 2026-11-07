import { Controller } from '@hotwired/stimulus';

/*
 * TEAM › PERMISSIONS › POSITIONS — the filter bar over the rows the server
 * wrote (team-module/team/permissions/positions.html's script, over the DOM
 * rather than a data file).
 *
 * The list is the house table: each position a tr.pq-r, its open body the
 * tr.pq-body after it, which travels with it when the rows are ordered and is
 * shown only while its row is on the page and open. Clicking the row or its
 * chevron opens and closes it; the body does not. The door column's head is
 * one toggle over the page shown: Expand all while any row there is closed,
 * Collapse all once every one is open.
 *
 * Each row carries what the filters read: data-find (the name, lower-cased),
 * data-holders, data-flags (vacant exception locked honoured organization
 * area) and data-concerns (the concern keys it holds). The search and the
 * concern narrow the rows; the status keeps those with its flag; the sort
 * orders them, most holders first or by name. Every count is redrawn from the
 * rows: a status counts over what the search and the concern leave, a concern
 * over what the search and the status leave, and the index counts the rows
 * shown.
 *
 * The bar is the house .lfilt: each axis a <details class="i-dd"> whose
 * summary is the chip naming the chosen option and its count, its options
 * buttons carrying data-opt. One chip is open at a time; an outside click or
 * Escape closes them; a choice closes its chip and sets its axis.
 *
 * The register foot pages what the filters leave, in the sort's order: any
 * change of search, chip or sort goes back to page one; Expand all and
 * Collapse all act on the page shown; the index counts every row the filters
 * leave, not the page. The page size is the foot's own chooser (20, 50, 100
 * or all), remembered for this table in sessionStorage under sizeKey.
 */
export default class extends Controller {
    static targets = ['search', 'chip', 'size', 'list', 'row', 'view', 'empty', 'foot', 'count', 'pager', 'all'];

    static values = { perPage: { type: Number, default: 20 }, sizeKey: String };

    connect() {
        this.state = { q: '', concern: '', only: 'all', sort: 'holders', page: 1, size: this.remembered() || String(this.perPageValue) };
        this.draw();
    }

    search(event) {
        this.state.q = event.target.value.trim().toLowerCase();
        this.state.page = 1;
        this.draw();
    }

    toggled(event) {
        if (event.currentTarget.open) {
            this.closeAll(event.currentTarget);
        }
    }

    outside(event) {
        this.closeAll(event.target.closest ? event.target.closest('details.i-dd') : null);
    }

    closeAll(except) {
        this.chipTargets.forEach((chip) => {
            if (chip !== except) {
                chip.open = false;
            }
        });
    }

    choose(event) {
        const option = event.target.closest('[data-opt]');
        if (!option) {
            return;
        }
        const chip = option.closest('details.i-dd');
        chip.open = false;
        const value = option.dataset.opt;
        const axis = chip.dataset.axis;
        if ('only' === axis) {
            this.state.only = value;
        } else if ('concern' === axis) {
            this.state.concern = value;
        } else if ('sort' === axis) {
            this.state.sort = 'name' === value ? 'name' : 'holders';
        } else if ('size' === axis) {
            this.state.size = value;
            this.remember(value);
        }
        this.state.page = 1;
        this.draw();
    }

    remembered() {
        if (!this.sizeKeyValue) {
            return null;
        }
        try {
            const size = window.sessionStorage.getItem(this.sizeKeyValue);
            return ['20', '50', '100', 'all'].indexOf(size) > -1 ? size : null;
        } catch (e) {
            return null;
        }
    }

    remember(size) {
        if (!this.sizeKeyValue) {
            return;
        }
        try {
            window.sessionStorage.setItem(this.sizeKeyValue, size);
        } catch (e) {
            // storage refused: the choice lasts as long as the page
        }
    }

    go(event) {
        const button = event.target.closest('[data-go]');
        if (!button) {
            return;
        }
        const step = button.dataset.go;
        this.state.page += Number(step);
        this.draw();
        const next = this.pagerTarget.querySelector('[data-go="' + step + '"]') || this.pagerTarget.querySelector('[data-go]');
        if (next) {
            next.focus();
        }
    }

    all() {
        const rows = this.shown();
        const open = !this.everyOpen(rows);
        rows.forEach((row) => this.setOpen(row, open));
    }

    everyOpen(rows) {
        return rows.length > 0 && rows.every((row) => row.classList.contains('pq-open'));
    }

    drawAll() {
        const every = this.everyOpen(this.shown());
        this.allTarget.textContent = every ? 'Collapse all' : 'Expand all';
    }

    fold(event) {
        const row = event.target.closest('tr.pq-r');
        if (!row || event.target.closest('a')) {
            return;
        }
        const selection = window.getSelection && window.getSelection();
        if (selection && String(selection).length && !event.target.closest('.pq-chev')) {
            return;
        }
        this.setOpen(row, !row.classList.contains('pq-open'));
    }

    setOpen(row, open) {
        row.classList.toggle('pq-open', open);
        row.nextElementSibling.hidden = row.hidden || !open;
        const chev = row.querySelector('.pq-chev');
        chev.setAttribute('aria-expanded', String(open));
        chev.setAttribute('aria-label', (open ? 'Collapse ' : 'Expand ') + row.dataset.name);
        this.drawAll();
    }

    bySearch(row) {
        return !this.state.q || row.dataset.find.indexOf(this.state.q) > -1;
    }

    byConcern(row, key = this.state.concern) {
        return !key || row.dataset.concerns.split(' ').indexOf(key) > -1;
    }

    byOnly(row, key = this.state.only) {
        return 'all' === key || row.dataset.flags.split(' ').indexOf(key) > -1;
    }

    shown() {
        return this.rowTargets.filter((row) => !row.hidden);
    }

    chip(axis) {
        return this.chipTargets.find((chip) => chip.dataset.axis === axis);
    }

    fill(axis, chosen, count, label, on) {
        const chip = this.chip(axis);
        let chosenLabel = '';
        chip.querySelectorAll('.i-ddopt').forEach((option) => {
            const value = option.dataset.opt;
            const isOn = value === chosen;
            option.classList.toggle('on', isOn);
            option.setAttribute('aria-pressed', String(isOn));
            const n = option.querySelector('.i-ddopt-n');
            if (n && count) {
                const c = count(value);
                n.textContent = c;
                option.classList.toggle('pq-zero', !c);
            }
            if (isOn) {
                chosenLabel = option.querySelector('.i-ddopt-l').textContent;
            }
        });
        chip.querySelector('.i-ddval').textContent = label(chosenLabel.toLowerCase());
        chip.querySelector('summary').classList.toggle('on', on);
    }

    draw() {
        const rows = this.rowTargets;
        const { only, concern } = this.state;

        const forStatus = rows.filter((r) => this.bySearch(r) && this.byConcern(r));
        const nStatus = (key) => forStatus.filter((r) => this.byOnly(r, key)).length;
        this.fill('only', only, nStatus, (l) => ('all' === only ? 'all' : l) + ' · ' + nStatus(only), 'all' !== only);

        const forConcerns = rows.filter((r) => this.bySearch(r) && this.byOnly(r));
        const nConcern = (key) => (key ? forConcerns.filter((r) => this.byConcern(r, key)).length : forConcerns.length);
        this.fill('concern', concern, nConcern, (l) => (concern ? l + ' · ' + nConcern(concern) : 'resource'), '' !== concern);

        this.fill('sort', this.state.sort, null, (l) => l, false);

        const shown = rows.filter((r) => this.bySearch(r) && this.byConcern(r) && this.byOnly(r));
        const order = rows.slice().sort('name' === this.state.sort
            ? (a, b) => a.dataset.name.localeCompare(b.dataset.name)
            : (a, b) => Number(b.dataset.holders) - Number(a.dataset.holders) || a.dataset.name.localeCompare(b.dataset.name));

        const size = this.state.size;
        const per = 'all' === size ? Math.max(1, shown.length) : Number(size);
        const pages = Math.max(1, Math.ceil(shown.length / per));
        this.state.page = Math.min(Math.max(1, this.state.page), pages);
        const ordered = order.filter((r) => shown.indexOf(r) > -1);
        const onPage = ordered.slice((this.state.page - 1) * per, this.state.page * per);
        const bodies = new Map(rows.map((r) => [r, r.nextElementSibling]));
        rows.forEach((r) => {
            r.hidden = onPage.indexOf(r) < 0;
            bodies.get(r).hidden = r.hidden || !r.classList.contains('pq-open');
        });
        onPage.concat(order.filter((r) => r.hidden)).forEach((r) => {
            this.listTarget.appendChild(r);
            this.listTarget.appendChild(bodies.get(r));
        });
        this.drawAll();

        this.footTarget.hidden = !shown.length;
        this.countTarget.textContent = shown.length + (1 === shown.length ? ' position' : ' positions');
        this.sizeTarget.querySelector('.pg').textContent = 'all' === size ? 'all' : size;
        this.sizeTarget.querySelectorAll('.i-ddopt').forEach((option) => {
            const on = option.dataset.opt === size;
            option.classList.toggle('on', on);
            option.setAttribute('aria-pressed', String(on));
        });
        this.pagerTarget.innerHTML = (this.state.page > 1 ? '<button type="button" data-go="-1" aria-label="Previous page">&lsaquo;</button>' : '')
            + 'page <span class="pg">' + this.state.page + '</span> of ' + pages
            + (this.state.page < pages ? '<button type="button" data-go="1" aria-label="Next page">&rsaquo;</button>' : '');

        this.viewTarget.hidden = !shown.length;
        this.emptyTarget.hidden = shown.length > 0;
        if (window.shellOnpage) {
            window.shellOnpage.count('positions', shown.length);
        }
    }
}
