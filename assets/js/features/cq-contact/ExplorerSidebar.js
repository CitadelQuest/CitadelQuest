import * as bootstrap from 'bootstrap';
import { updatesService } from '../../services/UpdatesService.js';

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
        this.contactsWithUnread = new Set();

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
            this.initUpdatesListener();
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

        // Listen for global feed-updates event (polled centrally from cq-chat-modal.js)
        this.initFeedUpdatesListener();
    }

    /**
     * Listen for global feed-updates events and re-render sidebar highlights.
     */
    initFeedUpdatesListener() {
        window.addEventListener('cq-feed-updates', (e) => {
            const { items } = e.detail;
            const lastViewed = localStorage.getItem('cqFeedLastViewedAt');
            let hasAnyNewFeed = false;
            this.feedItems = (items || []).map(item => {
                if (item.has_new_feed) {
                    const feedTs = item.last_feed_updated_at;
                    const feedIsUnviewed = !lastViewed || (feedTs && feedTs > lastViewed);
                    if (feedIsUnviewed) hasAnyNewFeed = true;
                }
                return {
                    follow: item,
                    hasNew: item.has_new || false,
                    hasNewContent: item.has_new_content || false,
                    hasNewFeed: item.has_new_feed || false,
                };
            });
            this.updateFeedTabNotification(hasAnyNewFeed);
            this._updateBadgeCount();
            this.renderFollowingSidebar();

            // Re-highlight the currently active profile
            const explorer = window.citadelExplorer;
            if (explorer && explorer.profileUrl) {
                this.highlightActiveItem(explorer.profileUrl);
            }

            // If CQ Feed tab is active and there are new feed posts, auto-refresh content
            // but do NOT markFeedViewed — let the bell stay as visual cue
            const feedTab = document.getElementById('cqFeedTab');
            if (hasAnyNewFeed && feedTab && feedTab.classList.contains('active') && window.cqFeedManager) {
                window.cqFeedManager.refresh();
            }
        });
    }

    // ========================================
    // In-page Explore (no page reload)
    // ========================================

    exploreProfile(profileUrl, sinceValue) {
        const explorer = window.citadelExplorer;
        if (!explorer) return;

        // Switch to CQ Explorer tab if CQ Feed is currently active
        const explorerTab = document.getElementById('cqExplorerTab');
        const feedTab = document.getElementById('cqFeedTab');
        const explorerPane = document.getElementById('cqExplorerPane');
        const feedPane = document.getElementById('cqFeedPane');
        if (explorerTab && feedTab && explorerPane && feedPane && feedTab.classList.contains('active')) {
            explorerTab.classList.add('active');
            feedTab.classList.remove('active');
            explorerPane.classList.remove('d-none');
            feedPane.classList.add('d-none');
            localStorage.setItem('cqExplorerActiveTab', 'explorer');
        }

        explorer.urlInput.value = profileUrl;
        explorer.fetchBtn.disabled = false;
        explorer.toggleUrlHelp();

        // Set sinceTimestamp for "New" content highlighting
        explorer.sinceTimestamp = sinceValue || null;

        explorer.explore();
        this.highlightActiveItem(profileUrl);
    }

    highlightActiveItem(profileUrl) {
        // Clear all active states across all three lists
        document.querySelectorAll('.sidebar-hover-item.sidebar-active').forEach(el => {
            el.classList.remove('sidebar-active');
        });

        if (!profileUrl) return;

        // Normalize: strip trailing slash
        const normalized = profileUrl.replace(/\/$/, '');

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
                hasNewContent: item.has_new_content || false,
                hasNewFeed: item.has_new_feed || false,
            }));

            // Count new items for dashboard badge
            const lastViewed = localStorage.getItem('cqFeedLastViewedAt');
            let newCount = 0;
            let hasAnyNewFeed = false;
            this.feedItems.forEach(item => {
                if (item.hasNewFeed) {
                    const feedTs = item.follow.last_feed_updated_at;
                    const feedIsUnviewed = !lastViewed || (feedTs && feedTs > lastViewed);
                    if (feedIsUnviewed) hasAnyNewFeed = true;
                }
                // Badge: count items with content updates, or unviewed feed updates
                if (item.hasNewContent) {
                    newCount++;
                } else if (item.hasNew && item.hasNewFeed) {
                    // Feed-only update — only count if not yet viewed
                    const feedTs = item.follow.last_feed_updated_at;
                    if (!lastViewed || (feedTs && feedTs > lastViewed)) newCount++;
                }
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

            // Notify CQ Feed tab about new feed posts
            this.updateFeedTabNotification(hasAnyNewFeed);
        } catch (e) {
            console.error('ExplorerSidebar: Failed to load feed updates', e);
        }
    }

    /**
     * Mark CQ Feed as viewed — store latest feed timestamp so bell clears.
     */
    markFeedViewed() {
        // Store the max last_feed_updated_at from current feedItems
        let maxTs = null;
        this.feedItems.forEach(item => {
            const ts = item.follow.last_feed_updated_at;
            if (ts && (!maxTs || ts > maxTs)) maxTs = ts;
        });
        // Fallback: use current UTC time if feedItems not loaded yet
        if (!maxTs) {
            maxTs = new Date().toISOString().replace('T', ' ').substring(0, 19);
        }
        localStorage.setItem('cqFeedLastViewedAt', maxTs);
        // Immediately clear the notification
        this.updateFeedTabNotification(false);
        // Also update badge count to exclude now-viewed feed items
        this._updateBadgeCount();
    }

    _updateBadgeCount() {
        const lastViewed = localStorage.getItem('cqFeedLastViewedAt');
        let newCount = 0;
        this.feedItems.forEach(item => {
            if (item.hasNewContent) {
                newCount++;
            } else if (item.hasNew && item.hasNewFeed) {
                const feedTs = item.follow.last_feed_updated_at;
                if (!lastViewed || (feedTs && feedTs > lastViewed)) newCount++;
            }
        });
        // Update dashboard badge
        const badge = document.getElementById('feed-new-badge');
        if (badge) {
            if (newCount > 0) {
                badge.textContent = newCount;
                badge.classList.remove('d-none');
            } else {
                badge.classList.add('d-none');
            }
        }
        // Update main nav CQ Explorer badge
        const navBadge = document.getElementById('cqExplorerNewBadge');
        if (navBadge) {
            if (newCount > 0) {
                navBadge.textContent = newCount;
                navBadge.classList.remove('d-none');
            } else {
                navBadge.textContent = '';
                navBadge.classList.add('d-none');
            }
        }
    }

    updateFeedTabNotification(hasNewFeed) {
        const feedTab = document.getElementById('cqFeedTab');
        if (!feedTab) return;

        // If hasNewFeed from backend, double-check against local viewed timestamp
        if (hasNewFeed) {
            const lastViewed = localStorage.getItem('cqFeedLastViewedAt');
            if (lastViewed) {
                // Only show bell if any feed has updates newer than what we last viewed
                const hasUnviewed = this.feedItems.some(item => {
                    const ts = item.follow.last_feed_updated_at;
                    return ts && ts > lastViewed;
                });
                if (!hasUnviewed) hasNewFeed = false;
            }
        }

        const existingBell = feedTab.querySelector('.feed-tab-bell');
        if (hasNewFeed) {
            if (!existingBell) {
                const bell = document.createElement('i');
                bell.className = 'mdi mdi-bell-ring text-warning ms-1 feed-tab-bell';
                bell.style.fontSize = '0.7rem';
                feedTab.appendChild(bell);
            }
            feedTab.style.borderColor = 'rgba(255, 193, 7, 0.5)';
            feedTab.style.borderWidth = '1px';
            feedTab.style.borderStyle = 'solid';
            feedTab.style.borderRadius = '4px';
        } else {
            if (existingBell) existingBell.remove();
            feedTab.style.borderColor = '';
            feedTab.style.borderWidth = '';
            feedTab.style.borderStyle = '';
            feedTab.style.borderRadius = '';
        }
    }

    // ========================================
    // Sidebar Rendering - Following
    // ========================================

    renderFollowingSidebar() {
        if (!this.followingListEl) return;

        // Sort A-Z by username
        this.follows.sort((a, b) => (a.cq_contact_username || '').localeCompare(b.cq_contact_username || ''));

        if (this.follows.length === 0) {
            this.followingListEl.innerHTML = '<div class="text-center py-2"><small class="text-muted"><i class="mdi mdi-rss-off me-1"></i>' + this.t('no_following', 'Not following anyone yet') + '</small></div>';
            return;
        }

        let html = '';
        this.follows.forEach(f => {
            const photoUrl = f.cq_contact_url + '/photo';
            const feedItem = this.feedItems.find(fi => fi.follow.cq_contact_id === f.cq_contact_id);
            const hasNewContent = feedItem && feedItem.hasNewContent;
            const hasNew = feedItem && feedItem.hasNew;
            const sinceValue = hasNew && f.last_visited_at ? f.last_visited_at : '';
            const newClass = hasNewContent ? ' bg-warning bg-opacity-10' : '';
            const dotHidden = hasNewContent ? '' : ' d-none';

            const bellIcon = hasNewContent ? '<i class="mdi mdi-bell-ring text-warning ms-1" style="font-size: 0.6rem;"></i>' : '';

            html += '<div class="d-flex align-items-center px-2 py-1 rounded sidebar-hover-item' + newClass + '">' +
                '<a href="#" class="d-flex align-items-center text-decoration-none text-light flex-grow-1" style="min-width: 0;" data-profile-url="' + this.escHtml(f.cq_contact_url) + '" data-since="' + this.escHtml(sinceValue) + '" data-cq-contact-id="' + f.cq_contact_id + '">' +
                this.avatarHtml(photoUrl, 28, 'border-success') +
                '<div class="text-truncate" style="min-width: 0; line-height:0.9rem;"><div class="small fw-bold text-truncate">' + this.escHtml(f.cq_contact_username) + '</div><div class="text-light opacity-50" style="font-size: 0.65rem;">' + this.escHtml(f.cq_contact_domain) + '</div></div>' +
                '</a>' +
                '<div class="flex-shrink-0 ms-auto d-flex align-items-center gap-2">' +
                '<span class="feed-new-dot' + dotHidden + '" data-dot-id="' + f.cq_contact_id + '"><span class="badge bg-warning bg-opacity-75 rounded-pill" style="width: 8px; height: 8px; padding: 0;"></span></span>' +
                bellIcon + 
                '<a href="#" class="follow-status-toggle d-inline-flex align-items-center text-warning opacity-50" data-cq-contact-id="' + f.cq_contact_id + '" title="' + this.t('unfollow', 'Unfollow') + '" style="padding: 4px;">' +
                '<i class="mdi mdi-rss" style="font-size: 0.75rem;"></i></a>' +
                '<a href="#" class="follow-unfollow-btn d-none align-items-center text-danger" data-cq-contact-id="' + f.cq_contact_id + '" title="' + this.t('unfollow', 'Unfollow') + '" style="padding: 4px;">' +
                '<i class="mdi mdi-rss-off" style="font-size: 0.85rem;"></i></a>' +
                '</div>' +
                '</div>';
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

        // Bind follow status toggle: click RSS icon → reveal unfollow button
        this.followingListEl.querySelectorAll('.follow-status-toggle').forEach(toggle => {
            toggle.addEventListener('click', (e) => {
                e.preventDefault();
                e.stopPropagation();
                toggle.classList.remove('d-inline-flex');
                toggle.classList.add('d-none');
                const unfollowBtn = toggle.nextElementSibling;
                if (unfollowBtn && unfollowBtn.classList.contains('follow-unfollow-btn')) {
                    unfollowBtn.classList.remove('d-none');
                    unfollowBtn.classList.add('d-inline-flex');
                    // Auto-hide after 3 seconds
                    setTimeout(() => {
                        if (unfollowBtn.classList.contains('d-inline-flex')) {
                            unfollowBtn.classList.remove('d-inline-flex');
                            unfollowBtn.classList.add('d-none');
                            toggle.classList.remove('d-none');
                            toggle.classList.add('d-inline-flex');
                        }
                    }, 3000);
                }
            });
        });

        // Bind unfollow button: perform unfollow and remove item
        this.followingListEl.querySelectorAll('.follow-unfollow-btn').forEach(btn => {
            btn.addEventListener('click', async (e) => {
                e.preventDefault();
                e.stopPropagation();
                const contactId = btn.dataset.cqContactId;
                btn.innerHTML = '<i class="mdi mdi-loading mdi-spin" style="font-size: 0.85rem;"></i>';
                try {
                    const resp = await fetch('/api/follow/unfollow', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ cq_contact_id: contactId })
                    });
                    const data = await resp.json();
                    if (data.success) {
                        window.toast?.success(this.t('unfollow_success', 'Unfollowed'));
                        await this.loadFollowingList();
                        this.renderFollowingSidebar();
                    } else {
                        throw new Error(data.error || 'Failed');
                    }
                } catch (error) {
                    console.error('Sidebar unfollow error:', error);
                    window.toast?.error(error.message);
                    btn.innerHTML = '<i class="mdi mdi-rss-off" style="font-size: 0.85rem;"></i>';
                }
            });
        });
    }

    // ========================================
    // Sidebar Rendering - Followers
    // ========================================

    renderFollowersSidebar() {
        if (!this.followersListEl) return;

        // Sort A-Z by username
        this.followers.sort((a, b) => (a.cq_contact_username || '').localeCompare(b.cq_contact_username || ''));

        if (this.followers.length === 0) {
            this.followersListEl.innerHTML = '<div class="text-center py-2"><small class="text-muted"><i class="mdi mdi-account-group-outline me-1"></i>' + this.t('no_followers', 'No followers yet') + '</small></div>';
            return;
        }

        let html = '';
        this.followers.forEach(f => {
            const photoUrl = f.cq_contact_url + '/photo';
            html += '<a href="#" class="d-flex align-items-center text-decoration-none text-light px-2 py-1 rounded sidebar-hover-item" data-profile-url="' + this.escHtml(f.cq_contact_url) + '">' +
                this.avatarHtml(photoUrl, 28, 'border-secondary') +
                '<div class="text-truncate" style="min-width: 0; line-height:0.9rem;"><div class="small fw-bold text-truncate">' + this.escHtml(f.cq_contact_username) + '</div><div class="text-light opacity-50" style="font-size: 0.65rem;">' + this.escHtml(f.cq_contact_domain) + '</div></div>' +
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

        // Sort A-Z by username
        this.contacts.sort((a, b) => (a.cqContactUsername || '').localeCompare(b.cqContactUsername || ''));

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
                '<div class="text-truncate" style="min-width: 0; line-height:0.9rem;"><div class="small fw-bold text-truncate">' + this.escHtml(c.cqContactUsername) + '</div><div class="text-light opacity-50" style="font-size: 0.65rem;">' + this.escHtml(c.cqContactDomain) + '</div></div>' +
                '</a>' +
                '<div class="flex-shrink-0 ms-auto d-flex align-items-center gap-0">' + statusIcon + actions + '</div>' +
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

        // Bind status toggle: click accepted badge → reveal delete button
        this.contactsListEl.querySelectorAll('.contact-status-toggle').forEach(toggle => {
            toggle.addEventListener('click', (e) => {
                e.preventDefault();
                e.stopPropagation();
                // Hide the check icon, show the delete button
                toggle.classList.remove('d-inline-flex');
                toggle.classList.add('d-none');
                const deleteBtn = toggle.nextElementSibling;
                if (deleteBtn && deleteBtn.classList.contains('contact-delete-btn')) {
                    deleteBtn.classList.remove('d-none');
                    deleteBtn.classList.add('d-inline-flex');
                    // Auto-hide after 3 seconds if not clicked
                    setTimeout(() => {
                        if (deleteBtn.classList.contains('d-inline-flex')) {
                            deleteBtn.classList.remove('d-inline-flex');
                            deleteBtn.classList.add('d-none');
                            toggle.classList.remove('d-none');
                            toggle.classList.add('d-inline-flex');
                        }
                    }, 3000);
                }
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
        // Bind chat buttons
        this.contactsListEl.querySelectorAll('.sidebar-chat-btn').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.preventDefault();
                e.stopPropagation();
                this.openChatWithContact(btn.dataset.chatContactId);
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
            // Clickable badge: click to reveal delete button
            return '<a href="#" class="contact-status-toggle d-inline-flex align-items-center" data-contact-id="' + contact.id + '" title="' + this.t('delete_contact', 'Remove contact') + '" style="padding: 4px;">' +
                '<i class="mdi mdi-check-circle text-success opacity-50" style="font-size: 0.75rem;"></i></a>' +
                '<a href="#" class="contact-delete-btn d-none align-items-center text-danger" data-delete-id="' + contact.id + '" title="' + this.t('delete_contact', 'Remove contact') + '" style="padding: 4px;">' +
                '<i class="mdi mdi-account-minus" style="font-size: 0.85rem;"></i></a>';
        } else if (s === 'SENT') {
            return '<span class="contact-status-toggle d-inline-flex align-items-center" data-contact-id="' + contact.id + '" style="padding: 4px; cursor: pointer;">' +
                '<i class="mdi mdi-clock text-warning" style="font-size: 0.75rem;" title="Pending"></i></span>' +
                '<a href="#" class="contact-delete-btn d-none align-items-center text-danger" data-delete-id="' + contact.id + '" title="' + this.t('delete_contact', 'Remove contact') + '" style="padding: 4px;">' +
                '<i class="mdi mdi-account-minus" style="font-size: 0.85rem;"></i></a>';
        } else if (s === 'RECEIVED') {
            return '<i class="mdi mdi-inbox text-info" style="font-size: 0.75rem;" title="Received"></i>';
        } else if (s === 'REJECTED') {
            return '<span class="contact-status-toggle d-inline-flex align-items-center" data-contact-id="' + contact.id + '" style="padding: 4px; cursor: pointer;">' +
                '<i class="mdi mdi-close-circle text-danger opacity-50" style="font-size: 0.75rem;"></i></span>' +
                '<a href="#" class="contact-delete-btn d-none align-items-center text-danger" data-delete-id="' + contact.id + '" title="' + this.t('delete_contact', 'Remove contact') + '" style="padding: 4px;">' +
                '<i class="mdi mdi-account-minus" style="font-size: 0.85rem;"></i></a>';
        }
        return '';
    }

    getContactActions(contact) {
        let html = '';
        if (contact.friendRequestStatus === 'ACCEPTED' && contact.isActive) {
            html += '<button class="btn btn-sm p-0 px-1 btn-outline-secondary border-0 sidebar-chat-btn" data-chat-contact-id="' + contact.id + '" title="' + this.t('open_chat') + '"><i class="mdi mdi-forum" style="font-size: 0.75rem;"></i></button>';
        }
        if (contact.friendRequestStatus === 'RECEIVED') {
            html += '<button class="btn btn-sm p-0 px-1 btn-outline-success border-0" data-accept-id="' + contact.id + '" title="Accept"><i class="mdi mdi-check" style="font-size: 0.75rem;"></i></button>' +
                '<button class="btn btn-sm p-0 px-1 btn-outline-danger border-0" data-reject-id="' + contact.id + '" title="Reject"><i class="mdi mdi-close" style="font-size: 0.75rem;"></i></button>';
        }
        return html;
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
                // Re-explore to refresh the main profile view with new scope
                if (window.citadelExplorer) {
                    window.citadelExplorer.explore();
                }
            } else {
                window.toast && window.toast.error(data.message || data.error || 'Error');
            }
        } catch (e) {
            console.error('ExplorerSidebar: Friend request error', e);
            window.toast && window.toast.error(e.message || 'Error');
        }
    }

    // ========================================
    // CQ Chat integration
    // ========================================

    initUpdatesListener() {
        updatesService.addListener('explorerSidebar', (updates) => {
            if (updates.contactsWithUnread) {
                this.contactsWithUnread = new Set(updates.contactsWithUnread);
                this.updateChatButtonHighlights();
            }
        });
    }

    updateChatButtonHighlights() {
        if (!this.contactsListEl) return;
        this.contactsListEl.querySelectorAll('.sidebar-chat-btn').forEach(btn => {
            const contactId = btn.dataset.chatContactId;
            if (this.contactsWithUnread.has(contactId)) {
                btn.classList.remove('btn-outline-secondary');
                btn.classList.add('btn-outline-primary');
            } else {
                btn.classList.remove('btn-outline-primary');
                btn.classList.add('btn-outline-secondary');
            }
        });
    }

    async openChatWithContact(contactId) {
        try {
            const resp = await fetch('/api/cq-chat/find-or-create', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ contact_id: contactId }),
            });
            const data = await resp.json();
            if (data.success && data.chat) {
                // Open the chat modal via the global CqChatModalManager
                if (window.cqChatModalManager) {
                    window.cqChatModalManager.openChat(data.chat.id);
                }
            } else {
                window.toast && window.toast.error(data.error || 'Error');
            }
        } catch (e) {
            console.error('ExplorerSidebar: openChatWithContact error', e);
            window.toast && window.toast.error(e.message || 'Error');
        }
    }

    // ========================================
    // Utilities
    // ========================================

    avatarHtml(photoUrl, size, borderClass) {
        return '<div class="rounded border_border-1_' + borderClass + ' me-2 flex-shrink-0 overflow-hidden d-flex align-items-center justify-content-center" style="width: ' + size + 'px; height: ' + size + 'px; background: rgba(149,236,134,0.05);">' +
            '<img src="' + photoUrl + '" alt="" style="width: 100%; height: 100%; object-fit: cover;" onerror="this.style.display=\'none\'; this.nextElementSibling.style.display=\'inline\';">' +
            '<i class="mdi mdi-account text-cyber" style="display: none;"></i></div>';
    }

    escHtml(str) {
        if (!str) return '';
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }
}
