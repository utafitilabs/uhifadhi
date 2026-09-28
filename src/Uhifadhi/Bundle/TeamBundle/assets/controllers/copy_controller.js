import { Controller } from '@hotwired/stimulus';

/*
 * COPY — puts a short text on the clipboard with one press, and says so.
 *
 * Used for the one-time password on a person's configure page: the code is
 * shown once, and an administrator passes it on by phone or radio, so it has
 * to leave the page in one move. The source's text is copied as it reads;
 * the button says "Copied" for two seconds and then returns to itself.
 *
 * WHERE THE CLIPBOARD IS REFUSED (an insecure origin, a browser that asks and
 * is told no), the text is selected instead, so a copy by hand is one key.
 */
export default class extends Controller {
    static targets = ['source', 'button'];

    async copy() {
        const text = this.sourceTarget.textContent.trim();
        try {
            await navigator.clipboard.writeText(text);
            this.confirm('Copied');
        } catch (refused) {
            const range = document.createRange();
            range.selectNodeContents(this.sourceTarget);
            const selection = window.getSelection();
            selection.removeAllRanges();
            selection.addRange(range);
            this.confirm('Selected');
        }
    }

    confirm(word) {
        if (!this.hasButtonTarget) {
            return;
        }
        const label = this.buttonTarget.querySelector('[data-label]') ?? this.buttonTarget;
        const before = label.textContent;
        label.textContent = word;
        window.clearTimeout(this.restore);
        this.restore = window.setTimeout(() => { label.textContent = before; }, 2000);
    }

    disconnect() {
        window.clearTimeout(this.restore);
    }
}
