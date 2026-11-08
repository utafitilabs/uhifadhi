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
 * THE FOLD, the part CSS cannot do (uhifadhi-web/house-fold.js). Its rules are
 * the shell sheet's "HOUSE — THE FOLD" block. Mounted once, on <html>, by the
 * document; a page opts in by doing nothing.
 *
 * 1. html.fold-ready after the first paint: the gate for every transition in
 *    the sheet except a <details>, so nothing animates as a page loads.
 * 2. Width folds: when #side gains or loses .rail, or <html> gains or loses
 *    .onpage-closed, the sheet eases the width; here the folded container's
 *    content fades in over the same 200ms, so labels swapping for icons never
 *    read as a jump. The container is #side, or the page's .onpage-nav.
 * 3. Table rows: a <tr> whose hidden attribute changes, by any script, folds.
 *    The rows stay drawn while they close; the rows under them slide up into
 *    their place and the table's bottom margin follows, so nothing below the
 *    table snaps. Opening is the same, reversed.
 * 4. <details> where ::details-content is not supported: the content fades in.
 * Reduced motion: none of this runs; every fold is instant.
 *
 * @see https://stimulus.hotwired.dev/reference/lifecycle-callbacks
 * @see https://developer.mozilla.org/en-US/docs/Web/API/Element/animate
 */

const D = 200;
const READY = 'fold-ready';

export default class extends Controller {
    connect() {
        this.root = document.documentElement;
        this.still = window.matchMedia('(prefers-reduced-motion: reduce)');
        this.mine = new WeakSet();
        this.observers = [];

        this.frame = requestAnimationFrame(() => {
            this.frame = requestAnimationFrame(() => this.root.classList.add(READY));
        });

        const side = document.getElementById('side');
        this.watchClass(side, 'rail', () => side);
        this.watchClass(this.root, 'onpage-closed', () => document.querySelector('.onpage-nav'));
        this.watchRows();
        if (!window.CSS || !CSS.supports('selector(::details-content)')) {
            this.onToggle = (event) => this.detailsFallback(event);
            document.addEventListener('toggle', this.onToggle, true);
        }
    }

    disconnect() {
        cancelAnimationFrame(this.frame);
        this.observers.forEach((observer) => observer.disconnect());
        this.observers = [];
        if (this.onToggle) {
            document.removeEventListener('toggle', this.onToggle, true);
        }
        this.root.classList.remove(READY);
    }

    quiet() {
        return this.still.matches || !this.root.classList.contains(READY);
    }

    /* ---- 2. width folds ------------------------------------------------- */
    fade(element, opening) {
        if (this.quiet() || !element || !element.animate) {
            return;
        }
        element.animate([{ opacity: 0 }, { opacity: 1 }], { duration: D, easing: opening ? 'ease-out' : 'ease-in' });
    }

    watchClass(element, name, target) {
        if (!element) {
            return;
        }
        let was = element.classList.contains(name);
        const observer = new MutationObserver(() => {
            const now = element.classList.contains(name);
            if (now === was) {
                return;
            }
            was = now;
            this.fade(target(), !now);
        });
        observer.observe(element, { attributes: true, attributeFilter: ['class'] });
        this.observers.push(observer);
    }

    /* ---- 3. table rows -------------------------------------------------- */
    setHidden(row, hidden) {
        this.mine.add(row);
        row.hidden = hidden;
    }

    watchRows() {
        const observer = new MutationObserver((records) => {
            const groups = new Map();
            records.forEach((record) => {
                const row = record.target;
                if ('TR' !== row.tagName) {
                    return;
                }
                if (this.mine.has(row)) {
                    this.mine.delete(row);
                    return;
                }
                const nowHidden = row.hidden;
                const wasHidden = null !== record.oldValue;
                if (nowHidden === wasHidden || this.quiet()) {
                    return;
                }
                const body = row.parentNode;
                if (!groups.has(body)) {
                    groups.set(body, { open: [], shut: [] });
                }
                groups.get(body)[nowHidden ? 'shut' : 'open'].push(row);
            });
            groups.forEach((group, body) => {
                if (group.shut.length) {
                    this.foldRows(body, group.shut, false);
                }
                if (group.open.length) {
                    this.foldRows(body, group.open, true);
                }
            });
        });
        observer.observe(document.body, { attributes: true, attributeFilter: ['hidden'], attributeOldValue: true, subtree: true });
        this.observers.push(observer);
    }

    foldRows(body, rows, opening) {
        const table = body.closest('table');
        if (!opening) {
            rows.forEach((row) => this.setHidden(row, false));
        }
        let height = 0;
        rows.forEach((row) => { height += row.getBoundingClientRect().height; });
        if (!height || !table) {
            if (!opening) {
                rows.forEach((row) => this.setHidden(row, true));
            }
            return;
        }
        const below = [];
        let next = rows[rows.length - 1].nextElementSibling;
        while (next) {
            if (!next.hidden) {
                below.push(next);
            }
            next = next.nextElementSibling;
        }
        const options = { duration: D, easing: opening ? 'ease-out' : 'ease-in' };
        const slide = opening
            ? [{ transform: `translateY(${-height}px)` }, { transform: 'none' }]
            : [{ transform: 'none' }, { transform: `translateY(${-height}px)` }];
        const fade = opening ? [{ opacity: 0 }, { opacity: 1 }] : [{ opacity: 1 }, { opacity: 0 }];
        const margin = parseFloat(getComputedStyle(table).marginBottom) || 0;
        const follow = opening
            ? [{ marginBottom: `${margin - height}px` }, { marginBottom: `${margin}px` }]
            : [{ marginBottom: `${margin}px` }, { marginBottom: `${margin - height}px` }];
        rows.forEach((row) => row.animate(fade, options));
        below.forEach((row) => row.animate(slide, options));
        table.animate(follow, options).onfinish = () => {
            if (!opening) {
                rows.forEach((row) => this.setHidden(row, true));
            }
        };
    }

    /* ---- 4. <details> without ::details-content -------------------------- */
    detailsFallback(event) {
        const details = event.target;
        if ('DETAILS' !== details.tagName || !details.open || this.quiet()) {
            return;
        }
        [...details.children].forEach((child) => {
            if ('SUMMARY' !== child.tagName && child.animate) {
                child.animate([{ opacity: 0 }, { opacity: 1 }], { duration: D, easing: 'ease-out' });
            }
        });
    }
}
