import { Controller } from '@hotwired/stimulus';

/*
 * AREA › A LIST WHOSE ADDRESS IS ITS QUESTION — the stations register and the
 * postings, filtered by the server.
 *
 * Each house dropdown in the bar (`@Shell/_dropdown.html.twig`) is named for
 * the query parameter it sets (`data-dd="zone"`). A choice sets that
 * parameter, or removes it where the choice is the dropdown's default, goes
 * back to page one and loads the address; the server draws the answer, so a
 * filtered list is a link somebody can send.
 *
 * Wired as data-action="uhifadhi--shell-bundle--dropdown:choose->uhifadhi--area-bundle--area-query#chosen".
 */
export default class extends Controller {
    chosen(event) {
        const wrapper = event.target.closest('[data-dd]');
        if (!wrapper) {
            return;
        }
        const url = new URL(window.location.href);
        const value = event.detail ? event.detail.value : '';
        if (value === null || value === '' || value === wrapper.dataset.default) {
            url.searchParams.delete(wrapper.dataset.dd);
        } else {
            url.searchParams.set(wrapper.dataset.dd, value);
        }
        url.searchParams.delete('page');
        window.location.assign(url.toString());
    }
}
