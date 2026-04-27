import { ImagerApiService }    from './ImagerApiService';
import { ImagerControlPanel }  from './ImagerControlPanel';
import { ImagerCanvas }        from './ImagerCanvas';
import { ImagerHistoryPanel }  from './ImagerHistoryPanel';
import { ToastService }        from '../../shared/toast';
import { t }                   from './i18n';

/**
 * CQ Imager — main entry point.
 *
 * Wires the three panels (controls | canvas | history) around the
 * ImagerApiService. Loads the model catalog on boot and renders the
 * control panel; binds Generate → canvas + history optimistic update.
 *
 * @see /docs/features/CQ-IMAGER.md
 */
class CQImagerApp {
    constructor() {
        this.api = new ImagerApiService();
        this.toast = new ToastService();

        this.controlsEl = document.getElementById('cq-imager-controls');
        this.canvasEl   = document.getElementById('cq-imager-canvas');
        this.historyEl  = document.getElementById('cq-imager-history');

        if (!this.controlsEl || !this.canvasEl || !this.historyEl) {
            console.warn('CQ Imager: required DOM hooks missing; skipping init.');
            return;
        }

        this.projectId = document.getElementById('cq-imager-project-id')?.value || 'general';
        this.outputDir = document.getElementById('cq-imager-preselected-dir')?.value || '/uploads/imager';
        this.preselectedModel = document.getElementById('cq-imager-preselected-model')?.value || null;

        this.canvas = new ImagerCanvas(this.canvasEl, {
            onUseAsInput:  (img) => this._handleUseAsInput(img),
            onReuseParams: (sel) => this._handleReuseParams(sel),
        });
        this.currentGenId = null;
        this.history = new ImagerHistoryPanel(this.historyEl, {
            apiService: this.api,
            onSelect: (gen) => this._displayHistoricalGeneration(gen),
            onDeleted: (id) => {
                if (this.currentGenId === id) {
                    this.canvas._renderIdle();
                    this.currentGenId = null;
                }
            },
        });

        // Forward in-app toast events from child components
        document.addEventListener('cq-imager:toast', (e) => {
            const { type = 'info', msg = '' } = e.detail || {};
            this.toast[type] ? this.toast[type](msg) : this.toast.info(msg);
        });

        this._init();
    }

    async _init() {
        try {
            const res = await this.api.getModels();
            const models = res.models || [];
            if (!models.length) {
                this.controlsEl.innerHTML =
                    `<p class="text-warning small">${this._esc(t('controls.no_models', 'No image-diffusion models are currently available on your gateway.'))}</p>`;
                return;
            }

            this.controls = new ImagerControlPanel(this.controlsEl, {
                models,
                preselectedModel: this.preselectedModel,
                onGenerate: (req) => this._handleGenerate(req),
            });
        } catch (err) {
            this.controlsEl.innerHTML =
                `<p class="text-warning small">${this._esc(t('controls.load_failed', 'Failed to load models'))}: ${this._esc(err.message)}</p>`;
            console.error('CQ Imager init error:', err);
        }
    }

    async _handleGenerate({ model, params }) {
        this.canvas.showBusy(t('canvas.busy', 'Generating… this may take up to a minute.'));

        try {
            const res = await this.api.generate(model, params, {
                projectId: this.projectId,
                outputDir: this.outputDir,
            });

            if (!res.success) {
                const msg = res.error || t('canvas.error_default', 'Generation failed');
                this.canvas.showError(msg);
                this.toast.error(msg);
                return;
            }

            // Attach the flat params used so canvas can show the "Generation
            // params used" block (incl. reference image thumbnails). `model`
            // is always the AIR id (needed by Re-Use Params); `modelName`
            // provides a human-readable label for the footer.
            const selectedName = this.controls?.selectedModel?.name || model;
            this.canvas.showResult({ ...res, params, model, modelName: selectedName });
            // Track the freshly-created generation id for delete-sync.
            this.currentGenId = res.generations?.[0]?.id || null;

            // Optimistically prepend each freshly created generation
            const gens = Array.isArray(res.generations) ? res.generations : [];
            for (const g of gens) this.history.prepend(g);

            const cost = Number(res.total_cost_credits ?? 0);
            this.toast.success(
                t(
                    'toasts.generated',
                    'Generated {count} image(s) — {cost} credits',
                    { count: res.files?.length || 0, cost: cost.toFixed(2) }
                ),
                t('app_name', 'CQ Imager')
            );
        } catch (err) {
            const fail = err.message || t('canvas.error_default', 'Generation failed');
            this.canvas.showError(fail);
            this.toast.error(fail);
            console.error('CQ Imager generate error:', err);
        }
    }

    /**
     * "Use as input" button — append the current canvas image as a
     * `cqfile://<id>[#name]` token to the first image[] form field
     * (e.g. inputs.referenceImages for Qwen / Nano-Banana).
     */
    _handleUseAsInput({ id, name }) {
        if (!id) {
            this.toast.warning(t('toasts.use_as_input_no_file', 'No owned project file for this image yet.'));
            return;
        }
        const form = this.controls?.paramsForm;
        if (!form) {
            this.toast.warning(t('toasts.use_as_input_no_model', 'Select a model first.'));
            return;
        }
        const token = name ? `cqfile://${id}#${name}` : `cqfile://${id}`;
        const ok = form.appendImageToken(token);
        if (ok) {
            this.toast.success(t('toasts.use_as_input_ok', 'Image added as reference input.'));
        } else {
            this.toast.warning(t('toasts.use_as_input_no_field', 'Current model has no image input field to append to.'));
        }
    }

    /**
     * "Re-Use Params" button — select the model that produced the current
     * image and fill the form with its stored generation params.
     */
    _handleReuseParams({ model, params }) {
        if (!this.controls) return;
        if (model) {
            // skipRestore: re-use values must take precedence over the
            // localStorage snapshot for that model.
            this.controls.selectModel(model, { skipRestore: true });
        }
        // selectModel re-creates the DynamicParamsForm synchronously, so
        // paramsForm is ready on the very next line.
        if (params && this.controls.paramsForm) {
            this.controls.paramsForm.setValues(params);
            this.toast.success(t('toasts.reuse_ok', 'Params loaded — tweak and Generate.'));
        }
    }

    /**
     * Re-display a previous generation in the canvas from its history record.
     * Prefers the owned `/api/project-file/{id}/download` (canvas chooses it
     * whenever `files[].id` is present), so display survives Runware TTL.
     */
    _displayHistoricalGeneration(gen) {
        if (!gen) return;
        this.currentGenId = gen.id;
        this.canvas.showResult({
            files: [{
                id: gen.projectFileId,
                path: '',
                name: '',
                seed: gen.seed,
                imageURL: gen.imageUrl, // fallback only
            }],
            // AIR id drives Re-Use Params; name is just for display.
            model:     gen.model || gen.modelSlug || gen.modelName,
            modelName: gen.modelName || gen.modelSlug || gen.model,
            total_cost_credits: gen.costCredits ?? 0,
            taskUUID: gen.taskUuid || '',
            params: gen.params || {}, // show original generation params (+ ref images)
        });
    }

    _esc(s) {
        return String(s ?? '')
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;');
    }
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => new CQImagerApp());
} else {
    new CQImagerApp();
}
