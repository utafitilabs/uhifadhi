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
 * THE DROPDOWN — a pill trigger and its floating panel of grouped rows. The
 * markup is the shell's (`@Shell/_dropdown.html.twig`), the values are the
 * design's (DesignsProjects/uhifadhi-web/house-dropdown.css) and live in
 * shell.css; this is the design's house-dropdown.js, behaviour unchanged.
 * The contract is docs/dropdown.md.
 *
 * THE MARKUP the partial writes:
 *   <div class="dd [dd-end] [dd-set]" data-controller="uhifadhi--shell-bundle--dropdown"
 *        data-dd="<name>" [data-default="<value>"] data-dd-value="<value>">
 *     <button type="button" class="dd-t" aria-haspopup="listbox" aria-expanded="false" aria-controls="dd-p-<name>">
 *       <svg class="dd-i">…</svg>                       leading 16px icon (optional)
 *       <span class="dd-v" data-placeholder="…">…</span>  the label
 *       <svg class="dd-c">…</svg>                       chevron
 *     </button>
 *     <div class="dd-p" id="dd-p-<name>" hidden>
 *       <label class="dd-q"><svg>…</svg><input type="search"></label>   search (optional)
 *       <div class="dd-l" role="listbox">
 *         <div role="group" aria-label="…">
 *           <div class="dd-h"><span>Head</span><span data-dd-n>12</span></div>
 *           <button type="button" class="dd-o" role="option" tabindex="-1" aria-selected="false"
 *                   data-value="…" data-label="…" data-find="…" [data-default]>
 *             <span class="dd-ol">Label</span><span class="dd-m">muted</span>
 *           </button>
 *         </div>
 *         <p class="dd-none" hidden>Nothing matches.</p>   (optional)
 *       </div>
 *     </div>
 *   </div>
 *
 * BEHAVIOUR. Click or ArrowDown on the trigger opens; one panel is open at a
 * time. Up/Down/Home/End move, Enter chooses (Enter in the search chooses the
 * first match), Escape, an outside click or tabbing away closes; the trigger
 * takes the focus back. While open, the panel's .page is lifted (dd-lift)
 * above the page foot, and the list's height is capped to end inside the
 * viewport. The search filters rows by data-find and recounts each head.
 * The handlers are the design's, delegated from document, so a list a page
 * fills or refills later works; they are installed with the first dropdown
 * and removed with the last.
 *
 * THE API
 *   the wrapper fires 'uhifadhi--shell-bundle--dropdown:choose', bubbling,
 *   detail {value}, on a choice by the user
 *   window.shellDropdown.set(wrapper, value)  choose from script (no event); null clears
 *   window.shellDropdown.value(wrapper)       the current value, or null
 *   window.shellDropdown.close()              close whatever is open
 * The trigger label becomes the row's data-label (or its .dd-ol text); the
 * wrapper carries .dd-set while a value other than data-default is chosen.
 *
 * Identifier in a host: `uhifadhi--shell-bundle--dropdown`, the `name` in
 * assets/package.json; StimulusBundle registers a controller under that name
 * with `/` turned into `--`.
 *
 * @see https://symfony.com/bundles/StimulusBundle/current/index.html#ux-packages
 * @see vendor/symfony/stimulus-bundle/src/AssetMapper/ControllersMapGenerator.php
 *      ("if (isset($packageControllerConfig['name']))")
 * @see https://stimulus.hotwired.dev/reference/lifecycle-callbacks "connect()
 *      Anytime the controller is connected to the DOM" / "disconnect()"
 * @see https://developer.mozilla.org/en-US/docs/Web/API/CustomEvent/CustomEvent
 *      ("bubbles … indicating whether the event bubbles")
 */

const CHOOSE = 'uhifadhi--shell-bundle--dropdown:choose';

let open = null;
let connected = 0;

function parts(w) {
    return {
        w: w, btn: w.querySelector('.dd-t'), panel: w.querySelector('.dd-p'), list: w.querySelector('.dd-l'),
        search: w.querySelector('.dd-q input'), val: w.querySelector('.dd-v'),
    };
}

function rows(w) {
    return Array.from(w.querySelectorAll('.dd-o')).filter((o) => !o.hidden && !o.closest('[hidden]'));
}

function labelOf(o) {
    if (o.hasAttribute('data-label')) {
        return o.getAttribute('data-label');
    }
    const l = o.querySelector('.dd-ol');
    return (l || o).textContent;
}

function placeholder(p) {
    if (!p.val.hasAttribute('data-placeholder')) {
        p.val.setAttribute('data-placeholder', p.val.textContent);
    }
    return p.val.getAttribute('data-placeholder');
}

function set(w, value) {
    const p = parts(w);
    let hit = null;
    placeholder(p);
    Array.from(w.querySelectorAll('.dd-o')).forEach((o) => {
        const on = value != null && o.getAttribute('data-value') === String(value);
        o.setAttribute('aria-selected', String(on));
        if (on) {
            hit = o;
        }
    });
    w.setAttribute('data-dd-value', hit ? hit.getAttribute('data-value') : '');
    p.val.textContent = hit ? labelOf(hit) : p.val.getAttribute('data-placeholder');
    w.classList.toggle('dd-set', !!hit && hit.getAttribute('data-value') !== w.getAttribute('data-default'));
}

function value(w) {
    const v = w.getAttribute('data-dd-value');
    return v ? v : null;
}

function filter(w, text) {
    const t = String(text || '').trim().toLowerCase();
    let any = false;
    let groups = w.querySelectorAll('.dd-l [role="group"]');
    if (!groups.length) {
        groups = [w.querySelector('.dd-l')];
    }
    Array.from(groups).forEach((g) => {
        let n = 0;
        Array.from(g.querySelectorAll('.dd-o')).forEach((o) => {
            const hay = (o.getAttribute('data-find') || o.textContent).toLowerCase();
            const ok = !t || hay.indexOf(t) > -1;
            o.hidden = !ok;
            if (ok) {
                n++;
            }
        });
        if (g.getAttribute('role') === 'group') {
            g.hidden = n === 0;
        }
        const c = g.querySelector('[data-dd-n]');
        if (c) {
            c.textContent = n;
        }
        any = any || n > 0;
    });
    const none = w.querySelector('.dd-none');
    if (none) {
        none.hidden = any;
    }
}

function fit(p) {
    const top = p.list.getBoundingClientRect().top;
    const room = Math.floor(window.innerHeight - top - 16 - 8);
    p.list.style.maxHeight = Math.max(120, Math.min(360, room)) + 'px';
}

function lift(w, on) {
    const pg = w.closest('.page');
    if (pg) {
        pg.classList.toggle('dd-lift', on);
    }
}

function close(focus) {
    if (!open) {
        return;
    }
    const p = parts(open);
    p.panel.hidden = true;
    p.btn.setAttribute('aria-expanded', 'false');
    lift(open, false);
    open = null;
    if (focus) {
        p.btn.focus();
    }
}

function show(w) {
    if (open && open !== w) {
        close(false);
    }
    const p = parts(w);
    p.panel.hidden = false;
    p.btn.setAttribute('aria-expanded', 'true');
    lift(w, true);
    open = w;
    if (p.search) {
        p.search.value = '';
        filter(w, '');
    }
    fit(p);
    if (p.search) {
        p.search.focus();
        return;
    }
    const on = w.querySelector('.dd-o[aria-selected="true"]') || rows(w)[0];
    if (on) {
        on.focus();
    }
}

function choose(w, o) {
    const v = o.getAttribute('data-value');
    set(w, v);
    close(true);
    w.dispatchEvent(new CustomEvent(CHOOSE, { bubbles: true, detail: { value: v } }));
}

function onClick(e) {
    const w = e.target.closest('[data-dd]');
    if (!w) {
        close(false);
        return;
    }
    const t = e.target.closest('.dd-t');
    if (t && w.contains(t)) {
        if (open === w) {
            close(true);
        } else {
            show(w);
        }
        return;
    }
    const o = e.target.closest('.dd-o');
    if (o && w.contains(o)) {
        choose(w, o);
    }
}

function onKey(e) {
    const w = e.target.closest && e.target.closest('[data-dd]');
    if (e.key === 'Escape') {
        if (open) {
            e.preventDefault();
            close(true);
        }
        return;
    }
    if (!w) {
        return;
    }
    const p = parts(w);
    if (e.target === p.btn) {
        if (e.key === 'ArrowDown' && p.panel.hidden) {
            e.preventDefault();
            show(w);
        }
        return;
    }
    if (p.panel.hidden || !p.panel.contains(e.target)) {
        return;
    }
    const all = rows(w);
    const i = all.indexOf(document.activeElement);
    if (e.key === 'ArrowDown') {
        e.preventDefault();
        if (all.length) {
            all[Math.min(i + 1, all.length - 1)].focus();
        }
    } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        if (i <= 0) {
            (p.search || all[0] || p.btn).focus();
        } else {
            all[i - 1].focus();
        }
    } else if (e.key === 'Home' && i > -1) {
        e.preventDefault();
        all[0].focus();
    } else if (e.key === 'End' && i > -1) {
        e.preventDefault();
        all[all.length - 1].focus();
    } else if (e.key === 'Enter' && e.target === p.search) {
        e.preventDefault();
        if (all[0]) {
            choose(w, all[0]);
        }
    }
}

function onInput(e) {
    const w = e.target.closest && e.target.closest('[data-dd]');
    if (w && e.target.matches('.dd-q input')) {
        filter(w, e.target.value);
    }
}

function onFocusOut(e) {
    if (open && e.relatedTarget && !open.contains(e.relatedTarget)) {
        close(false);
    }
}

function onResize() {
    if (open) {
        fit(parts(open));
    }
}

window.shellDropdown = { set: set, value: value, close: () => close(false) };

export default class extends Controller {
    connect() {
        if (connected++ === 0) {
            document.addEventListener('click', onClick);
            document.addEventListener('keydown', onKey);
            document.addEventListener('input', onInput);
            document.addEventListener('focusout', onFocusOut);
            window.addEventListener('resize', onResize);
        }
    }

    disconnect() {
        if (open === this.element) {
            lift(open, false);
            open = null;
        }
        if (--connected === 0) {
            document.removeEventListener('click', onClick);
            document.removeEventListener('keydown', onKey);
            document.removeEventListener('input', onInput);
            document.removeEventListener('focusout', onFocusOut);
            window.removeEventListener('resize', onResize);
        }
    }
}
