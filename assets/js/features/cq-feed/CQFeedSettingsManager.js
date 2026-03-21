import * as bootstrap from 'bootstrap';
import { formatDate, formatTime, getCitadelLocale } from '../../shared/date-utils';

/**
 * CQ Feed Settings Manager
 * Handles both "My Feeds" CRUD and "Subscribed Feeds" management
 */
export class CQFeedSettingsManager {
    constructor(mode, containerEl) {
        this.mode = mode; // 'my-feeds' or 'subscribed'
        this.container = containerEl;
        this.apiUrl = containerEl.dataset.apiUrl;
        this.translations = JSON.parse(containerEl.dataset.translations || '{}');
        this.feeds = [];
    }

    t(key, fallback) {
        return this.translations[key] || fallback || key;
    }

    async init() {
        if (this.mode === 'my-feeds') {
            this.initMyFeeds();
        } else {
            this.initSubscribed();
        }
    }

    // ========================================
    // My Feeds
    // ========================================

    initMyFeeds() {
        this.username = this.container.dataset.username;
        this.feedsList = document.getElementById('feeds-list');
        this.feedsLoading = document.getElementById('feeds-loading');
        this.feedsEmpty = document.getElementById('feeds-empty');

        // Modal elements
        this.modal = new bootstrap.Modal(document.getElementById('feedEditModal'));
        this.modalTitle = document.getElementById('feedEditModalTitle');
        this.editId = document.getElementById('feed-edit-id');
        this.editTitle = document.getElementById('feed-edit-title');
        this.editSlug = document.getElementById('feed-edit-slug');
        this.editScope = document.getElementById('feed-edit-scope');
        this.editDescription = document.getElementById('feed-edit-description');
        this.editActive = document.getElementById('feed-edit-active');
        this.slugPreview = document.getElementById('slug-preview-val');

        // Events
        document.getElementById('btn-create-feed').addEventListener('click', () => this.showCreateModal());
        document.getElementById('btn-save-feed').addEventListener('click', () => this.saveFeed());

        // Auto-generate slug from title
        this.editTitle.addEventListener('input', () => {
            if (!this.editId.value) {
                const slug = this.editTitle.value.toLowerCase()
                    .replace(/[^a-z0-9\s-]/g, '')
                    .replace(/\s+/g, '-')
                    .replace(/-+/g, '-')
                    .substring(0, 64);
                this.editSlug.value = slug;
                this.slugPreview.textContent = slug || 'my-feed';
            }
        });

        this.editSlug.addEventListener('input', () => {
            this.slugPreview.textContent = this.editSlug.value || 'my-feed';
        });

        this.loadMyFeeds();
    }

    async loadMyFeeds() {
        this.feedsLoading.classList.remove('d-none');
        this.feedsList.classList.add('d-none');
        this.feedsEmpty.classList.add('d-none');

        try {
            const response = await fetch(this.apiUrl);
            const data = await response.json();
            
            if (data.success) {
                this.feeds = data.feeds || [];
                this.renderMyFeeds();
            }
        } catch (e) {
            console.error('Failed to load feeds', e);
        } finally {
            this.feedsLoading.classList.add('d-none');
        }
    }

    renderMyFeeds() {
        if (this.feeds.length === 0) {
            this.feedsEmpty.classList.remove('d-none');
            this.feedsList.classList.add('d-none');
            return;
        }

        this.feedsEmpty.classList.add('d-none');
        this.feedsList.classList.remove('d-none');

        this.feedsList.innerHTML = this.feeds.map(feed => {
            const scopeLabel = feed.scope === 0 
                ? `<i class="mdi mdi-earth me-1"></i>${this.t('scope_public', 'Public')}`
                : `<i class="mdi mdi-account-group me-1"></i>${this.t('scope_contacts', 'CQ Contacts')}`;
            const activeClass = feed.is_active ? 'border-success border-opacity-50' : 'border-secondary border-opacity-25 opacity-50';
            const activeBadge = feed.is_active 
                ? '<span class="badge bg-success bg-opacity-25 text-success">Active</span>'
                : '<span class="badge bg-secondary bg-opacity-25 text-secondary">Inactive</span>';
            const createdAt = feed.created_at ? formatDate(feed.created_at) : '';

            return `
                <div class="glass-panel p-3 mb-2 border-1 ${activeClass}">
                    <div class="d-flex justify-content-between align-items-start">
                        <div class="flex-grow-1">
                            <div class="d-flex align-items-center gap-2 mb-1">
                                <i class="mdi mdi-rss text-cyber"></i>
                                <strong class="text-light">${this.escapeHtml(feed.title)}</strong>
                                ${activeBadge}
                            </div>
                            <div class="small text-muted">
                                <span class="me-3"><i class="mdi mdi-link-variant me-1"></i><code>/${this.username}/feed/${this.escapeHtml(feed.feed_url_slug)}</code></span>
                                <span class="me-3">${scopeLabel}</span>
                                <span class="me-3"><i class="mdi mdi-calendar me-1"></i>${createdAt}</span>
                            </div>
                            ${feed.description ? `<div class="small text-muted mt-1 opacity-75">${this.escapeHtml(feed.description)}</div>` : ''}
                        </div>
                        <div class="d-flex gap-1">
                            <button class="btn btn-sm btn-outline-cyber" data-action="edit" data-id="${feed.id}" title="${this.t('edit_feed', 'Edit')}" style="padding: 2px 10px !important;">
                                <i class="mdi mdi-pencil"></i>
                            </button>
                            <button class="btn btn-sm btn-outline-danger" data-action="delete" data-id="${feed.id}" data-title="${this.escapeHtml(feed.title)}" title="${this.t('delete_feed', 'Delete')}" style="padding: 2px 10px !important;">
                                <i class="mdi mdi-delete"></i>
                            </button>
                        </div>
                    </div>
                </div>
            `;
        }).join('');

        // Attach events
        this.feedsList.querySelectorAll('[data-action="edit"]').forEach(btn => {
            btn.addEventListener('click', () => this.showEditModal(btn.dataset.id));
        });
        this.feedsList.querySelectorAll('[data-action="delete"]').forEach(btn => {
            btn.addEventListener('click', () => this.deleteFeed(btn.dataset.id, btn.dataset.title));
        });
    }

    showCreateModal() {
        this.editId.value = '';
        this.editTitle.value = '';
        this.editSlug.value = '';
        this.editScope.value = '1';
        this.editDescription.value = '';
        this.editActive.checked = true;
        this.slugPreview.textContent = 'my-feed';
        this.modalTitle.innerHTML = `<i class="mdi mdi-rss me-2"></i>${this.t('create_feed', 'Create Feed')}`;
        this.modal.show();
        setTimeout(() => this.editTitle.focus(), 300);
    }

    showEditModal(id) {
        const feed = this.feeds.find(f => f.id === id);
        if (!feed) return;

        this.editId.value = feed.id;
        this.editTitle.value = feed.title;
        this.editSlug.value = feed.feed_url_slug;
        this.editScope.value = String(feed.scope);
        this.editDescription.value = feed.description || '';
        this.editActive.checked = !!feed.is_active;
        this.slugPreview.textContent = feed.feed_url_slug;
        this.modalTitle.innerHTML = `<i class="mdi mdi-pencil me-2"></i>${this.t('edit_feed', 'Edit Feed')}`;
        this.modal.show();
        setTimeout(() => this.editTitle.focus(), 300);
    }

    async saveFeed() {
        const id = this.editId.value;
        const payload = {
            title: this.editTitle.value.trim(),
            feed_url_slug: this.editSlug.value.trim(),
            scope: parseInt(this.editScope.value),
            description: this.editDescription.value.trim() || null,
            is_active: this.editActive.checked ? 1 : 0,
        };

        if (!payload.title) {
            window.toast?.error(this.t('error', 'Error'));
            return;
        }

        const saveBtn = document.getElementById('btn-save-feed');
        const spinner = saveBtn.querySelector('.spinner-border');
        spinner.classList.remove('d-none');
        saveBtn.disabled = true;

        try {
            const url = id ? `${this.apiUrl}/${id}` : this.apiUrl;
            const method = id ? 'PUT' : 'POST';

            const response = await fetch(url, {
                method,
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload),
            });

            const data = await response.json();
            if (data.success) {
                this.modal.hide();
                window.toast?.success(this.t('saved', 'Saved'));
                this.loadMyFeeds();
            } else {
                window.toast?.error(data.message || this.t('error', 'Error'));
            }
        } catch (e) {
            window.toast?.error(this.t('error', 'Error'));
        } finally {
            spinner.classList.add('d-none');
            saveBtn.disabled = false;
        }
    }

    async deleteFeed(id, title) {
        if (!confirm(`${this.t('delete_confirm', 'Delete this feed?')}\n\n${title}`)) return;

        try {
            const response = await fetch(`${this.apiUrl}/${id}`, { method: 'DELETE' });
            const data = await response.json();
            if (data.success) {
                window.toast?.success(this.t('deleted', 'Deleted'));
                this.loadMyFeeds();
            } else {
                window.toast?.error(data.message || this.t('error', 'Error'));
            }
        } catch (e) {
            window.toast?.error(this.t('error', 'Error'));
        }
    }

    // ========================================
    // Subscribed Feeds
    // ========================================

    initSubscribed() {
        this.syncUrl = this.container.dataset.syncUrl;
        this.subscribedList = document.getElementById('subscribed-list');
        this.subscribedLoading = document.getElementById('subscribed-loading');
        this.subscribedEmpty = document.getElementById('subscribed-empty');

        // Sync button
        const syncBtn = document.getElementById('btn-sync-subscriptions');
        if (syncBtn) {
            syncBtn.addEventListener('click', () => this.syncSubscriptions(syncBtn));
        }

        this.loadSubscribed();
    }

    async loadSubscribed() {
        this.subscribedLoading.classList.remove('d-none');
        this.subscribedList.classList.add('d-none');
        this.subscribedEmpty.classList.add('d-none');

        try {
            const response = await fetch(this.apiUrl);
            const data = await response.json();
            
            if (data.success) {
                this.feeds = data.feeds || [];
                this.renderSubscribed();
            }
        } catch (e) {
            console.error('Failed to load subscribed feeds', e);
        } finally {
            this.subscribedLoading.classList.add('d-none');
        }
    }

    renderSubscribed() {
        if (this.feeds.length === 0) {
            this.subscribedEmpty.classList.remove('d-none');
            this.subscribedList.classList.add('d-none');
            return;
        }

        this.subscribedEmpty.classList.add('d-none');
        this.subscribedList.classList.remove('d-none');

        // Group by contact
        const grouped = {};
        this.feeds.forEach(feed => {
            const key = feed.cq_contact_id;
            if (!grouped[key]) {
                grouped[key] = {
                    contactUsername: feed.cq_contact_username,
                    contactDomain: feed.cq_contact_domain,
                    contactUrl: feed.cq_contact_url,
                    feeds: [],
                };
            }
            grouped[key].feeds.push(feed);
        });

        let html = '';
        for (const [contactId, group] of Object.entries(grouped)) {
            html += `
                <div class="glass-panel p-3 mb-3 border-1 border-primary border-opacity-25">
                    <div class="d-flex align-items-center gap-2 mb-2">
                        <i class="mdi mdi-account-box text-cyber"></i>
                        <strong class="text-light">${this.escapeHtml(group.contactUsername)}</strong>
                        <span class="text-muted small">@${this.escapeHtml(group.contactDomain)}</span>
                    </div>
                    <div class="ms-3">
                        ${group.feeds.map(feed => this.renderSubscribedFeed(feed)).join('')}
                    </div>
                </div>
            `;
        }

        this.subscribedList.innerHTML = html;

        // Attach events
        this.subscribedList.querySelectorAll('[data-action="toggle"]').forEach(btn => {
            btn.addEventListener('click', () => this.toggleSubscribed(btn.dataset.id, btn));
        });
        this.subscribedList.querySelectorAll('[data-action="unsubscribe"]').forEach(btn => {
            btn.addEventListener('click', () => this.unsubscribeFeed(btn.dataset.id, btn.dataset.title));
        });
    }

    renderSubscribedFeed(feed) {
        const activeClass = feed.is_active ? '' : 'opacity-50';
        const statusBadge = feed.is_active
            ? `<span class="badge bg-success bg-opacity-25 text-success small">${this.t('active', 'Active')}</span>`
            : `<span class="badge bg-secondary bg-opacity-25 text-secondary small">${this.t('paused', 'Paused')}</span>`;
        const lastVisited = feed.last_visited_at && feed.last_visited_at !== '2000-01-01 00:00:00'
            ? `${formatDate(feed.last_visited_at)} ${formatTime(feed.last_visited_at)}`
            : '—';

        return `
            <div class="d-flex justify-content-between align-items-center flex-column flex-sm-row py-2 border-bottom border-secondary border-opacity-25 ${activeClass}">
                <div class="flex-grow-1">
                    <div class="d-flex align-items-center gap-2">
                        <i class="mdi mdi-rss text-warning"></i>
                        <span class="text-light">${this.escapeHtml(feed.title)}</span>
                        ${statusBadge}
                    </div>
                    <div class="small text-muted">
                        <span class="me-3"><i class="mdi mdi-link-variant me-1"></i><code>/${this.escapeHtml(feed.cq_contact_username)}/feed/${this.escapeHtml(feed.feed_url_slug)}</code></span>
                        <span><i class="mdi mdi-clock-outline me-1"></i>${this.t('last_visited', 'Last sync')}: ${lastVisited}</span>
                    </div>
                </div>
                <div class="d-flex gap-1">
                    <button class="btn btn-sm ${feed.is_active ? 'btn-outline-warning' : 'btn-outline-success'}" 
                            data-action="toggle" data-id="${feed.id}" 
                            title="${this.t('toggle_active', 'Toggle')}" style="padding: 2px 10px !important;">
                        <i class="mdi mdi-${feed.is_active ? 'pause' : 'play'}"></i>
                    </button>
                    <button class="btn btn-sm btn-outline-danger" 
                            data-action="unsubscribe" data-id="${feed.id}" data-title="${this.escapeHtml(feed.title)}"
                            title="${this.t('unsubscribe', 'Unsubscribe')}" style="padding: 2px 10px !important;">
                        <i class="mdi mdi-rss-off"></i>
                    </button>
                </div>
            </div>
        `;
    }

    async toggleSubscribed(id, btn) {
        try {
            const response = await fetch(`${this.apiUrl}/${id}/toggle`, { method: 'POST' });
            const data = await response.json();
            if (data.success) {
                this.loadSubscribed();
            } else {
                window.toast?.error(data.message || this.t('error', 'Error'));
            }
        } catch (e) {
            window.toast?.error(this.t('error', 'Error'));
        }
    }

    async unsubscribeFeed(id, title) {
        if (!confirm(`${this.t('unsubscribe_confirm', 'Unsubscribe from this feed?')}\n\n${title}`)) return;

        try {
            const response = await fetch(`${this.apiUrl}/${id}`, { method: 'DELETE' });
            const data = await response.json();
            if (data.success) {
                window.toast?.success(this.t('unsubscribed', 'Unsubscribed'));
                this.loadSubscribed();
            } else {
                window.toast?.error(data.message || this.t('error', 'Error'));
            }
        } catch (e) {
            window.toast?.error(this.t('error', 'Error'));
        }
    }

    async syncSubscriptions(btn) {
        const spinner = btn.querySelector('.spinner-border');
        spinner.classList.remove('d-none');
        btn.disabled = true;

        try {
            const response = await fetch(this.syncUrl, { method: 'POST' });
            const data = await response.json();
            if (data.success) {
                const msg = data.new_subscriptions > 0
                    ? `${this.t('synced', 'Synced')} (+${data.new_subscriptions})`
                    : this.t('synced', 'Synced');
                window.toast?.success(msg);
                this.loadSubscribed();
            } else {
                window.toast?.error(data.message || this.t('sync_error', 'Sync error'));
            }
        } catch (e) {
            window.toast?.error(this.t('sync_error', 'Sync error'));
        } finally {
            spinner.classList.add('d-none');
            btn.disabled = false;
        }
    }

    // ========================================
    // Helpers
    // ========================================

    escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = str || '';
        return div.innerHTML;
    }
}
