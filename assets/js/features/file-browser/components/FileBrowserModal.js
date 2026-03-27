import { FileBrowser } from './FileBrowser';
import * as bootstrap from 'bootstrap';

/**
 * FileBrowserModal — wraps FileBrowser in a Bootstrap modal for file selection.
 * Used by CQ Feed Post Composer to pick file attachments.
 *
 * Usage:
 *   const modal = new FileBrowserModal({ translations });
 *   const file = await modal.open();
 *   // file = { id, name, mimeType, isImage, path } or null if cancelled
 */
export class FileBrowserModal {
    constructor(options = {}) {
        this.translations = options.translations || {};
        this.fileBrowser = null;
        this.modalEl = null;
        this.bsModal = null;
        this._resolve = null;
    }

    /**
     * Open the file browser modal and return a Promise that resolves
     * with the selected file data or null if cancelled.
     */
    open() {
        return new Promise((resolve) => {
            this._resolve = resolve;
            this._ensureModalDOM();
            this._initFileBrowser();

            // Show modal
            this.bsModal.show();
        });
    }

    /**
     * Create the modal DOM structure (once).
     */
    _ensureModalDOM() {
        if (this.modalEl) return;

        const t = (key, fallback) => this.translations[key] || fallback;

        this.modalEl = document.createElement('div');
        this.modalEl.className = 'modal fade';
        this.modalEl.id = 'fileBrowserModal';
        this.modalEl.tabIndex = -1;
        this.modalEl.setAttribute('aria-hidden', 'true');
        this.modalEl.innerHTML = `
            <div class="modal-dialog modal-xl modal-dialog-scrollable">
                <div class="modal-content glass-panel" style="min-height: 70vh;">
                    <div class="modal-header bg-cyber-g border-success border-1 border-bottom py-2">
                        <h6 class="modal-title mb-0">
                            <i class="mdi mdi-folder-open-outline me-2"></i>${t('select_file', 'Select File')}
                        </h6>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body p-0">
                        <div id="fileBrowserModalContainer" style="height: 60vh;"></div>
                    </div>
                    <div class="modal-footer d-flex justify-content-between border-top-0 py-2">
                        <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">
                            <i class="mdi mdi-close me-1"></i>${t('cancel', 'Cancel')}
                        </button>
                        <button type="button" class="btn btn-sm btn-cyber" id="fileBrowserModalSelectBtn" disabled>
                            <i class="mdi mdi-check me-1"></i>${t('select', 'Select')}
                        </button>
                    </div>
                </div>
            </div>
        `;
        document.body.appendChild(this.modalEl);

        this.bsModal = new bootstrap.Modal(this.modalEl, { backdrop: 'static' });

        // Select button click
        this.modalEl.querySelector('#fileBrowserModalSelectBtn').addEventListener('click', () => {
            this._onSelect();
        });

        // On modal hidden (cancelled or closed)
        this.modalEl.addEventListener('hidden.bs.modal', () => {
            if (this._resolve) {
                this._resolve(null);
                this._resolve = null;
            }
        });
    }

    /**
     * Initialize FileBrowser inside the modal container.
     */
    _initFileBrowser() {
        const selectBtn = this.modalEl.querySelector('#fileBrowserModalSelectBtn');
        selectBtn.disabled = true;

        if (!this.fileBrowser) {
            this.fileBrowser = new FileBrowser({
                containerId: 'fileBrowserModalContainer',
                projectId: 'general',
                translations: this.translations,
            });

            // Monkey-patch: listen for file selection changes
            const origSelectFile = this.fileBrowser.selectFile.bind(this.fileBrowser);
            this.fileBrowser.selectFile = async (fileId, skipIfGalleryOpen) => {
                await origSelectFile(fileId, skipIfGalleryOpen);
                this._onFileSelectionChanged();
            };
        }
    }

    /**
     * Called when the FileBrowser's selected file changes.
     * Enable/disable the Select button based on whether a non-directory file is selected.
     */
    _onFileSelectionChanged() {
        const selectBtn = this.modalEl.querySelector('#fileBrowserModalSelectBtn');
        const file = this.fileBrowser?.selectedFile;
        selectBtn.disabled = !file || file.isDirectory;
    }

    /**
     * Called when user clicks "Select".
     */
    _onSelect() {
        const file = this.fileBrowser?.selectedFile;
        if (!file || file.isDirectory) return;

        const ext = (file.name || '').split('.').pop().toLowerCase();
        const isImage = ['jpg', 'jpeg', 'png', 'gif', 'svg', 'webp', 'ico', 'bmp', 'avif', 'tiff'].includes(ext);

        const result = {
            id: file.id,
            name: file.name,
            mimeType: file.mimeType || '',
            isImage,
            path: file.path || '',
        };

        if (this._resolve) {
            this._resolve(result);
            this._resolve = null;
        }

        this.bsModal.hide();
    }
}
