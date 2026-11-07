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
 * ON THIS PAGE — the index a page carries left of its sections. One index per
 * page. The markup is the shell's (`@Shell/_onpage.html.twig`), the values
 * are the design's (DesignsProjects/team-module/vendor/house-index.css) and
 * live in shell.css; this is the design's house-index.js, behaviour unchanged.
 *
 * THE MARKUP the partial writes:
 *   <script>…</script>    immediately before the grid: stamps html.onpage-closed
 *                         before the index is painted when the viewer collapsed
 *                         it, or the viewport is 900px or narrower
 *   <div class="onpage">
 *     <nav class="onpage-nav" aria-label="On this page" data-controller="uhifadhi--shell-bundle--onpage"
 *          data-action="click->uhifadhi--shell-bundle--onpage#go">
 *       <div class="onpage-top"><p class="onpage-h">On this page</p>
 *         <button class="onpage-tg" data-…-target="toggle" data-action="click->…#toggle">…</button></div>
 *       <a class="onpage-row" href="#id"><i class="onpage-dot"></i><span class="onpage-l">Label</span><b class="onpage-n">3</b></a>
 *       <p class="onpage-h">Head</p>   a head between rows (optional)
 *     </nav>
 *     <div class="onpage-main"> … the sections, each carrying its id … </div>
 *   </div>
 *
 * BEHAVIOUR. The current row (aria-current) is the last section whose top has
 * reached 16px under the sticky offset (the top bar's height + 24px); at the
 * foot of the page it is the last section. A section with no box (hidden) is
 * skipped. A click scrolls its section to the offset, smoothly unless reduced
 * motion is asked, and holds the row current until the scroll ends; a section
 * with no box scrolls to its nearest shown ancestor. The target flashes
 * (onpage-flash) from the moment of the click, so it is glowing as it scrolls
 * into view. The toggle collapses the index to the rail: remembered in
 * localStorage 'uhifadhi-pp-nav' ("closed" or absent) while the viewport is
 * wider than 900px; at 900px and below the rail is forced and a toggle there
 * is not remembered. Every row carries the same dot; a row without one gets
 * it. Every dot's title is its row's label.
 *
 * THE API
 *   window.shellOnpage.count(id, n)      the muted count; null removes it
 *   window.shellOnpage.hide(id, hidden)  hide or show a row
 *   window.shellOnpage.row(id)           the row element, or null
 *   window.shellOnpage.current(id)       mark a row current from script
 * On a page without an index every call answers null.
 *
 * Identifier in a host: `uhifadhi--shell-bundle--onpage`, the `name` in
 * assets/package.json; StimulusBundle registers a controller under that name
 * with `/` turned into `--`.
 *
 * @see https://symfony.com/bundles/StimulusBundle/current/index.html#ux-packages
 * @see vendor/symfony/stimulus-bundle/src/AssetMapper/ControllersMapGenerator.php
 *      ("if (isset($packageControllerConfig['name']))")
 * @see https://stimulus.hotwired.dev/reference/lifecycle-callbacks "connect()
 *      Anytime the controller is connected to the DOM" / "disconnect()"
 * @see https://stimulus.hotwired.dev/reference/targets
 * @see https://stimulus.hotwired.dev/reference/actions
 */

const KEY = 'uhifadhi-pp-nav';
const CLOSED = 'onpage-closed';
const NOOP = { count: () => null, hide: () => null, row: () => null, current: () => null };

window.shellOnpage = NOOP;

function shown(el) {
    return !!el && el.getClientRects().length > 0;
}

function stored() {
    try {
        return localStorage.getItem(KEY) === 'closed';
    } catch (e) {
        return false;
    }
}

export default class extends Controller {
    static targets = ['toggle'];

    connect() {
        this.root = document.documentElement;
        this.narrow = window.matchMedia('(max-width:900px)');
        this.still = window.matchMedia('(prefers-reduced-motion:reduce)');
        const bar = document.querySelector('.topbar');
        this.offset = (bar ? bar.offsetHeight : 56) + 24;
        this.line = this.offset + 16;

        this.rows = {};
        this.ids = [];
        Array.from(this.element.querySelectorAll('a.onpage-row[href^="#"]')).forEach((a) => {
            const id = decodeURIComponent(a.getAttribute('href').slice(1));
            Array.from(a.childNodes).forEach((n) => {
                if (n.nodeType === 3 && n.nodeValue.trim()) {
                    const l = document.createElement('span');
                    l.className = 'onpage-l';
                    l.textContent = n.nodeValue.trim();
                    a.replaceChild(l, n);
                }
            });
            if (!a.querySelector('.onpage-dot')) {
                const i = document.createElement('i');
                i.className = 'onpage-dot';
                i.setAttribute('aria-hidden', 'true');
                a.insertBefore(i, a.firstChild);
            }
            const lab = a.querySelector('.onpage-l');
            const dot = a.querySelector('.onpage-dot');
            dot.title = lab ? lab.textContent : '';
            dot.setAttribute('aria-hidden', 'true');
            const t = document.getElementById(id);
            if (t) {
                t.style.scrollMarginTop = this.offset + 'px';
            }
            this.rows[id] = a;
            this.ids.push(id);
        });

        this.pinned = false;
        this.pinT = 0;
        this.frame = 0;

        this.onScroll = () => {
            if (this.pinned) {
                clearTimeout(this.pinT);
                this.pinT = setTimeout(() => this.release(), 150);
                return;
            }
            this.schedule();
        };
        this.onResize = () => this.schedule();
        this.onWidth = () => {
            this.root.classList.toggle(CLOSED, this.narrow.matches || stored());
            this.sync();
        };
        window.addEventListener('scroll', this.onScroll, { passive: true });
        window.addEventListener('resize', this.onResize);
        window.addEventListener('load', this.onResize);
        if (this.narrow.addEventListener) {
            this.narrow.addEventListener('change', this.onWidth);
        } else if (this.narrow.addListener) {
            this.narrow.addListener(this.onWidth);
        }

        this.root.classList.toggle(CLOSED, this.narrow.matches || stored());
        this.sync();

        window.shellOnpage = {
            row: (id) => this.rows[id] || null,
            current: (id) => {
                if (this.rows[id]) {
                    this.current(id);
                }
            },
            hide: (id, hidden) => {
                if (this.rows[id]) {
                    this.rows[id].hidden = !!hidden;
                }
            },
            count: (id, n) => {
                const a = this.rows[id];
                if (!a) {
                    return;
                }
                let b = a.querySelector('.onpage-n');
                if (n == null || n === '') {
                    if (b) {
                        b.parentNode.removeChild(b);
                    }
                    return;
                }
                if (!b) {
                    b = document.createElement('b');
                    b.className = 'onpage-n';
                    a.appendChild(b);
                }
                b.textContent = n;
            },
        };

        const h = decodeURIComponent(location.hash.slice(1));
        if (this.rows[h]) {
            this.current(h);
        } else {
            this.spy();
        }
    }

    disconnect() {
        window.removeEventListener('scroll', this.onScroll);
        window.removeEventListener('resize', this.onResize);
        window.removeEventListener('load', this.onResize);
        if (this.narrow.removeEventListener) {
            this.narrow.removeEventListener('change', this.onWidth);
        } else if (this.narrow.removeListener) {
            this.narrow.removeListener(this.onWidth);
        }
        clearTimeout(this.pinT);
        window.shellOnpage = NOOP;
    }

    current(id) {
        this.ids.forEach((k) => {
            if (k === id) {
                this.rows[k].setAttribute('aria-current', 'true');
            } else {
                this.rows[k].removeAttribute('aria-current');
            }
        });
    }

    schedule() {
        if (!this.frame) {
            this.frame = window.requestAnimationFrame(() => this.spy());
        }
    }

    release() {
        this.pinned = false;
    }

    flash(el) {
        el.classList.remove('onpage-flash');
        void el.offsetWidth;
        el.classList.add('onpage-flash');
        el.addEventListener('animationend', function end(ev) {
            if (ev.target !== el) {
                return;
            }
            el.classList.remove('onpage-flash');
            el.removeEventListener('animationend', end);
        });
    }

    spy() {
        this.frame = 0;
        if (this.pinned) {
            return;
        }
        const live = this.ids.filter((id) => !this.rows[id].hidden && shown(document.getElementById(id)));
        if (!live.length) {
            return;
        }
        let pick = live[0];
        const doc = document.documentElement;
        if (window.scrollY > 0 && window.innerHeight + window.scrollY >= doc.scrollHeight - 2) {
            pick = live[live.length - 1];
        } else {
            let best = -Infinity;
            live.forEach((id) => {
                const top = document.getElementById(id).getBoundingClientRect().top;
                if (top <= this.line && top > best) {
                    best = top;
                    pick = id;
                }
            });
        }
        this.current(pick);
    }

    go(e) {
        const a = e.target.closest('a.onpage-row');
        if (!a) {
            return;
        }
        const id = decodeURIComponent(a.getAttribute('href').slice(1));
        let el = document.getElementById(id);
        while (el && !shown(el)) {
            el = el.parentElement;
        }
        if (!el) {
            return;
        }
        e.preventDefault();
        this.current(id);
        const y = Math.max(0, Math.min(el.getBoundingClientRect().top + window.scrollY - this.offset,
            document.documentElement.scrollHeight - window.innerHeight));
        this.flash(el);
        const release = () => this.release();
        this.pinned = true;
        clearTimeout(this.pinT);
        this.pinT = setTimeout(release, 1000);
        window.scrollTo({ top: y, behavior: this.still.matches ? 'auto' : 'smooth' });
        try {
            history.replaceState(history.state, '', location.pathname + location.search + '#' + encodeURIComponent(id));
        } catch (err) { /* an address that refuses to change still scrolls */ }
    }

    toggle() {
        const closed = this.root.classList.toggle(CLOSED);
        if (!this.narrow.matches) {
            try {
                if (closed) {
                    localStorage.setItem(KEY, 'closed');
                } else {
                    localStorage.removeItem(KEY);
                }
            } catch (e) { /* a browser that refuses to remember still collapses */ }
        }
        this.sync();
    }

    sync() {
        if (!this.hasToggleTarget) {
            return;
        }
        const tg = this.toggleTarget;
        const closed = this.root.classList.contains(CLOSED);
        const label = closed ? 'Expand index' : 'Collapse index';
        tg.setAttribute('aria-expanded', closed ? 'false' : 'true');
        tg.setAttribute('aria-label', label);
        tg.title = label;
    }
}
