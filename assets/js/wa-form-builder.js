/**
 * WA Native Forms: visual builder for WhatsApp Flow JSON (like Meta's Flow Playground).
 * Screens on the left, questions in the middle, a live WhatsApp preview on the right.
 * Produces static Flow JSON: every screen passes earlier answers forward via `data`,
 * the last screen "completes" with all answers so they reach the webhook as one response.
 */
(function () {
    'use strict';

    const KINDS = {
        heading:    { type: 'TextHeading',       name: 'Large heading',   grp: 'text', text: 80 },
        subheading: { type: 'TextSubheading',    name: 'Small heading',   grp: 'text', text: 80 },
        body:       { type: 'TextBody',          name: 'Paragraph',       grp: 'text', text: 4096 },
        caption:    { type: 'TextCaption',       name: 'Caption',         grp: 'text', text: 4096 },
        text:       { type: 'TextInput',         name: 'Short answer',    grp: 'q', label: 20, helper: true, input: true },
        textarea:   { type: 'TextArea',          name: 'Long answer',     grp: 'q', label: 20, helper: true },
        radio:      { type: 'RadioButtonsGroup', name: 'Single choice',   grp: 'q', label: 30, opts: true },
        checkbox:   { type: 'CheckboxGroup',     name: 'Multiple choice', grp: 'q', label: 30, opts: true },
        dropdown:   { type: 'Dropdown',          name: 'Dropdown',        grp: 'q', label: 20, opts: true },
        date:       { type: 'DatePicker',        name: 'Date',            grp: 'q', label: 40 },
        optin:      { type: 'OptIn',             name: 'Opt-in checkbox', grp: 'q', label: 120 },
    };
    const BY_TYPE = {};
    Object.keys(KINDS).forEach(k => { BY_TYPE[KINDS[k].type] = k; });
    const INPUT_TYPES = [['text', 'Text'], ['number', 'Number'], ['email', 'Email'], ['phone', 'Phone']];
    const DEFAULTS = {
        heading: { text: 'Your heading' },
        subheading: { text: 'Your small heading' },
        body: { text: 'Some helpful text for your customer.' },
        caption: { text: 'Small print' },
        text: { label: 'Full name', inputType: 'text', required: true },
        textarea: { label: 'Comments', required: false },
        radio: { label: 'Choose one', options: ['Yes', 'No'], required: true },
        checkbox: { label: 'Choose any', options: ['Option 1', 'Option 2'], required: false },
        dropdown: { label: 'Select one', options: ['Option 1', 'Option 2'], required: true },
        date: { label: 'Pick a date', required: true },
        optin: { label: 'I agree to be contacted on WhatsApp', required: false },
    };
    const LIM = { title: 30, button: 35, option: 30, helper: 80 };

    let state = blank();
    let sel = 0;      // screen being edited
    let pv = 0;       // screen shown in the preview
    let pvDone = false;
    let root, elScreens, elEditor, elPreview;

    function blank() {
        return { screens: [{ title: 'Your details', button: 'Submit', items: [
            { k: 'subheading', text: 'Tell us a little about you' },
            { k: 'text', label: 'Full name', inputType: 'text', required: true },
        ] }] };
    }

    const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const slug = s => String(s || '').toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '').slice(0, 40);
    const isQ = it => KINDS[it.k].grp === 'q';
    function letters(i) {
        let s = ''; i += 1;
        while (i > 0) { const m = (i - 1) % 26; s = String.fromCharCode(65 + m) + s; i = Math.floor((i - 1) / 26); }
        return s;
    }

    /* ── Flow JSON out ── */

    // Unique field names across the whole form (answers from every screen end up in one response).
    function assignNames() {
        const used = {};
        state.screens.forEach(s => s.items.forEach(it => {
            if (!isQ(it)) return;
            let base = /^[a-zA-Z][a-zA-Z0-9_]*$/.test(it.name || '') ? it.name : slug(it.label) || it.k;
            if (/^\d/.test(base)) base = 'f_' + base;
            let n = base, i = 2;
            while (used[n]) n = base + '_' + (i++);
            used[n] = true;
            it._n = n;
        }));
    }

    function optionList(it) {
        const seen = {};
        return (it.options || []).filter(o => String(o).trim() !== '').map((o, i) => {
            let id = slug(o) || 'option_' + (i + 1), n = id, j = 2;
            while (seen[n]) n = id + '_' + (j++);
            seen[n] = true;
            return { id: n, title: String(o).trim() };
        });
    }

    function toComponent(it) {
        const K = KINDS[it.k];
        if (K.text) return { type: K.type, text: it.text || '' };
        const c = { type: K.type, name: it._n, label: it.label || '' };
        if (it.k === 'text') c['input-type'] = it.inputType || 'text';
        if (K.opts) c['data-source'] = optionList(it);
        c.required = !!it.required;
        if (K.helper && (it.helper || '').trim()) c['helper-text'] = it.helper.trim();
        return c;
    }

    function dataSchema(it) {
        if (it.k === 'checkbox') return { type: 'array', items: { type: 'string' }, __example__: [] };
        if (it.k === 'optin') return { type: 'boolean', __example__: true };
        return { type: 'string', __example__: 'Example' };
    }

    function build() {
        assignNames();
        const ids = state.screens.map((_, i) => 'SCREEN_' + letters(i));
        let carried = [];
        const screens = state.screens.map((s, i) => {
            const last = i === state.screens.length - 1;
            const here = s.items.filter(isQ);
            const children = s.items.map(toComponent);
            const payload = {};
            carried.forEach(it => { payload[it._n] = '${data.' + it._n + '}'; });
            here.forEach(it => { payload[it._n] = '${form.' + it._n + '}'; });
            const action = last
                ? { name: 'complete', payload: payload }
                : { name: 'navigate', next: { type: 'screen', name: ids[i + 1] }, payload: payload };
            children.push({ type: 'Footer', label: (s.button || '').trim() || (last ? 'Submit' : 'Continue'), 'on-click-action': action });
            const data = {};
            carried.forEach(it => { data[it._n] = dataSchema(it); });
            const scr = { id: ids[i], title: (s.title || '').trim() || 'Form' };
            if (last) { scr.terminal = true; scr.success = true; }
            scr.data = data;
            scr.layout = { type: 'SingleColumnLayout', children: children };
            carried = carried.concat(here);
            return scr;
        });
        return { version: '6.0', screens: screens };
    }

    function validate() {
        let questions = 0;
        for (let i = 0; i < state.screens.length; i++) {
            const s = state.screens[i], where = 'Screen ' + (i + 1);
            if (!s.items.length) return where + ' is empty. Add at least one item or remove the screen.';
            for (const it of s.items) {
                const K = KINDS[it.k];
                if (K.text && !(it.text || '').trim()) return where + ': a ' + K.name.toLowerCase() + ' has no text.';
                if (!K.text && !(it.label || '').trim()) return where + ': a ' + K.name.toLowerCase() + ' question has no label.';
                if (K.opts && !optionList(it).length) return where + ': "' + it.label + '" needs at least one option.';
                if (isQ(it)) questions++;
            }
        }
        if (!questions) return 'Add at least one question (short answer, choice, date…) so the form collects something.';
        return '';
    }

    /* ── Flow JSON in (starters, drafts, Meta Playground JSON) ── */

    function fromJson(j) {
        if (typeof j === 'string') { try { j = JSON.parse(j); } catch (e) { return null; } }
        if (!j || !Array.isArray(j.screens) || !j.screens.length) return null;
        const flat = arr => (arr || []).reduce((o, c) => o.concat(c && c.type === 'Form' ? flat(c.children) : [c]), []);
        const screens = [];
        for (const sc of j.screens) {
            const s = { title: String(sc.title || ''), button: '', items: [] };
            for (const c of flat(sc.layout && sc.layout.children)) {
                if (!c || typeof c !== 'object') return null;
                if (c.type === 'Footer') { s.button = String(c.label || ''); continue; }
                const k = BY_TYPE[c.type];
                if (!k) return null;
                const K = KINDS[k], it = { k: k };
                if (K.text) {
                    if (typeof c.text !== 'string') return null;
                    it.text = c.text;
                } else {
                    if (typeof c.label !== 'string') return null;
                    it.label = c.label;
                    it.name = c.name;
                    it.required = !!c.required;
                    if (k === 'text') it.inputType = c['input-type'] || 'text';
                    if (K.helper) it.helper = c['helper-text'] || '';
                    if (K.opts) {
                        if (!Array.isArray(c['data-source'])) return null;
                        it.options = c['data-source'].map(o => String(o.title || ''));
                    }
                }
                s.items.push(it);
            }
            screens.push(s);
        }
        return { screens: screens };
    }

    /* ── Rendering ── */

    function counter(v, max) {
        const n = String(v || '').length;
        return '<span class="wfb-cnt' + (n > max ? ' over' : '') + '" data-max="' + max + '">' + n + '/' + max + '</span>';
    }

    function renderScreens() {
        elScreens.innerHTML = '<div class="wfb-h">Screens</div>' + state.screens.map((s, i) =>
            '<div class="wfb-scr' + (i === sel ? ' on' : '') + '" data-act="sel" data-i="' + i + '">' +
                '<span class="wfb-scr-n">' + (i + 1) + '</span><span class="wfb-scr-t">' + esc(s.title || 'Untitled screen') + '</span>' +
                (state.screens.length > 1 ? '<button type="button" class="wfb-x" data-act="delscr" data-i="' + i + '" aria-label="Remove screen">×</button>' : '') +
            '</div>').join('') +
            (state.screens.length < 10 ? '<button type="button" class="wfb-add-scr" data-act="addscr">+ Add screen</button>' : '') +
            '<p class="wfb-tip">Each screen is one page of the form. Customers tap <b>Continue</b> to go to the next one.</p>';
    }

    function itemFields(it, j) {
        const K = KINDS[it.k];
        const f = (field, val, max, ph, area) => (area
            ? '<textarea class="form-control" rows="3" data-f="' + field + '" data-j="' + j + '" placeholder="' + esc(ph || '') + '">' + esc(val) + '</textarea>'
            : '<input class="form-control" data-f="' + field + '" data-j="' + j + '" value="' + esc(val) + '" placeholder="' + esc(ph || '') + '">') + counter(val, max);
        let h = '';
        if (K.text) return '<div class="wfb-fld">' + f('text', it.text, K.text, 'Text', it.k === 'body' || it.k === 'caption') + '</div>';
        h += '<div class="wfb-fld"><label>Question</label>' + f('label', it.label, K.label, 'e.g. Your name') + '</div>';
        if (it.k === 'text') {
            h += '<div class="wfb-fld"><label>Answer type</label><select class="form-control" data-f="inputType" data-j="' + j + '">' +
                INPUT_TYPES.map(t => '<option value="' + t[0] + '"' + ((it.inputType || 'text') === t[0] ? ' selected' : '') + '>' + t[1] + '</option>').join('') + '</select></div>';
        }
        if (K.opts) {
            h += '<div class="wfb-fld"><label>Options</label>' + (it.options || []).map((o, oi) =>
                '<div class="wfb-opt"><input class="form-control" data-f="opt" data-o="' + oi + '" data-j="' + j + '" value="' + esc(o) + '" placeholder="Option ' + (oi + 1) + '">' + counter(o, LIM.option) +
                ((it.options || []).length > 1 ? '<button type="button" class="wfb-x" data-act="delopt" data-o="' + oi + '" data-j="' + j + '" aria-label="Remove option">×</button>' : '') + '</div>').join('') +
                ((it.options || []).length < 20 ? '<button type="button" class="wfb-link" data-act="addopt" data-j="' + j + '">+ Add option</button>' : '') + '</div>';
        }
        if (K.helper) h += '<div class="wfb-fld"><label>Hint under the box (optional)</label>' + f('helper', it.helper || '', LIM.helper, 'e.g. We never share it') + '</div>';
        h += '<label class="wfb-req"><input type="checkbox" data-f="required" data-j="' + j + '"' + (it.required ? ' checked' : '') + '> Required</label>';
        return h;
    }

    function renderEditor() {
        const s = state.screens[sel];
        const last = sel === state.screens.length - 1;
        let h = '<div class="wfb-scr-set">' +
            '<div class="wfb-fld"><label>Screen title (top bar)</label><input class="form-control" data-s="title" value="' + esc(s.title) + '" placeholder="e.g. Book a visit">' + counter(s.title, LIM.title) + '</div>' +
            '<div class="wfb-fld"><label>Button text</label><input class="form-control" data-s="button" value="' + esc(s.button) + '" placeholder="' + (last ? 'Submit' : 'Continue') + '">' + counter(s.button, LIM.button) + '</div>' +
        '</div>';
        h += s.items.map((it, j) =>
            '<div class="wfb-item">' +
                '<div class="wfb-item-h"><b>' + KINDS[it.k].name + '</b><span>' +
                    (j > 0 ? '<button type="button" data-act="up" data-j="' + j + '" title="Move up">↑</button>' : '') +
                    (j < s.items.length - 1 ? '<button type="button" data-act="down" data-j="' + j + '" title="Move down">↓</button>' : '') +
                    '<button type="button" class="del" data-act="del" data-j="' + j + '" title="Remove">×</button></span></div>' +
                itemFields(it, j) +
            '</div>').join('');
        if (!s.items.length) h += '<div class="wfb-empty">This screen is empty. Add a question or some text below.</div>';
        const btn = k => '<button type="button" data-act="add" data-k="' + k + '">' + KINDS[k].name + '</button>';
        h += '<div class="wfb-addbox"><div class="wfb-h">Add a question</div><div class="wfb-adds">' +
            ['text', 'textarea', 'radio', 'checkbox', 'dropdown', 'date', 'optin'].map(btn).join('') +
            '</div><div class="wfb-h">Add text</div><div class="wfb-adds">' + ['heading', 'subheading', 'body', 'caption'].map(btn).join('') + '</div></div>';
        elEditor.innerHTML = h;
    }

    function renderPreview() {
        if (pv >= state.screens.length) pv = state.screens.length - 1;
        const s = state.screens[pv], last = pv === state.screens.length - 1;
        const body = s.items.map((it, j) => {
            const n = 'pv' + pv + '_' + j, lab = esc(it.label);
            switch (it.k) {
                case 'heading': return '<h3>' + esc(it.text) + '</h3>';
                case 'subheading': return '<h4>' + esc(it.text) + '</h4>';
                case 'body': return '<p>' + esc(it.text) + '</p>';
                case 'caption': return '<small class="cap">' + esc(it.text) + '</small>';
                case 'text': return '<div class="in"><input type="' + (it.inputType === 'number' ? 'number' : 'text') + '" placeholder="' + lab + (it.required ? '' : ' (optional)') + '">' + (it.helper ? '<small>' + esc(it.helper) + '</small>' : '') + '</div>';
                case 'textarea': return '<div class="in"><textarea rows="3" placeholder="' + lab + (it.required ? '' : ' (optional)') + '"></textarea>' + (it.helper ? '<small>' + esc(it.helper) + '</small>' : '') + '</div>';
                case 'dropdown': return '<div class="in"><select><option>' + lab + '</option>' + (it.options || []).map(o => '<option>' + esc(o) + '</option>').join('') + '</select></div>';
                case 'date': return '<div class="in"><label>' + lab + '</label><input type="date"></div>';
                case 'optin': return '<label class="opt"><input type="checkbox"><span>' + lab + '</span></label>';
                case 'radio':
                case 'checkbox': {
                    const t = it.k === 'radio' ? 'radio' : 'checkbox';
                    return '<div class="grp"><div class="gl">' + lab + '</div>' + (it.options || []).filter(o => o.trim()).map(o =>
                        '<label class="ch"><span>' + esc(o) + '</span><input type="' + t + '" name="' + n + '"></label>').join('') + '</div>';
                }
            }
            return '';
        }).join('');
        elPreview.innerHTML =
            '<div class="wfb-ph-cap">Live preview · Screen ' + (pv + 1) + ' of ' + state.screens.length + '</div>' +
            '<div class="wfb-phone">' +
                '<div class="wfb-ph-top"><span>✕</span><b>' + esc(s.title || 'Form') + '</b><span>⋮</span></div>' +
                '<div class="wfb-ph-body">' + (body || '<p class="muted">Nothing on this screen yet.</p>') + '</div>' +
                '<div class="wfb-ph-foot"><button type="button" data-act="pnext">' + esc((s.button || '').trim() || (last ? 'Submit' : 'Continue')) + '</button>' +
                '<small>Managed by the business. <u>Learn more</u></small></div>' +
                (pvDone ? '<div class="wfb-ph-done"><div>✅</div><b>Response sent</b><span>This is what the customer sees after submitting.</span><button type="button" data-act="prestart">Start again</button></div>' : '') +
            '</div>' +
            (pv > 0 && !pvDone ? '<button type="button" class="wfb-link" data-act="pback">← Previous screen</button>' : '');
    }

    function renderAll() { renderScreens(); renderEditor(); renderPreview(); }

    /* ── Events ── */

    function onClick(e) {
        const b = e.target.closest('[data-act]');
        if (!b || !root.contains(b)) return;
        const act = b.dataset.act, s = state.screens[sel], j = +b.dataset.j, i = +b.dataset.i;
        switch (act) {
            case 'sel': if (e.target.closest('.wfb-x')) return; sel = pv = i; pvDone = false; break;
            case 'addscr':
                state.screens.forEach((x, xi) => { if (!x.button || x.button === 'Submit') x.button = xi === state.screens.length - 1 ? 'Continue' : x.button; });
                state.screens.push({ title: 'Screen ' + (state.screens.length + 1), button: 'Submit', items: [{ k: 'text', label: 'Your answer', inputType: 'text', required: true }] });
                sel = pv = state.screens.length - 1; pvDone = false; break;
            case 'delscr':
                if (!confirm('Remove this screen and everything on it?')) return;
                state.screens.splice(i, 1);
                sel = Math.min(sel, state.screens.length - 1); pv = sel; break;
            case 'add':
                s.items.push(Object.assign({ k: b.dataset.k }, JSON.parse(JSON.stringify(DEFAULTS[b.dataset.k]))));
                pv = sel; break;
            case 'del': s.items.splice(j, 1); break;
            case 'up': s.items.splice(j - 1, 0, s.items.splice(j, 1)[0]); break;
            case 'down': s.items.splice(j + 1, 0, s.items.splice(j, 1)[0]); break;
            case 'addopt': s.items[j].options.push('Option ' + (s.items[j].options.length + 1)); break;
            case 'delopt': s.items[j].options.splice(+b.dataset.o, 1); break;
            case 'pnext': if (pv < state.screens.length - 1) pv++; else pvDone = true; renderPreview(); return;
            case 'pback': pv = Math.max(0, pv - 1); renderPreview(); return;
            case 'prestart': pv = 0; pvDone = false; renderPreview(); return;
            default: return;
        }
        renderAll();
    }

    function onInput(e) {
        const t = e.target, s = state.screens[sel];
        if (t.dataset.s) {
            s[t.dataset.s] = t.value;
            if (t.dataset.s === 'title') renderScreens();
        } else if (t.dataset.f) {
            const it = s.items[+t.dataset.j];
            if (!it) return;
            if (t.dataset.f === 'opt') it.options[+t.dataset.o] = t.value;
            else if (t.dataset.f === 'required') it.required = t.checked;
            else it[t.dataset.f] = t.value;
        } else return;
        const c = t.parentNode.querySelector('.wfb-cnt');
        if (c && t.nextElementSibling === c) {
            const max = +c.dataset.max;
            c.textContent = t.value.length + '/' + max;
            c.classList.toggle('over', t.value.length > max);
        }
        pv = sel; pvDone = false;
        renderPreview();
    }

    window.WFBuilder = {
        mount: function (el) {
            root = el;
            root.innerHTML = '<div class="wfb-col wfb-left"></div><div class="wfb-col wfb-mid"></div><div class="wfb-col wfb-right"></div>';
            elScreens = root.querySelector('.wfb-left');
            elEditor = root.querySelector('.wfb-mid');
            elPreview = root.querySelector('.wfb-right');
            root.addEventListener('click', onClick);
            root.addEventListener('input', onInput);
            root.addEventListener('change', onInput);
            renderAll();
        },
        reset: function () { state = blank(); sel = pv = 0; pvDone = false; renderAll(); },
        /** Load Flow JSON (object or string). Returns false when it uses parts the builder can't edit. */
        load: function (json) {
            const s = fromJson(json);
            if (!s) return false;
            state = s; sel = pv = 0; pvDone = false; renderAll();
            return true;
        },
        validate: validate,
        json: function () { return JSON.stringify(build(), null, 2); },
    };
})();
