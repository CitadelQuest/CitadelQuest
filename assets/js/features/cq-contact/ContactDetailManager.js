import * as bootstrap from 'bootstrap';

/**
 * ContactDetailManager
 * Manages the CQ Contact detail page: shared items display, download, and Add to Library modal.
 * Reads config from window.cqContactDetailConfig (set by Twig template).
 */
export class ContactDetailManager {
    constructor() {
        this.config = window.cqContactDetailConfig || {};
        this.contact = this.config.contact || {};
        this.trans = this.config.translations || {};

        this.sharesContainer = document.getElementById('sharesContainer');
        this.btnRefresh = document.getElementById('btnRefreshShares');

        // Download status cache
        this.downloadStatus = {};

        // Add to Library state
        this.addToLibPackPath = null;
        this.addToLibPackName = null;

        this.init();
    }

    init() {
        if (this.btnRefresh) {
            this.btnRefresh.addEventListener('click', () => this.loadShares());
        }

        document.getElementById('btn-confirm-add-to-lib')?.addEventListener('click', () => this.confirmAddToLibrary());

        // Expose for onclick handlers in rendered HTML
        window.downloadToCitadel = (shareUrl, sourceType, title) => this.downloadToCitadel(shareUrl, sourceType, title);
        window.showAddToLibraryModal = (packPath, packName) => this.showAddToLibraryModal(packPath, packName);
        window.showPackInMemoryExplorer = (packPath, packName) => this.showPackInMemoryExplorer(packPath, packName);

        this.loadShares();
    }

    t(key, fallback) {
        return this.trans[key] || fallback || key;
    }

    async loadShares() {
        this.sharesContainer.innerHTML = `
            <div class="text-center py-5">
                <i class="mdi mdi-loading mdi-spin text-cyber fs-2"></i>
                <p class="mt-2">${this.t('loading', 'Loading shared items...')}</p>
            </div>`;

        try {
            const response = await fetch(`/api/cq-contact/${this.contact.id}/shares`);
            const data = await response.json();

            if (!data.success) {
                this.sharesContainer.innerHTML = `
                    <div class="text-center py-4 text-muted">
                        <i class="mdi mdi-alert-circle fs-2 d-block mb-2"></i>
                        ${data.message || this.t('error_loading', 'Failed to load shared items')}
                    </div>`;
                return;
            }

            const shares = data.shares || [];
            if (shares.length === 0) {
                this.sharesContainer.innerHTML = `
                    <div class="text-center py-4 text-muted">
                        <i class="mdi mdi-share-off fs-2 d-block mb-2"></i>
                        ${this.t('no_items', 'No shared items available')}
                    </div>`;
                return;
            }

            // Check which shares are already downloaded before rendering
            await this.checkDownloadStatus(shares);
            this.renderShares(shares);
        } catch (error) {
            console.error('Error loading shares:', error);
            this.sharesContainer.innerHTML = `
                <div class="text-center py-4 text-danger">
                    <i class="mdi mdi-alert-circle fs-2 d-block mb-2"></i>
                    ${this.t('connection_error', 'Connection error')}: ${error.message}
                </div>`;
        }
    }

    async checkDownloadStatus(shares) {
        try {
            const response = await fetch(`/api/cq-contact/${this.contact.id}/check-downloads`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ shares })
            });
            const data = await response.json();
            if (data.success && data.downloads) {
                this.downloadStatus = data.downloads;
            }
        } catch (e) {
            console.warn('Failed to check download status:', e);
        }
    }

    renderShares(shares) {
        let html = '<div class="row g-3">';

        shares.forEach(share => {
            const isCqmpack = share.source_type === 'cqmpack';
            const icon = isCqmpack ? 'mdi-graph' : 'mdi-file';
            const iconColor = isCqmpack ? 'text-info' : 'text-warning';
            const typeLabel = isCqmpack ? this.t('memory_pack', 'Memory Pack') : this.t('file', 'File');
            const dl = this.downloadStatus[share.share_url];
            const isDownloaded = dl && dl.downloaded;

            let actionsHtml = '';
            if (isDownloaded) {
                actionsHtml += `<span class="badge bg-success bg-opacity-25 disabled w-100 px-2"><i class="mdi mdi-check me-1"></i> ${this.t('downloaded', 'Downloaded!')}</span>`;
                if (isCqmpack) {
                    actionsHtml += ` <button class="btn btn-sm btn-outline-cyber ms-2" 
                        onclick="showPackInMemoryExplorer('${dl.path}', '${dl.fileName}')">
                        <i class="mdi mdi-eye me-1"></i> ${this.t('ui_view', 'View')}
                    </button>`;
                    actionsHtml += ` <button class="btn btn-sm btn-outline-cyber ms-2" 
                        onclick="showAddToLibraryModal('${dl.path}', '${dl.fileName}')">
                        <i class="mdi mdi-book-plus-outline me-1"></i> ${this.t('add_to_library', 'Add to Library')}
                    </button>`;
                }
            } else {
                const safeTitle = share.title.replace(/'/g, "\\'");
                actionsHtml = `<button class="btn btn-sm btn-cyber" data-share-url="${share.share_url}"
                        onclick="downloadToCitadel('${share.share_url}', '${share.source_type}', '${safeTitle}')">
                    <i class="mdi mdi-download me-1"></i> ${this.t('download_to_citadel', 'Download to Citadel')}
                </button>`;
            }

            html += `
                <div class="col-12 col-md-6 col-lg-4">
                    <div class="card glass-panel h-100">
                        <div class="card-body">
                            <div class="d-flex align-items-start mb-2">
                                <i class="mdi ${icon} ${iconColor} fs-4 me-2 mt-1"></i>
                                <div class="flex-grow-1 min-width-0">
                                    <span class="mb-1 text-light text-wrap fw-bold">${share.title}</span>
                                    <div class="small text-muted">
                                        <span><i class="mdi ${icon} me-1"></i>${typeLabel}</span>
                                        <span class="mx-1">&middot;</span>
                                        <small class="text-muted">
                                            <i class="mdi mdi-eye me-1"></i>${share.views || 0} ${this.t('views', 'views')}
                                        </small>
                                    </div>
                                </div>
                            </div>
                            <div class="d-flex justify-content-center align-items-center flex-wrap gap-2 mt-2" id="actions-${share.share_url}">
                                ${actionsHtml}
                            </div>
                        </div>
                    </div>
                </div>`;
        });

        html += '</div>';
        this.sharesContainer.innerHTML = html;
    }

    async downloadToCitadel(shareUrl, sourceType, title) {
        const btn = event.target.closest('button');
        const origHtml = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = `<i class="mdi mdi-loading mdi-spin me-1"></i> ${this.t('downloading', 'Downloading...')}`;

        try {
            const response = await fetch(`/api/cq-contact/${this.contact.id}/download-share`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    share_url: shareUrl,
                    source_type: sourceType,
                    title: title
                })
            });
            const data = await response.json();

            if (data.success) {
                window.toast?.success(data.message || this.t('download_success', 'Downloaded to your Citadel!'));

                // Replace the actions area with persistent Downloaded state
                const actionsDiv = document.getElementById(`actions-${shareUrl}`);
                if (actionsDiv) {
                    let html = `<span class="badge bg-success disabled"><i class="mdi mdi-check me-1"></i> ${this.t('downloaded', 'Downloaded!')}</span>`;
                    if (sourceType === 'cqmpack' && data.path && data.fileName) {
                        html += ` <button class="btn btn-sm btn-outline-cyber ms-2"
                            onclick="showAddToLibraryModal('${data.path}', '${data.fileName}')">
                            <i class="mdi mdi-book-plus-outline me-1"></i> ${this.t('add_to_library', 'Add to Library')}
                        </button>`;
                    }
                    actionsDiv.innerHTML = html;
                } else {
                    btn.innerHTML = `<i class="mdi mdi-check me-1"></i> ${this.t('downloaded', 'Downloaded!')}`;
                    btn.classList.remove('btn-cyber');
                    btn.classList.add('btn-success');
                }
            } else {
                btn.innerHTML = origHtml;
                btn.disabled = false;
                window.toast?.error(data.message || this.t('download_failed', 'Download failed'));
            }
        } catch (error) {
            console.error('Download error:', error);
            btn.innerHTML = origHtml;
            btn.disabled = false;
            window.toast?.error(this.t('download_failed', 'Download failed') + ': ' + error.message);
        }
    }

    // ========================================
    // View in Memory Explorer
    // ========================================

    showPackInMemoryExplorer(packPath, packName) {
        // Set pack selection in localStorage so Memory Explorer auto-selects it
        const packValue = JSON.stringify({ path: packPath, name: packName });
        localStorage.setItem('cqMemoryPack_global', packValue);
        // Clear library selection so it shows "All Packs"
        localStorage.removeItem('cqMemoryLib_global');
        // Navigate to Memory Explorer
        window.location.href = '/memory';
    }

    // ========================================
    // Add to Library
    // ========================================

    async showAddToLibraryModal(packPath, packName) {
        this.addToLibPackPath = packPath;
        this.addToLibPackName = packName;

        const select = document.getElementById('cq-add-pack-lib-select');
        if (!select) return;

        select.innerHTML = `<option value="">${this.t('loading_libraries', 'Loading libraries...')}</option>`;
        select.disabled = true;

        // Open modal immediately
        const modalEl = document.getElementById('addPackToLibraryModal');
        if (!modalEl) return;
        const bsModal = bootstrap.Modal.getOrCreateInstance(modalEl);
        bsModal.show();

        // Fetch all libraries from root
        try {
            const response = await fetch('/api/memory/library/list', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ projectId: 'general', path: '/' })
            });
            const data = await response.json();

            if (data.success && data.libraries) {
                let html = `<option value="">-- ${this.t('select_library', 'Select a library')} --</option>`;
                const libs = data.libraries.sort((a, b) =>
                    (a.displayName || a.name).localeCompare(b.displayName || b.name)
                );
                libs.forEach(lib => {
                    const val = JSON.stringify({ path: lib.path, name: lib.name });
                    const label = `${lib.displayName || lib.name} (${lib.packCount || 0} packs)`;
                    html += `<option value='${val.replace(/'/g, '&#39;')}'>${label}</option>`;
                });
                select.innerHTML = html;
                select.disabled = false;
            } else {
                select.innerHTML = `<option value="">${this.t('no_libraries', 'No libraries found')}</option>`;
            }
        } catch (e) {
            console.error('Failed to load libraries:', e);
            select.innerHTML = `<option value="">${this.t('error_loading_libraries', 'Error loading libraries')}</option>`;
        }
    }

    async confirmAddToLibrary() {
        const select = document.getElementById('cq-add-pack-lib-select');
        if (!select || !select.value) {
            window.toast?.warning(this.t('select_library_first', 'Select a library first'));
            return;
        }

        const confirmBtn = document.getElementById('btn-confirm-add-to-lib');
        const origHtml = confirmBtn.innerHTML;
        confirmBtn.disabled = true;
        confirmBtn.innerHTML = '<i class="mdi mdi-loading mdi-spin me-1"></i>';

        try {
            const libData = JSON.parse(select.value);
            const response = await fetch('/api/memory/library/add-pack', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    projectId: 'general',
                    libraryPath: libData.path,
                    libraryName: libData.name,
                    packPath: this.addToLibPackPath,
                    packName: this.addToLibPackName
                })
            });
            const data = await response.json();

            if (data.success) {
                window.toast?.success(this.t('added_to_library', 'Pack added to library!'));
                const modalEl = document.getElementById('addPackToLibraryModal');
                if (modalEl) bootstrap.Modal.getInstance(modalEl)?.hide();
            } else {
                throw new Error(data.error || this.t('add_to_library_failed', 'Failed to add pack to library'));
            }
        } catch (e) {
            console.error('Add to library error:', e);
            window.toast?.error(e.message);
        } finally {
            confirmBtn.innerHTML = origHtml;
            confirmBtn.disabled = false;
        }
    }
}
