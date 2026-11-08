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
 * A SETTINGS CASCADE IS LIVE (uhifadhi-web/settings/cascade.js): a setting's
 * name opens and shuts its places; on a Configure page its values change, a
 * change is marked, and the save bar counts, reviews, discards and saves.
 *
 * THE MARKUP — `settings/_cascade.html.twig`; nothing else is wired by hand.
 *   a setting      tbody tr[data-setting] holding button.mrow[aria-expanded];
 *                  its name is .cmname > b
 *   its places     the tr.dk rows after it; the default's is tr.dk[data-default]
 *                  and holds its one control; an area's is tr.dk[data-place]
 *   a figure       .ed-in > input, its unit in .ed-in > .u; an empty
 *                  default reads as its data-unset (what unset means), said
 *                  under it by .ed-hint[data-means-line]
 *   follows        .ed-line > .ed-follow + .btn.sm.ed-custom: the click puts
 *                  the default's control there, preset to its value and named
 *                  area[<key>][<uuid>]
 *   own value      .ed-line > .ed-in + .btn.sm.ed-reset: the click puts the
 *                  follows text back, so the area posts no field
 *   the summary    the setting row's .lvc.org .valf repeats the default, its
 *                  .lvc.area .sumc reads "n of m areas set their own"
 *   scrolling      .dw-scroll takes .scrolled while off its left edge
 *   the bar        .ed-bar: b (the count), .ed-btn.ghost Discard, .ed-btn
 *                  Review, .ed-btn.pri Save (the form's submit); .refused
 *                  while it shows the store's reason
 *
 * A CHANGE is a place whose value differs from the page as drawn: an .ed-dot
 * beside its control, .chg on its field. The bar reads "No unsaved changes",
 * "1 unsaved change", "n unsaved changes", or "Saved" after a save.
 */
export default class extends Controller {
    static values = { saved: Boolean };

    connect() {
        this.table = this.element.querySelector('.tbl.dw');
        if (!this.table || !this.table.tBodies[0]) {
            return;
        }
        this.tbody = this.table.tBodies[0];

        this.onClick = (event) => this.click(event);
        this.onInput = (event) => this.input(event);
        this.tbody.addEventListener('click', this.onClick);
        this.tbody.addEventListener('input', this.onInput);

        this.scroller = this.table.closest('.dw-scroll');
        if (this.scroller) {
            this.onScroll = () => this.scroller.classList.toggle('scrolled', this.scroller.scrollLeft > 0);
            this.scroller.addEventListener('scroll', this.onScroll, { passive: true });
            this.onScroll();
        }

        this.bar = this.element.querySelector('.ed-bar');
        if (!this.bar) {
            return;
        }
        this.count = this.bar.querySelector('b');
        this.discardButton = this.bar.querySelector('.ed-btn.ghost');
        this.saveButton = this.bar.querySelector('.ed-btn.pri');
        this.reviewButton = [...this.bar.querySelectorAll('.ed-btn')].find((b) => b !== this.discardButton && b !== this.saveButton);
        this.refusal = this.bar.classList.contains('refused') ? this.count.textContent : null;
        this.justSaved = this.savedValue;

        this.review = document.createElement('div');
        this.review.className = 'c ed-reviewcard';
        this.review.hidden = true;
        this.review.innerHTML = '<span class="tab">Review<span class="src"></span></span><ul class="ed-review"></ul>';
        this.bar.after(this.review);

        this.onDiscard = () => {
            this.tbody.innerHTML = this.snapshot;
            this.justSaved = false;
            this.update();
        };
        this.onReview = () => {
            this.review.hidden = !this.review.hidden;
            this.update();
        };
        this.discardButton?.addEventListener('click', this.onDiscard);
        this.reviewButton?.addEventListener('click', this.onReview);

        this.saved = this.state();
        this.snapshot = this.tbody.innerHTML;
        this.update();
    }

    disconnect() {
        this.tbody?.removeEventListener('click', this.onClick);
        this.tbody?.removeEventListener('input', this.onInput);
        this.scroller?.removeEventListener('scroll', this.onScroll);
        this.discardButton?.removeEventListener('click', this.onDiscard);
        this.reviewButton?.removeEventListener('click', this.onReview);
        this.review?.remove();
    }

    /* ---- open / shut, and the two doors -------------------------------- */
    click(event) {
        const name = event.target.closest('button.mrow');
        if (name && this.tbody.contains(name)) {
            this.toggle(name);
            return;
        }
        if (!this.bar) {
            return;
        }
        const button = event.target.closest('button');
        if (!button || !this.tbody.contains(button)) {
            return;
        }
        if (button.classList.contains('ed-custom')) {
            this.makeOwn(button);
        } else if (button.classList.contains('ed-reset')) {
            this.makeFollow(button);
        }
    }

    toggle(name) {
        const row = name.closest('tr');
        const kids = this.placesRows(row);
        if (!kids.length) {
            return;
        }
        const open = !row.classList.contains('open');
        row.classList.toggle('open', open);
        name.setAttribute('aria-expanded', open ? 'true' : 'false');
        kids.forEach((kid) => { kid.hidden = !open; });
    }

    input(event) {
        if (this.bar && 'INPUT' === event.target.tagName) {
            this.update();
        }
    }

    /* ---- reading the table --------------------------------------------- */
    placesRows(row) {
        const out = [];
        let next = row.nextElementSibling;
        while (next && next.classList.contains('dk')) {
            out.push(next);
            next = next.nextElementSibling;
        }

        return out;
    }

    places() {
        const out = [];
        let setting = null;
        let main = null;
        [...this.tbody.rows].forEach((row) => {
            if (row.querySelector('.mrow')) {
                setting = row.querySelector('.cmname b').textContent.trim();
                main = row;
                return;
            }
            if (!row.classList.contains('dk') || !setting) {
                return;
            }
            const isDefault = row.hasAttribute('data-default');
            const cn = row.querySelector('.cn');
            const name = isDefault ? 'Default' : [...(cn ? cn.childNodes : [])]
                .filter((node) => 3 === node.nodeType).map((node) => node.textContent).join('').trim();
            out.push({ row, main, setting, place: name, isDefault });
        });

        return out;
    }

    control(row) {
        return row.querySelector('.ed-in, .ed-follow');
    }

    valueOf(element) {
        if (!element) {
            return null;
        }
        if (element.classList.contains('ed-follow')) {
            return null;
        }
        const input = element.querySelector('input');
        const unit = element.querySelector('.u');
        const figure = input.value.trim();
        if ('' === figure) {
            return input.dataset.unset || '';
        }

        return figure + (unit ? ' ' + unit.textContent.trim() : '');
    }

    key(place) {
        return place.setting + '\u0001' + place.place;
    }

    state() {
        const map = {};
        this.places().forEach((place) => {
            if (this.control(place.row)) {
                map[this.key(place)] = this.valueOf(this.control(place.row));
            }
        });

        return map;
    }

    defaultOf(main) {
        const found = this.places().find((place) => place.main === main && place.isDefault);

        return found ? found.row.querySelector('.ed-in') : null;
    }

    shown(value) {
        return null === value ? 'the default' : value;
    }

    /* ---- marking -------------------------------------------------------- */
    update() {
        const changes = [];
        const all = this.places();
        all.forEach((place) => {
            const element = this.control(place.row);
            if (!element) {
                return;
            }
            const key = this.key(place);
            const now = this.valueOf(element);
            const was = this.saved[key];
            place.row.querySelectorAll('.ed-dot').forEach((dot) => dot.remove());
            const changed = undefined !== was && now !== was;
            if (element.classList.contains('ed-in')) {
                element.classList.toggle('chg', changed);
            }
            if (changed) {
                const dot = document.createElement('span');
                dot.className = 'ed-dot';
                element.after(dot);
                const own = (value) => (null === value || place.isDefault ? this.shown(value) : 'this area’s own ' + value);
                changes.push({ place, from: own(was), to: own(now) });
            }
        });

        [...this.tbody.rows].filter((row) => row.querySelector('.mrow')).forEach((main) => {
            const areas = all.filter((place) => place.main === main && !place.isDefault && this.control(place.row));
            const summary = main.querySelector('.lvc.area .sumc');
            if (summary && areas.length) {
                const n = areas.filter((place) => null !== this.valueOf(this.control(place.row))).length;
                summary.textContent = n + ' of ' + areas.length + ' areas set their own';
            }
            const value = this.valueOf(this.defaultOf(main));
            if (null === value) {
                return;
            }
            const echo = main.querySelector('.lvc.org .valf');
            if (echo) {
                echo.textContent = value;
            }
            areas.forEach((place) => {
                const follows = place.row.querySelector('.ed-follow .valf');
                if (follows) {
                    follows.textContent = value;
                }
                const reset = place.row.querySelector('.ed-reset');
                if (reset) {
                    reset.title = 'Go back to ' + value + ', the default';
                }
            });
        });

        const n = changes.length;
        if (n) {
            this.justSaved = false;
            this.refusal = null;
            this.bar.classList.remove('refused');
        }
        this.count.textContent = n ? n + ' unsaved change' + (1 === n ? '' : 's')
            : (this.refusal ?? (this.justSaved ? 'Saved' : 'No unsaved changes'));
        [this.discardButton, this.reviewButton, this.saveButton].forEach((button) => {
            if (button) {
                button.disabled = !n;
            }
        });

        const list = this.review.querySelector('.ed-review');
        list.innerHTML = '';
        changes.forEach((change) => {
            const item = document.createElement('li');
            const name = document.createElement('b');
            const what = document.createElement('em');
            name.textContent = change.place.place;
            what.textContent = change.from + ' → ' + change.to;
            item.append(name, document.createTextNode(' · ' + change.place.setting + ': '), what);
            list.appendChild(item);
        });
        this.review.querySelector('.tab .src').textContent = ' · ' + n + ' change' + (1 === n ? '' : 's');
        if (!n) {
            this.review.hidden = true;
        }
        if (this.reviewButton) {
            this.reviewButton.textContent = this.review.hidden ? 'Review ▴' : 'Review ▾';
        }
    }

    /* ---- changing ------------------------------------------------------- */
    makeOwn(button) {
        const row = button.closest('tr');
        const place = this.places().find((p) => p.row === row);
        const fromDefault = place && this.defaultOf(place.main);
        if (!fromDefault) {
            return;
        }
        const made = fromDefault.cloneNode(true);
        made.classList.remove('chg');
        const input = made.querySelector('input');
        input.value = fromDefault.querySelector('input').value;
        input.name = 'area[' + row.dataset.setting + '][' + row.dataset.place + ']';
        input.required = true;
        delete input.dataset.unset;
        input.setAttribute('aria-label', place.setting + ' — ' + place.place);

        const reset = document.createElement('button');
        reset.type = 'button';
        reset.className = 'btn sm ed-reset';
        reset.textContent = 'Use the default';
        const line = button.closest('.ed-line');
        line.replaceChildren(made, reset);
        this.update();
        input.focus();
    }

    makeFollow(reset) {
        const line = reset.closest('.ed-line');
        line.innerHTML = '<span class="ed-follow"><span class="valf"></span> <span class="ed-fa"></span></span>'
            + '<button type="button" class="btn sm ed-custom"></button>';
        line.querySelector('.ed-custom').textContent = 'Set this area’s own';
        line.querySelector('.ed-fa').textContent = '· the default';
        this.update();
        line.querySelector('.ed-custom').focus();
    }
}
