import MarkdownIt from 'markdown-it';

/**
 * CQ Feed Timeline
 * Renders the aggregated feed timeline with "Load more" pagination.
 * Shows both own posts and federated posts in chronological order.
 * 
 */
export class CQFeedTimeline {
    constructor(container, apiService, translations = {}, profileConfig = {}) {
        this.container = container;
        this.api = apiService;
        this.trans = translations;
        this.userPhotoUrl = profileConfig.userPhotoUrl || '';
        this.username = profileConfig.username || '';
        this.feedLastViewedAt = localStorage.getItem('cqFeedLastViewedAt') || null;

        this.page = 1;
        this.limit = 20;
        this.total = 0;
        this.isLoading = false;
        this.hasMore = true;

        // Own posts + federated posts merged
        this.ownPosts = [];
        this.timelinePosts = [];

        this.md = new MarkdownIt({ html: false, linkify: true, typographer: true });
    }

    t(key, fallback) {
        return this.trans[key] || fallback || key;
    }

    async init() {
        this.page = 1;
        this.hasMore = true;
        this.ownPosts = [];
        this.timelinePosts = [];
        await this._loadOwnPosts();
        await this._loadTimeline();
        this.render();
    }

    async _loadOwnPosts() {
        try {
            const feedsData = await this.api.listMyFeeds();
            if (!feedsData.success) return;

            const feeds = feedsData.feeds || [];
            this.ownPosts = [];

            for (const feed of feeds) {
                const postsData = await this.api.listMyPosts(feed.id, 1, 50);
                if (postsData.success) {
                    for (const post of (postsData.posts || [])) {
                        this.ownPosts.push({
                            ...post,
                            feed_title: feed.title,
                            feed_slug: feed.feed_url_slug,
                            is_own: true,
                        });
                    }
                }
            }
        } catch (e) {
            console.error('CQFeedTimeline::_loadOwnPosts error', e);
        }
    }

    async _loadTimeline() {
        if (this.isLoading) return;
        this.isLoading = true;

        try {
            const data = await this.api.getTimeline(this.page, this.limit);
            if (data.success) {
                const newPosts = (data.posts || []).map(p => ({ ...p, is_own: false }));
                this.timelinePosts = this.timelinePosts.concat(newPosts);
                this.total = data.total || 0;
                this.hasMore = this.timelinePosts.length < this.total;
            }
        } catch (e) {
            console.error('CQFeedTimeline::_loadTimeline error', e);
        } finally {
            this.isLoading = false;
        }
    }

    async loadMore() {
        if (this.isLoading || !this.hasMore) return;
        this.page++;
        await this._loadTimeline();
        this.render();
    }

    /**
     * Get merged and sorted posts (own + timeline, newest first)
     */
    _getMergedPosts() {
        const all = [...this.ownPosts, ...this.timelinePosts];
        all.sort((a, b) => {
            const dateA = new Date(a.created_at);
            const dateB = new Date(b.created_at);
            return dateB - dateA;
        });
        return all;
    }

    render() {
        if (!this.container) return;

        const posts = this._getMergedPosts();

        if (posts.length === 0 && !this.isLoading) {
            this.container.innerHTML = `
                <div class="text-center py-5 text-light opacity-50">
                    <i class="mdi mdi-newspaper-variant-outline" style="font-size: 3rem;"></i>
                    <p class="mt-2">${this.t('feed_no_posts', 'No posts yet. Create your first post or follow someone!')}</p>
                </div>
            `;
            return;
        }

        let html = '<div class="cq-feed-timeline mb-4">';

        for (const post of posts) {
            html += this._renderPost(post);
        }

        // Load more button
        if (this.hasMore) {
            html += `
                <div class="text-center py-3">
                    <button class="btn btn-sm btn-outline-secondary" id="cqFeedLoadMoreBtn">
                        <i class="mdi mdi-chevron-down me-1"></i>${this.t('feed_load_more', 'Load more')}
                    </button>
                </div>
            `;
        }

        html += '</div>';
        this.container.innerHTML = html;

        // Bind load more
        const loadMoreBtn = this.container.querySelector('#cqFeedLoadMoreBtn');
        if (loadMoreBtn) {
            loadMoreBtn.addEventListener('click', () => this.loadMore());
        }

        // Bind delete buttons on own posts
        this.container.querySelectorAll('[data-feed-delete-post]').forEach(btn => {
            btn.addEventListener('click', (e) => {
                const postId = e.currentTarget.dataset.feedDeletePost;
                this._deletePost(postId);
            });
        });

        // Bind feed post author header click → open CQ Profile in CQ Explorer
        this.container.querySelectorAll('[data-profile-url]').forEach(el => {
            el.addEventListener('click', (e) => {
                e.preventDefault();
                const url = el.dataset.profileUrl;
                if (url && window.explorerSidebar) {
                    window.explorerSidebar.exploreProfile(url);
                }
            });
        });
    }

    _renderPost(post) {
        const isOwn = post.is_own;
        const authorName = isOwn ? this.username : (post.cq_contact_username || 'Unknown');
        const authorDomain = isOwn ? '' : (post.cq_contact_domain || '');
        const feedTitle = post.feed_title || '';
        const timeAgo = this._timeAgo(post.created_at);
        const contentHtml = this.md.render(post.content || '');

        // Profile photo
        let photoHtml;
        if (isOwn && this.userPhotoUrl) {
            photoHtml = `<img src="${this.userPhotoUrl}" alt="${this._escapeHtml(this.username)}" class="rounded me-2 border_border-light_border-opacity-25" style="width: 32px; height: 32px; object-fit: cover;">`;
        } else if (!isOwn && post.cq_contact_domain && post.cq_contact_username) {
            const remotePhotoUrl = `https://${post.cq_contact_domain}/${post.cq_contact_username}/photo`;
            photoHtml = `<img src="${remotePhotoUrl}" alt="${this._escapeHtml(authorName)}" class="rounded me-2 border_border-light_border-opacity-25" style="width: 32px; height: 32px; object-fit: cover;" onerror="this.outerHTML='<i class=\'mdi mdi-account-box text-info me-2\' style=\'font-size:1.8rem\'></i>'">`;
        } else {
            photoHtml = `<i class="mdi mdi-account-box ${isOwn ? 'text-cyber' : 'text-info'} me-2" style="font-size: 1.8rem;"></i>`;
        }

        const authorDisplay = isOwn
            ? `<span class="fw-bold text-cyber">${this._escapeHtml(authorName)}</span>`
            : `<span class="fw-bold text-light">${this._escapeHtml(authorName)}</span><span class="text-light opacity-50 small">${this._escapeHtml(authorDomain)}</span>`;

        const feedBadge = feedTitle
            ? `<span class="badge bg-secondary bg-opacity-10 text-secondary me-2 small">${this._escapeHtml(feedTitle)}</span>`
            : '';

        const deleteBtn = isOwn
            ? `<button class="btn btn-sm btn-link text-danger p-0 ms-2 opacity-50" data-feed-delete-post="${post.id}" title="${this.t('feed_delete_post', 'Delete')}"><i class="mdi mdi-delete-outline small"></i></button>`
            : '';

        // Determine if this is a "new" post (arrived after last feed view)
        const isNewPost = !isOwn && this.feedLastViewedAt && post.created_at > this.feedLastViewedAt;
        const newClass = isNewPost ? ' cq-feed-post-new' : '';

        // Build clickable profile URL for non-own posts
        const profileUrl = !isOwn && post.cq_contact_domain && post.cq_contact_username
            ? `https://${post.cq_contact_domain}/${post.cq_contact_username}`
            : '';
        const headerClickAttr = profileUrl
            ? ` data-profile-url="${this._escapeHtml(profileUrl)}" style="cursor:pointer;" title="${this._escapeHtml(authorName)}"`
            : '';

        return `
            <div class="glass-panel p-3 mb-2 cq-feed-post${newClass}" data-post-id="${post.id}">
                <div class="d-flex align-items-center mb-2 pb-2 border-0 border-bottom border-1 border-secondary border-opacity-10">
                    <div class="d-flex align-items-center flex-grow-1"${headerClickAttr}>
                        ${photoHtml}
                        <div class="d-flex flex-column" style="line-height:1rem;">${authorDisplay}</div>
                    </div>
                    ${deleteBtn}
                    ${feedBadge}
                    <span class="text-muted small ms-2">${timeAgo}</span>
                </div>
                <div class="cq-feed-post-content markdown-body overflow-auto">${contentHtml}</div>
            </div>
        `;
    }

    async _deletePost(postId) {
        if (!confirm(this.t('feed_delete_confirm', 'Delete this post?'))) return;

        try {
            const data = await this.api.deletePost(postId);
            if (data.success) {
                // Remove from own posts array
                this.ownPosts = this.ownPosts.filter(p => p.id !== postId);
                // Remove DOM element
                const el = this.container.querySelector(`[data-post-id="${postId}"]`);
                if (el) el.remove();
                window.toast?.success(this.t('feed_post_deleted', 'Post deleted'));
            } else {
                window.toast?.error(data.message || this.t('feed_delete_error', 'Failed to delete post'));
            }
        } catch (e) {
            console.error('CQFeedTimeline::_deletePost error', e);
            window.toast?.error(this.t('feed_delete_error', 'Failed to delete post'));
        }
    }

    /**
     * Prepend a new post to the timeline (called after post creation)
     */
    prependOwnPost(post, feedTitle) {
        this.ownPosts.unshift({
            ...post,
            feed_title: feedTitle,
            is_own: true,
        });
        this.render();
    }

    _timeAgo(dateStr) {
        if (!dateStr) return '';
        const date = new Date(dateStr + (dateStr.includes('Z') || dateStr.includes('+') ? '' : 'Z'));
        const now = new Date();
        const diff = Math.floor((now - date) / 1000);

        if (diff < 60) return this.t('feed_just_now', 'just now');
        if (diff < 3600) return Math.floor(diff / 60) + 'm';
        if (diff < 86400) return Math.floor(diff / 3600) + 'h';
        if (diff < 604800) return Math.floor(diff / 86400) + 'd';

        return date.toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
    }

    _escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = str || '';
        return div.innerHTML;
    }
}
