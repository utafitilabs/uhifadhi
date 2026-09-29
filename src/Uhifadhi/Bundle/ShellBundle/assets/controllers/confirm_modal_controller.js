import { Controller } from '@hotwired/stimulus';

/*
 * THE ONE WAY THE PRODUCT ASKS BEFORE IT DESTROYS SOMETHING.
 *
 * WHY THE SHELL OWNS IT. Removing a zone, closing a post, throwing away a
 * saved layout and resetting a dashboard are four modules' actions and one
 * question, and a person should not have to learn four ways of being asked.
 * So a module marks its destructive submit and hands over the words; the
 * dialog, its look, its keyboard and its focus are the frame's.
 *
 * THE BUTTON IS THE FORM'S OWN SUBMIT, DELIBERATELY. This controller
 * intercepts the click; it does not replace the control. An installation that
 * ships no JavaScript — or a page where this file failed to load — still
 * posts, still carries its CSRF token, and loses nothing but the question.
 * That is the right way round for a control that must never become
 * unreachable because a script did not arrive.
 *
 * WHAT A TRIGGER WRITES, and the whole of it:
 *
 *     <button type="submit"
 *             data-controller="confirm-modal"
 *             data-action="click->confirm-modal#ask"
 *             data-confirm-modal-title-value="Remove “Lone Hills”?"
 *             data-confirm-modal-message-value="Its ground becomes unzoned…"
 *             data-confirm-modal-confirm-label-value="Remove the zone"
 *             data-confirm-modal-danger-value="true">Remove the zone</button>
 *
 * THE DIALOG IS BUILT, NOT FOUND. Nothing in a page's markup describes it, so
 * a module cannot accidentally depend on its shape and the frame can change it
 * everywhere at once. The text a module supplies is written with textContent,
 * never as HTML: the words are a module's, the markup is not.
 *
 * IT IS REACHED BY THE SHORT NAME, AND THAT IS THE CONTRACT. StimulusBundle
 * derives an identifier from the composer name — `uhifadhi--shell-bundle--…` —
 * and a module's destructive button must not have to spell the shell's package
 * to ask a question, so the public name is `confirm-modal`. The shell mounts
 * this controller once on the document body it owns; that instance registers
 * the class under the short name and asks nothing itself, and every trigger in
 * every module then binds to it. Without this, four bundles write a trigger
 * that names a controller nobody answers — which is exactly the defect that
 * shipped, and it is silent: the button submits and nothing asks.
 *
 * IT ANNOUNCES ITSELF BEFORE IT SUBMITS. `confirm-modal:confirmed` is
 * dispatched on the trigger, so a page that wants to know can listen rather
 * than wrap the button; then the trigger's own form is submitted with
 * `requestSubmit`, which runs validation and fires `submit` exactly as a real
 * click would.
 */
/** The short, public name every trigger in the product writes. */
const PUBLIC_NAME = 'confirm-modal';

let registered = false;

export default class ConfirmModal extends Controller {
    static values = {
        title: String,
        message: String,
        confirmLabel: String,
        danger: Boolean,
    };

    connect() {
        this.dialog = null;
        this.onKey = this.onKey.bind(this);

        /* THE BODY'S INSTANCE PUBLISHES THE SHORT NAME, once per page. A
           second registration of the same identifier is the same class, so
           the guard is about noise rather than about correctness. */
        if (!registered && this.application) {
            registered = true;
            this.application.register(PUBLIC_NAME, ConfirmModal);
        }
    }

    disconnect() {
        this.close();
    }

    /**
     * THE CLICK IS HELD, NOT LOST. The submit is stopped, the question is
     * asked, and the answer decides whether the same submit happens.
     */
    ask(event) {
        if (this.dialog) {
            return;
        }

        event.preventDefault();
        this.open();
    }

    open() {
        const backdrop = document.createElement('div');
        backdrop.className = 'cmodalbd';
        backdrop.setAttribute('role', 'presentation');

        const box = document.createElement('div');
        box.className = this.dangerValue ? 'cmodal dg' : 'cmodal';
        box.setAttribute('role', 'alertdialog');
        box.setAttribute('aria-modal', 'true');

        const title = document.createElement('h2');
        title.className = 'cmodalhd';
        title.textContent = this.titleValue || 'Are you sure?';
        box.appendChild(title);

        if (this.messageValue) {
            const message = document.createElement('p');
            message.className = 'cmodalbody';
            message.textContent = this.messageValue;
            box.appendChild(message);
        }

        const foot = document.createElement('div');
        foot.className = 'cmodalfoot';

        const cancel = document.createElement('button');
        cancel.type = 'button';
        cancel.className = 'btn sm';
        cancel.textContent = 'Cancel';
        cancel.addEventListener('click', () => this.close());

        const go = document.createElement('button');
        go.type = 'button';
        go.className = this.dangerValue ? 'btn sm dg' : 'btn sm';
        go.textContent = this.confirmLabelValue || 'Confirm';
        go.addEventListener('click', () => this.confirm());

        foot.append(cancel, go);
        box.appendChild(foot);
        backdrop.appendChild(box);

        /* THE BACKDROP CANCELS, the box does not: a click inside the question
           is a click on the question. */
        backdrop.addEventListener('click', (event) => {
            if (event.target === backdrop) {
                this.close();
            }
        });

        document.body.appendChild(backdrop);
        document.addEventListener('keydown', this.onKey);

        this.dialog = backdrop;
        /* FOCUS GOES TO THE WAY OUT, not to the destructive answer: the
           dangerous button is never the one a stray Return presses. */
        cancel.focus();

        /* Title and message are the accessible name and description, and they
           are wired after both exist so a dialog with no message names none. */
        title.id = title.id || 'cmodal-title';
        box.setAttribute('aria-labelledby', title.id);
    }

    confirm() {
        const trigger = this.element;
        this.close();

        trigger.dispatchEvent(new CustomEvent('confirm-modal:confirmed', { bubbles: true }));

        const form = trigger.form || trigger.closest('form');
        if (!form) {
            return;
        }

        /* `requestSubmit` runs the form's validation and fires `submit`,
           which a bare `submit()` skips — and it submits AS this button, so a
           name/value the trigger carries is posted exactly as a click would
           post it. */
        if (typeof form.requestSubmit === 'function') {
            form.requestSubmit(trigger.type === 'submit' ? trigger : undefined);
        } else {
            form.submit();
        }
    }

    close() {
        if (!this.dialog) {
            return;
        }

        document.removeEventListener('keydown', this.onKey);
        this.dialog.remove();
        this.dialog = null;
        /* Focus goes back where it came from, which is the control that asked. */
        this.element.focus();
    }

    onKey(event) {
        if (event.key === 'Escape') {
            event.preventDefault();
            this.close();
        }
    }
}
