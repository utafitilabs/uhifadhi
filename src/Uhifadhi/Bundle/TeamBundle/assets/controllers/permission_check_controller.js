import { Controller } from '@hotwired/stimulus';

/*
 * TEAM › PERMISSIONS › CHECK — the page's behaviour
 * (team-module/team/permissions/check.html's script, over the markup the
 * server wrote rather than a data file).
 *
 * THE FORM is the house .lfilt bar: three chips, each a <details class="i-dd">
 * whose summary names the choice. The person and question panels carry a
 * find box pinned at their top (Enter takes the first option shown). A choice
 * lands in the form's hidden input; a power swaps its own targets into the
 * place chip (the <template data-pc-targets="<power>">), a permission the
 * places (data-pc-targets=""). Check submits the form, so the check is asked
 * by the server, live, at its own address; with no person or no question yet
 * it opens the chip still to choose.
 *
 * THE INDEX shows Everything here, which waits for a check, and Worth
 * running only when they are drawn: a row whose card is hidden is hidden
 * through window.shellOnpage.
 *
 * THE TWO REGISTERS, Everything here and Worth running: every row is in the
 * page; the .lfilt bar's <details class="i-dd"> chips, the search and the
 * foot filter, sort and page them here, one chip open at a time, an outside
 * click or Escape closing it. The page size is remembered per table for the
 * session. A row of Worth running runs its check.
 *
 * RECENT CHECKS are this session's, kept in sessionStorage, newest first;
 * the check this page answers is added on arrival. They are the third
 * register, drawn here as rows of the same house table.
 */
const RECENT = 'uhifadhi-pp-recent';
const SIZE = 'uhifadhi-pp-pagesize:';

function store() {
    try {
        return window.sessionStorage;
    } catch (e) {
        return null;
    }
}

export default class extends Controller {
    static targets = ['form', 'person', 'question', 'where', 'every', 'worth', 'recent', 'recentCard', 'none'];

    static values = { page: { type: Number, default: 20 }, asked: Object };

    connect() {
        this.onClick = (e) => this.click(e);
        this.onKey = (e) => { if ('Escape' === e.key) { this.closeAll(null); } };
        this.onToggle = (e) => {
            const d = e.target;
            if (d.matches && d.matches('details.i-dd') && d.open) {
                this.closeAll(d);
                const box = d.querySelector('[data-pc-find-box]');
                if (box) {
                    box.focus();
                }
            }
        };
        this.onFind = (e) => {
            if (e.target.matches && e.target.matches('[data-pc-find-box]')) {
                this.findIn(e.target.closest('details.i-dd'), e.target.value);
            }
        };
        this.onFindKey = (e) => {
            if ('Enter' !== e.key || !e.target.matches || !e.target.matches('[data-pc-find-box]')) {
                return;
            }
            e.preventDefault();
            const d = e.target.closest('details.i-dd');
            const first = Array.from(d.querySelectorAll('[data-pc-opt]')).filter((o) => !o.hidden && !o.closest('[hidden]'))[0];
            if (first) {
                this.choose(d, first);
            }
        };
        document.addEventListener('click', this.onClick);
        document.addEventListener('keydown', this.onKey);
        this.element.addEventListener('toggle', this.onToggle, true);
        this.formTarget.addEventListener('input', this.onFind);
        this.formTarget.addEventListener('keydown', this.onFindKey);

        this.registers = [];
        if (this.hasEveryTarget && this.everyTarget.querySelector('tbody')) {
            this.registers.push(this.register(this.everyTarget, 'everything'));
        }
        if (this.hasWorthTarget && this.worthTarget.querySelector('tbody')) {
            this.registers.push(this.register(this.worthTarget, 'worth'));
        }
        this.registers.forEach((r) => this.draw(r));

        if (this.hasAskedValue && this.askedValue.href) {
            this.remember(this.askedValue);
        }
        this.drawRecent();
        this.recentRegister = this.register(this.recentCardTarget, 'recent');
        this.registers.push(this.recentRegister);
        this.draw(this.recentRegister);
        window.requestAnimationFrame(() => this.index(3));
    }

    index(tries) {
        const nav = window.shellOnpage;
        if (!nav || !nav.row('check')) {
            if (tries > 0) {
                window.requestAnimationFrame(() => this.index(tries - 1));
            }
            return;
        }
        ['everything', 'worth'].forEach((id) => {
            const card = document.getElementById(id);
            nav.hide(id, !card || card.hidden);
        });
        this.countRecent();
    }

    disconnect() {
        document.removeEventListener('click', this.onClick);
        document.removeEventListener('keydown', this.onKey);
        this.element.removeEventListener('toggle', this.onToggle, true);
        this.formTarget.removeEventListener('input', this.onFind);
        this.formTarget.removeEventListener('keydown', this.onFindKey);
    }

    // ---- the form

    choose(d, option) {
        const axis = d.dataset.pcAxis;
        const value = option.dataset.pcOpt;
        this.mark(d, value);
        d.open = false;
        this.findIn(d, '');
        if ('person' === axis) {
            this.personTarget.value = value;
        } else if ('q' === axis) {
            this.questionTarget.value = value;
            this.swapTargets(this.element.querySelector('template[data-pc-targets="' + CSS.escape(value) + '"]') ? value : '');
        } else if ('target' === axis) {
            this.whereTarget.value = value;
        }
    }

    mark(d, value) {
        let label = null;
        d.querySelectorAll('[data-pc-opt]').forEach((o) => {
            const on = o.dataset.pcOpt === value;
            o.classList.toggle('on', on);
            o.setAttribute('aria-pressed', String(on));
            if (on) {
                label = o.dataset.pcLabel;
            }
        });
        const val = d.querySelector('.i-ddval');
        val.textContent = null === label ? (val.dataset.pcEmpty || '') : label.toLowerCase();
    }

    findIn(d, text) {
        const box = d ? d.querySelector('[data-pc-find-box]') : null;
        if (!box) {
            return;
        }
        box.value = text;
        const q = text.trim().toLowerCase();
        let any = false;
        d.querySelectorAll('.pp-ddg').forEach((g) => {
            let n = 0;
            g.querySelectorAll('[data-pc-opt]').forEach((o) => {
                const hit = !q || (o.dataset.pcFind || '').indexOf(q) > -1;
                o.hidden = !hit;
                if (hit) {
                    n += 1;
                }
            });
            g.hidden = 0 === n;
            if (n) {
                any = true;
            }
        });
        const none = d.querySelector('.pp-ddnone');
        if (none) {
            none.hidden = any;
        }
    }

    swapTargets(key) {
        const d = this.formTarget.querySelector('details[data-pc-axis="target"]');
        const list = d.querySelector('.pp-ddlist');
        if (list.dataset.pcKey === key) {
            return;
        }
        const template = this.element.querySelector('template[data-pc-targets="' + CSS.escape(key) + '"]');
        if (!template) {
            return;
        }
        list.replaceChildren(template.content.cloneNode(true));
        list.dataset.pcKey = key;
        const keep = list.querySelector('[data-pc-opt="' + CSS.escape(this.whereTarget.value) + '"]');
        this.whereTarget.value = keep ? this.whereTarget.value : (template.dataset.pcDefault || '');
        this.mark(d, this.whereTarget.value);
    }

    submit(event) {
        if (this.personTarget.value && this.questionTarget.value) {
            return;
        }
        event.preventDefault();
        const axis = this.personTarget.value ? 'q' : 'person';
        this.formTarget.querySelector('details[data-pc-axis="' + axis + '"]').open = true;
    }

    // ---- the registers

    register(card, kind) {
        const rows = Array.from(card.querySelectorAll('tbody > tr'));
        let size = null;
        const s = store();
        try {
            size = s ? s.getItem(SIZE + kind) : null;
        } catch (e) {
            size = null;
        }
        const r = { card, kind, rows, only: 'all', concern: '', sort: 'flagged', q: '', page: 1, size: size || String(this.pageValue) };
        const search = card.querySelector('[data-pc-search]');
        if (search) {
            search.addEventListener('input', () => {
                r.q = search.value.trim().toLowerCase();
                r.page = 1;
                this.draw(r);
            });
        }
        return r;
    }

    find(el) {
        return this.registers.filter((r) => r.card.contains(el))[0] || null;
    }

    byOnly(r, row, only) {
        const o = undefined === only ? r.only : only;
        if ('all' === o) {
            return true;
        }
        if ('everything' === r.kind) {
            if ('allowed' === o) {
                return row.classList.contains('ok');
            }
            if ('refused' === o) {
                return row.classList.contains('no');
            }
            return row.dataset.group === o;
        }
        return row.dataset.kind === o;
    }

    byConcern(r, row, concern) {
        const c = undefined === concern ? r.concern : concern;
        return 'everything' !== r.kind || !c || row.dataset.concern === c;
    }

    bySearch(r, row) {
        return !r.q || (row.dataset.find || '').indexOf(r.q) > -1;
    }

    fill(chip, current, count, label) {
        let chosen = null;
        chip.querySelectorAll('.i-ddopt').forEach((o) => {
            const on = o.dataset.pcOpt === current;
            o.classList.toggle('on', on);
            o.setAttribute('aria-pressed', String(on));
            const n = o.querySelector('.i-ddopt-n');
            if (n && count) {
                const k = count(o.dataset.pcOpt);
                n.textContent = String(k);
                o.classList.toggle('pq-zero', 0 === k);
            }
            if (on) {
                chosen = o;
            }
        });
        chip.querySelector('.i-ddval').textContent = label(chosen);
        chip.querySelector('summary').classList.toggle('on', null !== chosen && '' !== current && 'all' !== current && 'flagged' !== current);
    }

    draw(r) {
        const card = r.card;
        const only = card.querySelector('details[data-pc-axis$="-only"]');
        const base = r.rows.filter((row) => this.bySearch(r, row) && this.byConcern(r, row));
        const nOnly = (k) => base.filter((row) => this.byOnly(r, row, k)).length;
        this.fill(only, r.only, nOnly, (o) => ('all' === r.only || !o ? 'all' : o.dataset.pcLabel.toLowerCase()) + ' · ' + nOnly(r.only));

        const concern = card.querySelector('details[data-pc-axis="here-concern"]');
        if (concern) {
            const cbase = r.rows.filter((row) => this.bySearch(r, row) && this.byOnly(r, row));
            const nC = (k) => (k ? cbase.filter((row) => row.dataset.concern === k).length : cbase.length);
            this.fill(concern, r.concern, nC, (o) => (r.concern && o ? o.dataset.pcLabel + ' · ' + nC(r.concern) : 'resource'));
        }
        const sort = card.querySelector('details[data-pc-axis="worth-sort"]');
        if (sort) {
            this.fill(sort, r.sort, null, (o) => (o ? o.dataset.pcLabel : 'flagged first'));
        }

        const shown = base.filter((row) => this.byOnly(r, row));
        const ordered = shown.slice();
        if ('name' === r.sort) {
            ordered.sort((a, b) => a.dataset.person.localeCompare(b.dataset.person));
        } else if ('place' === r.sort) {
            ordered.sort((a, b) => a.dataset.place.localeCompare(b.dataset.place) || a.dataset.person.localeCompare(b.dataset.person));
        } else if ('everything' !== r.kind) {
            ordered.sort((a, b) => Number(a.dataset.order) - Number(b.dataset.order));
        }
        const per = 'all' === r.size ? Math.max(1, ordered.length) : Number(r.size) || this.pageValue;
        const pages = Math.max(1, Math.ceil(ordered.length / per));
        r.page = Math.min(Math.max(1, r.page), pages);
        const onPage = ordered.slice((r.page - 1) * per, r.page * per);
        const body = card.querySelector('tbody');
        r.rows.forEach((row) => { row.hidden = onPage.indexOf(row) < 0; });
        onPage.forEach((row) => body.appendChild(row));

        const n = ordered.length;
        const nouns = { everything: [' permission', ' permissions'], worth: [' person', ' people'], recent: [' check', ' checks'] };
        const noun = nouns[r.kind][1 === n ? 0 : 1];
        card.querySelector('[data-pc-count]').textContent = n + noun;
        const size = card.querySelector('details[data-pc-size]');
        const summary = size.querySelector('summary');
        const pg = document.createElement('span');
        pg.className = 'pg';
        pg.textContent = r.size;
        summary.replaceChildren(...('all' === r.size ? ['all on one page'] : [pg, ' per page']), summary.querySelector('.i-ddcaret'));
        size.querySelectorAll('[data-pc-per]').forEach((o) => {
            const on = o.dataset.pcPer === r.size;
            o.classList.toggle('on', on);
            o.setAttribute('aria-pressed', String(on));
        });
        card.querySelector('[data-pc-page]').innerHTML = (r.page > 1 ? '<button type="button" data-pc-go="-1" aria-label="Previous page">&lsaquo;</button>' : '')
            + 'page <span class="pg">' + r.page + '</span> of ' + pages
            + (r.page < pages ? '<button type="button" data-pc-go="1" aria-label="Next page">&rsaquo;</button>' : '');

        card.querySelector('table').hidden = 0 === n;
        card.querySelector('.rdf-foot').hidden = 0 === n;
        card.querySelector('.pq-empty').hidden = n > 0 || 0 === r.rows.length;
        const shownLine = card.querySelector('[data-pc-shown]');
        if (shownLine) {
            const filtered = !!r.q || !!r.concern || 'all' !== r.only;
            shownLine.replaceChildren();
            if (filtered) {
                const b = document.createElement('b');
                b.textContent = String(n);
                shownLine.append(b, ' shown · ');
            }
        }
    }

    click(event) {
        const t = event.target;
        if (!t.closest || !this.element.contains(t)) {
            this.closeAll(null);
            return;
        }
        const formOption = this.formTarget.contains(t) ? t.closest('[data-pc-opt]') : null;
        if (formOption) {
            this.choose(formOption.closest('details.i-dd'), formOption);
            return;
        }
        const r = this.find(t);
        const option = t.closest('[data-pc-opt]');
        if (r && option) {
            const axis = option.closest('details').dataset.pcAxis;
            if (axis.endsWith('-only')) {
                r.only = option.dataset.pcOpt;
            } else if ('here-concern' === axis) {
                r.concern = option.dataset.pcOpt;
            } else if ('worth-sort' === axis) {
                r.sort = option.dataset.pcOpt;
            }
            r.page = 1;
            this.closeAll(null);
            this.draw(r);
            return;
        }
        const per = t.closest('[data-pc-per]');
        if (r && per) {
            r.size = per.dataset.pcPer;
            r.page = 1;
            const s = store();
            try {
                if (s) {
                    s.setItem(SIZE + r.kind, r.size);
                }
            } catch (e) {
                // a session that keeps nothing keeps the default
            }
            this.closeAll(null);
            this.draw(r);
            return;
        }
        const go = t.closest('[data-pc-go]');
        if (r && go) {
            r.page += Number(go.dataset.pcGo);
            this.draw(r);
            const next = r.card.querySelector('[data-pc-go="' + go.dataset.pcGo + '"]') || r.card.querySelector('[data-pc-go]');
            if (next) {
                next.focus();
            }
            return;
        }
        this.closeAll(t.closest('details.i-dd'));
    }

    closeAll(except) {
        this.element.querySelectorAll('details.i-dd[open]').forEach((d) => {
            if (d !== except) {
                d.open = false;
            }
        });
    }

    run(event) {
        const row = event.target.closest('tr');
        const link = row ? row.querySelector('a.more') : null;
        if (!link) {
            return;
        }
        if (event.target.closest('a') && (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || 0 !== event.button)) {
            return;
        }
        event.preventDefault();
        window.location.assign(link.href);
    }

    // ---- recent checks

    recent() {
        const s = store();
        try {
            const list = s ? JSON.parse(s.getItem(RECENT) || '[]') : [];
            return Array.isArray(list) ? list : [];
        } catch (e) {
            return [];
        }
    }

    remember(asked) {
        const d = new Date();
        const pad = (n) => (n < 10 ? '0' : '') + n;
        const entry = {
            person: asked.person, words: asked.words, question: asked.question, place: asked.place, allowed: !!asked.allowed,
            href: asked.href, at: pad(d.getHours()) + ':' + pad(d.getMinutes()),
        };
        const list = [entry].concat(this.recent().filter((x) => x.href !== entry.href));
        const s = store();
        try {
            if (s) {
                s.setItem(RECENT, JSON.stringify(list));
            }
        } catch (e) {
            // a session that keeps nothing shows nothing
        }
    }

    drawRecent() {
        const list = this.recent();
        const cell = (text, sub) => {
            const td = document.createElement('td');
            td.textContent = text;
            if (sub) {
                const s = document.createElement('span');
                s.className = 'pp-sub';
                s.textContent = sub;
                td.append(s);
            }
            return td;
        };
        this.recentTarget.replaceChildren(...list.map((r, i) => {
            const words = r.words || [r.question, r.place].filter(Boolean).join(' · ');
            const tr = document.createElement('tr');
            tr.dataset.kind = r.allowed ? 'allowed' : 'refused';
            tr.dataset.order = String(i);
            tr.dataset.person = r.person || '';
            tr.dataset.place = r.place || '';
            tr.dataset.find = ((r.person || '') + ' ' + words + ' ' + (r.place || '')).toLowerCase();
            const verdict = document.createElement('td');
            verdict.className = 'pp-vd';
            const chip = document.createElement('span');
            chip.className = 'au-v pp-v ' + (r.allowed ? 'ok' : 'no');
            chip.textContent = r.allowed ? 'allowed' : 'refused';
            verdict.append(chip);
            const door = document.createElement('td');
            door.className = 'pp-door';
            const a = document.createElement('a');
            a.className = 'more';
            a.href = r.href;
            a.textContent = 'Run →';
            door.append(a);
            tr.append(cell(r.person || '', r.at || ''), cell(words), verdict, door);
            return tr;
        }));
        this.noneTarget.hidden = list.length > 0;
    }

    countRecent() {
        if (window.shellOnpage) {
            const n = this.recent().length;
            window.shellOnpage.count('recent', n || null);
        }
    }
}
