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
 * ONE CONTROL MOVES A ROW WITHIN AN ORDERED LIST — the ranks ladder, an area's
 * running modules, and any list a module draws the same way.
 *
 * THREE WAYS TO MOVE, ONE RESULT. A pointer drag on the grip (mouse, pen or
 * touch), the up and down carets beside it, and the arrow keys while the grip
 * has focus. A caret or a key moves the row one step; a drag moves it to
 * wherever it is released. Every move repaints the numbers from the rows'
 * places, disables the first row's up and the last row's down, and says what
 * happened in the polite live line.
 *
 * THE DRAG SHOWS WHERE THE ROW WILL LAND. The row lifts off the list and
 * rides the pointer (its y only), and an empty dashed slot of its own height
 * opens where it would land, closing behind it as the pointer moves on. On
 * release the row settles into the slot. The values are the design's
 * (DesignsProjects/uhifadhi-web/reorder.css) and live in shell.css; this file
 * only adds and removes the classes and holds the row where the pointer is.
 *
 * THE MARKUP
 *   data-controller="uhifadhi--shell-bundle--reorder" on the element holding
 *     the list, with -url-value and -token-value where every move is posted,
 *     and -first-value where the first movable row is not number 1 (a list
 *     whose pinned row holds the front)
 *   -target="row" on every movable row, with data-reorder-name (its label)
 *     and data-reorder-key (what is posted for it)
 *   -target="grip" on the grip button, with the pointer and key actions
 *   -target="up" / "down" on the two carets, with #up / #down
 *   -target="number" on the row's number, if it shows one
 *   -target="status" on one visually hidden aria-live="polite" line
 *
 * THE ORDER IS THE PAGE'S TO SEND. A form reads the order from its own field
 * order and sends it with its Save, so nothing is posted here. A list that
 * saves as it moves names a URL, and after every move the rows' keys are
 * posted as `order[]`, each write chained after the last so an earlier order
 * never lands after a later one. The response is not read: the page already
 * shows the outcome. Nothing is remembered in the browser.
 *
 * WITH SCRIPTING OFF nothing moves, and nothing else is lost: a form still
 * saves its fields, and a list's switches are real forms of their own.
 *
 * @see https://stimulus.hotwired.dev/reference/targets "Define a method
 *      `[name]TargetConnected` or `[name]TargetDisconnected` in the
 *      controller" — the grip gets its touch-action there.
 * @see https://stimulus.hotwired.dev/reference/actions — the KeyboardEvent
 *      filter ("up", "down", "esc"; mapped to ArrowUp / ArrowDown / Escape in
 *      @hotwired/stimulus's defaultSchema.keyMappings) and ":prevent", which
 *      "calls .preventDefault() on the event before invoking the method".
 * @see https://developer.mozilla.org/en-US/docs/Web/API/Element/setPointerCapture
 *      "Subsequent events for the pointer will be targeted at the capture
 *      element until capture is released"
 * @see https://developer.mozilla.org/en-US/docs/Web/CSS/touch-action `none`:
 *      "Disable browser handling of all panning and zooming gestures", so an
 *      application "can supply its own behavior in pointermove and pointerup
 *      listeners".
 * @see https://developer.mozilla.org/en-US/docs/Web/Accessibility/ARIA/Reference/Attributes/aria-live
 *      polite: "updates to the region should be presented at the next
 *      graceful opportunity"
 */

/* How far the pointer travels before a press on the grip becomes a drag. */
const THRESHOLD = 4;

/* The settle, in step with shell.css's `.reorder-settling` (.18s). */
const SETTLE = 180;

/* A div row stands this far proud of the list on each side while it is lifted. */
const PROUD = 8;

export default class extends Controller {
    static targets = ['row', 'grip', 'up', 'down', 'number', 'status'];
    static values = { url: String, token: String, first: { type: Number, default: 1 } };

    connect() {
        this.drag = null;
        this.settling = false;
        this.sending = Promise.resolve();
        this.paint();
    }

    disconnect() {
        if (this.drag) {
            this.cancel();
        }
    }

    gripTargetConnected(grip) {
        grip.style.touchAction = 'none';
    }

    // ---- one step: the carets and the arrow keys -----------------------

    up(event) {
        this.step(event, -1);
    }

    down(event) {
        this.step(event, 1);
    }

    step(event, by) {
        const pressed = event.currentTarget;
        const row = this.rowOf(pressed);
        if (!row || this.drag || this.settling) {
            return;
        }
        const rows = this.rowTargets;
        const to = rows.indexOf(row) + by;
        if (to < 0 || to >= rows.length) {
            return;
        }
        if (by < 0) {
            rows[to].before(row);
        } else {
            rows[to].after(row);
        }
        this.moved(row);

        // Moving a row takes focus out of it; it goes back to what was
        // pressed, or to the grip when that caret has just become an end.
        const next = pressed.disabled ? this.gripOf(row) : pressed;
        next?.focus({ preventScroll: true });
    }

    // ---- the drag ---------------------------------------------------------

    grab(event) {
        if (this.drag || this.settling || event.button !== 0) {
            return;
        }
        const grip = event.currentTarget;
        const row = this.rowOf(grip);
        if (!row) {
            return;
        }
        event.preventDefault();
        grip.focus({ preventScroll: true });
        grip.setPointerCapture(event.pointerId);
        this.drag = {
            row,
            grip,
            pointer: event.pointerId,
            startY: event.clientY,
            from: this.rowTargets.indexOf(row),
            lifted: false,
            slot: null,
            styled: [],
        };
    }

    move(event) {
        const drag = this.drag;
        if (!drag || event.pointerId !== drag.pointer) {
            return;
        }
        const dy = event.clientY - drag.startY;
        if (!drag.lifted) {
            if (Math.abs(dy) < THRESHOLD) {
                return;
            }
            this.lift(drag);
        }
        drag.row.style.transform = `translateY(${dy}px)`;
        this.place(event.clientY);
    }

    drop(event) {
        const drag = this.drag;
        if (!drag || event.pointerId !== drag.pointer) {
            return;
        }
        this.drag = null;
        if (!drag.lifted) {
            return;
        }

        const land = () => {
            this.settling = false;
            this.unlift(drag);
            drag.slot.replaceWith(drag.row);
            if (this.rowTargets.indexOf(drag.row) !== drag.from) {
                this.moved(drag.row);
            } else {
                this.paint();
            }
            drag.grip.focus({ preventScroll: true });
        };

        if (this.still()) {
            land();

            return;
        }
        this.settling = true;
        const home = drag.slot.getBoundingClientRect().top - drag.top;
        drag.row.classList.add('reorder-settling');
        drag.row.style.transform = `translateY(${home}px)`;
        window.setTimeout(land, SETTLE);
    }

    /* Escape, a cancelled pointer or a lost capture: the row goes back where it was. */
    cancel(event) {
        const drag = this.drag;
        if (!drag || (event?.pointerId !== undefined && event.pointerId !== drag.pointer)) {
            return;
        }
        this.drag = null;
        if (!drag.lifted) {
            return;
        }
        this.unlift(drag);
        drag.slot.remove();
        const others = this.rowTargets.filter((row) => row !== drag.row);
        if (drag.from < others.length) {
            others[drag.from].before(drag.row);
        } else if (others.length > 0) {
            others[others.length - 1].after(drag.row);
        }
        this.paint();
    }

    lift(drag) {
        const row = drag.row;
        const box = row.getBoundingClientRect();
        drag.lifted = true;
        drag.top = box.top;

        drag.slot = this.slot(row, box.height, false);
        row.before(drag.slot);

        const set = (element, property, value) => {
            element.style.setProperty(property, value);
            drag.styled.push([element, property]);
        };
        let left = box.left;
        let width = box.width;
        if (row.tagName === 'TR') {
            // A row out of its table keeps its columns only if each cell is
            // held at the width it had, in a box of its own.
            Array.from(row.cells).forEach((cell) => set(cell, 'width', `${cell.getBoundingClientRect().width}px`));
            set(row, 'display', 'table');
            set(row, 'table-layout', 'fixed');
        } else {
            const style = window.getComputedStyle(row);
            set(row, 'padding-left', `${parseFloat(style.paddingLeft) + PROUD}px`);
            set(row, 'padding-right', `${parseFloat(style.paddingRight) + PROUD}px`);
            left -= PROUD;
            width += PROUD * 2;
        }
        set(row, 'position', 'fixed');
        set(row, 'left', `${left}px`);
        set(row, 'top', `${box.top}px`);
        set(row, 'width', `${width}px`);
        set(row, 'box-sizing', 'border-box');
        set(row, 'transform', 'none');
        row.classList.add('reorder-lifted');
    }

    unlift(drag) {
        drag.row.classList.remove('reorder-lifted', 'reorder-settling');
        drag.styled.forEach(([element, property]) => element.style.removeProperty(property));
        drag.styled = [];
    }

    /* Moves the slot to where the pointer is, if that is somewhere new. */
    place(y) {
        const drag = this.drag;
        const others = this.rowTargets.filter((row) => row !== drag.row);
        let to = others.findIndex((row) => {
            const box = row.getBoundingClientRect();

            return y < box.top + box.height / 2;
        });
        if (to === -1) {
            to = others.length;
        }
        if (to === this.slotIndex(others)) {
            return;
        }

        const left = drag.slot;
        drag.slot = this.slot(drag.row, left.style.getPropertyValue('--reorder-h'), true);
        if (to < others.length) {
            others[to].before(drag.slot);
        } else {
            others[others.length - 1].after(drag.slot);
        }

        if (this.still()) {
            left.remove();
        } else {
            left.classList.add('closing');
            window.setTimeout(() => left.remove(), SETTLE);
        }
        this.paint();
    }

    /* How many of the other rows stand before the slot. */
    slotIndex(others) {
        const slot = this.drag.slot;

        return others.filter((row) => row.compareDocumentPosition(slot) & Node.DOCUMENT_POSITION_FOLLOWING).length;
    }

    /*
     * An empty place of the row's own height, in the row's own kind of
     * element: a table row holds one cell across the table.
     */
    slot(row, height, opening) {
        const slot = document.createElement(row.tagName === 'TR' ? 'tr' : 'div');
        slot.classList.add('reorder-slot');
        slot.setAttribute('aria-hidden', 'true');
        slot.style.setProperty('--reorder-h', typeof height === 'number' ? `${height}px` : height);

        const gap = document.createElement('div');
        gap.classList.add('reorder-gap');
        if (!opening) {
            // The first slot is where the row already was: it is open, not opening.
            gap.style.animation = 'none';
        }
        if (row.tagName === 'TR') {
            const cell = document.createElement('td');
            cell.colSpan = row.cells.length;
            cell.append(gap);
            slot.append(cell);
        } else {
            slot.append(gap);
        }

        return slot;
    }

    // ---- what every move does ------------------------------------------------

    moved(row) {
        this.paint();
        this.announce(row);
        this.send();
    }

    /* The rows as they will stand: while a drag is on, the lifted row stands where its slot is. */
    order() {
        const drag = this.drag;
        if (!drag || !drag.lifted) {
            return this.rowTargets;
        }
        const others = this.rowTargets.filter((row) => row !== drag.row);
        others.splice(this.slotIndex(others), 0, drag.row);

        return others;
    }

    paint() {
        const rows = this.order();
        const last = rows.length - 1;
        rows.forEach((row, index) => {
            this.numberTargets.filter((number) => row.contains(number)).forEach((number) => {
                number.textContent = String(index + this.firstValue);
            });
            this.upTargets.filter((up) => row.contains(up)).forEach((up) => {
                up.disabled = index === 0;
            });
            this.downTargets.filter((down) => row.contains(down)).forEach((down) => {
                down.disabled = index === last;
            });
        });
    }

    announce(row) {
        if (!this.hasStatusTarget) {
            return;
        }
        const place = this.rowTargets.indexOf(row) + this.firstValue;
        this.statusTarget.textContent = `${row.dataset.reorderName ?? 'Row'} moved to position ${place}`;
    }

    send() {
        if (!this.hasUrlValue || this.urlValue === '') {
            return;
        }
        const body = new URLSearchParams();
        body.append('_token', this.tokenValue);
        this.rowTargets.forEach((row) => body.append('order[]', row.dataset.reorderKey ?? ''));

        const url = this.urlValue;
        this.sending = this.sending
            .then(() => fetch(url, { method: 'POST', body, credentials: 'same-origin' }))
            .catch(() => {});
    }

    // ---- helpers ---------------------------------------------------------------

    rowOf(element) {
        return this.rowTargets.find((row) => row.contains(element)) ?? null;
    }

    gripOf(row) {
        return this.gripTargets.find((grip) => row.contains(grip)) ?? null;
    }

    still() {
        return window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    }
}
