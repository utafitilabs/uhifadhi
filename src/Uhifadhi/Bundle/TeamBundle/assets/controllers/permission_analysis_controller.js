import { Controller } from '@hotwired/stimulus';

/*
 * TEAM › PERMISSIONS › ANALYSIS — one card's registers
 * (team-module/team/permissions/analysis.html's script, over the markup the
 * server wrote rather than a data file). The same register pages Above the
 * matrix on Positions and the permission history on the hub.
 *
 * A REGISTER is a [data-pa-reg] block: the house filter bar (.lfilt), a table
 * whose rows are tr[data-pa-r], the house foot (.rdf-foot) and the line it
 * says when nothing matches. A row carries its values on the bar's axes as
 * JSON in data-pa-f and the words a search matches in data-pa-q. Each chip
 * is a <details class="i-dd" data-pa-axis> whose options are buttons
 * carrying data-pa-opt; '' is the All option. Every chip counts over what
 * the other chips and the search leave. Any choice goes back to page 1.
 *
 * THE QUESTION'S GRID ([data-pa-grid]) narrows by its own three chips: rows
 * (all, with people, empty, changed since the last run), department (each
 * cell recounted to the people who serve it, read from the cell's
 * <template data-pa-people> whose <li> carry data-pa-id and data-pa-deps, a
 * person counting under every department they serve) and sort (most
 * people first, by name). The totals count every row the chips keep.
 *
 * THE FOOT pages at 20, 50, 100 or all ("all on one page"), remembered per
 * register for the session (uhifadhi-pp-pagesize:<register>); an arrow only where there is a
 * page to go to.
 *
 * A cell is a button carrying data-pa-cell; a click opens its people under
 * the grid, a second click on it closes them, one cell at a time. One chip
 * is open at a time; an outside click or Escape closes it. The question
 * chip's options are links: the question is the address.
 */
const SIZES = [20, 50, 100, 0];
const KEY = 'uhifadhi-pp-pagesize:';

export default class extends Controller {
    static targets = ['people', 'head', 'names'];

    connect() {
        this.state = new Map();
        this.element.querySelectorAll('[data-pa-reg]').forEach((reg) => {
            let size = 20;
            try {
                const kept = parseInt(window.sessionStorage.getItem(KEY + reg.dataset.paReg) || '', 10);
                if (SIZES.includes(kept)) {
                    size = kept;
                }
            } catch (e) {
                size = 20;
            }
            reg.querySelectorAll('details.i-dd[data-pa-axis] .i-ddval').forEach((v) => {
                v.dataset.paWord = v.textContent;
            });
            const foot = reg.querySelector('[data-pa-foot]');
            this.state.set(reg, {
                chosen: {},
                q: '',
                page: 1,
                size,
                noun: foot ? foot.dataset.paNoun : '',
                one: foot ? foot.dataset.paOne : '',
                rows: [...reg.querySelectorAll('tbody tr[data-pa-r]')].map((tr) => ({ tr, f: tr.dataset.paF ? JSON.parse(tr.dataset.paF) : {} })),
            });
            this.apply(reg);
        });
    }

    click(event) {
        const t = event.target;
        const reg = t.closest('[data-pa-reg]');
        if (!reg || !this.state.has(reg)) {
            return;
        }
        const st = this.state.get(reg);
        const opt = t.closest('[data-pa-opt]');
        const size = t.closest('[data-pa-size]');
        const go = t.closest('[data-pa-go]');
        if (opt) {
            const chip = opt.closest('details.i-dd');
            chip.open = false;
            st.chosen[chip.dataset.paAxis] = opt.dataset.paOpt;
            st.page = 1;
        } else if (size) {
            size.closest('details.i-dd').open = false;
            st.size = parseInt(size.dataset.paSize, 10) || 0;
            st.page = 1;
            try {
                window.sessionStorage.setItem(KEY + reg.dataset.paReg, String(st.size));
            } catch (e) {
                // a session the browser will not keep still pages
            }
        } else if (go) {
            st.page += parseInt(go.dataset.paGo, 10);
        } else if (t.closest('.pa-cell')) {
            this.open(t.closest('.pa-cell'), reg);
            return;
        } else {
            return;
        }
        this.apply(reg);
        if (go) {
            const again = reg.querySelector('[data-pa-go="' + go.dataset.paGo + '"]') || reg.querySelector('[data-pa-go]');
            if (again) {
                again.focus();
            }
        }
    }

    search(event) {
        const input = event.target.closest('[data-pa-search]');
        const reg = input && input.closest('[data-pa-reg]');
        if (!reg || !this.state.has(reg)) {
            return;
        }
        const st = this.state.get(reg);
        st.q = input.value.trim().toLowerCase();
        st.page = 1;
        this.apply(reg);
    }

    outside(event) {
        const here = event.target.closest ? event.target.closest('details.i-dd') : null;
        document.querySelectorAll('details.i-dd[open]').forEach((d) => {
            if (d !== here) {
                d.open = false;
            }
        });
    }

    escape(event) {
        if ('Escape' === event.key) {
            document.querySelectorAll('details.i-dd[open]').forEach((d) => {
                d.open = false;
            });
        }
    }

    apply(reg) {
        const st = this.state.get(reg);
        const shown = undefined !== reg.dataset.paGrid ? this.grid(reg, st) : this.list(reg, st);
        this.page(reg, st, shown);
    }

    list(reg, st) {
        const axes = [...reg.querySelectorAll('details.i-dd[data-pa-axis]')].map((d) => d.dataset.paAxis).filter((axis) => 'q' !== axis);
        const passes = (row, except) => axes.every((axis) => {
            const v = st.chosen[axis];
            return axis === except || !v || (row.f[axis] || []).includes(v);
        }) && (!st.q || (row.tr.dataset.paQ || '').includes(st.q));

        axes.forEach((axis) => {
            const left = st.rows.filter((r) => passes(r, axis));
            const count = (v) => ('' === v ? left.length : left.filter((r) => (r.f[axis] || []).includes(v)).length);
            this.fill(reg, axis, st.chosen[axis] || '', count);
        });

        return st.rows.filter((r) => passes(r, null)).map((r) => r.tr);
    }

    grid(reg, st) {
        const dept = st.chosen.department || '';
        const rowsAxis = st.chosen.rows || 'all';
        const sort = st.chosen.sort || 'people';
        const people = (tr, d) => {
            const cells = {};
            const seen = new Set();
            tr.querySelectorAll('td[data-pa-at]').forEach((td) => {
                const b = td.querySelector('.pa-cell');
                const tpl = b ? reg.querySelector('template[data-pa-people="' + CSS.escape(b.dataset.paCell) + '"]') : null;
                const lis = tpl ? [...tpl.content.querySelectorAll('li')] : [];
                const kept = d ? lis.filter((li) => (li.dataset.paDeps || '').split(' ').includes(d)) : lis;
                cells[td.dataset.paAt] = b ? (d ? kept.length : parseInt(b.dataset.paN, 10)) : 0;
                kept.forEach((li) => seen.add(li.dataset.paId));
            });
            return { cells, ids: seen, n: d ? seen.size : parseInt(tr.dataset.paPeople, 10) };
        };
        const keep = (axis, row, view) => ('with-people' === axis ? view.n > 0
            : 'empty' === axis ? 0 === view.n
                : 'changed' === axis ? '1' === row.tr.dataset.paChanged : true);

        const views = st.rows.map((row) => ({ row, view: people(row.tr, dept), plain: people(row.tr, '') }));
        const counts = {
            all: views.length,
            'with-people': views.filter((v) => keep('with-people', v.row, v.view)).length,
            empty: views.filter((v) => keep('empty', v.row, v.view)).length,
            changed: views.filter((v) => keep('changed', v.row, v.view)).length,
        };
        this.fill(reg, 'rows', rowsAxis, (v) => counts[v]);

        const axisRows = views.filter((v) => keep(rowsAxis, v.row, v.plain));
        const serving = (d) => {
            const s = new Set();
            axisRows.forEach((v) => {
                const all = people(v.row.tr, d);
                all.ids.forEach((id) => s.add(id));
            });
            return s.size;
        };
        this.fill(reg, 'department', dept, (v) => serving(v));
        this.fill(reg, 'sort', sort, () => null);

        const kept = views.filter((v) => keep(rowsAxis, v.row, v.view));
        kept.sort('name' === sort
            ? (a, b) => a.row.tr.dataset.paName.localeCompare(b.row.tr.dataset.paName)
            : (a, b) => b.view.n - a.view.n || a.row.tr.dataset.paName.localeCompare(b.row.tr.dataset.paName));
        const body = reg.querySelector('tbody');
        kept.forEach((v) => body.appendChild(v.row.tr));
        views.filter((v) => !kept.includes(v)).forEach((v) => body.appendChild(v.row.tr));

        views.forEach((v) => v.row.tr.querySelectorAll('td[data-pa-at]').forEach((td) => {
            const b = td.querySelector('.pa-cell');
            if (b) {
                const n = v.view.cells[td.dataset.paAt];
                b.textContent = String(n);
                b.hidden = 0 === n;
            }
        }));

        const sums = reg.querySelectorAll('[data-pa-sum]');
        const keys = [...(st.rows[0] ? st.rows[0].tr.querySelectorAll('td[data-pa-at]') : [])].map((td) => td.dataset.paAt.split('|')[1]);
        sums.forEach((td, i) => {
            const total = kept.reduce((n, v) => n + (v.view.cells[v.row.tr.querySelectorAll('td[data-pa-at]')[i].dataset.paAt] || 0), 0);
            td.textContent = total > 0 && keys[i] ? String(total) : '';
        });
        st.kept = kept.length;
        this.close();

        return kept.map((v) => v.row.tr);
    }

    fill(reg, axis, chosen, count) {
        const chip = reg.querySelector('details.i-dd[data-pa-axis="' + axis + '"]');
        if (!chip) {
            return;
        }
        let label = null;
        let n = null;
        chip.querySelectorAll('[data-pa-opt]').forEach((o) => {
            const on = o.dataset.paOpt === chosen;
            const c = count(o.dataset.paOpt);
            o.classList.toggle('on', on);
            o.setAttribute('aria-pressed', on ? 'true' : 'false');
            const tag = o.querySelector('.i-ddopt-n');
            if (tag && null !== c) {
                tag.textContent = String(c);
                o.classList.toggle('pa-zero', 0 === c);
            }
            if (on) {
                label = o.querySelector('.i-ddopt-l').textContent;
                n = c;
            }
        });
        const val = chip.querySelector('.i-ddval');
        const word = val.dataset.paWord;
        const first = chip.querySelector('[data-pa-opt]');
        const isFirst = first && first.dataset.paOpt === chosen;
        let text;
        if ('sort' === axis) {
            text = (label || '').toLowerCase();
        } else if ('rows' === axis) {
            text = (label || '').toLowerCase() + ' · ' + n;
        } else if (isFirst) {
            text = word.startsWith('all · ') ? 'all · ' + n : word;
        } else {
            text = (label || '').toLowerCase() + ' · ' + n;
        }
        val.textContent = text;
        chip.querySelector('summary').classList.toggle('on', !isFirst && 'sort' !== axis);
    }

    page(reg, st, shown) {
        const size = st.size || shown.length || 1;
        const pages = Math.max(1, Math.ceil(shown.length / size));
        st.page = Math.min(Math.max(1, st.page), pages);
        const from = (st.page - 1) * size;
        st.rows.forEach((r) => {
            r.tr.hidden = true;
        });
        shown.slice(from, from + size).forEach((tr) => {
            tr.hidden = false;
        });

        const table = reg.querySelector('table');
        const foot = reg.querySelector('[data-pa-foot]');
        const none = reg.querySelector('[data-pa-nomatch]');
        const empty = 0 === shown.length && st.rows.length > 0;
        if (table) {
            table.hidden = empty;
        }
        if (none) {
            none.hidden = !empty;
        }
        if (!foot) {
            return;
        }
        foot.hidden = empty;
        foot.querySelector('[data-pa-count]').textContent = shown.length + ' ' + (1 === shown.length ? st.one : st.noun);
        const sized = foot.querySelector('.rdf-size > summary');
        const caret = sized.querySelector('.i-ddcaret');
        const pg = document.createElement('span');
        pg.className = 'pg';
        pg.textContent = String(st.size);
        sized.replaceChildren(...(st.size ? [pg, ' per page'] : ['all on one page']), caret);
        foot.querySelectorAll('[data-pa-size]').forEach((o) => {
            o.classList.toggle('on', (parseInt(o.dataset.paSize, 10) || 0) === st.size);
        });
        const at = foot.querySelector('[data-pa-page]');
        const prev = document.createElement('button');
        prev.type = 'button';
        prev.dataset.paGo = '-1';
        prev.setAttribute('aria-label', 'Previous page');
        prev.textContent = '‹';
        const next = document.createElement('button');
        next.type = 'button';
        next.dataset.paGo = '1';
        next.setAttribute('aria-label', 'Next page');
        next.textContent = '›';
        const on = document.createElement('span');
        on.className = 'pg';
        on.textContent = String(st.page);
        at.replaceChildren(...(st.page > 1 ? [prev] : []), 'page ', on, ' of ' + pages, ...(st.page < pages ? [next] : []));

        const total = reg.querySelector('[data-pa-total]');
        if (total) {
            total.textContent = pages > 1 ? 'Total · all ' + shown.length : 'Total';
        }
    }

    open(button, reg) {
        if (!this.hasPeopleTarget) {
            return;
        }
        const key = button.dataset.paCell;
        const wasOpen = button.classList.contains('pa-on');
        this.close();
        if (wasOpen) {
            return;
        }
        const list = reg.querySelector('template[data-pa-people="' + CSS.escape(key) + '"]');
        if (!list) {
            return;
        }
        const st = this.state.get(reg);
        const dept = st.chosen.department || '';
        const chip = reg.querySelector('[data-pa-axis="department"] [data-pa-opt="' + CSS.escape(dept) + '"] .i-ddopt-l');
        button.classList.add('pa-on');
        button.setAttribute('aria-expanded', 'true');
        const names = [...list.content.cloneNode(true).querySelectorAll('li')]
            .filter((li) => !dept || (li.dataset.paDeps || '').split(' ').includes(dept));
        const head = document.createElement('b');
        head.textContent = names.length + (1 === names.length ? ' person' : ' people');
        this.headTarget.replaceChildren(head, document.createTextNode(' · ' + list.dataset.paWhere + (dept && chip ? ' · serving ' + chip.textContent : '')));
        this.namesTarget.replaceChildren(...names);
        this.peopleTarget.hidden = false;
    }

    close() {
        this.element.querySelectorAll('.pa-cell.pa-on').forEach((b) => {
            b.classList.remove('pa-on');
            b.setAttribute('aria-expanded', 'false');
        });
        if (this.hasPeopleTarget) {
            this.peopleTarget.hidden = true;
        }
    }
}
