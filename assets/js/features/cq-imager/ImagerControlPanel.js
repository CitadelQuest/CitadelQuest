import { DynamicParamsForm } from './DynamicParamsForm';
import { t } from './i18n';

/**
 * ImagerControlPanel — left-column composite.
 *
 *   ┌─────────────────────────┐
 *   │ Model picker (cards)    │   ← scrollable list, click to select
 *   ├─────────────────────────┤
 *   │ Dynamic params form     │   ← rendered for selected model
 *   ├─────────────────────────┤
 *   │ [ Generate ] button     │
 *   └─────────────────────────┘
 *
 * Emits via callback: { model: airId, params: {...} }
 */
export class ImagerControlPanel {
    /**
     * @param {HTMLElement} container
     * @param {object} opts
     *   - models:           array of normalized descriptors
     *   - preselectedModel: optional AIR id to auto-select
     *   - onGenerate:       async ({ model, params }) => void
     */
    constructor(container, { models, preselectedModel = null, onGenerate }) {
        this.container = container;
        this.models = models || [];
        this.onGenerate = onGenerate || (() => {});
        this.selectedModel = null;
        this.paramsForm = null;

        this._render();

        const initial = preselectedModel
            ? this.models.find(m => m.id === preselectedModel)
            : this.models[0];
        if (initial) this.selectModel(initial.id);
    }

    _render() {
        this.container.innerHTML = `
            <div class="imager-panel-section">
                <div class="d-flex align-items-center mb-2">
                    <i class="mdi mdi-tune-variant me-1 text-cyber"></i>
                    <span class="text-cyber fw-bold">${this._esc(t('controls.title', 'Controls'))}</span>
                </div>

                <select class="form-select form-select-sm imager-model-select mb-2" data-role="model-select"></select>

                <div class="imager-selected-model mb-2" data-role="selected"></div>
                <div class="imager-params-form" data-role="form"></div>

                <button type="button" class="btn btn-sm btn-cyber w-100 mt-3" data-role="generate" disabled>
                    <i class="mdi mdi-creation-outline me-1"></i>
                    <span data-role="generate-label">${this._esc(t('controls.generate', 'Generate'))}</span>
                </button>
            </div>
        `;

        this.$modelSelect = this.container.querySelector('[data-role="model-select"]');
        this.$selected    = this.container.querySelector('[data-role="selected"]');
        this.$form        = this.container.querySelector('[data-role="form"]');
        this.$generate    = this.container.querySelector('[data-role="generate"]');
        this.$genLabel    = this.container.querySelector('[data-role="generate-label"]');

        this._renderModelOptions();

        this.$modelSelect.addEventListener('change', (e) => this.selectModel(e.target.value));
        this.$generate.addEventListener('click', () => this._handleGenerate());
    }

    _renderModelOptions() {
        this.$modelSelect.innerHTML = '';
        for (const m of this.models) {
            const opt = document.createElement('option');
            opt.value = m.id;
            const label = m.name || m.id;
            const creator = '';//m.creator?.name ? ` — ${m.creator.name}` : '';
            const price = m.pricing?.[0]?.price ? ` (${m.pricing[0].price}+)` : '';
            opt.textContent = `${label}${creator}${price}`;
            this.$modelSelect.appendChild(opt);
        }
    }

    selectModel(airId) {
        const m = this.models.find(x => x.id === airId);
        if (!m) return;

        this.selectedModel = m;
        if (this.$modelSelect && this.$modelSelect.value !== airId) {
            this.$modelSelect.value = airId;
        }

        // Detailed selected-model card: cover + creator + capabilities + description
        const cover = m.coverImage
            ? `<img class="imager-selected-cover rounded d-flex d-sm-none d-md-flex" src="${this._esc(m.coverImage)}" alt="${this._esc(m.name || '')}">`
            : `<div class="imager-selected-cover imager-selected-cover-placeholder rounded d-flex align-items-center justify-content-center"><i class="mdi mdi-image-outline text-muted"></i></div>`;

        const creator = m.creator?.name
            ? `<span class="imager-model-creator small text-muted">${this._esc(m.creator.name)}</span>`
            : '';
        const price = m.pricing?.[0]?.price
            ? `<i class="mdi mdi-circle-multiple-outline ms-3 text-cyber opacity-50 small"></i><span class="imager-model-price small text-muted mx-1">${this._esc(m.pricing[0].price)}</span>`
            : '';
        const capsHtml = (m.capabilities || [])
            .map(c => `<span class="badge bg-primary text-cyber bg-opacity-10">${this._esc(c)}</span>`).join('');

        this.$selected.innerHTML = `
            <div class="imager-selected-card p-2 rounded bg-dark bg-opacity-50">
                <div class="d-flex gap-2 mb-2">
                    ${cover}
                    <div class="imager-selected-info flex-grow-1 min-width-0">
                        <div class="small text-cyber fw-bold text-truncate">${this._esc(m.name || m.id)}</div>
                        <div class="mb-1 d-flex justify-content-between align-items-center gap-2">
                            <span>${creator}</span>
                            <span class="d-inline-block d-sm-none d-md-inline-block">${price}<span class="text-muted">+</span></span>
                        </div>
                    </div>
                </div>
                ${capsHtml ? `<div class="imager-model-caps small d-flex d-sm-none d-md-flex gap-1">${capsHtml}</div>` : ''}
            </div>
        `;

        // Render dynamic form
        this.paramsForm = new DynamicParamsForm(this.$form, m);
        this.$generate.disabled = false;
    }

    async _handleGenerate() {
        if (!this.selectedModel || !this.paramsForm) return;

        const collected = this.paramsForm.collect();
        if (!collected.ok) {
            const msg = (collected.errors || []).join('; ');
            document.dispatchEvent(new CustomEvent('cq-imager:toast', {
                detail: { type: 'warning', msg: msg || t('controls.invalid_params', 'Invalid params') }
            }));
            return;
        }

        this.setBusy(true);
        try {
            await this.onGenerate({
                model: this.selectedModel.id,
                params: collected.params,
            });
        } finally {
            this.setBusy(false);
        }
    }

    setBusy(busy) {
        this.$generate.disabled = busy;
        this.$genLabel.innerHTML = busy
            ? `<span class="spinner-border spinner-border-sm me-1"></span>${this._esc(t('controls.generating', 'Generating…'))}`
            : this._esc(t('controls.generate', 'Generate'));
    }

    _esc(s) {
        return String(s ?? '')
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;');
    }
}
