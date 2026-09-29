import { Controller } from '@hotwired/stimulus';

/*
 * THE POSITION CARD'S FOOT — what the form now holds against what was saved.
 *
 * The grants card's foot rewrites itself as boxes are ticked; this card's said
 * "no changes" from the moment it was drawn until the page was left, whatever
 * was chosen. Now it compares every field with its SAVED state — the one the
 * browser already keeps on each element (`defaultSelected`, `defaultChecked`,
 * `defaultValue`), so the markup needs no `data-was` — and names what moved:
 * "2 changes · position → Ranger · 3 departments", beside the reach and the
 * departments counted from the boxes as they stand.
 *
 * IT WRITES NOTHING. Nothing reaches the database until Save; Discard is the
 * form's own reset, after which the foot reads "no changes" again.
 *
 * THE REACH is the chosen position's: each option carries how many people it
 * would reach with this person in it (`data-reach`).
 */
export default class extends Controller {
    static targets = ['preview'];

    connect() {
        this.#redraw();
        this.element.addEventListener('change', this.#onChange);
        this.element.addEventListener('input', this.#onChange);
        // A reset restores the fields AFTER its event; read them on the next turn.
        this.element.addEventListener('reset', this.#onReset);
    }

    disconnect() {
        this.element.removeEventListener('change', this.#onChange);
        this.element.removeEventListener('input', this.#onChange);
        this.element.removeEventListener('reset', this.#onReset);
    }

    #onChange = () => this.#redraw();

    #onReset = () => setTimeout(() => this.#redraw(), 0);

    #redraw() {
        if (!this.hasPreviewTarget) {
            return;
        }

        const form = this.element;
        const moved = [];

        const position = form.querySelector('select[name="position"]');
        if (position && this.#selectMoved(position)) {
            const label = position.selectedOptions[0]?.textContent.split(' — ')[0].trim() || 'no position';
            moved.push(`position → ${label === '— no position —' ? 'no position' : label}`);
        }

        const rank = form.querySelector('select[name="rank"]');
        if (rank && this.#selectMoved(rank)) {
            moved.push(`rank → ${rank.selectedOptions[0]?.textContent.trim() || 'no rank'}`);
        }

        const boxes = [...form.querySelectorAll('input[type="checkbox"]')];
        const where = boxes.filter((b) => (b.name === 'where' || b.name === 'areas[]') && b.checked !== b.defaultChecked);
        if (where.length > 0) {
            moved.push('where it applies');
        }
        const departments = boxes.filter((b) => (b.name === 'all_departments' || b.name === 'departments[]') && b.checked !== b.defaultChecked);
        if (departments.length > 0) {
            moved.push('departments');
        }

        const every = boxes.filter((b) => b.name === 'departments[]');
        const all = boxes.find((b) => b.name === 'all_departments');
        const chosen = all?.checked ? every.length : every.filter((b) => b.checked).length;

        const option = position?.selectedOptions[0];
        const reach = Number(option?.dataset.reach ?? 0);
        const tail = `<span class="to">reaches <b>${reach}</b> ${reach === 1 ? 'person' : 'people'} · <b>${chosen}</b> of ${every.length} departments</span>`;

        if (moved.length === 0) {
            this.previewTarget.innerHTML = `<b>no changes</b>${tail}`;

            return;
        }

        this.previewTarget.innerHTML = [
            `<b>${moved.length} change${moved.length === 1 ? '' : 's'}</b>`,
            ...moved.map((text) => `<span class="nm">${text}</span>`),
            tail,
        ].join('');
    }

    #selectMoved(select) {
        const saved = [...select.options].find((o) => o.defaultSelected) ?? select.options[0];

        return select.selectedOptions[0] !== saved;
    }
}
