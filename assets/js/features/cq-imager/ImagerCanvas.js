import { ImageShowcase } from '../../shared/image-showcase';
import { t } from './i18n';

/**
 * ImagerCanvas — center pane for CQ Imager.
 *
 * States:
 *   idle     → placeholder icon
 *   busy     → spinner + status message
 *   result   → latest image(s) + metadata footer + generation params
 *   error    → error panel with message
 *
 * Generated images are wrapped in the shared ImageShowcase for fullscreen
 * view; the params block re-renders any image-like inputs used
 * (`cqfile://<id>` tokens, URLs, or data URIs) as small thumbnails.
 */
export class ImagerCanvas {
    /**
     * @param {HTMLElement} container
     * @param {object} [opts]
     *   - onUseAsInput:  ({ id, name, src }) => void
     *   - onReuseParams: ({ model, params }) => void
     */
    constructor(container, opts = {}) {
        this.container = container;
        this.imageShowcase = new ImageShowcase('contentShowcaseModal');
        this.onUseAsInput  = opts.onUseAsInput  || (() => {});
        this.onReuseParams = opts.onReuseParams || (() => {});
        // Remember the latest displayed generation so the action buttons
        // always act on what the user is currently looking at.
        this.currentResult = null;
        this._renderIdle();
    }

    _renderIdle() {
        this.container.innerHTML = `
            <div class="imager-canvas-idle text-center">
                <i class="mdi mdi-image-area text-cyber opacity-50" style="font-size:4rem;"></i>
                <p class="text-muted mt-3 mb-0">${this._esc(t('canvas.placeholder', 'Generated images will appear here.'))}</p>
            </div>
        `;
    }

    showBusy(statusMsg) {
        if (statusMsg == null) statusMsg = t('canvas.busy_short', 'Generating…');
        this.container.innerHTML = `
            <div class="imager-canvas-busy text-center">
                <div class="spinner-border text-cyber" role="status"></div>
                <p class="text-muted mt-3 mb-0">${this._esc(statusMsg)}</p>
            </div>
        `;
    }

    /**
     * @param {object} result  Gateway response (success payload from /generate)
     *   Expected: {
     *     files:  [{id,path,name,seed,imageURL?}, ...],
     *     params: {...}  // flat params used for this generation (optional)
     *     model, total_cost_credits, new_balance_credits, taskUUID
     *   }
     */
    showResult(result) {
        const files = result.files || [];
        if (!files.length) {
            this._renderError(t('canvas.no_images', 'Generation returned no images.'));
            return;
        }

        this.currentResult = result;
        // Primary image for "Use as input" (first of potentially many)
        const primary = files[0] || {};

        // Image source priority:
        //   1. /api/project-file/{id}/download — owned by the user, permanent,
        //      works even after the Runware URL TTL expires.
        //   2. Runware imageURL — fallback for legacy history rows without
        //      a resolvable project file id (shouldn't normally happen).
        const imgHtml = files.map((f, i) => {
            const src = f.id
                ? `/api/project-file/${encodeURIComponent(f.id)}/download`
                : (f.imageURL || '');
            return `
                <div class="imager-canvas-image-wrap">
                    <div class="content-showcase position-relative d-inline-block">
                        <img class="imager-canvas-image rounded shadow" data-file-id="${this._esc(f.id)}"
                             alt="Generated image ${i + 1}"
                             src="${this._esc(src)}">
                        <div class="content-showcase-icon position-absolute top-0 end-0 p-1 badge bg-dark bg-opacity-75 text-cyber cursor-pointer" title="${this._esc(t('canvas.fullscreen', 'Fullscreen'))}">
                            <i class="mdi mdi-fullscreen"></i>
                        </div>
                    </div>
                    <div class="imager-canvas-image-caption small text-muted mt-3 d-flex justify-content-between">
                        ${f.seed != null ? `<span>${this._esc(t('canvas.seed', 'seed'))}: <span class="text-light opacity-50">${this._esc(f.seed)}</span></span>` : ''}
                        ${f.id ? `<a class="mx-2 text-cyber" href="/api/project-file/${encodeURIComponent(f.id)}/download?download=1" title="${this._esc(t('canvas.download', 'Download'))}"><i class="mdi mdi-download"></i></a>` : ''}
                    </div>
                </div>
            `;
        }).join('');

        const cost = result.total_cost_credits ?? 0;
        const bal  = result.new_balance_credits;
        const paramsHtml = this._renderParamsBlock(result.params);

        // Action-button row: "Use as input" (feeds current image back into
        // the first image[] form field) + (rendered inside paramsHtml header)
        // a "Re-Use params" button — both require a project-file id, so only
        // shown when the primary image has one.
        const useAsInputBtn = primary.id ? `
            <button type="button" class="btn btn-sm btn-outline-cyber imager-canvas-use-input" title="${this._esc(t('canvas.use_as_input_title', 'Use this image as reference input'))}">
                <i class="mdi mdi-image-plus-outline me-1"></i>${this._esc(t('canvas.use_as_input', 'Use as input'))}
            </button>
        ` : '';

        this.container.innerHTML = `
            <div class="imager-canvas-result">
                <div class="imager-canvas-images">${imgHtml}</div>
                <div class="imager-canvas-footer d-flex justify-content-between gap-2 mt-2 small text-muted">
                    <span><i class="mdi mdi-chip me-1"></i>${this._esc(result.modelName || result.model || '')}</span>
                    ${cost ? `<span><i class="mdi mdi-circle-multiple-outline me-1 text-cyber opacity-50"></i>${this._fmtCost(cost)}</span>` : ''}
                    ${bal != null ? `<span><i class="mdi mdi-wallet-outline me-1"></i>${this._esc(t('canvas.balance', 'balance'))}: ${this._fmtCost(bal)}</span>` : ''}
                </div>
                <div class="imager-canvas-actions d-flex justify-content-start gap-2 mt-2">
                    ${useAsInputBtn}
                </div>
                ${paramsHtml}
            </div>
        `;

        this._bindActionButtons(primary, result);

        // Attach fullscreen handlers on the freshly-rendered showcase icons
        // (canvas image + every reference-image thumb in the params block).
        this.imageShowcase.init(this.container);
    }

    /**
     * Wire click handlers for the two action buttons that appear after a
     * successful render: "Use as input" and "Re-Use params".
     */
    _bindActionButtons(primary, result) {
        const useBtn = this.container.querySelector('.imager-canvas-use-input');
        if (useBtn) {
            useBtn.addEventListener('click', () => {
                this.onUseAsInput({
                    id:   primary.id,
                    name: primary.name || '',
                    src:  primary.id
                        ? `/api/project-file/${encodeURIComponent(primary.id)}/download`
                        : (primary.imageURL || ''),
                });
            });
        }
        const reuseBtn = this.container.querySelector('.imager-canvas-reuse-params');
        if (reuseBtn) {
            reuseBtn.addEventListener('click', () => {
                this.onReuseParams({
                    model:  result.model || null,
                    params: result.params || {},
                });
            });
        }
    }

    /**
     * Render a compact "Generation params used" block.
     * Handles image-like values (`cqfile://<id>[#name]`, data URIs, http URLs)
     * specially so the user can see reference images that were fed in.
     */
    _renderParamsBlock(params) {
        if (!params || typeof params !== 'object' || Array.isArray(params)) {
            return '';
        }
        const entries = Object.entries(params).filter(([, v]) =>
            v !== null && v !== undefined && v !== '' && !(Array.isArray(v) && v.length === 0)
        );
        if (!entries.length) return '';

        const rows = entries.map(([key, value]) => {
            const rendered = Array.isArray(value)
                ? `<div class="d-flex flex-wrap gap-1">${value.map(v => this._renderParamValue(v)).join('')}</div>`
                : this._renderParamValue(value);
            return `
                <div class="imager-param-row d-flex gap-0 small flex-column mb-1">
                    <div class="imager-param-key text-cyber opacity-75">${this._esc(key)}</div>
                    <div class="imager-param-val flex-grow-1 ms-3">${rendered}</div>
                </div>
            `;
        }).join('');

        return `
            <div class="imager-canvas-params mt-3">
                <div class="d-flex align-items-center gap-2 mb-1">
                    <div class="small text-cyber fw-bold text-uppercase">
                        <i class="mdi mdi-tune-variant me-1"></i>${this._esc(t('canvas.params_used', 'Generation params used'))}
                    </div>
                    <button type="button" class="btn btn-sm btn-outline-cyber imager-canvas-reuse-params ms-auto"
                            title="${this._esc(t('canvas.reuse_params_title', 'Re-use these params: select the same model and fill the form'))}">
                        <i class="mdi mdi-refresh me-1"></i>${this._esc(t('canvas.reuse_params', 'Re-Use Params'))}
                    </button>
                </div>
                <div class="imager-param-list ms-4 pb-3 d-none d-sm-flex">${rows}</div>
            </div>
        `;
    }

    /** Render a single param value — image-like values become thumbnails. */
    _renderParamValue(value) {
        if (typeof value !== 'string') {
            return `<code class="text-light small">${this._esc(JSON.stringify(value))}</code>`;
        }

        // cqfile://<id>[#filename] — use owned project-file endpoint
        if (value.startsWith('cqfile://')) {
            const rest = value.slice('cqfile://'.length);
            const hashIdx = rest.indexOf('#');
            const id = hashIdx >= 0 ? rest.slice(0, hashIdx) : rest;
            const name = hashIdx >= 0 ? rest.slice(hashIdx + 1) : '';
            const src = `/api/project-file/${encodeURIComponent(id)}/download`;
            return this._imgThumbHtml(src, name || id, name || '');
        }

        // data: URI or URL pointing to an image
        if (value.startsWith('data:image/') || /^https?:\/\//i.test(value)) {
            return this._imgThumbHtml(value, value, '');
        }

        // Long strings: truncate in-line
        const display = value.length > 200 ? value.slice(0, 200) + '…' : value;
        return `<span class="text-light">${this._esc(display)}</span>`;
    }

    _imgThumbHtml(src, alt, caption) {
        // Wrap each thumb in .content-showcase so the shared ImageShowcase
        // picks it up on init() and gives it the same fullscreen UX as the
        // main canvas image.
        return `
            <span class="imager-param-thumb d-inline-flex align-items-center gap-1">
                <span class="content-showcase position-relative d-inline-block">
                    <img src="${this._esc(src)}" alt="${this._esc(alt)}" class="rounded">
                    <span class="content-showcase-icon position-absolute top-0 end-0 p-1 badge bg-dark bg-opacity-75 text-cyber cursor-pointer" title="${this._esc(t('canvas.fullscreen', 'Fullscreen'))}">
                        <i class="mdi mdi-fullscreen"></i>
                    </span>
                </span>
                ${caption ? `<span class="small text-muted text-truncate">${this._esc(caption)}</span>` : ''}
            </span>
        `;
    }

    showError(message) {
        this._renderError(message);
    }

    _renderError(message) {
        this.container.innerHTML = `
            <div class="imager-canvas-error text-center">
                <i class="mdi mdi-alert-circle-outline text-warning" style="font-size:3rem;"></i>
                <p class="text-warning mt-3 mb-0">${this._esc(message || t('canvas.error_default', 'Generation failed'))}</p>
            </div>
        `;
    }

    _fmtCost(n) {
        const num = Number(n);
        if (!Number.isFinite(num)) return String(n);
        return num.toFixed(num < 10 ? 2 : 1);
    }

    _esc(s) {
        return String(s ?? '')
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;');
    }
}
