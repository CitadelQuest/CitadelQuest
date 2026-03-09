import { MemoryGraphView } from '../cq-memory/MemoryGraphView';
import MarkdownIt from 'markdown-it';
import * as bootstrap from 'bootstrap';

/**
 * CitadelExplorer
 * Discover and interact with any public CitadelQuest profile.
 * - Fetches remote profile JSON via proxy
 * - Renders full profile preview with photo, bio, spirits, shared items
 * - Download shared items to local Citadel
 * - Add to Library for Memory Packs
 * - Add Contact / Send Friend Request
 */
export class CitadelExplorer {
    constructor() {
        this.config = window.citadelExplorerConfig || {};
        this.trans = this.config.translations || {};

        this.urlInput = document.getElementById('explorerUrlInput');
        this.fetchBtn = document.getElementById('explorerFetchBtn');
        this.previewContainer = document.getElementById('explorerPreview');
        this.urlHelp = document.getElementById('explorerUrlHelp');

        // Current profile state
        this.profile = null;
        this.profileUrl = null;
        this.downloadStatus = {};
        this.graphViews = [];

        // Add to Library state
        this.addToLibPackPath = null;
        this.addToLibPackName = null;

        this.md = new MarkdownIt({ html: true, linkify: true, typographer: true });

        this.init();
    }

    t(key, fallback) {
        return this.trans[key] || fallback || key;
    }

    init() {
        if (!this.urlInput || !this.fetchBtn) return;

        // Check URL param first (e.g. /cq-contacts?url=https://...)
        const urlParams = new URLSearchParams(window.location.search);
        const paramUrl = urlParams.get('url');
        if (paramUrl) {
            localStorage.setItem('citadelExplorerUrl', paramUrl);
            // Clean URL without reloading (remove ?url= param)
            const cleanUrl = window.location.pathname;
            window.history.replaceState({}, '', cleanUrl);
        }

        // Restore URL from localStorage (or just-set param)
        const savedUrl = paramUrl || localStorage.getItem('citadelExplorerUrl');
        if (savedUrl) {
            this.urlInput.value = savedUrl;
            this.fetchBtn.disabled = false;
            this.toggleUrlHelp();
            // Auto-explore on page load if URL was saved
            setTimeout(() => this.explore(), 100);
        }

        // Enable/disable explore button based on input
        this.urlInput.addEventListener('input', () => {
            const url = this.urlInput.value.trim();
            this.fetchBtn.disabled = !url || !url.startsWith('https://');
            this.toggleUrlHelp();
        });

        // Explore on button click
        this.fetchBtn.addEventListener('click', () => this.explore());

        // Explore on Enter key
        this.urlInput.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' && !this.fetchBtn.disabled) {
                e.preventDefault();
                this.explore();
            }
        });

        // Expose global handlers for onclick in rendered HTML
        window.explorerDownload = (shareUrl, sourceType, title) => this.downloadShare(shareUrl, sourceType, title);
        window.explorerAddToLibrary = (packPath, packName) => this.showAddToLibraryModal(packPath, packName);
        window.explorerViewInMemory = (packPath, packName) => this.viewInMemoryExplorer(packPath, packName);
        window.explorerViewInFileBrowser = (filePath, fileName) => this.viewInFileBrowser(filePath, fileName);
    }

    toggleUrlHelp() {
        if (this.urlHelp) {
            this.urlHelp.style.display = this.urlInput.value.trim() ? 'none' : '';
        }
    }

    async explore() {
        let url = this.urlInput.value.trim();
        if (!url || !url.startsWith('https://')) return;

        // Detect share URL pattern: https://domain/username/share/{slug}
        // Strip /share/{slug} to get profile URL, remember the slug for highlighting
        this.highlightShareUrl = null;
        const shareMatch = url.match(/^(https:\/\/[^/]+\/[^/]+)\/share\/([^/]+)\/?$/);
        if (shareMatch) {
            url = shareMatch[1];
            this.highlightShareUrl = shareMatch[2];
        }

        this.profileUrl = url;
        this.destroyGraphs();

        // Show loading state
        this.previewContainer.classList.remove('d-none');
        this.previewContainer.innerHTML = `
            <div class="text-center py-5">
                <i class="mdi mdi-loading mdi-spin text-cyber fs-2"></i>
                <p class="mt-2">${this.t('loading', 'Loading...')}</p>
            </div>`;

        this.fetchBtn.disabled = true;

        try {
            const response = await fetch('/api/citadel-explorer/fetch', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ url })
            });

            const data = await response.json();

            if (!data.success) {
                this.showError(data.message || this.t('profile_not_public', 'Profile not available'));
                return;
            }

            this.profile = data;

            // Save URL to localStorage on successful explore
            localStorage.setItem('citadelExplorerUrl', url);

            // Share URL → show only that share item; otherwise full profile
            if (this.highlightShareUrl) {
                await this.renderShareOnly();
            } else {
                await this.renderProfile();
            }
        } catch (error) {
            console.error('Explorer fetch error:', error);
            this.showError(this.t('not_citadelquest', 'Could not connect to this Citadel'));
        } finally {
            this.fetchBtn.disabled = false;
        }
    }

    showError(message) {
        this.previewContainer.innerHTML = `
            <div class="text-center py-4 glass-panel rounded">
                <i class="mdi mdi-alert-circle text-warning fs-2 d-block mb-2"></i>
                <p class="text-muted mb-0">${message}</p>
            </div>`;
    }

    async renderProfile() {
        const p = this.profile;
        const profileLink = p.profile_url || this.profileUrl;

        // Build photo HTML
        let photoHtml = '';
        if (p.photo_url) {
            // Local contact photo proxy doesn't need the explorer proxy wrapper
            const proxyPhotoUrl = p.photo_url.startsWith('/api/')
                ? p.photo_url
                : `/api/citadel-explorer/photo?url=${encodeURIComponent(p.photo_url)}`;
            photoHtml = `
                <img src="${proxyPhotoUrl}" alt="${p.username}"
                     class="rounded-circle border border-2 border-success"
                     style="width: 96px; height: 96px; object-fit: cover;"
                     onerror="this.style.display='none'; this.nextElementSibling?.classList.remove('d-none');">
                <div class="rounded-circle border border-2 border-secondary d-flex align-items-center justify-content-center d-none"
                     style="width: 96px; height: 96px; background: rgba(255,255,255,0.05);">
                    <i class="mdi mdi-account text-cyber opacity-75" style="font-size: 40px;"></i>
                </div>`;
        } else {
            photoHtml = `
                <div class="rounded-circle border border-2 border-secondary d-flex align-items-center justify-content-center"
                     style="width: 96px; height: 96px; background: rgba(255,255,255,0.05);">
                    <i class="mdi mdi-account text-cyber opacity-75" style="font-size: 40px;"></i>
                </div>`;
        }

        // Build spirits HTML (matching public profile / CQ Contact detail style)
        let spiritsHtml = '';
        if (p.spirits && p.spirits.length > 0) {
            spiritsHtml = p.spirits.map(spirit => {
                const color = spirit.color || '#95ec86';
                const star = spirit.isPrimary && p.spirits.length > 1
                    ? `<i class="mdi mdi-star text-warning" style="font-size: 0.65rem;"></i>` : '';
                return `
                    <div class="d-flex align-items-center mb-1" style="white-space: nowrap;">
                        <div class="rounded-circle d-flex align-items-center justify-content-center me-2"
                             style="width: 28px; height: 28px; background: ${color}21;">
                            <i class="mdi mdi-ghost" style="color: ${color}; font-size: 14px;"></i>
                        </div>
                        <div>
                            <div class="small fw-bold text-light" style="line-height: 1.2;">
                                ${spirit.name} ${star}
                            </div>
                            <div class="text-muted" style="font-size: 0.65rem; line-height: 1;">Level ${spirit.level || 1} | ${spirit.experience || 0} XP</div>
                        </div>
                    </div>`;
            }).join('');
        }

        // Build contact action button
        let contactActionHtml = '';
        if (p.is_contact) {
            contactActionHtml = `
                <span class="badge bg-success bg-opacity-25 px-2 py-1">
                    <i class="mdi mdi-account-check"></i>
                </span>`;
        } else {
            contactActionHtml = `
                <button class="btn btn-sm btn-cyber" id="explorerAddContactBtn">
                    <i class="mdi mdi-account-plus me-1"></i><span class="d-none d-md-inline-block">${this.t('add_contact', 'Add Contact')}</span>
                </button>`;
        }

        // Build bio HTML with markdown rendering and show more for long bios
        let bioHtml = '';
        if (p.bio) {
            const fullBioHtml = this.md.render(p.bio);
            if (p.bio.length > 600) {
                const shortBio = this.md.render(p.bio.substring(0, 600) + '…');
                bioHtml = `<div class="text-light mt-2 mb-0 explorer-bio-md" style="word-break: break-word; overflow-wrap: break-word;">
                    <div class="explorer-bio-short">${shortBio}</div>
                    <div class="explorer-bio-full d-none">${fullBioHtml}</div>
                    <a href="#" class="explorer-bio-toggle text-cyber small ms-1">${this.t('show_more', 'show more')}</a>
                </div>`;
            } else {
                bioHtml = `<div class="text-light mt-2 mb-0 explorer-bio-md" style="word-break: break-word; overflow-wrap: break-word;">${fullBioHtml}</div>`;
            }
        }

        // Main profile card
        let html = `
            <div class="glass-panel rounded p-4 glass-panel-glow">
                <div class="d-block position-absolute top-0 end-0 m-1">
                    <button class="btn btn-sm btn-outline-secondary" id="explorerCloseBtn" title="Close"
                        style="padding: 0.1rem 0.4rem !important; opacity:0.6;">
                        <i class="mdi mdi-close"></i>
                    </button>
                </div>

                <div class="d-flex align-items-start">
                    <div class="me-3 flex-shrink-0">${photoHtml}</div>
                    <div class="flex-grow-1 d-none d-md-block">
                        <h3 class="h4 mb-1 text-cyber">${p.username || ''}</h3>
                        <small class="text-muted">
                            <i class="mdi mdi-web me-1"></i>
                            <a href="${profileLink}" target="_blank" class="text-muted text-decoration-none">
                                ${p.domain || ''}
                            </a>
                        </small>
                    </div>
                    <div class="d-flex flex-column align-items-end gap-2 ms-auto flex-shrink-0 mt-3">
                        ${spiritsHtml ? `<div>${spiritsHtml}</div>` : ''}
                        ${contactActionHtml}
                    </div>
                </div>
                <div class="d-md-none mt-3">
                    <h3 class="h4 mb-1 text-cyber">${p.username || ''}</h3>
                    <small class="text-muted">
                        <i class="mdi mdi-web me-1"></i>
                        <a href="${profileLink}" target="_blank" class="text-muted text-decoration-none">
                            ${p.domain || ''}
                        </a>
                    </small>
                </div>
                ${bioHtml}`;

        // Shared items section (single container for both public and contact-scoped shares)
        // When is_contact, contact shares are a superset of public shares (scope 0+1)
        const hasPublicShares = (p.shares || []).length > 0;
        const needsSharesSection = hasPublicShares || (p.is_contact && p.contact_id);
        if (needsSharesSection) {
            html += `
                <hr class="border-secondary border-opacity-25 my-4">
                <h5 class="text-cyber mb-3">
                    <i class="mdi mdi-share-variant me-2 text-success"></i>${this.t('shared_items', 'Shared Items')}
                </h5>
                <div id="explorerSharesContainer">
                    <div class="text-center py-3">
                        <i class="mdi mdi-loading mdi-spin text-cyber"></i>
                    </div>
                </div>`;
        }

        html += `</div>`;

        // Add to Library modal (reuse pattern from ContactDetailManager)
        html += `
            <div class="modal fade" id="explorerAddToLibModal" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog">
                    <div class="modal-content glass-panel">
                        <div class="modal-header bg-cyber-g border-success border-1 border-bottom">
                            <h5 class="modal-title">
                                <i class="mdi mdi-book-plus-outline me-2"></i>${this.t('add_to_library', 'Add to Library')}
                            </h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <select id="explorer-lib-select" class="form-select glass-input" disabled>
                                <option value="">${this.t('loading_libraries', 'Loading libraries...')}</option>
                            </select>
                        </div>
                        <div class="modal-footer border-top-0">
                            <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">
                                <i class="mdi mdi-cancel me-1"></i>Cancel
                            </button>
                            <button type="button" class="btn btn-cyber btn-sm" id="explorer-confirm-add-lib">
                                <i class="mdi mdi-book-plus-outline me-1"></i>${this.t('add_to_library', 'Add to Library')}
                            </button>
                        </div>
                    </div>
                </div>
            </div>`;

        this.previewContainer.innerHTML = html;

        // Move the modal to document.body so it's not trapped inside the preview container (z-index)
        const libModal = document.getElementById('explorerAddToLibModal');
        if (libModal) {
            document.body.appendChild(libModal);
        }

        // Bind close button
        document.getElementById('explorerCloseBtn')?.addEventListener('click', () => {
            this.destroyGraphs();
            this.previewContainer.classList.add('d-none');
            this.previewContainer.innerHTML = '';
            this.profile = null;
            this.urlInput.value = '';
            localStorage.removeItem('citadelExplorerUrl');
            this.toggleUrlHelp();
            // Remove orphaned modal from body
            const orphanModal = document.getElementById('explorerAddToLibModal');
            if (orphanModal) orphanModal.remove();
        });

        // Bind bio show more/less toggle
        document.querySelector('.explorer-bio-toggle')?.addEventListener('click', (e) => {
            e.preventDefault();
            const short = document.querySelector('.explorer-bio-short');
            const full = document.querySelector('.explorer-bio-full');
            const expanded = !full.classList.contains('d-none');
            short.classList.toggle('d-none', !expanded);
            full.classList.toggle('d-none', expanded);
            e.target.textContent = expanded ? this.t('show_more', 'show more') : this.t('show_less', 'show less');
        });

        // Bind add contact button
        document.getElementById('explorerAddContactBtn')?.addEventListener('click', () => this.addContact());

        // Bind add to library confirm
        document.getElementById('explorer-confirm-add-lib')?.addEventListener('click', () => this.confirmAddToLibrary());

        // Load and render shares — contact endpoint is superset of public shares
        if (needsSharesSection) {
            await this.loadAndRenderShares();
        }
    }

    /**
     * Render only a single share item (when navigated via share URL).
     * Shows a compact profile header + the matched share with full content.
     */
    async renderShareOnly() {
        const p = this.profile;
        const profileLink = p.profile_url || this.profileUrl;
        const slug = this.highlightShareUrl;

        // Find the share directly from the already-fetched profile data
        const shares = p.shares || [];
        const share = shares.find(s => s.share_url === slug);
        if (!share) {
            this.showError(this.t('share_not_found', 'Shared item not found'));
            return;
        }

        // Check download status for this single share
        this.downloadStatus = {};
        try {
            let dlUrl, dlBody;
            if (p.is_contact && p.contact_id) {
                dlUrl = `/api/cq-contact/${p.contact_id}/check-downloads`;
                dlBody = JSON.stringify({ shares: [share] });
            } else {
                dlUrl = '/api/citadel-explorer/check-downloads';
                dlBody = JSON.stringify({ profile_url: p.profile_url || this.profileUrl, shares: [share] });
            }
            const dlResp = await fetch(dlUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: dlBody
            });
            const dlData = await dlResp.json();
            if (dlData.success && dlData.downloads) {
                this.downloadStatus = dlData.downloads;
            }
        } catch (e) {
            console.warn('Failed to check download status:', e);
        }

        // Build compact profile header
        let photoHtml = '';
        if (p.photo_url) {
            const proxyPhotoUrl = p.photo_url.startsWith('/api/')
                ? p.photo_url
                : `/api/citadel-explorer/photo?url=${encodeURIComponent(p.photo_url)}`;
            photoHtml = `<img src="${proxyPhotoUrl}" alt="${p.username}"
                class="rounded-circle border border-2 border-success me-2"
                style="width: 36px; height: 36px; object-fit: cover;"
                onerror="this.style.display='none'">`;
        }

        let html = `
            <div class="glass-panel rounded p-4 glass-panel-glow">
                <div class="d-block position-absolute top-0 end-0 m-1">
                    <button class="btn btn-sm btn-outline-secondary" id="explorerCloseBtn" title="Close"
                        style="padding: 0.1rem 0.4rem !important; opacity:0.6;">
                        <i class="mdi mdi-close"></i>
                    </button>
                </div>

                <div class="d-flex align-items-center mb-3">
                    ${photoHtml}
                    <div>
                        <a href="#" class="text-cyber text-decoration-none fw-bold explorer-view-profile">${p.username || ''}</a>
                        <small class="text-muted ms-2">
                            <i class="mdi mdi-web me-1"></i>${p.domain || ''}
                        </small>
                    </div>
                </div>

                <div id="explorerSharesContainer"></div>
            </div>`;

        // Add to Library modal
        html += `
            <div class="modal fade" id="explorerAddToLibModal" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog">
                    <div class="modal-content glass-panel">
                        <div class="modal-header bg-cyber-g border-success border-1 border-bottom">
                            <h5 class="modal-title">
                                <i class="mdi mdi-book-plus-outline me-2"></i>${this.t('add_to_library', 'Add to Library')}
                            </h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <select id="explorer-lib-select" class="form-select glass-input" disabled>
                                <option value="">${this.t('loading_libraries', 'Loading libraries...')}</option>
                            </select>
                        </div>
                        <div class="modal-footer border-top-0">
                            <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">
                                <i class="mdi mdi-cancel me-1"></i>Cancel
                            </button>
                            <button type="button" class="btn btn-cyber btn-sm" id="explorer-confirm-add-lib">
                                <i class="mdi mdi-book-plus-outline me-1"></i>${this.t('add_to_library', 'Add to Library')}
                            </button>
                        </div>
                    </div>
                </div>
            </div>`;

        this.previewContainer.innerHTML = html;

        const libModal = document.getElementById('explorerAddToLibModal');
        if (libModal) document.body.appendChild(libModal);

        // Render just the single share
        const container = document.getElementById('explorerSharesContainer');
        this.renderShares([share], container);

        // Bind close button
        document.getElementById('explorerCloseBtn')?.addEventListener('click', () => {
            this.destroyGraphs();
            this.previewContainer.classList.add('d-none');
            this.previewContainer.innerHTML = '';
            this.profile = null;
            this.urlInput.value = '';
            localStorage.removeItem('citadelExplorerUrl');
            this.toggleUrlHelp();
            const orphanModal = document.getElementById('explorerAddToLibModal');
            if (orphanModal) orphanModal.remove();
        });

        // Bind "view full profile" link
        this.previewContainer.querySelector('.explorer-view-profile')?.addEventListener('click', (e) => {
            e.preventDefault();
            this.highlightShareUrl = null;
            this.urlInput.value = profileLink;
            this.explore();
        });

        // Bind add to library confirm
        document.getElementById('explorer-confirm-add-lib')?.addEventListener('click', () => this.confirmAddToLibrary());
    }

    /**
     * Load shares and render them. When is_contact, fetches from the contact
     * federation endpoint (superset: public + CQ Contact scoped shares).
     * Otherwise uses public shares already in this.profile.
     */
    async loadAndRenderShares() {
        const container = document.getElementById('explorerSharesContainer');
        if (!container) return;

        const p = this.profile;
        let shares;

        if (p.is_contact && p.contact_id) {
            // Fetch from contact API — returns public + CQ Contact scoped shares
            try {
                const response = await fetch(`/api/cq-contact/${p.contact_id}/shares`);
                const data = await response.json();
                shares = (data.success && data.shares) ? data.shares : [];
            } catch (error) {
                console.error('Error loading contact shares:', error);
                container.innerHTML = `
                    <div class="text-center py-3 text-muted small">
                        <i class="mdi mdi-alert-circle me-1"></i>${error.message}
                    </div>`;
                return;
            }
        } else {
            shares = p.shares || [];
        }

        if (shares.length === 0) {
            container.innerHTML = `
                <div class="text-center py-3 text-muted small">
                    <i class="mdi mdi-share-off me-1"></i>${this.t('no_items', 'No shared items')}
                </div>`;
            return;
        }

        // Check download status — pick endpoint based on is_contact
        this.downloadStatus = {};
        try {
            let dlUrl, dlBody;
            if (p.is_contact && p.contact_id) {
                dlUrl = `/api/cq-contact/${p.contact_id}/check-downloads`;
                dlBody = JSON.stringify({ shares });
            } else {
                dlUrl = '/api/citadel-explorer/check-downloads';
                dlBody = JSON.stringify({ profile_url: p.profile_url || this.profileUrl, shares });
            }
            const dlResp = await fetch(dlUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: dlBody
            });
            const dlData = await dlResp.json();
            if (dlData.success && dlData.downloads) {
                this.downloadStatus = dlData.downloads;
            }
        } catch (e) {
            console.warn('Failed to check download status:', e);
        }

        this.renderShares(shares, container);
    }

    renderShares(shares, container) {
        let html = '<div class="list-group list-group-flush bg-transparent">';

        shares.forEach(share => {
            const isCqmpack = share.source_type === 'cqmpack';
            const icon = isCqmpack ? 'mdi-graph' : 'mdi-file';
            const iconColor = isCqmpack ? 'text-info' : 'text-warning';
            const typeLabel = isCqmpack ? this.t('memory_pack', 'Memory Pack') : this.t('file', 'File');

            const dl = this.downloadStatus[share.share_url];
            const isDownloaded = dl && dl.downloaded;

            // Download action — single handler, endpoint chosen in downloadShare()
            let actionsHtml = '';
            if (isDownloaded) {
                actionsHtml = `<span class="badge bg-success bg-opacity-25 px-2"><i class="mdi mdi-check me-1"></i>${this.t('downloaded', 'Downloaded!')}</span>`;
                if (dl.path && dl.fileName) {
                    if (isCqmpack) {
                        actionsHtml += ` <button class="btn btn-sm btn-outline-cyber ms-1 md-my-0 my-2"
                            onclick="explorerViewInMemory('${dl.path}', '${dl.fileName}')">
                            <i class="mdi mdi-eye me-1"></i>${this.t('ui_view', 'View')}
                        </button>`;
                        actionsHtml += ` <button class="btn btn-sm btn-outline-cyber ms-1"
                            onclick="explorerAddToLibrary('${dl.path}', '${dl.fileName}')">
                            <i class="mdi mdi-book-plus-outline me-1"></i>${this.t('add_to_library', 'Add to Library')}
                        </button>`;
                    } else {
                        actionsHtml += ` <button class="btn btn-sm btn-outline-cyber ms-1 md-my-0 my-2"
                            onclick="explorerViewInFileBrowser('${dl.path}', '${dl.fileName}')">
                            <i class="mdi mdi-eye me-1"></i>${this.t('ui_view', 'View')}
                        </button>`;
                    }
                }
            } else {
                const safeTitle = (share.title || '').replace(/'/g, "\\'");
                actionsHtml = `<button class="btn btn-sm btn-cyber" data-share-url="${share.share_url}"
                    onclick="explorerDownload('${share.share_url}', '${share.source_type}', '${safeTitle}')">
                    <i class="mdi mdi-download me-1"></i>${this.t('download_to_citadel', 'Download to Citadel')}
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
                            <button class="btn btn-sm btn-outline-primary border-0" title="${this.t('copy_link', 'Copy link')}"
                                onclick="navigator.clipboard.writeText('${(this.profile.profile_url || this.profileUrl).replace(/'/g, "\\'")}/share/${share.share_url}').then(() => window.toast?.success('${this.t('link_copied', 'Link copied!')}'))">
                                <i class="mdi mdi-link-variant"></i>
                            </button>
                        </div>
                        <div class="d-flex align-items-center gap-2">
                            <div id="explorer-actions-${share.share_url}" class="text-end">${actionsHtml}</div>
                        </div>
                    </div>`;

            // Description + Content preview with layout control
            const ds = parseInt(share.display_style ?? 1);
            const desc = (share.description || '').trim();
            const dds = parseInt(share.description_display_style ?? 1);
            const hasPreview = this.profile.show_share_content && share.preview_type && ds > 0;
            const hasDesc = desc.length > 0;
            const isColumn = dds === 2 || dds === 3;

            if (hasDesc || hasPreview) {
                const descRendered = this.md.render(desc);
                const descHtml = `<div class="p-3 rounded text-light small" style="background: rgba(0,0,0,0.15); word-break: break-word; overflow-wrap: break-word;">${descRendered}</div>`;
                const colWidth = isColumn && hasDesc && hasPreview ? ' style="width: 40%; min-width: 120px;"' : '';
                const wrapClass = isColumn && hasDesc && hasPreview ? ' d-flex flex-column flex-md-row gap-3' : '';

                html += `<div class="mt-3${wrapClass}">`;

                // Description above (0) or left (2)
                if (hasDesc && (dds === 0 || dds === 2)) {
                    html += `<div class="${isColumn && hasPreview ? 'flex-shrink-0' : ''} mb-${isColumn ? '0' : '3'}"${colWidth}>${descHtml}</div>`;
                }

                // Content preview block
                if (hasPreview) {
                    html += `<div class="${isColumn && hasDesc ? 'flex-grow-1' : ''}" style="min-width: 0;">`;

                    if (share.preview_type === 'image' && share.preview_url) {
                        const imgStyle = ds === 1
                            ? 'max-height: 500px; object-fit: contain; background: rgba(0,0,0,0.2);'
                            : 'background: rgba(0,0,0,0.2);';
                        html += `<div><img src="${share.preview_url}" alt="${share.title || ''}" class="rounded w-100" style="${imgStyle}"></div>`;
                    }

                    if (share.preview_type === 'html' && share.preview_content) {
                        if (ds === 1) {
                            const escaped = share.preview_content.replace(/</g, '&lt;').replace(/>/g, '&gt;');
                            html += `<div class="p-3 rounded share-preview-scroll" style="background: rgba(0,0,0,0.2); max-height: 300px; overflow-y: auto;"><pre class="mb-0 text-light small" style="white-space: pre-wrap; word-break: break-word;">${escaped}</pre></div>`;
                        } else if (ds === 2) {
                            html += `<div class="rounded" style="background: rgba(0,0,0,0.1);">${share.preview_content}</div>`;
                        }
                    }

                    if (share.preview_type === 'text' && share.preview_content) {
                        const ext = share.preview_ext || '';
                        const scrollStyle = ds === 1
                            ? 'background: rgba(0,0,0,0.2); max-height: 300px; overflow-y: auto;'
                            : 'background: rgba(0,0,0,0.2);';
                        if (['md', 'markdown'].includes(ext)) {
                            const rendered = this.md.render(share.preview_content);
                            html += `<div class="p-3 rounded share-preview-scroll" style="${scrollStyle}"><div class="text-light small">${rendered}</div></div>`;
                        } else {
                            const escaped = share.preview_content.replace(/</g, '&lt;').replace(/>/g, '&gt;');
                            html += `<div class="p-3 rounded share-preview-scroll" style="${scrollStyle}"><pre class="mb-0 text-light small" style="white-space: pre-wrap; word-break: break-word;">${escaped}</pre></div>`;
                        }
                    }

                    if (share.preview_type === 'graph' && share.preview_graph_url) {
                        html += `
                            <div>
                                <div class="share-graph-preview memory-graph-preview rounded"
                                     data-graph-url="${share.preview_graph_url}"
                                     style="height: 250px; background: rgba(10, 10, 15, 0.6); position: relative;">
                                    <div class="graph-loading position-absolute top-50 start-50 translate-middle text-center" style="z-index: 10;">
                                        <div class="spinner-border spinner-border-sm text-cyber" role="status">
                                            <span class="visually-hidden">Loading...</span>
                                        </div>
                                    </div>
                                    <canvas class="rounded" style="position: absolute; top: 0; left: 0; width: 100%; height: 100%;"></canvas>
                                </div>
                                <div class="d-flex justify-content-center align-items-center mt-2 share-graph-stats">
                                    <small class="text-secondary">
                                        <i class="mdi mdi-circle-multiple text-cyber opacity-25"></i>
                                        <span class="stat-nodes">0</span>x ${this.t('nodes', 'nodes')} &nbsp; · &nbsp;
                                        <i class="mdi mdi-link-variant text-cyber opacity-25"></i>
                                        <span class="stat-edges">0</span>x ${this.t('relationships', 'relationships')}
                                    </small>
                                </div>
                            </div>`;
                    }

                    html += `</div>`;
                }

                // Description below (1) or right (3)
                if (hasDesc && (dds === 1 || dds === 3)) {
                    html += `<div class="${isColumn && hasPreview ? 'flex-shrink-0' : ''} mt-${isColumn ? '0' : '3'}"${colWidth}>${descHtml}</div>`;
                }

                html += `</div>`;
            }

            html += `</div>`;
        });

        html += '</div>';
        container.innerHTML = html;

        // Initialize 3D graphs after DOM update
        this.initGraphPreviews();

    }

    // ========================================
    // 3D Graph Previews
    // ========================================

    initGraphPreviews() {
        const containers = this.previewContainer.querySelectorAll('.share-graph-preview');
        containers.forEach(container => {
            const graphUrl = container.dataset.graphUrl;
            if (!graphUrl) return;
            this.initSingleGraph(container, graphUrl);
        });
    }

    async initSingleGraph(container, graphUrl) {
        const canvas = container.querySelector('canvas');
        if (!canvas) return;

        const rect = container.getBoundingClientRect();
        if (rect.width === 0 || rect.height === 0) return;
        canvas.width = rect.width;
        canvas.height = rect.height;

        const graphView = new MemoryGraphView(container, {
            backgroundColor: 0x0a0a0f,
            compact: true
        });
        graphView.setOnNodeSelect(null);
        this.graphViews.push(graphView);

        try {
            const proxyUrl = `/api/citadel-explorer/graph?url=${encodeURIComponent(graphUrl)}`;
            const response = await fetch(proxyUrl);
            if (!response.ok) throw new Error(`HTTP ${response.status}`);

            const data = await response.json();
            const graphData = {
                nodes: data.nodes || [],
                edges: data.edges || [],
                stats: data.stats || {},
                packs: {}
            };

            const statsEl = container.parentElement?.querySelector('.share-graph-stats');
            if (statsEl) {
                const nodesEl = statsEl.querySelector('.stat-nodes');
                const edgesEl = statsEl.querySelector('.stat-edges');
                if (nodesEl) nodesEl.textContent = graphData.nodes.length;
                if (edgesEl) edgesEl.textContent = graphData.edges.length;
            }

            graphView.loadGraph(graphData);
            graphView.resetView();

            const loadingEl = container.querySelector('.graph-loading');
            if (loadingEl) loadingEl.classList.add('d-none');

            if (graphView.controls) {
                graphView.controls.autoRotate = true;
                graphView.controls.autoRotateSpeed = 0.5;
            }
        } catch (error) {
            console.warn('Failed to load explorer graph:', error);
            const loadingEl = container.querySelector('.graph-loading');
            if (loadingEl) {
                loadingEl.innerHTML = `
                    <div class="text-secondary small">
                        <i class="mdi mdi-alert-circle-outline"></i>
                        <p class="mt-1 mb-0">Could not load graph</p>
                    </div>`;
            }
        }
    }

    destroyGraphs() {
        this.graphViews.forEach(gv => {
            try { gv.destroy(); } catch (e) {}
        });
        this.graphViews = [];
    }

    // ========================================
    // Download
    // ========================================

    async downloadShare(shareUrl, sourceType, title) {
        const actionsDiv = document.getElementById(`explorer-actions-${shareUrl}`);
        const btn = actionsDiv?.querySelector('button');
        if (!btn) return;

        const origHtml = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = `<i class="mdi mdi-loading mdi-spin me-1"></i>${this.t('downloading', 'Downloading...')}`;

        try {
            const p = this.profile;
            let response;

            if (p.is_contact && p.contact_id) {
                // Authenticated contact download
                response = await fetch(`/api/cq-contact/${p.contact_id}/download-share`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        share_url: shareUrl,
                        source_type: sourceType,
                        title: title
                    })
                });
            } else {
                // Public explorer download
                response = await fetch('/api/citadel-explorer/download', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        profile_url: p.profile_url || this.profileUrl,
                        share_url: shareUrl,
                        source_type: sourceType,
                        title: title,
                        domain: p.domain,
                        username: p.username
                    })
                });
            }
            const data = await response.json();

            if (data.success) {
                window.toast?.success(data.message || this.t('download_success', 'Downloaded to your Citadel!'));

                let html = `<span class="badge bg-success bg-opacity-25 px-2"><i class="mdi mdi-check me-1"></i>${this.t('downloaded', 'Downloaded!')}</span>`;
                if (data.path && data.fileName) {
                    if (sourceType === 'cqmpack') {
                        html += ` <button class="btn btn-sm btn-outline-cyber ms-1 md-my-0 my-2"
                            onclick="explorerViewInMemory('${data.path}', '${data.fileName}')">
                            <i class="mdi mdi-eye me-1"></i>${this.t('ui_view', 'View')}
                        </button>`;
                        html += ` <button class="btn btn-sm btn-outline-cyber ms-1"
                            onclick="explorerAddToLibrary('${data.path}', '${data.fileName}')">
                            <i class="mdi mdi-book-plus-outline me-1"></i>${this.t('add_to_library', 'Add to Library')}
                        </button>`;
                    } else {
                        html += ` <button class="btn btn-sm btn-outline-cyber ms-1 md-my-0 my-2"
                            onclick="explorerViewInFileBrowser('${data.path}', '${data.fileName}')">
                            <i class="mdi mdi-eye me-1"></i>${this.t('ui_view', 'View')}
                        </button>`;
                    }
                }
                actionsDiv.innerHTML = html;
            } else {
                btn.innerHTML = origHtml;
                btn.disabled = false;
                window.toast?.error(data.message || this.t('download_failed', 'Download failed'));
            }
        } catch (error) {
            console.error('Explorer download error:', error);
            btn.innerHTML = origHtml;
            btn.disabled = false;
            window.toast?.error(this.t('download_failed', 'Download failed') + ': ' + error.message);
        }
    }

    // ========================================
    // Add to Library
    // ========================================

    async showAddToLibraryModal(packPath, packName) {
        this.addToLibPackPath = packPath;
        this.addToLibPackName = packName;

        const select = document.getElementById('explorer-lib-select');
        if (!select) return;

        select.innerHTML = `<option value="">${this.t('loading_libraries', 'Loading libraries...')}</option>`;
        select.disabled = true;

        const modalEl = document.getElementById('explorerAddToLibModal');
        if (!modalEl) return;
        const bsModal = bootstrap.Modal.getOrCreateInstance(modalEl);
        bsModal.show();

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
            select.innerHTML = `<option value="">Error loading libraries</option>`;
        }
    }

    async confirmAddToLibrary() {
        const select = document.getElementById('explorer-lib-select');
        if (!select || !select.value) {
            window.toast?.warning(this.t('select_library_first', 'Select a library first'));
            return;
        }

        const confirmBtn = document.getElementById('explorer-confirm-add-lib');
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
                const modalEl = document.getElementById('explorerAddToLibModal');
                if (modalEl) bootstrap.Modal.getInstance(modalEl)?.hide();
            } else {
                throw new Error(data.error || 'Failed');
            }
        } catch (e) {
            console.error('Add to library error:', e);
            window.toast?.error(e.message);
        } finally {
            confirmBtn.innerHTML = origHtml;
            confirmBtn.disabled = false;
        }
    }

    viewInMemoryExplorer(packPath, packName) {
        const packValue = JSON.stringify({ path: packPath, name: packName });
        localStorage.setItem('cqMemoryPack_global', packValue);
        localStorage.removeItem('cqMemoryLib_global');
        window.location.href = '/memory';
    }

    viewInFileBrowser(filePath, fileName) {
        localStorage.setItem('fileBrowserSelectFile', JSON.stringify({ path: filePath, name: fileName }));
        window.location.href = '/file-browser';
    }

    // ========================================
    // Add Contact
    // ========================================

    async addContact() {
        const btn = document.getElementById('explorerAddContactBtn');
        if (!btn) return;

        const origHtml = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = `<i class="mdi mdi-loading mdi-spin me-1"></i>`;

        try {
            const urlObj = new URL(this.profileUrl);
            const pathParts = urlObj.pathname.split('/').filter(p => p);
            const username = pathParts[pathParts.length - 1];
            const domain = urlObj.hostname;

            // Create contact
            const createResp = await fetch('/api/cq-contact', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    cqContactUrl: this.profileUrl,
                    cqContactDomain: domain,
                    cqContactUsername: username,
                })
            });
            const createData = await createResp.json();

            if (!createData.id) {
                throw new Error(createData.error || 'Failed to create contact');
            }

            // Send friend request
            const frResp = await fetch(`/api/cq-contact/${createData.id}/friend-request`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ friendRequestStatus: 'SENT' })
            });
            const frData = await frResp.json();

            if (frData.success) {
                window.toast?.success(this.t('friend_request_sent', 'Friend request sent!'));
                // Replace button with "View Contact" link
                btn.outerHTML = `
                    <span class="badge bg-success bg-opacity-25 px-2 py-1">
                        <i class="mdi mdi-account-check"></i>
                    </span>`;

                // Reload contacts list
                if (typeof window.loadContacts === 'function') {
                    window.loadContacts();
                }
            } else {
                throw new Error(frData.message || 'Friend request failed');
            }
        } catch (error) {
            console.error('Add contact error:', error);
            window.toast?.error(error.message);
            btn.innerHTML = origHtml;
            btn.disabled = false;
        }
    }
}
