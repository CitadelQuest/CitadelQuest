import { MemoryGraphView } from '../cq-memory/MemoryGraphView';
import MarkdownIt from 'markdown-it';
import * as bootstrap from 'bootstrap';
import { renderSharePreviewBlock } from '../../shared/share-preview';

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
        this.activeGroupSlug = null;

        // Add to Library state
        this.addToLibPackPath = null;
        this.addToLibPackName = null;

        this.md = new MarkdownIt({ html: true, linkify: true, typographer: true });
        this._originalTitle = document.title;

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
        const sinceParam = urlParams.get('since');
        this.sinceTimestamp = sinceParam || null;
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
        this.activeGroupSlug = null;
        const shareMatch = url.match(/^(https:\/\/[^/]+\/[^/]+)\/share\/([^/]+)\/?$/);
        if (shareMatch) {
            url = shareMatch[1];
            this.highlightShareUrl = shareMatch[2];
        }

        // Detect group URL pattern: https://domain/username/{groupSlug}
        // Strip /{groupSlug} to get profile URL, remember slug for group navigation
        if (!this.highlightShareUrl) {
            const groupMatch = url.match(/^(https:\/\/[^/]+\/[^/]+)\/([^/]+)\/?$/);
            if (groupMatch) {
                // Make sure it's not a known sub-path like /json, /photo, /background, /shares
                const knownPaths = ['json', 'photo', 'background', 'shares', 'api'];
                if (!knownPaths.includes(groupMatch[2])) {
                    url = groupMatch[1];
                    this.activeGroupSlug = groupMatch[2];
                }
            }
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

            // Notify sidebar to highlight active item (after render completes)
            if (window.explorerSidebar) {
                window.explorerSidebar.highlightActiveItem(url);
                // Also try canonical profile_url from response in case it differs
                if (data.profile_url && data.profile_url !== url) {
                    window.explorerSidebar.highlightActiveItem(data.profile_url);
                }
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

        // Update page title with profile info
        if (p.username) {
            const baseTitle = document.title.replace(/^.*?\s-\s/, '');
            document.title = `${p.username} / ${p.domain || ''} - ${baseTitle}`;
        }

        // Remove any previous photo modal from body (profile switch)
        const oldPhotoModal = document.getElementById('explorerPhotoModal');
        if (oldPhotoModal) oldPhotoModal.remove();

        // Build photo HTML
        let photoHtml = '';
        let photoModalHtml = '';
        if (p.photo_url) {
            // Local contact photo proxy doesn't need the explorer proxy wrapper
            const proxyPhotoUrl = p.photo_url.startsWith('/api/')
                ? p.photo_url
                : `/api/citadel-explorer/photo?url=${encodeURIComponent(p.photo_url)}`;
            // Full-size photo URL for the modal (append ?full=1)
            const fullPhotoUrl = proxyPhotoUrl + (proxyPhotoUrl.includes('?') ? '&full=1' : '?full=1');
            photoHtml = `
                <a href="#" class="explorer-photo-trigger" style="cursor: pointer;">
                <img src="${proxyPhotoUrl}" alt="${p.username}"
                     class="rounded-circle border border-2 border-success"
                     style="width: 96px; height: 96px; object-fit: cover;"
                     onerror="this.style.display='none'; this.nextElementSibling?.classList.remove('d-none');">
                <div class="rounded-circle border border-2 border-secondary d-flex align-items-center justify-content-center d-none"
                     style="width: 96px; height: 96px; background: rgba(255,255,255,0.05);">
                    <i class="mdi mdi-account text-cyber opacity-75" style="font-size: 40px;"></i>
                </div>
                </a>`;
            photoModalHtml = `
                <div class="modal fade" id="explorerPhotoModal" tabindex="-1" aria-hidden="true">
                    <div class="modal-dialog modal-dialog-centered modal-lg">
                        <div class="modal-content glass-panel border-0" style="background: transparent;">
                            <div class="modal-header border-0 pb-0">
                                <h5 class="modal-title text-cyber"><i class="mdi mdi-account-circle me-2"></i>${p.username || ''}</h5>
                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body text-center p-4">
                                <img src="${fullPhotoUrl}" alt="${p.username}" class="rounded-circle border border-2 border-success img-fluid">
                            </div>
                        </div>
                    </div>
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
                        <div class="rounded-circle d-flex align-items-center justify-content-center"
                             style="width: 28px; height: 28px; background: ${color}21;">
                            <i class="mdi mdi-ghost" style="color: ${color}; font-size: 14px;"></i>
                        </div>
                        <div class="d-none d-sm-inline-block ms-2">
                            <div class="small fw-bold text-light" style="line-height: 1.2;">
                                ${spirit.name} ${star}
                            </div>
                            <div class="text-light opacity-75" style="font-size: 0.65rem; line-height: 1;">Level ${spirit.level || 1} | ${spirit.experience || 0} XP</div>
                        </div>
                    </div>`;
            }).join('');
        }

        // Build contact action button
        let contactActionHtml = '';
        const cStatus = p.contact_status;
        if (p.is_contact && p.cq_contact_id && cStatus === 'ACCEPTED') {
            contactActionHtml = `
                <a href="#" class="badge bg-success bg-opacity-25 px-2 py-1 text-decoration-none explorer-contact-status-toggle" 
                   data-contact-id="${p.cq_contact_id}" title="${this.t('delete_contact', 'Remove contact')}" style="cursor: pointer;">
                    <i class="mdi mdi-account-check"></i>
                </a>
                <a href="#" class="d-none align-items-center text-danger explorer-contact-delete-btn" 
                   data-delete-id="${p.cq_contact_id}" title="${this.t('delete_contact', 'Remove contact')}" style="padding: 4px;">
                    <i class="mdi mdi-account-minus" style="font-size: 1rem;"></i>
                </a>`;
        } else if (p.is_contact && cStatus === 'SENT') {
            contactActionHtml = `
                <span class="badge bg-warning bg-opacity-25 px-2 py-1" title="${this.t('friend_request_sent', 'Friend request sent')}">
                    <i class="mdi mdi-account-clock text-warning"></i>
                </span>`;
        } else if (p.is_contact && cStatus === 'RECEIVED') {
            contactActionHtml = `
                <span class="d-flex align-items-center gap-1">
                    <span class="badge bg-info bg-opacity-25 px-2 py-1" title="${this.t('friend_request_received')}">
                        <i class="mdi mdi-account-arrow-left text-info"></i>
                    </span>
                    <button class="btn btn-sm btn-outline-success border-0 px-1 explorer-fr-accept-btn"
                            data-contact-id="${p.cq_contact_id}" title="${this.t('accept_friend_request')}">
                        <i class="mdi mdi-check"></i>
                    </button>
                    <button class="btn btn-sm btn-outline-danger border-0 px-1 explorer-fr-reject-btn"
                            data-contact-id="${p.cq_contact_id}" title="${this.t('reject_friend_request')}">
                        <i class="mdi mdi-close"></i>
                    </button>
                </span>`;
        } else if (p.is_contact && cStatus === 'REJECTED') {
            contactActionHtml = `
                <span class="badge bg-danger bg-opacity-25 px-2 py-1" title="${this.t('contact_rejected', 'Contact removed')}">
                    <i class="mdi mdi-account-off text-danger"></i>
                </span>`;
        } else if (p.is_contact) {
            contactActionHtml = `
                <span class="badge bg-success bg-opacity-25 px-2 py-1">
                    <i class="mdi mdi-account-check"></i>
                </span>`;
        } else {
            contactActionHtml = `
                <button class="btn btn-sm btn-cyber" id="explorerAddContactBtn">
                    <i class="mdi mdi-account-plus me-1"></i><span class="d-none d-sm-inline-block">${this.t('add_contact', 'Add Contact')}</span>
                </button>`;
        }

        // Build follow action button
        let followActionHtml = '';
        if (p.cq_contact_id) {
            const isFollowing = p.is_following || false;
            if (isFollowing) {
                // Toggle pattern: click badge → reveal Unfollow button (auto-hides after 3s)
                followActionHtml = `
                    <a href="#" class="badge bg-warning bg-opacity-25 px-2 py-1 text-decoration-none explorer-follow-status-toggle" 
                       style="cursor: pointer;" title="${this.t('unfollow', 'Unfollow')}">
                        <i class="mdi mdi-rss text-warning"></i>
                    </a>
                    <button class="btn btn-sm btn-outline-danger d-none explorer-unfollow-btn"
                            data-cq-contact-id="${p.cq_contact_id}">
                        <i class="mdi mdi-rss-off me-1"></i><span class="d-none d-sm-inline-block">${this.t('unfollow', 'Unfollow')}</span>
                    </button>`;
            } else {
                followActionHtml = `
                    <button class="btn btn-sm btn-outline-warning" id="explorerFollowBtn"
                            data-cq-contact-id="${p.cq_contact_id}"
                            data-cq-contact-url="${p.profile_url || ''}"
                            data-cq-contact-domain="${p.domain || ''}"
                            data-cq-contact-username="${p.username || ''}">
                        <i class="mdi mdi-rss me-1"></i><span class="d-none d-sm-inline-block">${this.t('follow', 'Follow')}</span>
                    </button>`;
            }
        }

        // Follower count badge
        let followerCountHtml = '';
        if (p.follower_count > 0) {
            followerCountHtml = `<small class="text-light opacity-50"><i class="mdi mdi-account-voice me-1"></i>${p.follower_count}</small>`;
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

        // Background image support
        let bgClass = '';
        let bgStyle = '';
        if (p.background_url) {
            const proxyBgUrl = p.background_url.startsWith('/') 
                ? p.background_url 
                : `/api/citadel-explorer/photo?url=${encodeURIComponent(p.background_url)}`;
            bgClass = `profile-header-bg${p.bg_overlay === false ? ' no-overlay' : ''}`;
            bgStyle = `--header-bg-image: url('${proxyBgUrl}');`;
        }

        // Main profile card
        let html = `
            <div class="${bgClass ? bgClass + ' glass-panel-glow' : 'glass-panel rounded p-4 glass-panel-glow'}"${bgStyle ? ` style="${bgStyle}"` : ''}>
                ${bgClass ? '<div class="p-4 m-4 glass-panel">' : ''}
                <div class="d-block position-absolute top-0 end-0 m-1" style="z-index: 3;">
                    <button class="btn btn-sm btn-outline-secondary" id="explorerCloseBtn" title="Close"
                        style="padding: 0.1rem 0.4rem !important; opacity:0.6;">
                        <i class="mdi mdi-close"></i>
                    </button>
                </div>

                <div class="d-flex align-items-start">
                    <div class="me-3 flex-shrink-0">${photoHtml}</div>
                    <div class="flex-grow-1 d-none d-md-block">
                        <h3 class="h4 mb-1 text-cyber">${p.username || ''}</h3>
                        <small class="text-light opacity-75">
                            <i class="mdi mdi-web me-1"></i>
                            <a href="${profileLink}" target="_blank" class="text-light opacity-75 text-decoration-none">
                                ${p.domain || ''}
                            </a>
                        </small>
                        ${bioHtml}
                    </div>
                    <div class="d-flex flex-column align-items-end gap-2 ms-auto flex-shrink-0 mt-3">
                        ${spiritsHtml ? `<div class="d-flex gap-2 flex-sm-column flex-row">${spiritsHtml}</div>` : ''}
                        ${contactActionHtml}
                        ${followActionHtml}
                        ${followerCountHtml}
                    </div>
                </div>
                <div class="d-md-none mt-3">
                    <h3 class="h4 mb-1 text-cyber">${p.username || ''}</h3>
                    <small class="text-light opacity-75">
                        <i class="mdi mdi-web me-1"></i>
                        <a href="${profileLink}" target="_blank" class="text-light opacity-75 text-decoration-none">
                            ${p.domain || ''}
                        </a>
                    </small>
                    ${bioHtml}
                </div>`;

        // Close header wrapper when background is present
        if (bgClass) {
            html += `</div></div>`;
        }

        // Shared items section (single container for both public and contact-scoped shares)
        // When is_contact, contact shares are a superset of public shares (scope 0+1)
        const hasPublicShares = (p.shares || []).length > 0;
        const hasShareGroups = (p.share_groups || []).length > 0;
        const isAcceptedContact = p.is_contact && p.contact_id && p.contact_status === 'ACCEPTED';
        const needsSharesSection = hasPublicShares || hasShareGroups || isAcceptedContact;
        if (needsSharesSection) {
            // Navigation panel for groups (matching public profile style)
            const navGroups = (p.share_groups || []).filter(g => g.show_in_nav && (g.items || []).length > 0);
            let navPanelHtml = '';
            if (navGroups.length > 0) {
                // Default to first group when no specific slug (profile homepage)
                if (!this.activeGroupSlug) {
                    this.activeGroupSlug = navGroups[0].url_slug || navGroups[0].id;
                }
                let badgesHtml = '';
                navGroups.forEach(group => {
                    const slug = group.url_slug || group.id;
                    const isActive = this.activeGroupSlug && slug === this.activeGroupSlug;
                    const iconColor = group.icon_color || '#95ec86';
                    const groupItems = group.items || [];
                    const navHasNew = this.sinceTimestamp && groupItems.some(i => (i.updated_at || i.share_updated_at) && (i.updated_at || i.share_updated_at) > this.sinceTimestamp);
                    const navNewClass = navHasNew ? 'border-start border-end border-3 border-top-0 border-bottom-0 border-warning' : '';
                    badgesHtml += `
                        <a href="#" class="text-decoration-none explorer-group-nav mb-2" data-group-slug="${slug}">
                            <span class="glass-panel ${isActive ? 'bg-success bg-opacity-25' : ''} ${navNewClass} py-2 px-3" style="font-size: 0.85rem;">
                                <i class="mdi ${group.mdi_icon || 'mdi-folder'} me-1" style="color: ${iconColor};"></i>
                                <span class="${isActive ? 'text-cyber' : 'text-light opacity-75'}">${group.title || ''}</span>
                                ${navHasNew ? '<i class="mdi mdi-bell-ring text-warning ms-1" style="font-size: 0.7rem;"></i>' : ''}
                            </span>
                        </a>`;
                });
                navPanelHtml = `
                    <div class="mb-2 px-2">
                        <div class="d-flex align-items-center flex-wrap gap-2">${badgesHtml}</div>
                    </div>`;
            }

            if (bgClass) {
                // Nav + shares sit below the bg header card
                html += `
                    <div class="mt-3">
                        ${navPanelHtml}
                        <div id="explorerSharesContainer">
                            <div class="text-center py-3">
                                <i class="mdi mdi-loading mdi-spin text-cyber"></i>
                            </div>
                        </div>
                    </div>`;
            } else {
                html += `
                    <hr class="border-secondary border-opacity-25 my-4">
                    ${navPanelHtml}
                    <div id="explorerSharesContainer">
                        <div class="text-center py-3">
                            <i class="mdi mdi-loading mdi-spin text-cyber"></i>
                        </div>
                    </div>`;
            }
        }

        // Close outer div (only for non-bg case; bg case already closed above)
        if (!bgClass) {
            html += `</div>`;
        }

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

        // Append photo modal if present
        html += photoModalHtml;

        this.previewContainer.innerHTML = html;

        // Move modals to document.body so they're not trapped inside the preview container (z-index)
        const libModal = document.getElementById('explorerAddToLibModal');
        if (libModal) {
            document.body.appendChild(libModal);
        }
        const photoModal = document.getElementById('explorerPhotoModal');
        if (photoModal) {
            document.body.appendChild(photoModal);
        }

        // Bind photo click to open fullscreen modal
        document.querySelectorAll('.explorer-photo-trigger').forEach(trigger => {
            trigger.addEventListener('click', (e) => {
                e.preventDefault();
                const modal = document.getElementById('explorerPhotoModal');
                if (modal) {
                    const bsModal = new bootstrap.Modal(modal);
                    bsModal.show();
                }
            });
        });

        // Bind close button
        document.getElementById('explorerCloseBtn')?.addEventListener('click', () => {
            this.destroyGraphs();
            this.previewContainer.classList.add('d-none');
            this.previewContainer.innerHTML = '';
            this.profile = null;
            this.profileUrl = null;
            this.urlInput.value = '';
            localStorage.removeItem('citadelExplorerUrl');
            this.toggleUrlHelp();
            // Clear sidebar highlights
            if (window.explorerSidebar) {
                window.explorerSidebar.highlightActiveItem(null);
            }
            // Remove orphaned modals from body
            const orphanModal = document.getElementById('explorerAddToLibModal');
            if (orphanModal) orphanModal.remove();
            const orphanPhotoModal = document.getElementById('explorerPhotoModal');
            if (orphanPhotoModal) orphanPhotoModal.remove();
            // Restore original page title
            document.title = this._originalTitle;
        });

        // Bind bio show more/less toggle (both desktop and mobile instances)
        document.querySelectorAll('.explorer-bio-toggle').forEach(link => {
            link.addEventListener('click', (e) => {
                e.preventDefault();
                const container = e.target.closest('.explorer-bio-md');
                if (!container) return;
                const short = container.querySelector('.explorer-bio-short');
                const full = container.querySelector('.explorer-bio-full');
                const expanded = !full.classList.contains('d-none');
                short.classList.toggle('d-none', !expanded);
                full.classList.toggle('d-none', expanded);
                e.target.textContent = expanded ? this.t('show_more', 'show more') : this.t('show_less', 'show less');
            });
        });

        // Bind add contact button
        document.getElementById('explorerAddContactBtn')?.addEventListener('click', () => this.addContact());

        // Bind explorer profile contact status toggle → reveal delete button
        document.querySelector('.explorer-contact-status-toggle')?.addEventListener('click', (e) => {
            e.preventDefault();
            const toggle = e.currentTarget;
            toggle.classList.add('d-none');
            const deleteBtn = toggle.nextElementSibling;
            if (deleteBtn && deleteBtn.classList.contains('explorer-contact-delete-btn')) {
                deleteBtn.classList.remove('d-none');
                deleteBtn.classList.add('d-inline-flex');
                // Auto-hide after 3 seconds
                setTimeout(() => {
                    if (deleteBtn.classList.contains('d-inline-flex')) {
                        deleteBtn.classList.remove('d-inline-flex');
                        deleteBtn.classList.add('d-none');
                        toggle.classList.remove('d-none');
                    }
                }, 3000);
            }
        });

        // Bind explorer profile delete contact button → open modal
        document.querySelector('.explorer-contact-delete-btn')?.addEventListener('click', (e) => {
            e.preventDefault();
            const contactId = e.currentTarget.dataset.deleteId;
            const confirmBtn = document.getElementById('confirmDeleteContact');
            if (confirmBtn) {
                confirmBtn.dataset.contactId = contactId;
                const modal = document.getElementById('deleteContactModal');
                if (modal) {
                    const bsModal = new bootstrap.Modal(modal);
                    bsModal.show();
                }
            }
        });

        // Bind friend request accept/reject buttons on profile view
        document.querySelector('.explorer-fr-accept-btn')?.addEventListener('click', (e) => {
            e.preventDefault();
            this.handleExplorerFriendRequest(e.currentTarget.dataset.contactId, 'ACCEPTED');
        });
        document.querySelector('.explorer-fr-reject-btn')?.addEventListener('click', (e) => {
            e.preventDefault();
            this.handleExplorerFriendRequest(e.currentTarget.dataset.contactId, 'REJECTED');
        });

        // Bind follow button (for non-following state)
        document.getElementById('explorerFollowBtn')?.addEventListener('click', () => this.toggleFollow());

        // Bind unfollow toggle + confirm (for already-following state)
        this.bindExplorerUnfollowHandlers();

        // Bind add to library confirm
        document.getElementById('explorer-confirm-add-lib')?.addEventListener('click', () => this.confirmAddToLibrary());

        // Load and render shares — contact endpoint is superset of public shares
        if (needsSharesSection) {
            await this.loadAndRenderShares();
        }

        // Bind group navigation badges
        document.querySelectorAll('.explorer-group-nav').forEach(el => {
            el.addEventListener('click', (e) => {
                e.preventDefault();
                const slug = el.dataset.groupSlug;
                this.switchGroup(slug);
            });
        });

        // Scroll to content navigation if a specific group was requested via URL
        if (this.activeGroupSlug) {
            const navPanel = document.querySelector('.explorer-group-nav');
            if (navPanel) {
                setTimeout(() => navPanel.closest('.mb-3')?.scrollIntoView({ behavior: 'smooth', block: 'start' }), 300);
            }
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

        // Find the share from all available data: ungrouped shares + share group items
        let share = (p.shares || []).find(s => s.share_url === slug);
        if (!share) {
            for (const group of (p.share_groups || [])) {
                share = (group.items || []).find(s => s.share_url === slug);
                if (share) break;
            }
        }

        // Fallback: fetch share metadata directly from remote (handles toggled-off shares)
        if (!share) {
            try {
                const shareMetaUrl = `${profileLink}/share/${slug}`;
                let metaResp;
                if (p.is_contact && p.contact_id && p.contact_status === 'ACCEPTED') {
                    metaResp = await fetch(`/api/cq-contact/${p.contact_id}/share-meta`, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ share_url: slug })
                    });
                } else {
                    metaResp = await fetch('/api/citadel-explorer/fetch-share', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ url: shareMetaUrl })
                    });
                }
                const metaData = await metaResp.json();
                if (metaData.success && metaData.share) {
                    share = metaData.share;
                }
            } catch (e) {
                console.warn('Failed to fetch share metadata directly:', e);
            }
        }

        if (!share) {
            this.showError(this.t('share_not_found', 'Shared item not found'));
            return;
        }

        // Check download status for this single share
        this.downloadStatus = {};
        try {
            let dlUrl, dlBody;
            if (p.is_contact && p.contact_id && p.contact_status === 'ACCEPTED') {
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
                        <small class="text-light opacity-75 ms-2">
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
        if (container) {
            container.innerHTML = this.renderShareItem(share, { showContent: true });
            this.initGraphPreviews();
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

        let shareGroups = [];

        if (p.is_contact && p.contact_id && p.contact_status === 'ACCEPTED') {
            // Fetch from contact API — returns public + CQ Contact scoped shares
            try {
                const response = await fetch(`/api/cq-contact/${p.contact_id}/shares`);
                const data = await response.json();
                shares = (data.success && data.shares) ? data.shares : [];
                shareGroups = (data.success && data.share_groups) ? data.share_groups : [];
                // Store show_share_content from federation response
                if (data.show_share_content !== undefined) {
                    this.profile.show_share_content = data.show_share_content;
                }
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
            shareGroups = p.share_groups || [];
        }

        if (shares.length === 0 && shareGroups.length === 0) {
            container.innerHTML = `
                <div class="text-center py-3 text-muted small">
                    <i class="mdi mdi-share-off me-1"></i>${this.t('no_items', 'No shared items')}
                </div>`;
            return;
        }

        // Collect all shares (ungrouped + group items) for download status check
        const allSharesForDl = [...shares];
        shareGroups.forEach(g => (g.items || []).forEach(item => allSharesForDl.push(item)));

        // Check download status — pick endpoint based on is_contact
        this.downloadStatus = {};
        try {
            let dlUrl, dlBody;
            if (p.is_contact && p.contact_id && p.contact_status === 'ACCEPTED') {
                dlUrl = `/api/cq-contact/${p.contact_id}/check-downloads`;
                dlBody = JSON.stringify({ shares: allSharesForDl });
            } else {
                dlUrl = '/api/citadel-explorer/check-downloads';
                dlBody = JSON.stringify({ profile_url: p.profile_url || this.profileUrl, shares: allSharesForDl });
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

        // Cache for switchGroup re-rendering
        this._lastShareGroups = shareGroups;
        this._lastUngroupedShares = shares;

        this.renderShareGroups(shareGroups, shares, container);
    }

    /**
     * Render share groups followed by ungrouped shares into the container.
     * When activeGroupSlug is set, show only that group's content.
     */
    renderShareGroups(groups, ungroupedShares, container) {
        let html = '';

        // Determine which groups to render
        let groupsToRender = groups;
        if (this.activeGroupSlug) {
            const activeGroup = groups.find(g => (g.url_slug || g.id) === this.activeGroupSlug);
            groupsToRender = activeGroup ? [activeGroup] : groups;
        }

        // Render group(s) as card sections
        groupsToRender.forEach(group => {
            const items = group.items || [];
            if (items.length === 0) return;

            const groupHasNew = this.sinceTimestamp && items.some(i => (i.updated_at || i.share_updated_at) && (i.updated_at || i.share_updated_at) > this.sinceTimestamp);
            const iconColor = group.icon_color || '#95ec86';
            html += `
            <div class="card glass-panel mb-3" id="share-group-${group.id}">
                <div class="card-header bg-transparent border-success border-1 border-bottom p-3">
                    <div class="mb-0">
                        <i class="mdi ${group.mdi_icon || 'mdi-folder'} me-2" style="color: ${iconColor};"></i>
                        <span class="text-cyber">${group.title || ''}</span>
                        ${groupHasNew ? '<span class="badge bg-warning bg-opacity-25 text-warning ms-2 small"><i class="mdi mdi-bell-ring"></i></span>' : ''}
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="list-group list-group-flush bg-transparent">`;

            items.forEach(item => {
                html += this.renderShareItem(item, {
                    showHeader: item.show_header != 0,
                    displayStyle: item.effective_display_style ?? item.share_display_style,
                    descriptionDisplayStyle: item.effective_description_display_style ?? item.share_description_display_style,
                    title: item.share_title || item.title || '',
                    showContent: true, // Profile Content groups always show previews
                });
            });

            html += `</div></div></div>`;
        });

        // Render ungrouped shares (always visible below groups, matching public profile)
        if (ungroupedShares.length > 0) {
            const sharesHasNew = this.sinceTimestamp && ungroupedShares.some(s => (s.updated_at || s.share_updated_at) && (s.updated_at || s.share_updated_at) > this.sinceTimestamp);
            html += `
            <div class="card glass-panel">
                <div class="card-header bg-transparent border-success border-1 border-bottom p-3">
                    <div class="mb-0 d-flex align-items-center flex-wrap gap-2">
                        <span>
                            <i class="mdi mdi-share-variant me-2 text-success opacity-50"></i>
                            <span class="text-cyber">${this.t('shared_items', 'Shared Items')}</span>
                            ${sharesHasNew ? '<span class="badge bg-warning bg-opacity-25 text-warning ms-2 small"><i class="mdi mdi-bell-ring me-1"></i>New</span>' : ''}
                        </span>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="list-group list-group-flush bg-transparent">`;

            ungroupedShares.forEach(share => {
                html += this.renderShareItem(share);
            });

            html += `</div></div></div>`;
        }

        container.innerHTML = html;
        this.initGraphPreviews();
    }

    /**
     * Switch to a different group in the explorer (in-place, no reload).
     * Toggles activeGroupSlug and re-renders shares + nav badge states.
     */
    switchGroup(slug) {
        // Toggle: clicking the same group deselects it (show all)
        this.activeGroupSlug = (this.activeGroupSlug === slug) ? null : slug;

        // Update nav badge active states
        document.querySelectorAll('.explorer-group-nav').forEach(el => {
            const badgeSlug = el.dataset.groupSlug;
            const span = el.querySelector('.glass-panel');
            const label = el.querySelector('span:last-child');
            if (!span) return;
            if (badgeSlug === this.activeGroupSlug) {
                span.classList.add('bg-success', 'bg-opacity-25');
                if (label) { label.classList.remove('opacity-75'); label.classList.add('text-cyber'); }
            } else {
                span.classList.remove('bg-success', 'bg-opacity-25');
                if (label) { label.classList.add('opacity-75'); label.classList.remove('text-cyber'); }
            }
        });

        // Re-render shares content
        const container = document.getElementById('explorerSharesContainer');
        if (container && this._lastShareGroups !== undefined) {
            this.renderShareGroups(this._lastShareGroups, this._lastUngroupedShares, container);
        }

        // Update URL in input to reflect the active group
        if (this.urlInput && this.profileUrl) {
            this.urlInput.value = this.activeGroupSlug
                ? this.profileUrl + '/' + this.activeGroupSlug
                : this.profileUrl;
            localStorage.setItem('citadelExplorerUrl', this.urlInput.value);
        }
    }

    /**
     * Render a single share item (used by both groups and ungrouped shares).
     * @param {Object} share - Share data
     * @param {Object} opts - Options: showHeader, displayStyle, descriptionDisplayStyle, title
     */
    renderShareItem(share, opts = {}) {
        const showHeader = opts.showHeader !== false;
        const title = opts.title || share.title || '';

        const isCqmpack = share.source_type === 'cqmpack';
        const isPdf = share.preview_type === 'pdf';
        const icon = isCqmpack ? 'mdi-graph' : (isPdf ? 'mdi-file-pdf-box' : 'mdi-file');
        const iconColor = isCqmpack ? 'text-info' : (isPdf ? 'text-danger' : 'text-warning');
        const typeLabel = isCqmpack ? this.t('memory_pack', 'Memory Pack') : this.t('file', 'File');

        const dl = this.downloadStatus[share.share_url];
        const isDownloaded = dl && dl.downloaded;

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
            const safeTitle = (title).replace(/'/g, "\\'");
            actionsHtml = `<button class="btn btn-sm btn-cyber" data-share-url="${share.share_url}"
                onclick="explorerDownload('${share.share_url}', '${share.source_type}', '${safeTitle}')">
                <i class="mdi mdi-download me-1"></i>${this.t('download_to_citadel', 'Download to Citadel')}
            </button>`;
        }

        // Check if this share is new since last visit (from Feed)
        // Group items have share_updated_at, ungrouped shares have updated_at
        const shareUpdatedAt = share.updated_at || share.share_updated_at;
        const isNewSinceVisit = this.sinceTimestamp && shareUpdatedAt && shareUpdatedAt > this.sinceTimestamp;
        const newHighlight = isNewSinceVisit ? 'bg-warning bg-opacity-10 border-start border-3 border-warning' : '';

        let html = `<div class="list-group-item bg-transparent border-0 px-4 py-3 ${newHighlight}" data-share-url="${share.share_url}">`;

        if (showHeader) {
            html += `
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <div>
                        <i class="mdi ${icon} ${iconColor} me-2"></i>
                        <span class="text-light fw-bold">${title}</span>
                        ${isNewSinceVisit ? '<span class="badge bg-warning bg-opacity-25 text-warning ms-2 small"><i class="mdi mdi-bell-ring"></i></span>' : ''}
                        <span class="badge bg-secondary bg-opacity-25 ms-2 small">${typeLabel}</span>
                        <small class="text-light opacity-75"><i class="mdi mdi-eye me-1 ms-2"></i>${share.views || 0} ${this.t('views', 'views')}</small>
                        <button class="btn btn-sm btn-outline-primary border-0" title="${this.t('copy_link', 'Copy link')}"
                            onclick="navigator.clipboard.writeText('${(this.profile.profile_url || this.profileUrl).replace(/'/g, "\\'")}/share/${share.share_url}').then(() => window.toast?.success('${this.t('link_copied', 'Link copied!')}'))">
                            <i class="mdi mdi-link-variant"></i>
                        </button>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <div id="explorer-actions-${share.share_url}" class="text-end">${actionsHtml}</div>
                    </div>
                </div>`;
        }

        // Build preview options with overrides from group item config
        const previewOpts = {
            showContent: opts.showContent !== undefined ? opts.showContent : this.profile.show_share_content,
            md: this.md,
            t: (k, f) => this.t(k, f)
        };
        if (opts.displayStyle !== undefined && opts.displayStyle !== null) {
            previewOpts.displayStyleOverride = opts.displayStyle;
        }
        if (opts.descriptionDisplayStyle !== undefined && opts.descriptionDisplayStyle !== null) {
            previewOpts.descriptionDisplayStyleOverride = opts.descriptionDisplayStyle;
        }

        html += renderSharePreviewBlock(share, previewOpts);
        html += `</div>`;

        return html;
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

            if (p.is_contact && p.contact_id && p.contact_status === 'ACCEPTED') {
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

                // Reload contacts list
                if (typeof window.loadContacts === 'function') {
                    window.loadContacts();
                }

                // Re-explore to refresh profile view with correct status badge
                this.explore();
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

    // ========================================
    // Friend Request Accept / Reject (profile view)
    // ========================================

    async handleExplorerFriendRequest(contactId, status) {
        try {
            const resp = await fetch(`/api/cq-contact/${contactId}/friend-request`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ friendRequestStatus: status }),
            });
            const data = await resp.json();
            if (data.success) {
                window.toast?.success(this.t('friend_request_updated'));
                // Reload sidebar contacts
                if (window.explorerSidebar) {
                    await window.explorerSidebar.loadContacts();
                    window.explorerSidebar.renderContactsSidebar();
                }
                // Re-explore to refresh profile with new status/scope
                this.explore();
            } else {
                window.toast?.error(data.message || data.error || 'Error');
            }
        } catch (e) {
            console.error('CitadelExplorer: Friend request error', e);
            window.toast?.error(e.message || 'Error');
        }
    }

    // ========================================
    // Follow / Unfollow
    // ========================================

    bindExplorerUnfollowHandlers() {
        // Bind follow status toggle → reveal Unfollow button
        document.querySelector('.explorer-follow-status-toggle')?.addEventListener('click', (e) => {
            e.preventDefault();
            const toggle = e.currentTarget;
            toggle.classList.add('d-none');
            const unfollowBtn = toggle.nextElementSibling;
            if (unfollowBtn && unfollowBtn.classList.contains('explorer-unfollow-btn')) {
                unfollowBtn.classList.remove('d-none');
                this._unfollowTimer = setTimeout(() => {
                    if (!unfollowBtn.classList.contains('d-none')) {
                        unfollowBtn.classList.add('d-none');
                        toggle.classList.remove('d-none');
                    }
                }, 3000);
            }
        });

        // Bind Unfollow confirm button
        document.querySelector('.explorer-unfollow-btn')?.addEventListener('click', async (e) => {
            const btn = e.currentTarget;
            const contactId = btn.dataset.cqContactId;
            if (this._unfollowTimer) clearTimeout(this._unfollowTimer);
            btn.disabled = true;
            btn.innerHTML = `<i class="mdi mdi-loading mdi-spin"></i>`;
            try {
                const resp = await fetch('/api/follow/unfollow', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ cq_contact_id: contactId })
                });
                const data = await resp.json();
                if (data.success) {
                    window.toast?.success(this.t('unfollow_success', 'Unfollowed'));
                    const wrapper = btn.parentElement;
                    if (wrapper) {
                        const p = this.profile;
                        const toggle = wrapper.querySelector('.explorer-follow-status-toggle');
                        if (toggle) toggle.remove();
                        // Replace via outerHTML to discard old event handlers
                        const followBtnHtml = `<button class="btn btn-sm btn-outline-warning" id="explorerFollowBtn"
                            data-cq-contact-id="${contactId}"
                            data-cq-contact-url="${p?.profile_url || ''}"
                            data-cq-contact-domain="${p?.domain || ''}"
                            data-cq-contact-username="${p?.username || ''}">
                            <i class="mdi mdi-rss me-1"></i><span class="d-none d-sm-inline-block">${this.t('follow', 'Follow')}</span>
                        </button>`;
                        btn.outerHTML = followBtnHtml;
                        // Bind the new element
                        document.getElementById('explorerFollowBtn')?.addEventListener('click', () => this.toggleFollow());
                    }
                    if (window.explorerSidebar) {
                        await window.explorerSidebar.loadFollowingList();
                        window.explorerSidebar.renderFollowingSidebar();
                    }
                } else {
                    throw new Error(data.error || 'Failed');
                }
            } catch (error) {
                console.error('Unfollow error:', error);
                window.toast?.error(error.message);
                btn.disabled = false;
                btn.innerHTML = `<i class="mdi mdi-rss-off me-1"></i><span class="d-none d-sm-inline-block">${this.t('unfollow', 'Unfollow')}</span>`;
            }
        });
    }

    async toggleFollow() {
        const btn = document.getElementById('explorerFollowBtn');
        if (!btn) return;

        const origHtml = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = `<i class="mdi mdi-loading mdi-spin me-1"></i>`;

        try {
            const resp = await fetch('/api/follow/follow', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    cq_contact_id: btn.dataset.cqContactId,
                    cq_contact_url: btn.dataset.cqContactUrl,
                    cq_contact_domain: btn.dataset.cqContactDomain,
                    cq_contact_username: btn.dataset.cqContactUsername,
                })
            });
            const data = await resp.json();

            if (data.success) {
                window.toast?.success(this.t('follow_success', 'Following!'));
                // Replace Follow button with status badge + unfollow toggle
                const contactId = btn.dataset.cqContactId;
                const wrapper = btn.parentElement;
                if (wrapper) {
                    const toggleHtml = `
                        <a href="#" class="badge bg-warning bg-opacity-25 px-2 py-1 text-decoration-none explorer-follow-status-toggle" 
                           style="cursor: pointer;" title="${this.t('unfollow', 'Unfollow')}">
                            <i class="mdi mdi-rss text-warning"></i>
                        </a>
                        <button class="btn btn-sm btn-outline-danger d-none explorer-unfollow-btn"
                                data-cq-contact-id="${contactId}">
                            <i class="mdi mdi-rss-off me-1"></i><span class="d-none d-sm-inline-block">${this.t('unfollow', 'Unfollow')}</span>
                        </button>`;
                    btn.outerHTML = toggleHtml;
                    // Re-bind the new toggle + unfollow handlers
                    this.bindExplorerUnfollowHandlers();
                }
                // Refresh sidebar
                if (window.explorerSidebar) {
                    await window.explorerSidebar.loadFollowingList();
                    window.explorerSidebar.renderFollowingSidebar();
                }
            } else {
                throw new Error(data.error || 'Failed');
            }
        } catch (error) {
            console.error('Follow error:', error);
            window.toast?.error(error.message);
            btn.innerHTML = origHtml;
            btn.disabled = false;
        }
    }
}
