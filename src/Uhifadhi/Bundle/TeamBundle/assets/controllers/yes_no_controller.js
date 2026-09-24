import { Controller } from '@hotwired/stimulus';

/*
 * YES / NO — two halves of one control, carrying a real field.
 *
 * THE BUTTONS PAINT THE CHOICE AND THE HIDDEN INPUT POSTS IT. Nothing is
 * written until the form's own Save is pressed, which is what lets a card of
 * three rules be saved as one write and cancelled as one reset.
 *
 * IT OWNS NO STATE. The choice lives on the field, where the browser keeps it
 * and where a form reset restores it; the controller only repaints from the
 * field, so a reset repaints too.
 */
export default class extends Controller {
    static targets = ['field', 'option'];

    connect() {
        this.form = this.element.closest('form');
        this.onReset = () => window.setTimeout(() => this.paint(), 0);
        this.form?.addEventListener('reset', this.onReset);
        this.paint();
    }

    disconnect() {
        this.form?.removeEventListener('reset', this.onReset);
    }

    pick(event) {
        this.fieldTarget.value = event.currentTarget.dataset.value;
        this.paint();
    }

    paint() {
        const chosen = this.fieldTarget.value;
        this.optionTargets.forEach((option) => {
            const on = option.dataset.value === chosen;
            option.classList.toggle('on', on);
            option.setAttribute('aria-pressed', on ? 'true' : 'false');
        });
    }
}
