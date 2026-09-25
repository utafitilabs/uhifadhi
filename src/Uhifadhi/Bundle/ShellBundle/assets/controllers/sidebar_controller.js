import { Controller } from '@hotwired/stimulus';

/*
 * The left navigation, in the two states it has.
 *
 * THE RAIL — desktop widths, remembered. The sidebar shrinks to an icon-only
 * rail, reclaiming width for maps and wide tables, and the choice is kept.
 * TWO CLASSES, ONE STATE, AND THE SECOND ONE IS THE INTERESTING ONE. `rail` on
 * the sidebar itself is what a reader expects; `shell-rail` on <html> is what
 * the document's inline pre-paint script can set, because <html> is the only
 * element that exists before the sidebar is parsed. Without it a remembered
 * rail is applied on connect — after the first frame — and a 264px sidebar
 * visibly jumps to 66px on every page load. The stylesheet draws the rail from
 * either, only above the phone breakpoint, so this controller keeps both true
 * and the page never flashes.
 *
 * THE DRAWER — below 900px, derived, never remembered (ruled 2026-09-25). The
 * sidebar is off the screen until the top bar's opener is tapped; it then
 * slides in over a scrim with the full tree, and leaves again on the scrim,
 * the close mark, Escape, or a followed link. `drawer-open` on `.shell` draws
 * it, `shell-drawer` on <html> stops the page behind from scrolling. Nothing
 * is written anywhere — the sidebar ruling: what is open is derived, and every
 * page loads with the drawer shut.
 *
 * While it is out it is a modal dialog, by the WAI-ARIA dialog pattern
 * (https://www.w3.org/WAI/ARIA/apg/patterns/dialog-modal/):
 *
 *   "The element that serves as the dialog container has a role of dialog."
 *   "The dialog container element has aria-modal set to true."
 *   "When a dialog opens, focus moves to an element inside the dialog."
 *   "When a dialog closes, focus returns to the element that invoked the
 *    dialog"
 *   "Escape: Closes the dialog."
 *
 * The role is set on open and taken off on close, because on a desktop the
 * same aside is a sidebar and announcing it as a dialog there would be false.
 * The main column is made `inert` behind it, which is what keeps Tab inside
 * the drawer and the page behind out of the accessibility tree.
 *
 * WHY IT SITS ON `.shell`. The opener is in the top bar and the scrim beside
 * the aside; a Stimulus action reaches only a controller whose element
 * contains it, so the controller spans the whole frame and names its parts as
 * targets — "Targets let you reference important elements by name"
 * (https://stimulus.hotwired.dev/reference/targets). A host that replaced a
 * part keeps the rest: every use asks `has…Target` first.
 */

/* The phone breakpoint, and the same one the stylesheet's drawer block uses. */
const PHONE = '(max-width: 900px)';

export default class extends Controller {
    static targets = ['side', 'opener', 'closer', 'main'];

    connect() {
        if (this.hasSideTarget) {
            this.sideTarget.classList.toggle('rail', this.remembered());
        }
        document.documentElement.classList.toggle('shell-rail', this.remembered());

        /* A phone turned to landscape, or a window widened past the
           breakpoint, has no drawer to show — and would keep an inert main
           column behind nothing. */
        this.phone = window.matchMedia(PHONE);
        this.onBreakpoint = (event) => {
            if (!event.matches) {
                this.close({ restoreFocus: false });
            }
        };
        this.phone.addEventListener('change', this.onBreakpoint);
    }

    disconnect() {
        this.phone?.removeEventListener('change', this.onBreakpoint);
        this.close({ restoreFocus: false });
    }

    /* THE RAIL. */
    toggle() {
        if (!this.hasSideTarget) {
            return;
        }
        const rail = this.sideTarget.classList.toggle('rail');
        document.documentElement.classList.toggle('shell-rail', rail);
        try {
            localStorage.setItem('shell-sidebar', rail ? 'rail' : 'full');
        } catch (e) {
            // Storage unavailable — the toggle still works for this page view.
        }
    }

    remembered() {
        try {
            return localStorage.getItem('shell-sidebar') === 'rail';
        } catch (e) {
            // Storage unavailable — start expanded.
            return false;
        }
    }

    /* THE DRAWER. */
    open() {
        if (!this.hasSideTarget || this.isOpen()) {
            return;
        }

        this.element.classList.add('drawer-open');
        document.documentElement.classList.add('shell-drawer');

        this.sideTarget.setAttribute('role', 'dialog');
        this.sideTarget.setAttribute('aria-modal', 'true');
        this.sideTarget.setAttribute('aria-label', 'Menu');

        if (this.hasOpenerTarget) {
            this.openerTarget.setAttribute('aria-expanded', 'true');
        }
        if (this.hasMainTarget) {
            this.mainTarget.inert = true;
        }
        if (this.hasCloserTarget) {
            this.closerTarget.focus();
        }
    }

    /* Called by the close mark, the scrim and Escape as an action (the
       argument is then the event, whose `restoreFocus` is undefined), and by
       this controller with `{ restoreFocus: false }` where focus should stay
       where the reader put it. */
    close({ restoreFocus = true } = {}) {
        if (!this.isOpen()) {
            return;
        }

        this.element.classList.remove('drawer-open');
        document.documentElement.classList.remove('shell-drawer');

        if (this.hasSideTarget) {
            this.sideTarget.removeAttribute('role');
            this.sideTarget.removeAttribute('aria-modal');
            this.sideTarget.removeAttribute('aria-label');
        }
        if (this.hasMainTarget) {
            this.mainTarget.inert = false;
        }
        if (this.hasOpenerTarget) {
            this.openerTarget.setAttribute('aria-expanded', 'false');
            if (restoreFocus !== false) {
                this.openerTarget.focus();
            }
        }
    }

    /* Following a link closes the drawer, so a page Turbo keeps in its cache
       is a page with the drawer shut. A tree caret never gets here: it lives
       inside its row's link and stops the click itself. */
    follow(event) {
        if (this.isOpen() && event.target.closest('a[href]')) {
            this.close({ restoreFocus: false });
        }
    }

    isOpen() {
        return this.element.classList.contains('drawer-open');
    }
}
