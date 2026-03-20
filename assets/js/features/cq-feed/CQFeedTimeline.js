import MarkdownIt from 'markdown-it';
import { getCitadelLocale } from '../../shared/date-utils';

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
                            feed_scope: feed.scope,
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

        // Bind timeline feed badge toggle → show pause/unsub actions
        this._bindTimelineFeedBadgeHandlers();

        // Bind reaction buttons (like/dislike)
        this._bindReactionHandlers();

        // Lazy refresh stats from source Citadels (non-own posts only)
        this._refreshStats();
    }

    _bindTimelineFeedBadgeHandlers() {
        this.container.querySelectorAll('.timeline-feed-badge-toggle').forEach(toggle => {
            toggle.addEventListener('click', (e) => {
                e.preventDefault();
                e.stopPropagation();
                const group = toggle.closest('.timeline-feed-badge-group');
                const actions = group?.querySelector('.timeline-feed-badge-actions');
                if (!actions) return;

                toggle.classList.add('d-none');
                actions.classList.remove('d-none');
                actions.classList.add('d-inline-flex');

                if (this._tlFeedTimer) clearTimeout(this._tlFeedTimer);
                this._tlFeedTimer = setTimeout(() => {
                    actions.classList.add('d-none');
                    actions.classList.remove('d-inline-flex');
                    toggle.classList.remove('d-none');
                }, 3000);
            });
        });

        // Pause
        this.container.querySelectorAll('.timeline-feed-pause-btn').forEach(btn => {
            btn.addEventListener('click', async (e) => {
                e.preventDefault();
                e.stopPropagation();
                const feedId = btn.dataset.feedId;
                btn.disabled = true;
                btn.innerHTML = `<i class="mdi mdi-loading mdi-spin" style="font-size:0.75rem;"></i>`;
                try {
                    const resp = await fetch(`/api/feed/subscribed/${feedId}/toggle`, { method: 'POST' });
                    const data = await resp.json();
                    if (data.success) {
                        window.toast?.success(data.feed?.is_active == 1
                            ? this.t('feed_resumed', 'Feed resumed')
                            : this.t('feed_paused', 'Feed paused'));
                        // Refresh the timeline
                        await this.init();
                    } else {
                        throw new Error(data.message || 'Failed');
                    }
                } catch (error) {
                    console.error('Timeline feed pause error:', error);
                    window.toast?.error(error.message);
                    btn.disabled = false;
                }
            });
        });

        // Unsubscribe
        this.container.querySelectorAll('.timeline-feed-unsub-btn').forEach(btn => {
            btn.addEventListener('click', async (e) => {
                e.preventDefault();
                e.stopPropagation();
                const feedId = btn.dataset.feedId;
                btn.disabled = true;
                btn.innerHTML = `<i class="mdi mdi-loading mdi-spin" style="font-size:0.75rem;"></i>`;
                try {
                    const resp = await fetch(`/api/feed/subscribed/${feedId}`, { method: 'DELETE' });
                    const data = await resp.json();
                    if (data.success) {
                        window.toast?.success(this.t('feed_unsubscribed', 'Unsubscribed'));
                        // Refresh the timeline
                        await this.init();
                    } else {
                        throw new Error(data.message || 'Failed');
                    }
                } catch (error) {
                    console.error('Timeline feed unsub error:', error);
                    window.toast?.error(error.message);
                    btn.disabled = false;
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

        // Profile photo — use public URL for federation posts (matches sidebar pattern)
        let photoHtml;
        if (isOwn && this.userPhotoUrl) {
            photoHtml = `<div class="rounded border_border-1_border-success me-2 flex-shrink-0 overflow-hidden d-flex align-items-center justify-content-center" style="width: 32px; height: 32px; background: rgba(149,236,134,0.05);"><img src="${this.userPhotoUrl}" alt="" style="width: 100%; height: 100%; object-fit: cover;" onerror="this.style.display='none'; this.nextElementSibling.style.display='inline';"><i class="mdi mdi-account text-cyber" style="display: none;"></i></div>`;
        } else if (!isOwn && post.cq_contact_domain && post.cq_contact_username) {
            const photoUrl = `https://${post.cq_contact_domain}/${post.cq_contact_username}/photo`;
            photoHtml = `<div class="rounded border_border-1_border-success me-2 flex-shrink-0 overflow-hidden d-flex align-items-center justify-content-center" style="width: 32px; height: 32px; background: rgba(149,236,134,0.05);"><img src="${photoUrl}" alt="" style="width: 100%; height: 100%; object-fit: cover;" onerror="this.style.display='none'; this.nextElementSibling.style.display='inline';"><i class="mdi mdi-account text-cyber" style="display: none;"></i></div>`;
        } else {
            photoHtml = `<div class="rounded border_border-1_border-success me-2 flex-shrink-0 overflow-hidden d-flex align-items-center justify-content-center" style="width: 32px; height: 32px; background: rgba(149,236,134,0.05);"><i class="mdi mdi-account text-cyber"></i></div>`;
        }

        const authorDisplay = isOwn
            ? `<span class="fw-bold text-cyber">${this._escapeHtml(authorName)}</span>`
            : `<span class="fw-bold text-light">${this._escapeHtml(authorName)}</span><span class="text-light opacity-50 small">${this._escapeHtml(authorDomain)}</span>`;

        // Feed badge — interactive for federation posts (pause/unsubscribe)
        // scope: 0=Public (secondary), 1=CQ Contacts (primary)
        const feedScope = parseInt(post.feed_scope || 0);
        const badgeBg = feedScope === 1 ? 'bg-primary bg-opacity-10 text-primary opacity-75' : 'bg-secondary bg-opacity-10 text-secondary';
        let feedBadge = '';
        if (feedTitle && !isOwn && post.cq_feed_id) {
            feedBadge = `
                <div class="d-inline-flex align-items-center gap-1 me-2 timeline-feed-badge-group" data-feed-id="${post.cq_feed_id}">
                    <a href="#" class="badge ${badgeBg} small text-decoration-none timeline-feed-badge-toggle" style="cursor:pointer;"><i class="mdi mdi-rss me-1"></i>${this._escapeHtml(feedTitle)}</a>
                    <div class="d-none timeline-feed-badge-actions align-items-center gap-1">
                        <button class="btn btn-sm px-1 py-0 btn-outline-warning timeline-feed-pause-btn" data-feed-id="${post.cq_feed_id}" title="${this.t('feed_pause', 'Pause')}"><i class="mdi mdi-pause" style="font-size:0.75rem;"></i></button>
                        <button class="btn btn-sm px-1 py-0 btn-outline-danger timeline-feed-unsub-btn" data-feed-id="${post.cq_feed_id}" title="${this.t('feed_unsubscribe', 'Unsubscribe')}"><i class="mdi mdi-rss-off" style="font-size:0.75rem;"></i></button>
                    </div>
                </div>`;
        } else if (feedTitle) {
            feedBadge = `<span class="badge ${badgeBg} me-2 small"><i class="mdi mdi-rss me-1"></i>${this._escapeHtml(feedTitle)}</span>`;
        }

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

        // Reaction stats
        const stats = this._parseStats(post.stats);
        const myReaction = stats.my_reaction ?? null;
        const likesCount = stats.likes || 0;
        const dislikesCount = stats.dislikes || 0;

        const likeActive = myReaction === 0 ? ' active' : '';
        const dislikeActive = myReaction === 1 ? ' active' : '';

        // Reaction buttons — show on all posts, interactive on non-own
        const reactionsHtml = `
            <div class="d-flex align-items-center gap-3 pt-1 border-0 border-top border-1 border-secondary border-opacity-10 cq-feed-reactions" data-post-id="${post.id}">
                <button class="btn btn-sm p-0 border-0 d-flex align-items-center gap-1 cq-reaction-btn cq-reaction-like${likeActive}" data-reaction="0" ${isOwn ? 'disabled' : ''} style="background:none;">
                    <i class="mdi ${myReaction === 0 ? 'mdi-heart' : 'mdi-heart'} ${myReaction === 0 ? 'text-danger' : 'text-secondary'}" style="font-size:1rem;"></i>
                    <span class="small ${likesCount > 0 ? 'text-light' : 'text-secondary'} cq-reaction-count">${likesCount > 0 ? likesCount : ''}</span>
                </button>
                <button class="btn btn-sm p-0 border-0 d-flex align-items-center gap-1 cq-reaction-btn cq-reaction-dislike${dislikeActive}" data-reaction="1" ${isOwn ? 'disabled' : ''} style="background:none;">
                    <i class="mdi ${myReaction === 1 ? 'mdi-thumb-down' : 'mdi-thumb-down'} ${myReaction === 1 ? 'text-light opacity-75' : 'text-secondary'}" style="font-size:0.9rem;"></i>
                    <span class="small ${dislikesCount > 0 ? 'text-light' : 'text-secondary'} cq-reaction-count">${dislikesCount > 0 ? dislikesCount : ''}</span>
                </button>
            </div>`;

        return `
            <div class="glass-panel p-3 pb-2 mb-2 cq-feed-post${newClass}" data-post-id="${post.id}">
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
                ${reactionsHtml}
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

        return date.toLocaleDateString(getCitadelLocale(), { month: 'short', day: 'numeric' });
    }

    _escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = str || '';
        return div.innerHTML;
    }

    _parseStats(stats) {
        if (!stats) return { likes: 0, dislikes: 0, comments: 0, my_reaction: null };
        if (typeof stats === 'string') {
            try { return JSON.parse(stats); } catch { return { likes: 0, dislikes: 0, comments: 0, my_reaction: null }; }
        }
        return stats;
    }

    _refreshStats() {
        // Collect non-own post IDs currently rendered
        const postEls = this.container.querySelectorAll('.cq-feed-post');
        const nonOwnIds = [];
        for (const el of postEls) {
            const postId = el.dataset.postId;
            const post = this.timelinePosts.find(p => p.id === postId);
            if (post) nonOwnIds.push(postId);
        }

        // Fire parallel stats requests
        for (const postId of nonOwnIds) {
            this.api.getPostStats(postId).then(data => {
                const postEl = this.container.querySelector(`.cq-feed-post[data-post-id="${postId}"]`);
                if (!postEl) return;

                if (!data.success && data.deleted) {
                    // Post deleted on source — remove from timeline
                    this.timelinePosts = this.timelinePosts.filter(p => p.id !== postId);
                    postEl.remove();
                    return;
                }

                if (data.success && data.stats) {
                    const stats = data.stats;
                    const reactionsEl = postEl.querySelector('.cq-feed-reactions');
                    if (!reactionsEl) return;

                    // Preserve my_reaction from local data
                    const postData = this.timelinePosts.find(p => p.id === postId);
                    const existingStats = this._parseStats(postData?.stats);
                    const myReaction = existingStats.my_reaction ?? null;

                    // Update like count
                    const likeBtn = reactionsEl.querySelector('.cq-reaction-like');
                    const likeCount = likeBtn?.querySelector('.cq-reaction-count');
                    if (likeCount) {
                        likeCount.textContent = (stats.likes || 0) > 0 ? stats.likes : '';
                        likeCount.className = `small ${(stats.likes || 0) > 0 ? 'text-light' : 'text-secondary'} cq-reaction-count`;
                    }

                    // Update dislike count
                    const dislikeBtn = reactionsEl.querySelector('.cq-reaction-dislike');
                    const dislikeCount = dislikeBtn?.querySelector('.cq-reaction-count');
                    if (dislikeCount) {
                        dislikeCount.textContent = (stats.dislikes || 0) > 0 ? stats.dislikes : '';
                        dislikeCount.className = `small ${(stats.dislikes || 0) > 0 ? 'text-light' : 'text-secondary'} cq-reaction-count`;
                    }

                    // Update in-memory data
                    if (postData) {
                        postData.stats = JSON.stringify({ ...stats, my_reaction: myReaction });
                    }
                }
            }).catch(e => {
                // Silently ignore — stats refresh is best-effort
            });
        }
    }

    _bindReactionHandlers() {
        this.container.querySelectorAll('.cq-reaction-btn:not([disabled])').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.preventDefault();
                e.stopPropagation();
                const postEl = btn.closest('.cq-feed-post');
                const postId = postEl?.dataset.postId;
                const reaction = parseInt(btn.dataset.reaction);
                if (postId && !isNaN(reaction)) {
                    this._handleReaction(postId, reaction, btn);
                }
            });
        });
    }

    async _handleReaction(postId, reaction, btn) {
        const postEl = this.container.querySelector(`.cq-feed-post[data-post-id="${postId}"]`);
        const reactionsEl = postEl?.querySelector('.cq-feed-reactions');
        if (!reactionsEl) return;

        // Disable buttons during request
        reactionsEl.querySelectorAll('.cq-reaction-btn').forEach(b => b.disabled = true);

        // Optimistic UI update
        const likeBtn = reactionsEl.querySelector('.cq-reaction-like');
        const dislikeBtn = reactionsEl.querySelector('.cq-reaction-dislike');
        const likeIcon = likeBtn?.querySelector('i');
        const dislikeIcon = dislikeBtn?.querySelector('i');

        try {
            const data = await this.api.reactToPost(postId, reaction);
            if (data.success) {
                const stats = data.stats || {};
                const myReaction = data.my_reaction;

                // Update like button
                if (likeIcon) {
                    likeIcon.className = `mdi ${myReaction === 0 ? 'mdi-heart text-danger' : 'mdi-heart text-secondary'}`;
                    likeIcon.style.fontSize = '1rem';
                }
                const likeCount = likeBtn?.querySelector('.cq-reaction-count');
                if (likeCount) {
                    likeCount.textContent = (stats.likes || 0) > 0 ? stats.likes : '';
                    likeCount.className = `small ${(stats.likes || 0) > 0 ? 'text-light opacity-75' : 'text-secondary'} cq-reaction-count`;
                }

                // Update dislike button
                if (dislikeIcon) {
                    dislikeIcon.className = `mdi ${myReaction === 1 ? 'mdi-thumb-down text-light opacity-75' : 'mdi-thumb-down text-secondary'}`;
                    dislikeIcon.style.fontSize = '0.9rem';
                }
                const dislikeCount = dislikeBtn?.querySelector('.cq-reaction-count');
                if (dislikeCount) {
                    dislikeCount.textContent = (stats.dislikes || 0) > 0 ? stats.dislikes : '';
                    dislikeCount.className = `small ${(stats.dislikes || 0) > 0 ? 'text-light' : 'text-secondary'} cq-reaction-count`;
                }

                // Update the post data in memory for re-renders
                const allPosts = [...this.ownPosts, ...this.timelinePosts];
                const postData = allPosts.find(p => p.id === postId);
                if (postData) {
                    postData.stats = JSON.stringify({ ...stats, my_reaction: myReaction });
                }
            } else {
                window.toast?.error(data.message || 'Reaction failed');
            }
        } catch (e) {
            console.error('CQFeedTimeline::_handleReaction error', e);
            window.toast?.error('Reaction failed');
        } finally {
            // Re-enable buttons (only non-own posts reach here)
            reactionsEl.querySelectorAll('.cq-reaction-btn').forEach(b => b.disabled = false);
        }
    }
}
