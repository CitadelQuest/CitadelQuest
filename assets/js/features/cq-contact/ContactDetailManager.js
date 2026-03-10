import * as bootstrap from 'bootstrap';
import MarkdownIt from 'markdown-it';
import { renderSharePreviewBlock } from '../../shared/share-preview';

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
        this.showShareContent = false;
        this.md = new MarkdownIt({ html: false, linkify: true, typographer: true });

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

        this.fetchProfile();
        this.loadShares();
    }

    async fetchProfile() {
        try {
            const response = await fetch(`/api/cq-contact/${this.contact.id}/profile`);
            const data = await response.json();

            if (!data.success || !data.profile) return;

            const profile = data.profile;

            // Update photo — use local proxy to handle cross-origin auth
            const photoEl = document.getElementById('contactProfilePhoto');
            if (photoEl && profile.photo_url) {
                const proxyUrl = `/api/cq-contact/${this.contact.id}/profile-photo`;
                photoEl.innerHTML = `
                    <img src="${proxyUrl}" alt="${profile.username || ''}"
                         class="rounded-circle border border-2 border-success"
                         style="width: 64px; height: 64px; object-fit: cover;"
                         onerror="this.style.display='none'; this.parentElement.querySelector('.fallback-icon')?.classList.remove('d-none');">
                    <div class="rounded-circle border border-2 border-secondary d-flex align-items-center justify-content-center d-none fallback-icon"
                         style="width: 64px; height: 64px; background: rgba(255,255,255,0.05);">
                        <i class="mdi mdi-account text-cyber opacity-75" style="font-size: 32px;"></i>
                    </div>
                `;
            }

            // Update bio
            const bioEl = document.getElementById('contactProfileBio');
            if (bioEl && profile.bio) {
                const escaped = profile.bio.replace(/</g, '&lt;').replace(/>/g, '&gt;');
                const bioNl2br = escaped.replace(/\n/g, '<br>');
                if (escaped.length > 600) {
                    const shortBio = escaped.substring(0, 600).replace(/\n/g, '<br>');
                    bioEl.innerHTML = `<div class="text-light mb-0 ms-0 ms-md-5 ps-0 ps-md-2 mt-2" style="word-break: break-word; overflow-wrap: break-word;">
                        <span class="contact-bio-short">${shortBio}…</span>
                        <span class="contact-bio-full d-none">${bioNl2br}</span>
                        <a href="#" class="contact-bio-toggle text-cyber small ms-1">${this.trans.show_more || 'show more'}</a>
                    </div>`;
                    bioEl.querySelector('.contact-bio-toggle')?.addEventListener('click', (e) => {
                        e.preventDefault();
                        const short = bioEl.querySelector('.contact-bio-short');
                        const full = bioEl.querySelector('.contact-bio-full');
                        const expanded = !full.classList.contains('d-none');
                        short.classList.toggle('d-none', !expanded);
                        full.classList.toggle('d-none', expanded);
                        e.target.textContent = expanded ? (this.trans.show_more || 'show more') : (this.trans.show_less || 'show less');
                    });
                } else {
                    bioEl.innerHTML = `<p class="text-light mb-0 ms-0 ms-md-5 ps-0 ps-md-2 mt-2" style="word-break: break-word; overflow-wrap: break-word;">${bioNl2br}</p>`;
                }
            } else if (bioEl && !profile.bio) {
                bioEl.innerHTML = '';
            }

            // Update spirits
            const spiritsEl = document.getElementById('contactProfileSpirits');
            if (spiritsEl && profile.spirits && profile.spirits.length > 0) {
                spiritsEl.innerHTML = profile.spirits.map(spirit => {
                    const color = spirit.color || '#95ec86';
                    const star = spirit.isPrimary && profile.spirits.length > 1
                        ? `<i class="mdi mdi-star text-warning" style="font-size: 0.65rem;"></i>` : '';
                    return `
                        <div class="d-flex align-items-center mb-1" style="white-space: nowrap;">
                            <div class="rounded-circle d-flex align-items-center justify-content-center me-2"
                                 style="width: 28px; height: 28px; background: ${color}21;">
                                <i class="mdi mdi-ghost" style="color: ${color}; font-size: 14px;"></i>
                            </div>
                            <div>
                                <div class="small fw-bold text-light" style="line-height: 1.2;">
                                    ${spirit.name}${star}
                                </div>
                                <div class="text-muted" style="font-size: 0.65rem; line-height: 1;">Level ${spirit.level || 1} | ${spirit.experience || 0} XP</div>
                            </div>
                        </div>`;
                }).join('');
                spiritsEl.classList.remove('d-none');
            }
        } catch (e) {
            // Silently fail — profile fetch is optional enhancement
            console.debug('Profile fetch failed:', e.message);
        }
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

            this.showShareContent = data.show_share_content || false;
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
        let html = '<div class="list-group list-group-flush bg-transparent">';

        shares.forEach(share => {
            const isCqmpack = share.source_type === 'cqmpack';
            const isPdf = share.preview_type === 'pdf' || (share.title || '').toLowerCase().endsWith('.pdf');
            const icon = isCqmpack ? 'mdi-graph' : (isPdf ? 'mdi-file-pdf-box' : 'mdi-file');
            const iconColor = isCqmpack ? 'text-info' : (isPdf ? 'text-danger' : 'text-warning');
            const typeLabel = isCqmpack ? this.t('memory_pack', 'Memory Pack') : this.t('file', 'File');
            const dl = this.downloadStatus[share.share_url];
            const isDownloaded = dl && dl.downloaded;

            let actionsHtml = '';
            if (isDownloaded) {
                actionsHtml += `<span class="badge bg-success bg-opacity-25 px-2"><i class="mdi mdi-check me-1"></i> ${this.t('downloaded', 'Downloaded!')}</span>`;
                if (isCqmpack) {
                    actionsHtml += ` <button class="btn btn-sm btn-outline-cyber ms-2" 
                        onclick="showPackInMemoryExplorer('${dl.path}', '${dl.fileName}')">
                        <i class="mdi mdi-eye me-1"></i><span class="d-none d-md-inline-block"> ${this.t('ui_view', 'View')}</span>
                    </button>`;
                    actionsHtml += ` <button class="btn btn-sm btn-outline-cyber ms-2" 
                        onclick="showAddToLibraryModal('${dl.path}', '${dl.fileName}')">
                        <i class="mdi mdi-book-plus-outline me-1"></i><span class="d-none d-md-inline-block"> ${this.t('add_to_library', 'Add to Library')}</span>
                    </button>`;
                }
            } else {
                const safeTitle = share.title.replace(/'/g, "\\'");
                actionsHtml = `<button class="btn btn-sm btn-cyber" data-share-url="${share.share_url}"
                        onclick="downloadToCitadel('${share.share_url}', '${share.source_type}', '${safeTitle}')">
                    <i class="mdi mdi-download me-1"></i><span class="d-none d-md-inline-block"> ${this.t('download_to_citadel', 'Download to Citadel')}</span>
                </button>`;
            }

            html += `
                <div class="list-group-item bg-transparent border-secondary border-opacity-25 px-4 py-3" data-share-url="${share.share_url}">
                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <div>
                            <i class="mdi ${icon} ${iconColor} me-2"></i>
                            <span class="text-light fw-bold">${share.title || ''}</span>
                            <span class="badge bg-secondary bg-opacity-25 ms-2 small">${typeLabel}</span>
                            <small class="text-muted"><i class="mdi mdi-eye me-1 ms-2"></i>${share.views || 0} ${this.t('views', 'views')}</small>
                        </div>
                        <div class="d-flex align-items-center gap-2">
                            <div id="actions-${share.share_url}" class="text-end">${actionsHtml}</div>
                        </div>
                    </div>`;

            // Description + Content preview (shared rendering)
            html += renderSharePreviewBlock(share, {
                showContent: this.showShareContent,
                md: this.md,
                t: (k, f) => this.t(k, f)
            });

            html += `</div>`;
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
