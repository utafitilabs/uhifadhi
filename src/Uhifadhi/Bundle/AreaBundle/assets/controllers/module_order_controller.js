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
 * THE ORDER AN AREA SHOWS ITS MODULES IN, SET BY DRAGGING.
 *
 * ANY NUMBER OF LISTS, ONE ORDER. A surface may draw the running set more than
 * once, and dragging in any list moves every list, or the page would show a
 * person two different answers to the same question. Rows are matched by SLUG,
 * which is also what the reorder route takes, so nothing here knows an
 * assignment's identity. Only a row that carries a slug is armed: a parked
 * row, or a pinned one, carries none and is never moved.
 *
 * THE POSITION IS REDRAWN WITH THE ROW. A register prints each running row's
 * place in the order; after a drag the places are renumbered from the list
 * the drag happened in, so the numbers and the rows never disagree.
 *
 * THE ONLY WRITE THAT NEEDS SCRIPTING, and the only one that does. Switching a
 * module on or off is a real form with a real submit button and works with
 * scripting off; a register whose only way to park a module was a drag would
 * be unreachable from a keyboard. What is lost without this file is the
 * ordering, which is a preference — not the composition, which is the point of
 * the screen.
 *
 * POSTED, NOT QUEUED. The new order goes to the server as soon as a drag ends,
 * because the alternative is a "Save" button somebody leaves the page without
 * pressing. The response is ignored: the DOM already shows the outcome, and
 * re-rendering from a reply would fight a drag that had already started.
 */
export default class extends Controller {
    static targets = ['list', 'row', 'handle'];
    static values = { url: String, token: String };

    connect() {
        this.dragging = null;
        this.draggedList = null;
        this.listTargets.forEach((list) => this.arm(list));
    }

    arm(list) {
        list.querySelectorAll('[data-slug]').forEach((row) => {
            row.draggable = true;
            row.addEventListener('dragstart', () => this.start(row, list));
            row.addEventListener('dragend', () => this.end(row));
            row.addEventListener('dragover', (event) => this.moveOver(event, list, row));
        });
    }

    start(row, list) {
        this.dragging = row;
        this.draggedList = list;
        row.classList.add('dragging');
    }

    end(row) {
        row.classList.remove('dragging');
        this.dragging = null;
        this.mirror();
        this.renumber();
        this.persist();
        this.draggedList = null;
    }

    /* Every list prints its running rows' places afresh, 1 upwards, in the new order. */
    renumber() {
        this.listTargets.forEach((list) => {
            let place = 0;
            list.querySelectorAll('[data-position]').forEach((cell) => {
                cell.textContent = String(++place);
            });
        });
    }

    moveOver(event, list, row) {
        event.preventDefault();
        if (!this.dragging || this.dragging === row || this.dragging.parentElement !== list) {
            return;
        }
        const box = row.getBoundingClientRect();
        const after = box.top + row.offsetHeight / 2 < event.clientY;
        list.insertBefore(this.dragging, after ? row.nextSibling : row);
    }

    /* Every list that was NOT dragged in is re-sorted to match the one that was. */
    mirror() {
        const order = this.order();
        this.listTargets
            .filter((list) => list !== this.draggedList)
            .forEach((list) => {
                order.forEach((slug) => {
                    const row = list.querySelector(`[data-slug="${slug}"]`);
                    if (row) {
                        list.appendChild(row);
                    }
                });
            });
    }

    /*
     * THE ORDER IS THE LIST THE DRAG HAPPENED IN. Both lists are `list` targets,
     * so a fixed one — the first in the template — would answer for its own drags
     * only and overwrite every drag made in the other.
     */
    order() {
        return this.draggedList
            ? Array.from(this.draggedList.querySelectorAll('[data-slug]')).map((row) => row.dataset.slug)
            : [];
    }

    persist() {
        if (!this.hasUrlValue) {
            return;
        }
        const body = new URLSearchParams();
        body.append('_token', this.tokenValue);
        this.order().forEach((slug) => body.append('order[]', slug));

        fetch(this.urlValue, { method: 'POST', body, credentials: 'same-origin' });
    }
}
