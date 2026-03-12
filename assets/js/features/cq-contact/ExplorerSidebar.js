/**
 * ExplorerSidebar
 * 
 * Manages the right sidebar in CQ Explorer: Following, Followers, and CQ Contacts lists.
 * Handles feed update polling, "New" content highlighting, and visited marking.
 * 
 * @see /docs/features/CQ-FOLLOW.md
 */
export class ExplorerSidebar {
    constructor(config = {}) {
        this.trans = config.translations || {};
        this.follows = [];
        this.followers = [];
        this.contacts = [];
        this.feedItems = [];

        // Sidebar DOM containers
        this.followingListEl = document.getElementById('sidebarFollowingList');
        this.followersListEl = document.getElementById('sidebarFollowersList');
        this.contactsListEl = document.getElementById('sidebarContactsList');
        this.followingCountEl = document.getElementById('sidebarFollowingCount');
        this.followersCountEl = document.getElementById('sidebarFollowersCount');
        this.contactsCountEl = document.getElementById('sidebarContactsCount');

        if (this.followingListEl || this.followersListEl || this.contactsListEl) {
            this.initCollapse();
            this.init();
        }
    }

    t(key, fallback) {
        return this.trans[key] || fallback || key;
    }

    // ========================================
    // Collapsible Sections
    // ========================================

    initCollapse() {
        const stored = this.getCollapseState();

        document.querySelectorAll('[data-sidebar-toggle]').forEach(header => {
            const key = header.dataset.sidebarToggle;
            const listEl = this.getListElForKey(key);
            const chevron = header.querySelector('.sidebar-chevron');

            // Apply saved state
            if (stored[key]) {
                if (listEl) listEl.style.display = 'none';
                if (chevron) chevron.classList.replace('mdi-chevron-down', 'mdi-chevron-right');
            }

            header.addEventListener('click', () => this.toggleSection(key));
        });
    }

    toggleSection(key) {
        const listEl = this.getListElForKey(key);
        if (!listEl) return;

        const header = document.querySelector(`[data-sidebar-toggle="${key}"]`);
        const chevron = header ? header.querySelector('.sidebar-chevron') : null;
        const isHidden = listEl.style.display === 'none';

        listEl.style.display = isHidden ? '' : 'none';
        if (chevron) {
            chevron.classList.replace(
                isHidden ? 'mdi-chevron-right' : 'mdi-chevron-down',
                isHidden ? 'mdi-chevron-down' : 'mdi-chevron-right'
            );
        }

        // Persist
        const state = this.getCollapseState();
        state[key] = !isHidden;
        localStorage.setItem('explorerSidebarCollapse', JSON.stringify(state));
    }

    getCollapseState() {
        try {
            return JSON.parse(localStorage.getItem('explorerSidebarCollapse')) || {};
        } catch { return {}; }
    }

    getListElForKey(key) {
        if (key === 'contacts') return this.contactsListEl;
        if (key === 'following') return this.followingListEl;
        if (key === 'followers') return this.followersListEl;
        return null;
    }

    async init() {
        await Promise.all([
            this.loadFollowingList(),
            this.loadFollowersList(),
            this.loadContacts(),
        ]);

        this.renderFollowingSidebar();
        this.renderFollowersSidebar();
        this.renderContactsSidebar();

        // Load feed updates to mark "New" items
        await this.loadFeedUpdates();

        // Re-render following with feed data (for since params)
        this.renderFollowingSidebar();

        // Highlight the currently explored profile (from URL param or localStorage)
        const explorer = window.citadelExplorer;
        if (explorer && explorer.profileUrl) {
            this.highlightActiveItem(explorer.profileUrl);

            // If the profile was auto-loaded from localStorage without sinceTimestamp,
            // check if it has new content and re-explore with highlighting
            if (!explorer.sinceTimestamp) {
                const normalizedUrl = explorer.profileUrl.replace(/\/$/, '');
                const matchingFollow = this.follows.find(f => f.cq_contact_url.replace(/\/$/, '') === normalizedUrl);
                if (matchingFollow) {
                    const feedItem = this.feedItems.find(fi => fi.follow.cq_contact_id === matchingFollow.cq_contact_id);
                    if (feedItem && feedItem.hasNew && matchingFollow.last_visited_at) {
                        explorer.sinceTimestamp = matchingFollow.last_visited_at;
                        explorer.explore();
                    }
                }
            }
        }
    }

    // ========================================
    // In-page Explore (no page reload)
    // ========================================

    exploreProfile(profileUrl, sinceValue) {
        const explorer = window.citadelExplorer;
        if (!explorer) return;

        explorer.urlInput.value = profileUrl;
        explorer.fetchBtn.disabled = false;
        explorer.toggleUrlHelp();

        // Set sinceTimestamp for "New" content highlighting
        explorer.sinceTimestamp = sinceValue || null;

        explorer.explore();
        this.highlightActiveItem(profileUrl);
    }

    highlightActiveItem(profileUrl) {
        if (!profileUrl) return;
        // Normalize: strip trailing slash
        const normalized = profileUrl.replace(/\/$/, '');

        // Clear all active states across all three lists
        document.querySelectorAll('.sidebar-hover-item.sidebar-active').forEach(el => {
            el.classList.remove('sidebar-active');
        });

        // Find and highlight matching items
        document.querySelectorAll('[data-profile-url]').forEach(el => {
            const elUrl = (el.dataset.profileUrl || '').replace(/\/$/, '');
            if (elUrl === normalized) {
                const item = el.closest('.sidebar-hover-item') || el;
                item.classList.add('sidebar-active');
            }
        });
    }

    // ========================================
    // Data Loading
    // ========================================

    async loadFollowingList() {
        try {
            const resp = await fetch('/api/follow/list');
            const data = await resp.json();
            if (data.success) {
                this.follows = data.follows || [];
                if (this.followingCountEl) this.followingCountEl.textContent = data.follow_count || 0;
            }
        } catch (e) {
            console.error('ExplorerSidebar: Failed to load following list', e);
        }
    }

    async loadFollowersList() {
        try {
            const resp = await fetch('/api/follow/followers');
            const data = await resp.json();
            if (data.success) {
                this.followers = data.followers || [];
                if (this.followersCountEl) this.followersCountEl.textContent = data.follower_count || 0;
            }
        } catch (e) {
            console.error('ExplorerSidebar: Failed to load followers list', e);
        }
    }

    async loadContacts() {
        try {
            const resp = await fetch('/api/cq-contact');
            const data = await resp.json();
            if (Array.isArray(data)) {
                this.contacts = data;
                if (this.contactsCountEl) this.contactsCountEl.textContent = data.length;
            }
        } catch (e) {
            console.error('ExplorerSidebar: Failed to load contacts', e);
        }
    }

    async loadFeedUpdates() {
        try {
            const resp = await fetch('/api/follow/feed-updates');
            const data = await resp.json();
            if (!data.success) return;

            this.feedItems = (data.items || []).map(item => ({
                follow: item,
                hasNew: item.has_new || false,
            }));

            // Count new items for dashboard badge
            let newCount = 0;
            this.feedItems.forEach(item => {
                if (item.hasNew) newCount++;
            });

            // Update dashboard badge if present
            const badge = document.getElementById('feed-new-badge');
            if (badge) {
                if (newCount > 0) {
                    badge.textContent = newCount;
                    badge.classList.remove('d-none');
                } else {
                    badge.classList.add('d-none');
                }
            }
        } catch (e) {
            console.error('ExplorerSidebar: Failed to load feed updates', e);
        }
    }

    // ========================================
    // Sidebar Rendering - Following
    // ========================================

    renderFollowingSidebar() {
        if (!this.followingListEl) return;

        if (this.follows.length === 0) {
            this.followingListEl.innerHTML = '<div class="text-center py-2"><small class="text-muted"><i class="mdi mdi-rss-off me-1"></i>' + this.t('no_following', 'Not following anyone yet') + '</small></div>';
            return;
        }

        let html = '';
        this.follows.forEach(f => {
            const photoUrl = f.cq_contact_url + '/photo';
            const feedItem = this.feedItems.find(fi => fi.follow.cq_contact_id === f.cq_contact_id);
            const hasNew = feedItem && feedItem.hasNew;
            const sinceValue = hasNew && f.last_visited_at ? f.last_visited_at : '';
            const newClass = hasNew ? ' bg-warning bg-opacity-10' : '';
            const dotHidden = hasNew ? '' : ' d-none';

            html += '<a href="#" class="d-flex align-items-center text-decoration-none text-light px-2 py-1 rounded sidebar-hover-item' + newClass + '" data-profile-url="' + this.escHtml(f.cq_contact_url) + '" data-since="' + this.escHtml(sinceValue) + '" data-cq-contact-id="' + f.cq_contact_id + '">' +
                this.avatarHtml(photoUrl, 28, 'border-success') +
                '<div class="text-truncate" style="min-width: 0;"><div class="small fw-bold text-truncate">' + this.escHtml(f.cq_contact_username) + '</div><div class="text-light opacity-50" style="font-size: 0.65rem;">' + this.escHtml(f.cq_contact_domain) + '</div></div>' +
                '<span class="feed-new-dot ms-auto flex-shrink-0' + dotHidden + '" data-dot-id="' + f.cq_contact_id + '"><span class="badge bg-warning bg-opacity-75 rounded-pill" style="width: 8px; height: 8px; padding: 0;"></span></span>' +
                '</a>';
        });
        this.followingListEl.innerHTML = html;

        // Click handler: in-page explore + sendBeacon visited
        this.followingListEl.querySelectorAll('[data-profile-url]').forEach(link => {
            link.addEventListener('click', (e) => {
                e.preventDefault();
                const profileUrl = link.dataset.profileUrl;
                const sinceVal = link.dataset.since || '';

                // Mark visited via beacon
                const contactId = link.dataset.cqContactId;
                if (contactId) {
                    const blob = new Blob([JSON.stringify({ cq_contact_id: contactId })], { type: 'application/json' });
                    navigator.sendBeacon('/api/follow/visited', blob);
                }

                this.exploreProfile(profileUrl, sinceVal || null);
            });
        });
    }

    // ========================================
    // Sidebar Rendering - Followers
    // ========================================

    renderFollowersSidebar() {
        if (!this.followersListEl) return;

        if (this.followers.length === 0) {
            this.followersListEl.innerHTML = '<div class="text-center py-2"><small class="text-muted"><i class="mdi mdi-account-group-outline me-1"></i>' + this.t('no_followers', 'No followers yet') + '</small></div>';
            return;
        }

        let html = '';
        this.followers.forEach(f => {
            const photoUrl = f.cq_contact_url + '/photo';
            html += '<a href="#" class="d-flex align-items-center text-decoration-none text-light px-2 py-1 rounded sidebar-hover-item" data-profile-url="' + this.escHtml(f.cq_contact_url) + '">' +
                this.avatarHtml(photoUrl, 28, 'border-secondary') +
                '<div class="text-truncate" style="min-width: 0;"><div class="small fw-bold text-truncate">' + this.escHtml(f.cq_contact_username) + '</div><div class="text-light opacity-50" style="font-size: 0.65rem;">' + this.escHtml(f.cq_contact_domain) + '</div></div>' +
                '</a>';
        });
        this.followersListEl.innerHTML = html;

        // Click handler: in-page explore
        this.followersListEl.querySelectorAll('[data-profile-url]').forEach(link => {
            link.addEventListener('click', (e) => {
                e.preventDefault();
                this.exploreProfile(link.dataset.profileUrl);
            });
        });
    }

    // ========================================
    // Sidebar Rendering - CQ Contacts
    // ========================================

    renderContactsSidebar() {
        if (!this.contactsListEl) return;

        if (this.contacts.length === 0) {
            this.contactsListEl.innerHTML = '<div class="text-center py-2"><small class="text-muted"><i class="mdi mdi-account-off me-1"></i>' + this.t('no_contacts', 'No contacts yet') + '</small></div>';
            return;
        }

        let html = '';
        this.contacts.forEach(c => {
            const photoUrl = '/api/cq-contact/' + c.id + '/profile-photo';
            const statusIcon = this.getContactStatusIcon(c);
            const actions = this.getContactActions(c);

            html += '<div class="d-flex align-items-center px-2 py-1 rounded sidebar-hover-item">' +
                '<a href="#" class="d-flex align-items-center text-decoration-none text-light flex-grow-1" style="min-width: 0;" data-profile-url="' + this.escHtml(c.cqContactUrl) + '">' +
                this.avatarHtml(photoUrl, 28, 'border-success') +
                '<div class="text-truncate" style="min-width: 0;"><div class="small fw-bold text-truncate">' + this.escHtml(c.cqContactUsername) + '</div><div class="text-light opacity-50" style="font-size: 0.65rem;">' + this.escHtml(c.cqContactDomain) + '</div></div>' +
                '</a>' +
                '<div class="flex-shrink-0 ms-auto d-flex align-items-center gap-1">' + statusIcon + actions + '</div>' +
                '</div>';
        });
        this.contactsListEl.innerHTML = html;

        // Click handler: in-page explore for contact links
        this.contactsListEl.querySelectorAll('[data-profile-url]').forEach(link => {
            link.addEventListener('click', (e) => {
                e.preventDefault();
                this.exploreProfile(link.dataset.profileUrl);
            });
        });

        // Bind friend request action handlers
        this.contactsListEl.querySelectorAll('[data-accept-id]').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.preventDefault();
                this.handleFriendRequest(btn.dataset.acceptId, 'ACCEPTED');
            });
        });
        this.contactsListEl.querySelectorAll('[data-reject-id]').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.preventDefault();
                this.handleFriendRequest(btn.dataset.rejectId, 'REJECTED');
            });
        });
        this.contactsListEl.querySelectorAll('[data-delete-id]').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.preventDefault();
                const contactId = btn.dataset.deleteId;
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
        });
    }

    getContactStatusIcon(contact) {
        const s = contact.friendRequestStatus;
        if (s === 'ACCEPTED' && contact.isActive) {
            return '<i class="mdi mdi-check-circle text-success opacity-50" style="font-size: 0.75rem;"></i>';
        } else if (s === 'SENT') {
            return '<i class="mdi mdi-clock text-warning" style="font-size: 0.75rem;" title="Pending"></i>';
        } else if (s === 'RECEIVED') {
            return '<i class="mdi mdi-inbox text-info" style="font-size: 0.75rem;" title="Received"></i>';
        } else if (s === 'REJECTED') {
            return '<i class="mdi mdi-close-circle text-danger opacity-50" style="font-size: 0.75rem;"></i>';
        }
        return '';
    }

    getContactActions(contact) {
        if (contact.friendRequestStatus === 'RECEIVED') {
            return '<button class="btn btn-sm p-0 px-1 btn-outline-success border-0" data-accept-id="' + contact.id + '" title="Accept"><i class="mdi mdi-check" style="font-size: 0.75rem;"></i></button>' +
                '<button class="btn btn-sm p-0 px-1 btn-outline-danger border-0" data-reject-id="' + contact.id + '" title="Reject"><i class="mdi mdi-close" style="font-size: 0.75rem;"></i></button>';
        }
        return '';
    }

    async handleFriendRequest(contactId, status) {
        try {
            const resp = await fetch('/api/cq-contact/' + contactId + '/friend-request', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ friendRequestStatus: status }),
            });
            const data = await resp.json();
            if (data.success) {
                window.toast && window.toast.success(this.t('friend_request_updated', 'Friend request updated'));
                await this.loadContacts();
                this.renderContactsSidebar();
            } else {
                window.toast && window.toast.error(data.message || data.error || 'Error');
            }
        } catch (e) {
            console.error('ExplorerSidebar: Friend request error', e);
            window.toast && window.toast.error(e.message || 'Error');
        }
    }

    // ========================================
    // Utilities
    // ========================================

    avatarHtml(photoUrl, size, borderClass) {
        return '<div class="rounded-circle border border-1 ' + borderClass + ' me-2 flex-shrink-0 overflow-hidden d-flex align-items-center justify-content-center" style="width: ' + size + 'px; height: ' + size + 'px; background: rgba(149,236,134,0.05);">' +
            '<img src="' + photoUrl + '" alt="" style="width: 100%; height: 100%; object-fit: cover;" onerror="this.style.display=\'none\'; this.nextElementSibling.style.display=\'inline\';">' +
            '<i class="mdi mdi-account text-cyber" style="font-size: ' + Math.round(size * 0.55) + 'px; display: none;"></i></div>';
    }

    escHtml(str) {
        if (!str) return '';
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }
}
