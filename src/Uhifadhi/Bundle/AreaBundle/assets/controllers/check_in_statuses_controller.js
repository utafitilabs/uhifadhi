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
 * THE AREA'S CHECK-IN STATUSES CARD (uhifadhi-web/areas/kilimani/configure/
 * check-in-statuses.js): the rows drawn from the card's model, edited in
 * place, and the whole list posted at once through the settings save bar.
 *
 * THE MARKUP — `area/configure/_settings.html.twig`, on the form element.
 *   the model      script[data-cis-model]: saved (key, label, kind, active,
 *                  used), current (the list as last sent, after a refusal),
 *                  kinds (v, label, means), refusal, refusedKey, justSaved
 *   the icons      template[data-cis-icon="up|down"], the house carets
 *   the rows       table.tbl.cis tbody, drawn here
 *   the count      [data-cis-count], "· n offered · m not offered"
 *   add            button[data-act="add"]
 *   the bar        .ed-bar: b (the count or the refusal), .cx-why, .ed-btn.ghost
 *                  Discard, .ed-btn[data-review] Review, .ed-btn.pri Save (the
 *                  form's submit); .refused while it shows the server's sentence
 *   the review     .ed-reviewcard, its .tab .src and ul.ed-review
 *
 * THE RULES, SAID BEFORE THEY BITE — the server refuses the same ones:
 *   a used status's kind is fixed; a new or unused one may change kind
 *   a status not offered is frozen: label and kind wait for Reactivate
 *   the last offered At a station status cannot be deactivated or change kind
 *   a new key is lower-case letters, digits and _, starting with a letter,
 *   unique in the area offered or not; fixed once saved
 *
 * ON SAVE the list, offered first, becomes statuses[i][key], statuses[i][label],
 * statuses[i][kind], statuses[i][active] and, for a new one, statuses[i][new].
 */
const AT_STATION = 'at_post';
const KEY = /^[a-z][a-z0-9_]{0,31}$/;

const derive = (label) => label.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '')
    .replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '').slice(0, 32);
const ordered = (rows) => [...rows.filter((r) => r.active), ...rows.filter((r) => !r.active)];
const clone = (rows) => rows.map((r) => ({ ...r }));

export default class extends Controller {
    connect() {
        const model = JSON.parse(this.element.querySelector('script[data-cis-model]').textContent);
        this.kinds = model.kinds;
        this.uid = 0;
        this.saved = model.saved.map((r) => this.stamp({ ...r, isNew: false }));
        this.rows = model.current
            ? model.current.map((r) => this.stamp({
                key: r.key,
                label: r.label,
                kind: r.kind,
                active: r.active,
                used: !r.new && !!this.savedOf(r.key)?.used,
                isNew: r.new,
                keyTouched: r.new,
            }))
            : clone(this.saved);
        this.saved.filter((s) => !this.rows.some((r) => !r.isNew && r.key === s.key)).forEach((s) => this.rows.push({ ...s }));
        this.refusal = model.refusal;
        this.refusedKey = model.refusedKey;
        this.justSaved = model.justSaved;
        this.reviewOpen = false;

        this.tbody = this.element.querySelector('table.tbl.cis tbody');
        this.bar = this.element.querySelector('.ed-bar');
        this.review = this.element.querySelector('.ed-reviewcard');
        this.discardButton = this.bar.querySelector('.ed-btn.ghost');
        this.reviewButton = this.bar.querySelector('[data-review]');
        this.saveButton = this.bar.querySelector('.ed-btn.pri');
        this.icons = {
            up: this.element.querySelector('template[data-cis-icon="up"]')?.innerHTML ?? '',
            down: this.element.querySelector('template[data-cis-icon="down"]')?.innerHTML ?? '',
        };

        this.onClick = (event) => this.click(event);
        this.onKeydown = (event) => this.keydown(event);
        this.onInput = (event) => this.input(event);
        this.onAdd = () => this.add();
        this.onDiscard = () => this.discard();
        this.onReview = () => {
            this.reviewOpen = !this.reviewOpen;
            this.draw();
        };
        this.onSubmit = (event) => this.submit(event);

        this.tbody.addEventListener('click', this.onClick);
        this.tbody.addEventListener('keydown', this.onKeydown);
        this.tbody.addEventListener('input', this.onInput);
        this.addButton = this.element.querySelector('[data-act="add"]');
        this.addButton?.addEventListener('click', this.onAdd);
        this.discardButton.addEventListener('click', this.onDiscard);
        this.reviewButton.addEventListener('click', this.onReview);
        this.element.addEventListener('submit', this.onSubmit);

        this.draw();
    }

    disconnect() {
        this.tbody?.removeEventListener('click', this.onClick);
        this.tbody?.removeEventListener('keydown', this.onKeydown);
        this.tbody?.removeEventListener('input', this.onInput);
        this.addButton?.removeEventListener('click', this.onAdd);
        this.discardButton?.removeEventListener('click', this.onDiscard);
        this.reviewButton?.removeEventListener('click', this.onReview);
        this.element.removeEventListener('submit', this.onSubmit);
    }

    stamp(row) {
        this.uid += 1;

        return { ...row, id: 'r' + this.uid };
    }

    savedOf(key) {
        return this.saved.find((s) => s.key === key) ?? null;
    }

    kindOf(v) {
        return this.kinds.find((k) => k.v === v) ?? null;
    }

    /* ---- rules ---------------------------------------------------------- */
    offeredAtStation() {
        return this.rows.filter((r) => r.active && AT_STATION === r.kind && (!r.isNew || null === this.problem(r)));
    }

    lockedAtStation(r) {
        const offered = this.offeredAtStation();

        return r.active && AT_STATION === r.kind && 1 === offered.length && offered[0] === r;
    }

    problem(r) {
        if (!r.isNew) {
            return null;
        }
        if (!r.label.trim()) {
            return 'Name it — this is what a ranger reads.';
        }
        if (!KEY.test(r.key)) {
            return 'A key is lower-case letters, digits and _ , starting with a letter.';
        }
        const twin = [...this.saved.filter((s) => !this.rows.some((x) => !x.isNew && x.key === s.key)), ...this.rows]
            .find((x) => x !== r && x.key === r.key);
        if (twin) {
            return '“' + twin.label + '” already sends ' + r.key + (twin.active ? '' : ', though it is not offered') + ' — choose another key.';
        }
        if (this.refusedKey && r.key === this.refusedKey) {
            return 'Another save took ' + r.key + ' first — choose another key.';
        }
        if (!r.kind) {
            return 'Choose what it means — a kind is required.';
        }

        return null;
    }

    /* ---- the diff the bar counts ---------------------------------------- */
    changes() {
        const out = [];
        this.rows.forEach((r) => {
            const kind = this.kindOf(r.kind);
            if (r.isNew) {
                out.push({ who: r.label.trim() || 'A new status', what: 'added · ' + (r.key || 'no key yet') + ' · ' + (kind ? kind.label : 'no kind yet') });

                return;
            }
            const s = this.savedOf(r.key);
            if (!s) {
                return;
            }
            if (s.label !== r.label) {
                out.push({ who: s.label, what: 'label: ' + s.label + ' → ' + r.label });
            }
            if (s.kind !== r.kind) {
                out.push({ who: r.label, what: 'kind: ' + this.kindOf(s.kind).label + ' → ' + kind.label });
            }
            if (s.active !== r.active) {
                out.push({ who: r.label, what: r.active ? 'offered again' : 'no longer offered' });
            }
        });
        const was = ordered(this.saved).filter((r) => r.active).map((r) => r.key);
        const now = this.rows.filter((r) => r.active && !r.isNew).map((r) => r.key).filter((k) => was.includes(k));
        const wasKept = was.filter((k) => now.includes(k));
        if (now.join() !== wasKept.join()) {
            out.push({ who: 'Order', what: this.rows.filter((r) => r.active).map((r) => r.label || '…').join(' · ') });
        }

        return out;
    }

    /* ---- drawing -------------------------------------------------------- */
    row(r, i, actives) {
        const s = r.isNew ? null : this.savedOf(r.key);
        const locked = this.lockedAtStation(r);
        const kindFixed = !r.isNew && !!r.used;
        const problem = this.problem(r);
        const off = !r.active;
        const tr = document.createElement('tr');
        tr.dataset.id = r.id;
        if (off) {
            tr.className = 'cis-off';
        }
        const name = r.label || 'the new status';

        /* order */
        const order = document.createElement('td');
        const cmord = this.el('span', 'cmord');
        if (off) {
            cmord.append(this.el('span', 'none', '—'));
        } else {
            const carets = this.el('span', 'reorder');
            carets.append(
                this.caret('up', 'Move ' + name + ' up', 0 === i),
                this.caret('down', 'Move ' + name + ' down', i === actives - 1),
            );
            cmord.append(carets, this.el('span', 'n', String(i + 1)));
        }
        order.append(cmord);

        /* label and key */
        const nameCell = this.el('td', 'cis-nm');
        const labelChanged = !!s && s.label !== r.label;
        const line = this.el('span', 'cis-fl');
        const label = this.field('fld' + (labelChanged ? ' chg' : ''), 'label', r.label, 64, 'Label of ' + name);
        if (r.isNew) {
            label.placeholder = 'What a ranger reads';
        }
        label.disabled = off;
        line.append(label);
        if (labelChanged) {
            line.append(this.el('span', 'ed-dot'));
        }
        nameCell.append(line);
        if (r.isNew) {
            const keyLine = this.el('span', 'cis-keyline');
            const key = this.field('fld cis-key', 'key', r.key, 32, 'Key of ' + name);
            key.placeholder = 'key';
            keyLine.append(key, this.el('span', 'ed-hint', 'the phone sends this · fixed once saved'));
            nameCell.append(keyLine);
        } else {
            nameCell.append(this.el('span', 'sub', r.key));
        }
        if (problem) {
            nameCell.append(this.el('span', 'ed-hint cis-warn', problem));
        }

        /* kind */
        const kindCell = document.createElement('td');
        const seg = this.el('div', 'seg row');
        seg.setAttribute('role', 'radiogroup');
        seg.setAttribute('aria-label', 'Kind of ' + name);
        this.kinds.forEach((k) => {
            const on = r.kind === k.v;
            const button = this.el('button', on ? 'on' : '', k.label);
            button.type = 'button';
            button.setAttribute('role', 'radio');
            button.dataset.kind = k.v;
            button.setAttribute('aria-checked', String(on));
            button.tabIndex = on || (!r.kind && AT_STATION === k.v) ? 0 : -1;
            button.disabled = off || ((locked || kindFixed) && !on);
            seg.append(button);
        });
        kindCell.append(seg);
        if (s && s.kind !== r.kind) {
            kindCell.append(this.el('span', 'ed-dot'));
        }
        const kind = this.kindOf(r.kind);
        const means = this.el('span', 'ed-hint', kind ? kind.means : 'Choose what it means — required');
        means.dataset.meansLine = '';
        kindCell.append(means);
        if (kindFixed && !off) {
            kindCell.append(this.el('span', 'ed-hint', 'its check-ins read by this kind · add a new status to mean something else'));
        }

        /* offered */
        const door = this.el('td', 'door');
        const act = this.el('span', 'cis-act');
        if (r.isNew) {
            act.append(this.el('span', 'chip acc', 'new'), this.action('remove', 'Remove', false));
        } else if (off) {
            act.append(this.el('span', 'chip idle', 'not offered'), this.action('on', 'Reactivate', false));
        } else {
            act.append(this.el('span', 'chip ok', 'offered'), this.action('off', 'Deactivate', locked));
        }
        if (s && s.active !== r.active) {
            act.append(this.el('span', 'ed-dot'));
        }
        door.append(act);
        if (locked) {
            door.append(this.el('span', 'ed-hint', 'the only ' + this.kindOf(AT_STATION).label + ' offered · it stays'));
        } else if (off) {
            door.append(this.el('span', 'ed-hint', 'its past check-ins keep it'));
        } else if (r.isNew || !r.used) {
            door.append(this.el('span', 'ed-hint', 'nothing on record yet'));
        }

        tr.append(order, nameCell, kindCell, door);

        return tr;
    }

    el(tag, className, text) {
        const element = document.createElement(tag);
        if (className) {
            element.className = className;
        }
        if (undefined !== text) {
            element.textContent = text;
        }

        return element;
    }

    field(className, f, value, max, label) {
        const input = this.el('input', className);
        input.dataset.f = f;
        input.maxLength = max;
        input.value = value;
        input.setAttribute('aria-label', label);

        return input;
    }

    caret(direction, label, disabled) {
        const button = this.el('button');
        button.type = 'button';
        button.dataset.act = direction;
        button.setAttribute('aria-label', label);
        button.disabled = disabled;
        button.innerHTML = this.icons[direction];

        return button;
    }

    action(act, text, disabled) {
        const button = this.el('button', 'btn sm', text);
        button.type = 'button';
        button.dataset.act = act;
        button.disabled = disabled;

        return button;
    }

    draw(focus) {
        this.rows = ordered(this.rows);
        const actives = this.rows.filter((r) => r.active).length;
        let i = 0;
        this.tbody.replaceChildren(...this.rows.map((r) => this.row(r, r.active ? i++ : -1, actives)));

        const count = this.element.querySelector('[data-cis-count]');
        if (count) {
            const offN = this.rows.length - actives;
            count.textContent = '· ' + actives + ' offered' + (offN ? ' · ' + offN + ' not offered' : '');
        }

        const list = this.changes();
        const n = list.length;
        const blocked = this.rows.some((r) => null !== this.problem(r));
        if (n) {
            this.justSaved = false;
        }
        const message = this.bar.querySelector('b');
        const why = this.bar.querySelector('.cx-why');
        this.bar.classList.toggle('refused', !!this.refusal);
        message.textContent = this.refusal || (n ? n + ' unsaved change' + (1 === n ? '' : 's') : (this.justSaved ? 'Saved' : 'No unsaved changes'));
        why.textContent = this.refusal ? '' : (blocked ? '· finish the new status to save' : (this.justSaved && !n ? '· phones pick it up at their next sync' : ''));
        this.discardButton.disabled = !n;
        this.reviewButton.disabled = !n;
        this.saveButton.disabled = !n || blocked;
        if (!n) {
            this.reviewOpen = false;
        }
        this.review.hidden = !this.reviewOpen;
        this.reviewButton.textContent = this.reviewOpen ? 'Review ▾' : 'Review ▴';
        this.review.querySelector('.tab .src').textContent = ' · ' + n + ' change' + (1 === n ? '' : 's');
        this.review.querySelector('.ed-review').replaceChildren(...list.map((c) => {
            const item = document.createElement('li');
            item.append(this.el('b', '', c.who), document.createTextNode(' · '), this.el('em', '', c.what));

            return item;
        }));

        if (focus) {
            const target = this.tbody.querySelector('tr[data-id="' + focus.id + '"] ' + focus.sel);
            if (target) {
                target.focus();
                if (undefined !== focus.caret && target.setSelectionRange) {
                    target.setSelectionRange(focus.caret, focus.caret);
                }
            }
        }
    }

    /* ---- changing ------------------------------------------------------- */
    byId(id) {
        return this.rows.find((r) => r.id === id);
    }

    move(id, d) {
        const actives = this.rows.filter((r) => r.active);
        const i = actives.findIndex((r) => r.id === id);
        const j = i + d;
        if (i < 0 || j < 0 || j >= actives.length) {
            return;
        }
        [actives[i], actives[j]] = [actives[j], actives[i]];
        this.rows = [...actives, ...this.rows.filter((r) => !r.active)];
    }

    touched() {
        this.refusal = null;
    }

    click(event) {
        const t = event.target.closest('button');
        if (!t || t.disabled || !this.tbody.contains(t)) {
            return;
        }
        const r = this.byId(t.closest('tr').dataset.id);
        const act = t.dataset.act;
        this.touched();
        if ('radio' === t.getAttribute('role')) {
            r.kind = t.dataset.kind;
            this.draw({ id: r.id, sel: '[data-kind="' + r.kind + '"]' });

            return;
        }
        if ('up' === act || 'down' === act) {
            this.move(r.id, 'up' === act ? -1 : 1);
            this.draw({ id: r.id, sel: '[data-act="' + act + '"]:not([disabled])' });

            return;
        }
        if ('off' === act) {
            r.active = false;
            this.draw({ id: r.id, sel: '[data-act="on"]' });

            return;
        }
        if ('on' === act) {
            r.active = true;
            this.rows = [...this.rows.filter((x) => x.active && x !== r), r, ...this.rows.filter((x) => !x.active)];
            this.draw({ id: r.id, sel: '[data-act="off"]' });

            return;
        }
        if ('remove' === act) {
            this.rows = this.rows.filter((x) => x !== r);
            this.draw();
        }
    }

    keydown(event) {
        const t = event.target;
        if ('radio' !== t.getAttribute('role')) {
            return;
        }
        const options = [...t.closest('.seg.row').querySelectorAll('[role="radio"]:not([disabled])')];
        let i = options.indexOf(t);
        if ('ArrowRight' === event.key || 'ArrowDown' === event.key) {
            i = (i + 1) % options.length;
        } else if ('ArrowLeft' === event.key || 'ArrowUp' === event.key) {
            i = (i - 1 + options.length) % options.length;
        } else if (' ' === event.key || 'Enter' === event.key) {
            event.preventDefault();
            t.click();

            return;
        } else {
            return;
        }
        event.preventDefault();
        options[i].focus();
    }

    input(event) {
        const t = event.target;
        const f = t.dataset.f;
        if (!f) {
            return;
        }
        const r = this.byId(t.closest('tr').dataset.id);
        this.touched();
        if ('label' === f) {
            r.label = t.value;
            if (r.isNew && !r.keyTouched) {
                r.key = derive(t.value);
            }
        } else {
            r.key = t.value;
            r.keyTouched = true;
        }
        this.draw({ id: r.id, sel: '[data-f="' + f + '"]', caret: t.selectionStart });
    }

    add() {
        this.touched();
        const r = this.stamp({ key: '', label: '', kind: null, active: true, used: false, isNew: true, keyTouched: false });
        this.rows.push(r);
        this.draw({ id: r.id, sel: '[data-f="label"]' });
    }

    discard() {
        this.rows = clone(this.saved);
        this.refusal = null;
        this.refusedKey = null;
        this.justSaved = false;
        this.reviewOpen = false;
        this.draw();
    }

    submit(event) {
        if (!this.changes().length || this.rows.some((r) => null !== this.problem(r))) {
            event.preventDefault();

            return;
        }
        this.element.querySelectorAll('input[data-cis-post]').forEach((input) => input.remove());
        ordered(this.rows).forEach((r, i) => {
            const fields = { key: r.key, label: r.label, kind: r.kind ?? '', active: r.active ? '1' : '0' };
            if (r.isNew) {
                fields.new = '1';
            }
            Object.entries(fields).forEach(([name, value]) => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'statuses[' + i + '][' + name + ']';
                input.value = value;
                input.dataset.cisPost = '';
                this.element.append(input);
            });
        });
    }
}
