import { Controller } from '@hotwired/stimulus';

/*
 * THE RANKS LIST'S ORDER — drag a row by its grip, or move it with the arrow
 * keys while its grip has focus.
 *
 * THE ORDER IS THE FORM'S ORDER. Every row posts its fields keyed by the
 * rank's identifier, and the server reads seniority from the order the rows
 * arrive in; moving a row in the page is therefore the whole write, and the
 * card's own Save sends it. Nothing is written on a drop.
 *
 * IT OWNS NO STATE: the numbers are repainted from the rows' places, so a
 * form reset (which does not move rows back) is followed by a reload of the
 * saved order — the Cancel button is a plain reset of the fields.
 */
export default class extends Controller {
    static targets = ['row', 'number'];

    connect() {
        this.dragged = null;
        this.rowTargets.forEach((row) => {
            row.addEventListener('dragstart', (event) => this.start(event, row));
            row.addEventListener('dragover', (event) => this.over(event, row));
            row.addEventListener('dragend', () => this.end());
        });
    }

    /* A row is only draggable while its grip is held, so the text fields keep their own selection. */
    grab(event) {
        event.currentTarget.closest('[data-uhifadhi--team-bundle--rank-order-target="row"]')?.setAttribute('draggable', 'true');
    }

    start(event, row) {
        this.dragged = row;
        event.dataTransfer.effectAllowed = 'move';
    }

    over(event, row) {
        if (!this.dragged || row === this.dragged) {
            return;
        }
        event.preventDefault();
        const box = row.getBoundingClientRect();
        const after = event.clientY > box.top + box.height / 2;
        row.parentNode.insertBefore(this.dragged, after ? row.nextSibling : row);
        this.paint();
    }

    end() {
        this.dragged?.removeAttribute('draggable');
        this.dragged = null;
    }

    key(event) {
        const row = event.currentTarget.closest('[data-uhifadhi--team-bundle--rank-order-target="row"]');
        if (!row) {
            return;
        }
        if (event.key === 'ArrowUp' && row.previousElementSibling?.matches('[data-uhifadhi--team-bundle--rank-order-target="row"]')) {
            event.preventDefault();
            row.parentNode.insertBefore(row, row.previousElementSibling);
        } else if (event.key === 'ArrowDown' && row.nextElementSibling?.matches('[data-uhifadhi--team-bundle--rank-order-target="row"]')) {
            event.preventDefault();
            row.parentNode.insertBefore(row.nextElementSibling, row);
        } else {
            return;
        }
        this.paint();
        event.currentTarget.focus();
    }

    paint() {
        this.rowTargets.forEach((row, index) => {
            const number = row.querySelector('[data-uhifadhi--team-bundle--rank-order-target="number"]');
            if (number) {
                number.textContent = String(index + 1);
            }
        });
    }
}
