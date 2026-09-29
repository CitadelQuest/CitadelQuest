import * as bootstrap from 'bootstrap';

/**
 * Modal for extracting a file into a new CQ Memory Pack.
 *
 * Mirrors the Memory Explorer extraction flow: pick an existing Memory Library,
 * name a new pack (prefilled from the selected file name), choose the max depth
 * and optionally run automatic relationship analysis afterwards.
 *
 * The modal only collects input + handles its own UI state; the actual
 * create-pack / extract work is performed by the `onConfirm` callback so the
 * caller (FileBrowser) can refresh the tree afterwards.
 */
export class MemoryPackExtractModal {
    static AUTO_ANALYZE_STORAGE_KEY = 'cq_memory_pack_extract_auto_analyze';
    static DEFAULT_MAX_DEPTH = 2;

    constructor(options = {}) {
        this.translations = options.translations || {};
        this.projectId = options.projectId || 'general';
        this.modalId = 'memoryPackExtractModal';
        this.modal = null;
        this.bsModal = null;
        this.libraries = [];
        this.currentItem = null;
        this.onConfirm = null;

        this.createModal();
    }

    /**
     * Create the modal element
     */
    createModal() {
        const existing = document.getElementById(this.modalId);
        if (existing) existing.remove();

        this.modal = document.createElement('div');
        this.modal.className = 'modal fade';
        this.modal.id = this.modalId;
        this.modal.tabIndex = -1;
        this.modal.setAttribute('aria-hidden', 'true');

        const t = this.translations;
        this.modal.innerHTML = `
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content glass-panel">
                    <div class="modal-header bg-cyber-g border-success border-1 border-bottom">
                        <h5 class="modal-title">
                            <i class="mdi mdi-graph text-info me-2"></i>
                            ${t.extract_to_memory_pack || 'Extract to CQ Memory Pack'}
                        </h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div id="mpExtractFileInfo" class="mb-3 small text-secondary"></div>

                        <div id="mpExtractNoLibraries" class="alert alert-warning d-none small">
                            <i class="mdi mdi-alert-outline me-1"></i>${t.extract_mp_no_libraries || 'No memory libraries found. Create one in Memory Explorer first.'}
                        </div>

                        <div class="mb-3">
                            <label class="form-label small text-cyber" for="mpExtractLibrarySelect">
                                <i class="mdi mdi-bookshelf me-1"></i>${t.extract_mp_library || 'Memory Library'}
                            </label>
                            <select class="form-select glass-input" id="mpExtractLibrarySelect"></select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label small text-cyber" for="mpExtractPackName">
                                <i class="mdi mdi-package-variant me-1"></i>${t.extract_mp_pack_name || 'Memory Pack Name'}
                            </label>
                            <input type="text" class="form-control glass-input" id="mpExtractPackName"
                                placeholder="${t.extract_mp_pack_name_placeholder || 'e.g., Philosophy Notes'}">
                        </div>

                        <div class="mb-3">
                            <label class="form-label small text-secondary d-flex justify-content-between">
                                <span>
                                    <i class="mdi mdi-layers-triple me-1"></i>${t.extract_mp_max_depth || 'Max Depth'}
                                </span>
                                <span id="mpExtractDepthValue" class="badge fw-normal bg-secondary bg-opacity-25">${MemoryPackExtractModal.DEFAULT_MAX_DEPTH}</span>
                            </label>
                            <input type="range" class="form-range-input w-100" id="mpExtractDepth"
                                min="1" max="3" value="${MemoryPackExtractModal.DEFAULT_MAX_DEPTH}">
                            <div class="d-flex justify-content-between small text-secondary">
                                <span>1 (${t.extract_mp_depth_fast || 'Fast'})</span>
                                <span>3 (${t.extract_mp_depth_deep || 'Deep'})</span>
                            </div>
                        </div>

                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" role="switch" id="mpExtractAutoAnalyze">
                            <label class="form-check-label small text-secondary" for="mpExtractAutoAnalyze">
                                <i class="mdi mdi-graph me-1 text-cyber opacity-50"></i>${t.extract_mp_auto_analyze || 'Automatic relationship analysis after extraction'}
                            </label>
                        </div>

                        <div id="mpExtractError" class="alert alert-danger d-none small"></div>
                    </div>
                    <div class="modal-footer border-top-0 d-flex justify-content-between">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                            <i class="mdi mdi-close me-2"></i>${t.cancel || 'Cancel'}
                        </button>
                        <button type="button" class="btn btn-cyber" id="mpExtractConfirmBtn">
                            <i class="mdi mdi-graph me-2"></i>${t.extract_mp_start || 'EXTRACT MEMORY'}
                        </button>
                    </div>
                </div>
            </div>
        `;

        document.body.appendChild(this.modal);
        this.attachEventListeners();
    }

    /**
     * Attach event listeners
     */
    attachEventListeners() {
        const depthSlider = this.modal.querySelector('#mpExtractDepth');
        const depthValue = this.modal.querySelector('#mpExtractDepthValue');
        depthSlider.addEventListener('input', () => {
            depthValue.textContent = depthSlider.value;
        });

        const autoAnalyze = this.modal.querySelector('#mpExtractAutoAnalyze');
        autoAnalyze.addEventListener('change', () => {
            localStorage.setItem(MemoryPackExtractModal.AUTO_ANALYZE_STORAGE_KEY, autoAnalyze.checked ? 'true' : 'false');
        });

        this.modal.querySelector('#mpExtractConfirmBtn').addEventListener('click', () => this.handleConfirm());

        const packNameInput = this.modal.querySelector('#mpExtractPackName');
        packNameInput.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') this.handleConfirm();
        });
        packNameInput.addEventListener('input', () => this.hideError());

        this.modal.querySelector('#mpExtractLibrarySelect').addEventListener('change', () => this.hideError());
    }

    /**
     * Open the modal for a file.
     *
     * @param {Object} item - Selected file { id, name, path, type }
     * @param {Function} onConfirm - async ({ library, packName, maxDepth, skipAnalysis }) => void
     *                               Should throw on failure (message is shown in the modal).
     */
    async open(item, onConfirm) {
        this.currentItem = item;
        this.onConfirm = onConfirm;

        this.hideError();
        this.resetForm();

        const bsModal = bootstrap.Modal.getOrCreateInstance(this.modal);
        bsModal.show();

        await this.loadLibraries();
    }

    /**
     * Reset the form to its defaults for the current file
     */
    resetForm() {
        const item = this.currentItem;

        // Prefill pack name from the file name (without extension)
        const dotIndex = item.name.lastIndexOf('.');
        const baseName = dotIndex > 0 ? item.name.slice(0, dotIndex) : item.name;
        this.modal.querySelector('#mpExtractPackName').value = baseName;

        // Depth
        const depthSlider = this.modal.querySelector('#mpExtractDepth');
        depthSlider.value = MemoryPackExtractModal.DEFAULT_MAX_DEPTH;
        this.modal.querySelector('#mpExtractDepthValue').textContent = MemoryPackExtractModal.DEFAULT_MAX_DEPTH;

        // Auto-analyze — default OFF, restored from a saved preference when present
        const autoAnalyze = this.modal.querySelector('#mpExtractAutoAnalyze');
        autoAnalyze.checked = localStorage.getItem(MemoryPackExtractModal.AUTO_ANALYZE_STORAGE_KEY) === 'true';

        // File info line
        this.modal.querySelector('#mpExtractFileInfo').innerHTML =
            `<i class="mdi mdi-file-outline me-1"></i>${this.escapeHtml(item.name)}`;
    }

    /**
     * Load available Memory Libraries and populate the selector
     */
    async loadLibraries() {
        const select = this.modal.querySelector('#mpExtractLibrarySelect');
        const noLibraries = this.modal.querySelector('#mpExtractNoLibraries');

        select.innerHTML = `<option value="">${this.translations.loading || 'Loading...'}</option>`;
        select.disabled = true;
        noLibraries.classList.add('d-none');
        this.libraries = [];

        try {
            const response = await fetch('/api/memory/pack/list', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ projectId: this.projectId, path: '/' })
            });

            if (!response.ok) throw new Error(`HTTP ${response.status}`);

            const data = await response.json();
            this.libraries = (data.libraries || []).sort((a, b) =>
                (a.displayName || a.name).toLowerCase().localeCompare((b.displayName || b.name).toLowerCase())
            );
        } catch (error) {
            console.error('Failed to load memory libraries:', error);
            this.showError(error.message || 'Failed to load memory libraries');
        }

        select.innerHTML = '';

        if (this.libraries.length === 0) {
            select.disabled = true;
            noLibraries.classList.remove('d-none');
            return;
        }

        this.libraries.forEach(lib => {
            const option = document.createElement('option');
            option.value = JSON.stringify({ path: lib.path, name: lib.name });
            const label = lib.displayName || lib.name;
            option.textContent = lib.packCount !== undefined ? `${label} (${lib.packCount} packs)` : label;
            select.appendChild(option);
        });
        select.disabled = false;
    }

    /**
     * Handle confirm button click — validate and delegate to onConfirm
     */
    async handleConfirm() {
        const select = this.modal.querySelector('#mpExtractLibrarySelect');
        const packName = this.modal.querySelector('#mpExtractPackName').value.trim();
        const confirmBtn = this.modal.querySelector('#mpExtractConfirmBtn');

        if (!packName) {
            this.showError(this.translations.extract_mp_name_required || 'Memory pack name is required');
            return;
        }

        let library = null;
        if (select.value) {
            try {
                library = JSON.parse(select.value);
            } catch {
                library = null;
            }
        }

        if (!library) {
            this.showError(this.translations.extract_mp_no_libraries || 'No memory libraries found. Create one in Memory Explorer first.');
            return;
        }

        const params = {
            library,
            packName,
            maxDepth: parseInt(this.modal.querySelector('#mpExtractDepth').value || '2', 10),
            skipAnalysis: !this.modal.querySelector('#mpExtractAutoAnalyze').checked
        };

        confirmBtn.disabled = true;
        const originalHtml = confirmBtn.innerHTML;
        confirmBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>' + (this.translations.loading || 'Loading...');

        try {
            await this.onConfirm(params);
            this.hide();
        } catch (error) {
            this.showError(error.message || 'Extraction failed');
        } finally {
            confirmBtn.disabled = false;
            confirmBtn.innerHTML = originalHtml;
        }
    }

    /**
     * Hide the modal
     */
    hide() {
        bootstrap.Modal.getOrCreateInstance(this.modal).hide();
    }

    /**
     * Show an error message inside the modal
     */
    showError(message) {
        const errorEl = this.modal.querySelector('#mpExtractError');
        errorEl.textContent = message;
        errorEl.classList.remove('d-none');
    }

    /**
     * Hide the error message
     */
    hideError() {
        this.modal.querySelector('#mpExtractError').classList.add('d-none');
    }

    escapeHtml(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }
}
