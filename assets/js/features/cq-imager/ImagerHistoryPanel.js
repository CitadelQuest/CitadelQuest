/**
 * ImagerHistoryPanel — right-column list of past generations.
 *
 * Minimal MVP: lists recent generations with thumbnail + model + seed.
 * Click to re-display in the canvas (via onSelect callback).
 *
 * Rich features (filters, remix, tag/organize) land in Phase 5.
 */
import { ImageShowcase } from '../../shared/image-showcase';
import { formatDate, formatTime, formatDateTime } from '../../shared/date-utils';
import { t } from './i18n';
export class ImagerHistoryPanel {
    /**
     * @param {HTMLElement} container
     * @param {object} opts
     *   - apiService:  ImagerApiService instance
     *   - onSelect:    (generation) => void  (re-display in canvas)
     *   - onDeleted:   (id) => void  (optional; fired after successful delete)
     */
    constructor(container, { apiService, onSelect, onDeleted }) {
        this.container = container;
        this.api = apiService;
        this.onSelect = onSelect || (() => {});
        this.onDeleted = onDeleted || (() => {});
        this.items = [];

        this._renderShell();
        this.refresh();
    }

    _renderShell() {
        this.container.innerHTML = `
            <div class="imager-panel-section h-100 d-flex flex-column">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <span class="text-cyber fw-bold">
                        <i class="mdi mdi-history me-1"></i><span class="d-inline-block d-sm-none d-md-inline-block">${this._esc(t('history.title', 'History'))}</span>
                    </span>
                    <button type="button" class="btn btn-sm btn-link p-0 text-muted"
                            data-role="refresh" title="${this._esc(t('history.refresh', 'Refresh'))}">
                        <i class="mdi mdi-refresh"></i>
                    </button>
                </div>
                <div class="imager-history-list flex-grow-1" data-role="list">
                    <p class="text-muted small">${this._esc(t('history.loading', 'Loading…'))}</p>
                </div>
            </div>
        `;
        this.$list = this.container.querySelector('[data-role="list"]');
        this.container.querySelector('[data-role="refresh"]')
            .addEventListener('click', () => this.refresh());
    }

    async refresh() {
        try {
            const res = await this.api.getHistory({ limit: 50 });
            this.items = res.generations || [];
            this._renderList();
        } catch (err) {
            this.$list.innerHTML = `<p class="text-warning small">${this._esc(t('history.load_failed', 'Failed to load'))}: ${this._esc(err.message)}</p>`;
        }
    }

    /** Prepend a freshly-produced generation (optimistic) */
    prepend(generation) {
        if (!generation) return;
        this.items.unshift(generation);
        this._renderList();
    }

    _renderList() {
        if (!this.items.length) {
            this.$list.innerHTML = `<p class="text-muted small">${this._esc(t('history.empty', 'No generations yet.'))}</p>`;
            return;
        }

        this.$list.innerHTML = this.items.map(g => {
            const when = g.createdAt ? formatDateTime(new Date(g.createdAt)) : '';
            const prompt = g.params?.positivePrompt || g.params?.prompt || '';
            const modelLabel = g.modelName || g.modelSlug || g.model || '';
            // Prefer the owned project-file (permanent) over the Runware URL (TTL).
            const thumbSrc = g.projectFileId
                ? `/api/project-file/${encodeURIComponent(g.projectFileId)}/download` // TODO: /content?thumb=1
                : (g.imageUrl || '');
            return `
                <div class="imager-history-row position-relative">
                    <button type="button" class="imager-history-item w-100 text-start p-2 rounded bg-dark bg-opacity-50 d-flex gap-2"
                            data-gen-id="${this._esc(g.id)}" data-file-id="${this._esc(g.projectFileId)}">
                        <div class="imager-history-thumb rounded"
                             style="${thumbSrc ? `background-image:url('${this._esc(thumbSrc)}')` : ''}">
                             ${!thumbSrc ? '<i class="mdi mdi-image-off-outline text-muted"></i>' : ''}
                        </div>
                        
                        <div class="imager-history-meta gap-1 d-flex d-sm-none d-md-flex flex-column">
                            <div class="small text-light text-truncate">${this._esc(prompt || t('history.no_prompt', '(no prompt)'))}</div>
                            <div class="small text-muted">
                                <span>${this._esc(modelLabel)}</span>
                            </div>
                            <div class="small text-muted opacity-50 imager-history-meta-date">${this._esc(when)}</div>
                            <div class="small w-100 text-end opacity-50 m-1 mx-2 position-absolute end-0 bottom-0">
                                ${g.costCredits != null ? `<span title="${this._esc(t('history.credits_tooltip', 'Credits'))}"><i class="mdi mdi-circle-multiple-outline me-1 text-cyber opacity-50"></i>${Number(g.costCredits).toFixed(2)}</span>` : ''}
                            </div>
                        </div>
                    </button>
                    <button type="button" class="imager-history-delete btn btn-sm btn-danger m-2 position-absolute end-0 top-0"
                            data-gen-id="${this._esc(g.id)}" title="${this._esc(t('history.delete', 'Delete from history'))}">
                        <i class="mdi mdi-trash-can-outline"></i>
                    </button>
                </div>
            `;
        }).join('');

        for (const btn of this.$list.querySelectorAll('.imager-history-item')) {
            btn.addEventListener('click', () => {
                const id = btn.dataset.genId;
                const gen = this.items.find(x => x.id === id);
                if (gen) this.onSelect(gen);
            });
        }
        for (const btn of this.$list.querySelectorAll('.imager-history-delete')) {
            btn.addEventListener('click', (e) => {
                e.stopPropagation();
                this._confirmDelete(btn.dataset.genId);
            });
        }
    }

    async _confirmDelete(id) {
        if (!id) return;
        if (!window.confirm(t('history.delete_confirm', 'Delete this generation and its image file from your File Browser? This cannot be undone.'))) {
            return;
        }
        try {
            await this.api.deleteGeneration(id, /* deleteFile */ true);
            this.items = this.items.filter(x => x.id !== id);
            this._renderList();
            this.onDeleted(id);
            document.dispatchEvent(new CustomEvent('cq-imager:toast', {
                detail: { type: 'success', msg: t('history.delete_success', 'History item deleted') }
            }));
        } catch (err) {
            document.dispatchEvent(new CustomEvent('cq-imager:toast', {
                detail: { type: 'error', msg: t('history.delete_failed', 'Delete failed') + ': ' + (err.message || 'unknown error') }
            }));
        }
    }

    _esc(s) {
        return String(s ?? '')
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;');
    }
}
