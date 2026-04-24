import { FileBrowserModal } from '../file-browser/components/FileBrowserModal';
import { t } from './i18n';

/**
 * DynamicParamsForm — renders a Bootstrap form from a model descriptor's
 * `params[]` array (already normalized by the gateway).
 *
 * Groups:  prompt → input → dimensions → output → advanced
 *
 * Param types handled:
 *   text | textarea | number | integer | boolean | enum | image | image[] | object
 *
 * Value shape (returned by `collect()`):
 *   - flat object keyed by `param.key` (may be dot-path, which the gateway
 *     mapper nests into the final Runware payload)
 *   - empty/unchanged values are omitted so descriptor defaults stay in charge
 */
export class DynamicParamsForm {
    static GROUP_ORDER = ['prompt', 'input', 'dimensions', 'output', 'advanced'];
    // Fallback labels — translated versions live under `form.groups.*`.
    static GROUP_LABELS = {
        prompt:     'Prompt',
        input:      'Input',
        dimensions: 'Dimensions',
        output:     'Output',
        advanced:   'Advanced',
    };

    /**
     * @param {HTMLElement} container   Where to render the form
     * @param {object}      descriptor  Normalized model descriptor from gateway
     */
    constructor(container, descriptor) {
        this.container = container;
        this.descriptor = descriptor;
        this.inputs = new Map(); // key -> { el, param }
        this.render();
    }

    render() {
        this.container.innerHTML = '';
        this.inputs.clear();

        const params = Array.isArray(this.descriptor?.params) ? this.descriptor.params : [];
        if (!params.length) {
            this.container.innerHTML = `<p class="text-muted small mb-0">${this._escape(t('form.no_params', 'This model has no configurable parameters.'))}</p>`;
            return;
        }

        // Group params
        const groups = new Map();
        for (const p of params) {
            const g = p.group || 'advanced';
            if (!groups.has(g)) groups.set(g, []);
            groups.get(g).push(p);
        }

        // Render groups in canonical order, then any leftovers
        const renderedGroups = new Set();
        for (const gKey of DynamicParamsForm.GROUP_ORDER) {
            if (groups.has(gKey)) {
                this._renderGroup(gKey, groups.get(gKey));
                renderedGroups.add(gKey);
            }
        }
        for (const [gKey, gParams] of groups.entries()) {
            if (!renderedGroups.has(gKey)) {
                this._renderGroup(gKey, gParams);
            }
        }
    }

    _renderGroup(groupKey, params) {
        // Prefer the translated label; fall back to English static label,
        // then to a capitalized form of the raw group key.
        const label = t(
            `form.groups.${groupKey}`,
            DynamicParamsForm.GROUP_LABELS[groupKey]
                || groupKey.charAt(0).toUpperCase() + groupKey.slice(1)
        );

        const collapsed = groupKey === 'advanced';
        const id = `imager-group-${groupKey}`;

        const section = document.createElement('div');
        section.className = 'imager-form-group mb-2';
        section.innerHTML = `
            <div class="imager-form-group-header d-flex align-items-center"
                 data-bs-toggle="collapse" data-bs-target="#${id}"
                 aria-expanded="${!collapsed}" role="button">
                <i class="mdi mdi-chevron-${collapsed ? 'right' : 'down'} imager-group-caret me-1"></i>
                <span class="text-cyber small fw-bold text-uppercase">${label}</span>
            </div>
            <div id="${id}" class="collapse ${collapsed ? '' : 'show'} imager-form-group-body pt-2"></div>
        `;
        this.container.appendChild(section);

        const body = section.querySelector(`#${id}`);

        // Dimensions group: lay width + height side-by-side on one row.
        const byKey = Object.fromEntries(params.map(p => [p.key, p]));
        const widthP  = byKey['width'];
        const heightP = byKey['height'];
        if (groupKey === 'dimensions' && widthP && heightP) {
            const row = document.createElement('div');
            row.className = 'row g-2 mb-1';
            const colW = document.createElement('div');
            colW.className = 'col-6';
            colW.appendChild(this._renderParam(widthP));
            const colH = document.createElement('div');
            colH.className = 'col-6';
            colH.appendChild(this._renderParam(heightP));
            row.appendChild(colW);
            row.appendChild(colH);
            body.appendChild(row);
            // Render remaining dimension params (aspectRatio, etc.) below
            for (const p of params) {
                if (p.key === 'width' || p.key === 'height') continue;
                body.appendChild(this._renderParam(p));
            }
        } else {
            for (const p of params) {
                body.appendChild(this._renderParam(p));
            }
        }

        // Toggle caret icon on collapse events
        body.addEventListener('show.bs.collapse', () => {
            const caret = section.querySelector('.imager-group-caret');
            if (caret) caret.className = 'mdi mdi-chevron-down imager-group-caret me-1';
        });
        body.addEventListener('hide.bs.collapse', () => {
            const caret = section.querySelector('.imager-group-caret');
            if (caret) caret.className = 'mdi mdi-chevron-right imager-group-caret me-1';
        });
    }

    _renderParam(param) {
        const wrapper = document.createElement('div');
        wrapper.className = 'mb-2 imager-form-field';

        const labelId = `imager-input-${this._safeId(param.key)}`;
        const requiredMark = param.required ? '<span class="text-warning ms-1">*</span>' : '';
        const titleHtml = `
            <label class="form-label small text-muted mb-1 d-flex align-items-center" for="${labelId}">
                <span>${this._escape(param.title || param.key)}${requiredMark}</span>
                ${param.description
                    ? `<i class="mdi mdi-information-outline ms-1 opacity-50" title="${this._escape(param.description)}"></i>`
                    : ''}
            </label>
        `;

        let control;
        switch (param.type) {
            case 'boolean':
                control = this._renderBoolean(param, labelId);
                break;
            case 'enum':
                control = this._renderEnum(param, labelId);
                break;
            case 'textarea':
                control = this._renderTextarea(param, labelId);
                break;
            case 'number':
            case 'integer':
                control = this._renderNumber(param, labelId);
                break;
            case 'image':
                control = this._renderImage(param, labelId);
                break;
            case 'image[]':
                control = this._renderImageArray(param, labelId);
                break;
            case 'object':
                control = this._renderJsonObject(param, labelId);
                break;
            default: // text and unknown
                // Catalog flags prompts with `multiline: true` — render as textarea
                // so positivePrompt/negativePrompt get the roomy input they deserve.
                control = param.multiline
                    ? this._renderTextarea(param, labelId)
                    : this._renderText(param, labelId);
        }

        wrapper.innerHTML = titleHtml;
        wrapper.appendChild(control);
        this.inputs.set(param.key, { el: control, param });
        return wrapper;
    }

    _renderText(param, id) {
        const input = document.createElement('input');
        input.type = 'text';
        input.id = id;
        input.className = 'form-control form-control-sm';
        if (param.default != null) input.value = String(param.default);
        if (param.placeholder) input.placeholder = param.placeholder;
        if (param.min != null) input.minLength = param.min;
        if (param.max != null) input.maxLength = param.max;
        return input;
    }

    _renderTextarea(param, id) {
        const input = document.createElement('textarea');
        input.id = id;
        input.className = 'form-control form-control-sm';
        input.rows = param.key === 'positivePrompt' ? 4 : 2;
        if (param.default != null) input.value = String(param.default);
        if (param.placeholder) input.placeholder = param.placeholder;
        if (param.max != null) input.maxLength = param.max;
        return input;
    }

    _renderNumber(param, id) {
        const input = document.createElement('input');
        input.type = 'number';
        input.id = id;
        input.className = 'form-control form-control-sm';
        if (param.default != null) input.value = String(param.default);
        if (param.min != null) input.min = param.min;
        if (param.max != null) input.max = param.max;
        input.step = param.type === 'integer' ? 1 : (param.step ?? 'any');
        return input;
    }

    _renderBoolean(param, id) {
        const wrap = document.createElement('div');
        wrap.className = 'form-check form-switch';
        const onLbl  = t('form.on', 'on');
        const offLbl = t('form.off', 'off');
        wrap.innerHTML = `
            <input class="form-check-input" type="checkbox" id="${id}"
                ${param.default ? 'checked' : ''}>
            <label class="form-check-label small text-muted" for="${id}">
                ${this._escape(param.default ? onLbl : offLbl)}
            </label>
        `;
        const cb = wrap.querySelector('input');
        const lbl = wrap.querySelector('label');
        cb.addEventListener('change', () => { lbl.textContent = cb.checked ? onLbl : offLbl; });
        return wrap;
    }

    _renderEnum(param, id) {
        const select = document.createElement('select');
        select.id = id;
        select.className = 'form-select form-select-sm';

        const values = Array.isArray(param.enum) ? param.enum : [];
        // Allow empty option if not required
        if (!param.required) {
            const opt = document.createElement('option');
            opt.value = '';
            opt.textContent = '—';
            select.appendChild(opt);
        }
        for (const v of values) {
            const opt = document.createElement('option');
            opt.value = String(v);
            opt.textContent = String(v);
            if (param.default != null && String(param.default) === String(v)) opt.selected = true;
            select.appendChild(opt);
        }
        return select;
    }

    _renderImage(param, id) {
        // Input-group: text input + "Add image" button (opens File Browser modal).
        // The button writes a `cqfile://<id>#<name>` token into the input; the
        // backend resolves tokens to data URIs before calling the gateway.
        const wrap = document.createElement('div');
        wrap.className = 'imager-image-field';
        wrap.innerHTML = `
            <div class="input-group input-group-sm">
                <input type="text" id="${id}" class="form-control form-control-sm"
                       placeholder="${this._escape(t('form.image_placeholder', 'Image URL, data URI, or click +'))}" data-role="input">
                <button type="button" class="btn btn-outline-cyber imager-image-attach"
                        data-role="attach" title="${this._escape(t('form.pick_file', 'Pick from File Browser'))}">
                    <i class="mdi mdi-folder-image-outline"></i>
                </button>
            </div>
            <div class="small text-muted imager-image-file-hint" data-role="hint" hidden></div>
        `;
        wrap.querySelector('[data-role="attach"]').addEventListener('click', async () => {
            const file = await this._pickFromFileBrowser();
            if (!file) return;
            const input = wrap.querySelector('[data-role="input"]');
            input.value = `cqfile://${file.id}#${file.name}`;
            this._setFileHint(wrap, file);
        });
        return wrap;
    }

    _renderImageArray(param, id) {
        // Textarea (one URL/UUID/token per line) + "Add image" button that
        // appends a `cqfile://<id>#<name>` token as a new line.
        const wrap = document.createElement('div');
        wrap.className = 'imager-image-field';
        wrap.innerHTML = `
            <textarea id="${id}" class="form-control form-control-sm" rows="3"
                placeholder="${this._escape(t('form.image_array_placeholder', 'One image URL / UUID / data URI / cqfile://… per line'))}"
                data-role="input"></textarea>
            <div class="d-flex justify-content-end mt-1">
                <button type="button" class="btn btn-sm btn-outline-cyber imager-image-attach"
                        data-role="attach" title="${this._escape(t('form.pick_file', 'Pick from File Browser'))}">
                    <i class="mdi mdi-image-plus-outline me-1"></i>${this._escape(t('form.add_image', 'Add image'))}
                </button>
            </div>
        `;
        wrap.querySelector('[data-role="attach"]').addEventListener('click', async () => {
            const file = await this._pickFromFileBrowser();
            if (!file) return;
            const ta = wrap.querySelector('[data-role="input"]');
            const token = `cqfile://${file.id}#${file.name}`;
            ta.value = ta.value
                ? ta.value.replace(/\s*$/, '') + '\n' + token
                : token;
        });
        return wrap;
    }

    /**
     * Open the shared File Browser modal and return the selected file
     * (image-like) or null. Lazily instantiated and reused.
     */
    async _pickFromFileBrowser() {
        if (!this._fileBrowserModal) {
            this._fileBrowserModal = new FileBrowserModal({ translations: {} });
        }
        const file = await this._fileBrowserModal.open();
        if (!file) return null;
        if (!file.isImage) {
            document.dispatchEvent(new CustomEvent('cq-imager:toast', {
                detail: { type: 'error', msg: t('form.select_image_file', 'Please select an image file.') }
            }));
            return null;
        }
        return file;
    }

    _setFileHint(wrap, file) {
        const hint = wrap.querySelector('[data-role="hint"]');
        if (!hint) return;
        hint.hidden = false;
        hint.innerHTML = `<i class="mdi mdi-file-image-outline me-1 opacity-75"></i>${this._escape(file.name)}`;
    }

    _renderJsonObject(param, id) {
        const ta = document.createElement('textarea');
        ta.id = id;
        ta.className = 'form-control form-control-sm font-monospace';
        ta.rows = 3;
        ta.placeholder = '{ "key": "value" }';
        if (param.default && typeof param.default === 'object') {
            ta.value = JSON.stringify(param.default, null, 2);
        }
        return ta;
    }

    /**
     * Collect the current form state as a flat params object.
     * Omits blank optional fields so descriptor defaults apply server-side.
     *
     * @returns {{ ok: boolean, params?: object, errors?: string[] }}
     */
    collect() {
        const out = {};
        const errors = [];

        for (const [key, { el, param }] of this.inputs.entries()) {
            const v = this._readValue(el, param);

            if (v === undefined || v === '' || (Array.isArray(v) && v.length === 0)) {
                if (param.required) {
                    errors.push(`${param.title || param.key} ${t('form.required_suffix', 'is required')}`);
                }
                continue;
            }

            out[key] = v;
        }

        return errors.length
            ? { ok: false, errors, params: out }
            : { ok: true, params: out };
    }

    _readValue(el, param) {
        switch (param.type) {
            case 'boolean': {
                const cb = el.querySelector('input[type=checkbox]');
                return cb ? cb.checked : undefined;
            }
            case 'number': {
                const raw = el.value.trim();
                if (raw === '') return undefined;
                const n = Number(raw);
                return Number.isFinite(n) ? n : undefined;
            }
            case 'integer': {
                const raw = el.value.trim();
                if (raw === '') return undefined;
                const n = parseInt(raw, 10);
                return Number.isFinite(n) ? n : undefined;
            }
            case 'enum': {
                const val = el.value;
                return val === '' ? undefined : val;
            }
            case 'image': {
                // Wrapper div — value lives on the inner input[data-role=input]
                const input = el.querySelector('[data-role="input"]');
                const raw = input ? input.value.trim() : '';
                return raw === '' ? undefined : raw;
            }
            case 'image[]': {
                const ta = el.querySelector('[data-role="input"]');
                if (!ta) return undefined;
                const lines = ta.value
                    .split('\n')
                    .map(l => l.trim())
                    .filter(Boolean);
                return lines.length ? lines : undefined;
            }
            case 'object': {
                const raw = el.value.trim();
                if (raw === '') return undefined;
                try { return JSON.parse(raw); }
                catch { return undefined; }
            }
            default: // text, textarea
                return el.value.trim() === '' ? undefined : el.value.trim();
        }
    }

    /**
     * Bulk-assign values into the rendered form (by `param.key`).
     * Used by the "Re-Use params" button on the canvas: unknown keys and
     * missing inputs are silently skipped so partial param sets work.
     */
    setValues(values) {
        if (!values || typeof values !== 'object') return;
        for (const [key, value] of Object.entries(values)) {
            const entry = this.inputs.get(key);
            if (!entry) continue;
            this._writeValue(entry.el, entry.param, value);
        }
    }

    /**
     * Append a `cqfile://<id>[#name]` token to the first `image[]` param
     * in the form (or to the single `image` param if no array field exists).
     * Returns true if a target was found. Used by the canvas
     * "Use as input" button.
     */
    appendImageToken(token) {
        if (typeof token !== 'string' || !token) return false;

        // 1. Preferred target: any image[] (referenceImages, ipAdapters, etc.)
        for (const [, { el, param }] of this.inputs) {
            if (param.type === 'image[]') {
                const ta = el.querySelector('[data-role="input"]');
                if (!ta) continue;
                ta.value = ta.value && ta.value.trim()
                    ? ta.value.replace(/\s*$/, '') + '\n' + token
                    : token;
                ta.dispatchEvent(new Event('input', { bubbles: true }));
                el.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                return true;
            }
        }
        // 2. Fallback: single image field (seedImage, maskImage…)
        for (const [, { el, param }] of this.inputs) {
            if (param.type === 'image') {
                const inp = el.querySelector('[data-role="input"]');
                if (!inp) continue;
                inp.value = token;
                inp.dispatchEvent(new Event('input', { bubbles: true }));
                el.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                return true;
            }
        }
        return false;
    }

    /**
     * Write a single value into its input DOM. Handles the wrapper-based
     * image / image[] / boolean types specially.
     */
    _writeValue(el, param, value) {
        switch (param.type) {
            case 'boolean': {
                const cb = el.querySelector('input[type=checkbox]');
                if (cb) cb.checked = Boolean(value);
                break;
            }
            case 'image': {
                const inp = el.querySelector('[data-role="input"]');
                if (inp) inp.value = value == null ? '' : String(value);
                break;
            }
            case 'image[]': {
                const ta = el.querySelector('[data-role="input"]');
                if (ta) {
                    ta.value = Array.isArray(value)
                        ? value.filter(v => v != null && v !== '').join('\n')
                        : (value == null ? '' : String(value));
                }
                break;
            }
            case 'object': {
                el.value = (typeof value === 'string')
                    ? value
                    : (value == null ? '' : JSON.stringify(value, null, 2));
                break;
            }
            default: // text, textarea, number, integer, enum
                el.value = value == null ? '' : String(value);
        }
    }

    _safeId(key) {
        return String(key).replace(/[^a-zA-Z0-9_-]+/g, '_');
    }

    _escape(s) {
        return String(s)
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;');
    }
}
