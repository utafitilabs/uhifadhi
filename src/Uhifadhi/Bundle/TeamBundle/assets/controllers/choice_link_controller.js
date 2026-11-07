import { Controller } from '@hotwired/stimulus';

/*
 * CHOICE LINK — a house dropdown whose every option is an address.
 *
 * The shell's dropdown chooses a value and says so with its `choose` event;
 * on a page where the address is the state, choosing an option is opening
 * the page that option names. The server writes the address of every option
 * into `urls`, keyed by the option's value, so this controller works nothing
 * out: it opens the address it is given, and an option with none stays where
 * it is.
 *
 * Wired as
 *   data-controller="uhifadhi--team-bundle--choice-link"
 *   data-uhifadhi--team-bundle--choice-link-urls-value='{"": "/a", "b": "/a?x=b"}'
 *   data-action="uhifadhi--shell-bundle--dropdown:choose->uhifadhi--team-bundle--choice-link#go"
 */
export default class extends Controller {
    static values = { urls: Object };

    go(event) {
        const url = this.urlsValue[event.detail.value ?? ''];
        if (url) {
            window.location.assign(url);
        }
    }
}
