import MarkdownIt from 'markdown-it';
import { getCitadelLocale } from '../../shared/date-utils';
import { CQProfileIcons } from '../cq-contact/CQProfileIcons.js';

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

        // Timeline feed filter (set by clicking feed badge)
        this.activeFeedFilter = null; // { feedUrl, feedTitle, feedScope }

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

    /**
     * Initialize with pre-fetched feeds (avoids duplicate listMyFeeds API call)
     */
    async initWithFeeds(feeds) {
        this.page = 1;
        this.hasMore = true;
        this.ownPosts = [];
        this.timelinePosts = [];
        await this._loadOwnPosts(feeds);
        //await this._loadTimeline(); // done later by refresh(), /api/feed/sync-subscribtions
        this.render();
    }

    /**
     * Reload only federated timeline posts (own posts unchanged).
     * Used after background sync to avoid re-fetching own posts + my-feeds.
     */
    async reloadFederatedPosts() {
        this.page = 1;
        this.hasMore = true;
        this.timelinePosts = [];
        await this._loadTimeline();
        this.render();
    }

    async _loadOwnPosts(prefetchedFeeds = null) {
        try {
            let feeds;
            if (prefetchedFeeds) {
                feeds = prefetchedFeeds;
            } else {
                const feedsData = await this.api.listMyFeeds();
                if (!feedsData.success) return;
                feeds = feedsData.feeds || [];
            }
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
                const newPosts = (data.posts || []).map(p => {
                    // Parse attachments_json from federation cached posts
                    let attachments = p.attachments || [];
                    if (!attachments.length && p.attachments_json) {
                        try { attachments = JSON.parse(p.attachments_json); } catch (e) { /* ignore */ }
                    }
                    return { ...p, is_own: false, attachments };
                });
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
     * Get merged and sorted posts (own + timeline, newest first).
     * Applies activeFeedFilter if set.
     */
    _getMergedPosts() {
        let all = [...this.ownPosts, ...this.timelinePosts];

        // Apply feed filter
        if (this.activeFeedFilter) {
            const filterUrl = this.activeFeedFilter.feedUrl;
            all = all.filter(p => this._buildFeedUrl(p) === filterUrl);
        }

        all.sort((a, b) => {
            const dateA = new Date(a.created_at);
            const dateB = new Date(b.created_at);
            return dateB - dateA;
        });
        return all;
    }

    /**
     * Build the full feed URL for a post (used for filter matching + deep links).
     * Format: https://domain/username/feed/slug
     */
    _buildFeedUrl(post) {
        if (post.is_own) {
            return `https://${window.location.hostname}/${this.username}/feed/${post.feed_slug || ''}`;
        }
        return `https://${post.cq_contact_domain || ''}/${post.cq_contact_username || ''}/feed/${post.feed_slug || ''}`;
    }

    /**
     * Set the timeline feed filter — only show posts from the given feed.
     */
    setFeedFilter(feedUrl, feedTitle, feedScope) {
        this.activeFeedFilter = { feedUrl, feedTitle: feedTitle || '', feedScope: feedScope || 0 };
        this.render();
    }

    /**
     * Clear the timeline feed filter — show all posts.
     */
    clearFeedFilter() {
        this.activeFeedFilter = null;
        this.render();
    }

    render() {
        if (!this.container) return;

        const posts = this._getMergedPosts();

        // Filter bar (shown when a feed filter is active)
        let filterHtml = '';
        if (this.activeFeedFilter) {
            const f = this.activeFeedFilter;
            const fScope = parseInt(f.feedScope || 0);
            const fBadgeBg = fScope === 1 ? 'bg-primary bg-opacity-25 text-primary opacity-75' : 'bg-secondary bg-opacity-25 text-light opacity-75';
            filterHtml = `
                <div class="cq-feed-filter-bar d-flex align-items-center gap-2 mb-3 px-3 py-2 glass-panel glass-panel-glow border border-primary border-opacity-50 border-bottom-0">
                    <span class="badge ${fBadgeBg}"><i class="mdi mdi-rss me-1 text-cyber"></i>${this._escapeHtml(f.feedTitle || 'Feed')}</span>
                    <button class="btn btn-sm p-0 border-0 text-secondary cq-feed-filter-close link-danger" title="${this.t('feed_filter_clear', 'Clear filter')}">
                        <i class="mdi mdi-close"></i>
                    </button>
                </div>`;
        }

        if (posts.length === 0 && !this.isLoading) {
            const emptyMsg = this.activeFeedFilter
                ? this.t('feed_no_posts_filtered', 'No posts in this feed yet.')
                : this.t('feed_no_posts', 'No posts yet. Create your first post or follow someone!');
            this.container.innerHTML = filterHtml + `
                <div class="text-center py-5 text-light opacity-50">
                    <i class="mdi mdi-newspaper-variant-outline" style="font-size: 3rem;"></i>
                    <p class="mt-2">${emptyMsg}</p>
                </div>
            `;
            this._bindFilterCloseHandler();
            return;
        }

        let html = filterHtml + '<div class="cq-feed-timeline mb-4">';

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

        // Bind filter close button
        this._bindFilterCloseHandler();

        // Bind timeline feed badge → filter + config actions
        this._bindTimelineFeedBadgeHandlers();

        // Bind reaction buttons (like/dislike)
        this._bindReactionHandlers();

        // Bind comment toggle + submit handlers
        this._bindCommentHandlers();

        // Lazy refresh stats from source Citadels (non-own posts only)
        this._refreshStats();

        // Handle pending scroll-to-post from notification click
        this._handlePendingScrollToPost();
    }

    _handlePendingScrollToPost() {
        const mgr = window.cqFeedManager;
        if (!mgr || !mgr.pendingScrollToPostId) return;
        if (mgr._initializing) return;

        const postId = mgr.pendingScrollToPostId;
        const postEl = this.container.querySelector(`.cq-feed-post[data-post-id="${postId}"]`);
        if (!postEl) return;

        // Clear pending so it doesn't re-trigger on manual refresh
        mgr.pendingScrollToPostId = null;

        // Scroll, highlight, open comments — all synchronous on the final DOM
        postEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
        postEl.classList.add('cq-feed-post-highlight');
        this._toggleComments(postId);
    }

    _bindFilterCloseHandler() {
        const closeBtn = this.container.querySelector('.cq-feed-filter-close');
        if (closeBtn) {
            closeBtn.addEventListener('click', (e) => {
                e.preventDefault();
                window.cqFeedManager?.clearFeedFilter();
            });
        }
    }

    _bindTimelineFeedBadgeHandlers() {
        // Feed badge click → set timeline filter
        this.container.querySelectorAll('.timeline-feed-badge-filter').forEach(badge => {
            badge.addEventListener('click', (e) => {
                e.preventDefault();
                e.stopPropagation();
                const feedUrl = badge.dataset.feedUrl;
                const feedTitle = badge.dataset.feedTitle;
                const feedScope = parseInt(badge.dataset.feedScope || 0);
                if (feedUrl) {
                    window.cqFeedManager?.setFeedFilter(feedUrl, feedTitle, feedScope);
                }
            });
        });

        // Config button → show pause/unsub actions
        this.container.querySelectorAll('.timeline-feed-badge-config').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.preventDefault();
                e.stopPropagation();
                const group = btn.closest('.timeline-feed-badge-group');
                const actions = group?.querySelector('.timeline-feed-badge-actions');
                if (!actions) return;

                btn.classList.add('d-none');
                actions.classList.remove('d-none');
                actions.classList.add('d-inline-flex');

                if (this._tlFeedTimer) clearTimeout(this._tlFeedTimer);
                this._tlFeedTimer = setTimeout(() => {
                    actions.classList.add('d-none');
                    actions.classList.remove('d-inline-flex');
                    btn.classList.remove('d-none');
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
            const contactUrl = `https://${post.cq_contact_domain}/${post.cq_contact_username}`;
            const iconData = CQProfileIcons.get(contactUrl);
            if (iconData) {
                photoHtml = `<div class="rounded border_border-1_border-success me-2 flex-shrink-0 overflow-hidden d-flex align-items-center justify-content-center" style="width: 32px; height: 32px; background: rgba(149,236,134,0.05);"><img src="${iconData}" alt="" style="width: 100%; height: 100%; object-fit: cover;" onerror="this.style.display='none'; this.nextElementSibling.style.display='inline';"><i class="mdi mdi-account text-cyber" style="display: none;"></i></div>`;
            } else {
                photoHtml = `<div class="rounded border_border-1_border-success me-2 flex-shrink-0 overflow-hidden d-flex align-items-center justify-content-center" style="width: 32px; height: 32px; background: rgba(149,236,134,0.05);"><i class="mdi mdi-account text-cyber"></i></div>`;
            }
        } else {
            photoHtml = `<div class="rounded border_border-1_border-success me-2 flex-shrink-0 overflow-hidden d-flex align-items-center justify-content-center" style="width: 32px; height: 32px; background: rgba(149,236,134,0.05);"><i class="mdi mdi-account text-cyber"></i></div>`;
        }

        const authorDisplay = isOwn
            ? `<span class="fw-bold text-cyber">${this._escapeHtml(authorName)}</span>`
            : `<span class="fw-bold text-light">${this._escapeHtml(authorName)}</span><span class="text-light opacity-50 small">${this._escapeHtml(authorDomain)}</span>`;

        // Feed badge — clickable to set timeline filter
        // scope: 0=Public (secondary), 1=CQ Contacts (primary)
        const feedScope = parseInt(post.feed_scope || 0);
        const badgeBg = feedScope === 1 ? 'bg-primary bg-opacity-10 text-primary opacity-75' : 'bg-secondary bg-opacity-10 text-secondary';
        const postFeedUrl = feedTitle ? this._buildFeedUrl(post) : '';
        let feedBadge = '';
        if (feedTitle && !isOwn && post.cq_feed_id) {
            feedBadge = `
                <div class="d-inline-flex align-items-center gap-1 me-2 timeline-feed-badge-group" data-feed-id="${post.cq_feed_id}">
                    <a href="#" class="badge ${badgeBg} small text-decoration-none timeline-feed-badge-filter" data-feed-url="${this._escapeHtml(postFeedUrl)}" data-feed-title="${this._escapeHtml(feedTitle)}" data-feed-scope="${feedScope}" style="cursor:pointer;"><i class="mdi mdi-rss me-1"></i>${this._escapeHtml(feedTitle)}</a>
                    <a href="#" class="timeline-feed-badge-config text-secondary" style="font-size:0.75rem; cursor:pointer;" title="${this.t('feed_options', 'Feed options')}"><i class="mdi mdi-dots-vertical"></i></a>
                    <div class="d-none timeline-feed-badge-actions align-items-center gap-1">
                        <button class="btn btn-sm px-1 py-0 btn-outline-warning timeline-feed-pause-btn" data-feed-id="${post.cq_feed_id}" title="${this.t('feed_pause', 'Pause')}"><i class="mdi mdi-pause" style="font-size:0.75rem;"></i></button>
                        <button class="btn btn-sm px-1 py-0 btn-outline-danger timeline-feed-unsub-btn" data-feed-id="${post.cq_feed_id}" title="${this.t('feed_unsubscribe', 'Unsubscribe')}"><i class="mdi mdi-rss-off" style="font-size:0.75rem;"></i></button>
                    </div>
                </div>`;
        } else if (feedTitle) {
            feedBadge = `<a href="#" class="badge ${badgeBg} me-2 small text-decoration-none timeline-feed-badge-filter" data-feed-url="${this._escapeHtml(postFeedUrl)}" data-feed-title="${this._escapeHtml(feedTitle)}" data-feed-scope="${feedScope}" style="cursor:pointer;"><i class="mdi mdi-rss me-1"></i>${this._escapeHtml(feedTitle)}</a>`;
        }

        const deleteBtn = isOwn
            ? `<button class="btn btn-sm btn-link text-secondary p-0 ms-2" data-feed-delete-post="${post.id}" title="${this.t('feed_delete_post', 'Delete')}"><i class="mdi mdi-delete-outline small"></i></button>`
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

        // Reaction + comment bar
        const commentsCount = stats.comments || 0;

        const reactionsHtml = `
            <div class="d-flex align-items-center gap-3 pt-1 border-0 border-top border-1 border-secondary border-opacity-10 cq-feed-reactions" data-post-id="${post.id}">
                <button class="btn btn-sm p-0 border-0 d-flex align-items-center gap-1 cq-reaction-btn cq-reaction-like${likeActive}" data-reaction="0" ${isOwn ? 'disabled' : ''} style="background:none;">
                    <i class="mdi ${myReaction === 0 ? 'mdi-heart' : 'mdi-heart'} ${myReaction === 0 ? 'text-danger' : 'text-secondary'}" style="font-size:1rem;"></i>
                    <span class="small ${likesCount > 0 ? 'text-light opacity-75' : 'text-secondary'} cq-reaction-count">${likesCount > 0 ? likesCount : ''}</span>
                </button>
                <button class="btn btn-sm p-0 border-0 d-flex align-items-center gap-1 cq-reaction-btn cq-reaction-dislike${dislikeActive}" data-reaction="1" ${isOwn ? 'disabled' : ''} style="background:none;">
                    <i class="mdi ${myReaction === 1 ? 'mdi-thumb-down' : 'mdi-thumb-down'} ${myReaction === 1 ? 'text-light opacity-75' : 'text-secondary'}" style="font-size:0.9rem;"></i>
                    <span class="small ${dislikesCount > 0 ? 'text-light opacity-75' : 'text-secondary'} cq-reaction-count">${dislikesCount > 0 ? dislikesCount : ''}</span>
                </button>
                <button class="btn btn-sm p-0 border-0 d-flex align-items-center gap-1 cq-comment-toggle-btn" style="background:none; margin-left:auto;">
                    <i class="mdi mdi-chat-outline text-secondary" style="font-size:0.95rem;"></i>
                    <span class="small ${commentsCount > 0 ? 'text-light opacity-75' : 'text-secondary'} cq-comment-count">${commentsCount > 0 ? commentsCount : ''}</span>
                </button>
            </div>`;

        // Collapsible comments panel (hidden by default)
        const commentsPanelHtml = `
            <div class="cq-feed-comments-panel d-none" data-post-id="${post.id}">
                <div class="cq-feed-comments-list small mt-2"></div>
                <div class="d-flex gap-2 mt-2">
                    <textarea class="form-control form-control-sm cq-comment-input" rows="1" maxlength="2000" placeholder="${this.t('feed_comment_placeholder', 'Write a comment...')}" style="resize:none; background:rgba(255,255,255,0.05); border-color:rgba(149,236,134,0.15); color:#e0e0e0; font-size:0.85rem;"></textarea>
                    <button class="btn btn-sm btn-cyber cq-comment-submit-btn px-2 align-self-end" style="font-size:0.8rem;">
                        <i class="mdi mdi-send"></i>
                    </button>
                </div>
            </div>`;

        return `
            <div class="glass-panel p-3 pb-2 mb-2 overflow-hidden cq-feed-post${newClass}" data-post-id="${post.id}">
                <div class="d-flex align-items-start flex-column flex-sm-row gap-1 mb-2 pb-2 border-0 border-bottom border-1 border-secondary border-opacity-10 overflow-hidden">
                    <div class="d-flex align-items-center flex-grow-1"${headerClickAttr}>
                        ${photoHtml}
                        <div class="d-flex flex-column" style="line-height:1rem;">${authorDisplay}</div>
                    </div>
                    ${deleteBtn}
                    <div class="d-flex justify-content-end align-items-center w-100">
                        ${feedBadge}
                        <span class="text-muted small ms-2 d-none_d-sm-inline-block">${timeAgo}</span>
                    </div>
                </div>
                <div class="cq-feed-post-content markdown-body overflow-auto">${contentHtml}</div>
                ${this._renderAttachments(post)}
                ${reactionsHtml}
                ${commentsPanelHtml}
            </div>
        `;
    }

    /**
     * Render attachments for a post based on display_style.
     * display_style: 0 = header only, 1 = preview (thumbnail), 2 = full content
     */
    _renderAttachments(post) {
        const attachments = post.attachments;
        if (!attachments || attachments.length === 0) return '';

        const isOwn = post.is_own;
        let html = '<div class="cq-feed-attachments mt-2 mb-1">';

        for (const att of attachments) {
            const displayStyle = parseInt(att.display_style ?? att.share_display_style ?? 1);
            const fileName = att.file_name || att.share_title || 'Attachment';
            const mimeType = att.file_mime_type || '';
            const isImage = mimeType.startsWith('image/');
            const sourceId = att.source_id || '';
            const shareUrl = att.share_url || '';
            // Federation share_url is absolute (https://...), own is relative slug
            const isAbsoluteUrl = shareUrl.startsWith('http');
            const viewUrl = isAbsoluteUrl ? shareUrl : (shareUrl ? `/${this.username}/share/${shareUrl}` : '');

            if (displayStyle === 0) {
                // Header only — just show file name with icon
                const icon = isImage ? 'mdi-image' : 'mdi-file';
                html += `
                    <div class="d-flex align-items-center gap-2 p-2 rounded mb-1 bg-dark bg-opacity-75">
                        <i class="mdi ${icon} text-cyber small"></i>
                        <span class="small text-light opacity-75">${this._escapeHtml(fileName)}</span>
                    </div>`;
            } else if ((displayStyle === 1 || displayStyle === 2) && isImage && isOwn && sourceId) {
                // Own image: use local file API for preview/full
                const maxH = displayStyle === 1 ? '250px' : '500px';
                const maxW = displayStyle === 1 ? 'max-width: 300px;' : '';
                html += `
                    <div class="cq-feed-attachment-preview mb-1 rounded overflow-hidden bg-dark bg-opacity-75" style="${maxW}">
                        <img src="/api/project-file/${sourceId}/download"
                             alt="${this._escapeHtml(fileName)}"
                             class="img-fluid rounded _cursor-pointer"
                             style="max-height: ${maxH}; object-fit: contain; width: 100%;"
                             loading="lazy"
                             onerror="this.parentElement.innerHTML='<div class=\\'p-2 small text-muted\\'><i class=\\'mdi mdi-image-broken-variant me-1\\'></i>${this._escapeHtml(fileName)}</div>'">
                    </div>`;
            } else if ((displayStyle === 1 || displayStyle === 2) && isImage && !isOwn && viewUrl) {
                // Federation image: use local proxy for authenticated access (respects CQ Contact scope)
                const contactId = post.cq_contact_id || '';
                const imgSrc = contactId
                    ? `/api/feed/attachment-proxy?url=${encodeURIComponent(viewUrl)}&contact_id=${encodeURIComponent(contactId)}`
                    : viewUrl;
                const maxH = displayStyle === 1 ? '250px' : '500px';
                const maxW = displayStyle === 1 ? 'max-width: 300px;' : '';
                html += `
                    <div class="cq-feed-attachment-preview mb-1 rounded overflow-hidden bg-dark bg-opacity-75" style="${maxW}">
                        <img src="${this._escapeHtml(imgSrc)}"
                             alt="${this._escapeHtml(fileName)}"
                             class="img-fluid rounded _cursor-pointer"
                             style="max-height: ${maxH}; object-fit: contain; width: 100%;"
                             loading="lazy"
                             onerror="this.parentElement.innerHTML='<div class=\\'p-2 small text-muted\\'><i class=\\'mdi mdi-image-broken-variant me-1\\'></i>${this._escapeHtml(fileName)}</div>'">
                    </div>`;
            } else {
                // Fallback — header with optional view link
                const icon = isImage ? 'mdi-image' : 'mdi-file';
                const linkPart = viewUrl
                    ? `<a href="${this._escapeHtml(viewUrl)}" target="_blank" class="text-cyber text-decoration-none small ms-auto"><i class="mdi mdi-open-in-new me-1"></i>View</a>`
                    : '';
                html += `
                    <div class="d-flex align-items-center gap-2 p-2 rounded mb-1 bg-dark bg-opacity-75">
                        <i class="mdi ${icon} text-cyber small"></i>
                        <span class="small text-light opacity-75">${this._escapeHtml(fileName)}</span>
                        ${linkPart}
                    </div>`;
            }
        }

        html += '</div>';
        return html;
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
                        likeCount.className = `small ${(stats.likes || 0) > 0 ? 'text-light opacity-75' : 'text-secondary'} cq-reaction-count`;
                    }

                    // Update dislike count
                    const dislikeBtn = reactionsEl.querySelector('.cq-reaction-dislike');
                    const dislikeCount = dislikeBtn?.querySelector('.cq-reaction-count');
                    if (dislikeCount) {
                        dislikeCount.textContent = (stats.dislikes || 0) > 0 ? stats.dislikes : '';
                        dislikeCount.className = `small ${(stats.dislikes || 0) > 0 ? 'text-light opacity-75' : 'text-secondary'} cq-reaction-count`;
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

    _bindCommentHandlers() {
        // Toggle comment panel on click
        this.container.querySelectorAll('.cq-comment-toggle-btn').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.preventDefault();
                e.stopPropagation();
                const postEl = btn.closest('.cq-feed-post');
                const postId = postEl?.dataset.postId;
                if (postId) this._toggleComments(postId);
            });
        });

        // Submit comment on button click
        this.container.querySelectorAll('.cq-comment-submit-btn').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.preventDefault();
                const panel = btn.closest('.cq-feed-comments-panel');
                const postId = panel?.dataset.postId;
                if (postId) this._submitComment(postId);
            });
        });

        // Submit comment on Enter (without Shift)
        this.container.querySelectorAll('.cq-comment-input').forEach(input => {
            input.addEventListener('keydown', (e) => {
                if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    const panel = input.closest('.cq-feed-comments-panel');
                    const postId = panel?.dataset.postId;
                    if (postId) this._submitComment(postId);
                }
            });
        });
    }

    _toggleComments(postId) {
        const panel = this.container.querySelector(`.cq-feed-comments-panel[data-post-id="${postId}"]`);
        if (!panel) return;

        const isHidden = panel.classList.contains('d-none');
        if (isHidden) {
            panel.classList.remove('d-none');
            // Load comments if not loaded yet
            if (!panel.dataset.loaded) {
                this._loadComments(postId);
            }
            // Focus input
            const input = panel.querySelector('.cq-comment-input');
            if (input) setTimeout(() => input.focus(), 100);
        } else {
            panel.classList.add('d-none');
        }
    }

    async _loadComments(postId) {
        const panel = this.container.querySelector(`.cq-feed-comments-panel[data-post-id="${postId}"]`);
        if (!panel) return;

        const listEl = panel.querySelector('.cq-feed-comments-list');
        if (!listEl) return;

        listEl.innerHTML = '<div class="text-center py-2"><i class="mdi mdi-loading mdi-spin text-secondary"></i></div>';

        try {
            const data = await this.api.listComments(postId);
            panel.dataset.loaded = '1';

            if (data.success) {
                this._renderComments(listEl, postId, data.comments || []);
            } else {
                listEl.innerHTML = `<div class="text-secondary small py-1">${this.t('feed_comment_load_error', 'Failed to load comments')}</div>`;
            }
        } catch (e) {
            console.error('CQFeedTimeline::_loadComments error', e);
            listEl.innerHTML = `<div class="text-secondary small py-1">${this.t('feed_comment_load_error', 'Failed to load comments')}</div>`;
        }
    }

    _renderComments(listEl, postId, comments) {
        if (comments.length === 0) {
            listEl.innerHTML = '';
            return;
        }

        let html = '';
        for (const comment of comments) {
            html += this._renderComment(postId, comment, false);
            if (comment.replies && comment.replies.length > 0) {
                for (const reply of comment.replies) {
                    html += this._renderComment(postId, reply, true);
                }
            }
        }
        listEl.innerHTML = html;

        // Bind reply buttons
        listEl.querySelectorAll('.cq-comment-reply-btn').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.preventDefault();
                const parentId = btn.dataset.commentId;
                const panel = btn.closest('.cq-feed-comments-panel');
                const input = panel?.querySelector('.cq-comment-input');
                if (input) {
                    input.dataset.parentId = parentId;
                    input.placeholder = this.t('feed_comment_reply', 'Write a reply...');
                    input.focus();
                }

                listEl.querySelectorAll('.cq-comment-reply-btn').forEach(btn => {
                    btn.classList.add('text-light');
                    btn.classList.remove('text-cyber');
                });
                btn.classList.remove('text-light');
                btn.classList.add('text-cyber');
            });
        });

        // Bind delete buttons (own comments)
        listEl.querySelectorAll('.cq-comment-delete-btn').forEach(btn => {
            btn.addEventListener('click', async (e) => {
                e.preventDefault();
                const commentId = btn.dataset.commentId;
                if (!confirm(this.t('feed_comment_delete_confirm', 'Delete this comment?'))) return;
                try {
                    const data = await this.api.deleteComment(commentId, postId);
                    if (data.success) {
                        this._updatePostStats(postId, data.stats);
                        this._loadComments(postId);
                    }
                } catch (err) {
                    console.error('Comment delete error', err);
                }
            });
        });

        // Bind edit buttons (own comments)
        listEl.querySelectorAll('.cq-comment-edit-btn').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.preventDefault();
                const commentId = btn.dataset.commentId;
                const commentEl = listEl.querySelector(`.cq-comment[data-comment-id="${commentId}"]`);
                const contentEl = commentEl?.querySelector('.cq-comment-content');
                if (!contentEl) return;

                const currentText = contentEl.textContent;
                const editInput = document.createElement('textarea');
                editInput.className = 'form-control form-control-sm';
                editInput.value = currentText;
                editInput.maxLength = 2000;
                editInput.rows = 2;
                editInput.style.cssText = 'resize:none; background:rgba(255,255,255,0.05); border-color:rgba(149,236,134,0.15); color:#e0e0e0; font-size:0.8rem;';
                contentEl.replaceWith(editInput);
                editInput.focus();

                const save = async () => {
                    const newContent = editInput.value.trim();
                    if (!newContent) return;
                    try {
                        const data = await this.api.updateComment(commentId, postId, newContent);
                        if (data.success) {
                            this._loadComments(postId);
                        }
                    } catch (err) {
                        console.error('Comment edit error', err);
                    }
                };

                editInput.addEventListener('keydown', (ev) => {
                    if (ev.key === 'Enter' && !ev.shiftKey) { ev.preventDefault(); save(); }
                    if (ev.key === 'Escape') { this._loadComments(postId); }
                });
                editInput.addEventListener('blur', () => save());
            });
        });

        // Bind comment profile links → open CQ Profile in CQ Explorer
        listEl.querySelectorAll('.cq-comment-profile-link[data-profile-url]').forEach(el => {
            el.addEventListener('click', (e) => {
                e.preventDefault();
                const url = el.dataset.profileUrl;
                if (url && window.explorerSidebar) {
                    window.explorerSidebar.exploreProfile(url);
                }
            });
        });

        // Bind toggle visibility buttons (post owner moderation)
        listEl.querySelectorAll('.cq-comment-toggle-vis-btn').forEach(btn => {
            btn.addEventListener('click', async (e) => {
                e.preventDefault();
                const commentId = btn.dataset.commentId;
                try {
                    const data = await this.api.toggleComment(commentId, postId);
                    if (data.success) {
                        this._updatePostStats(postId, data.stats);
                        this._loadComments(postId);
                    }
                } catch (err) {
                    console.error('Comment toggle error', err);
                }
            });
        });
    }

    _renderComment(postId, comment, isReply) {
        const indent = isReply ? 'ms-4' : '';
        const timeAgo = this._timeAgo(comment.created_at);

        // Determine if this comment belongs to the current user
        const postEl = this.container.querySelector(`.cq-feed-post[data-post-id="${postId}"]`);
        const isOwnPost = postEl?.querySelector('[data-feed-delete-post]') !== null;

        // Extract commenter name and domain from URL
        const contactUrl = comment.cq_contact_url || '';
        const urlParts = contactUrl.split('/');
        const commenterName = urlParts[urlParts.length - 1] || 'Unknown';
        // Extract domain: https://domain/username → domain
        let commenterDomain = '';
        try { commenterDomain = new URL(contactUrl).hostname; } catch (_) {}

        // Check if current user is the commenter (by matching username)
        const isOwnComment = commenterName === this.username;

        // Profile photo
        let photoHtml;
        let photoSize = '25px';
        if (isOwnComment && this.userPhotoUrl) {
            photoHtml = `<div class="rounded flex-shrink-0 overflow-hidden d-flex align-items-center justify-content-center" style="width:${photoSize}; height:${photoSize}; background:rgba(149,236,134,0.05);"><img src="${this.userPhotoUrl}" alt="" style="width:100%; height:100%; object-fit:cover;" onerror="this.style.display='none'; this.nextElementSibling.style.display='inline';"><i class="mdi mdi-account text-cyber" style="display:none; font-size:0.7rem;"></i></div>`;
        } else if (commenterDomain && commenterName) {
            const contactUrl = `https://${commenterDomain}/${commenterName}`;
            const iconData = CQProfileIcons.get(contactUrl);
            if (iconData) {
                photoHtml = `<div class="rounded flex-shrink-0 overflow-hidden d-flex align-items-center justify-content-center" style="width:${photoSize}; height:${photoSize}; background:rgba(149,236,134,0.05);"><img src="${iconData}" alt="" style="width:100%; height:100%; object-fit:cover;" onerror="this.style.display='none'; this.nextElementSibling.style.display='inline';"><i class="mdi mdi-account text-cyber" style="display:none; font-size:0.7rem;"></i></div>`;
            } else {
                photoHtml = `<div class="rounded flex-shrink-0 overflow-hidden d-flex align-items-center justify-content-center" style="width:${photoSize}; height:${photoSize}; background:rgba(149,236,134,0.05);"><i class="mdi mdi-account text-cyber" style="font-size:0.7rem;"></i></div>`;
            }
        } else {
            photoHtml = `<div class="rounded flex-shrink-0 overflow-hidden d-flex align-items-center justify-content-center" style="width:${photoSize}; height:${photoSize}; background:rgba(149,236,134,0.05);"><i class="mdi mdi-account text-cyber" style="font-size:0.7rem;"></i></div>`;
        }

        // Profile click attribute — opens CQ Profile in CQ Explorer
        const profileClickAttr = contactUrl
            ? ` data-profile-url="${this._escapeHtml(contactUrl)}" style="cursor:pointer;" title="${this._escapeHtml(commenterName)}"`
            : '';

        let actionsHtml = '';
        if (isOwnComment) {
            actionsHtml = `
                <button class="btn btn-sm p-0 border-0 text-secondary cq-comment-edit-btn" data-comment-id="${comment.id}" style="background:none; font-size:0.7rem;" title="${this.t('feed_comment_edit', 'Edit')}"><i class="mdi mdi-pencil-outline"></i></button>
                <button class="btn btn-sm p-0 border-0 text-secondary cq-comment-delete-btn" data-comment-id="${comment.id}" style="background:none; font-size:0.7rem;" title="${this.t('feed_comment_delete', 'Delete')}"><i class="mdi mdi-delete-outline"></i></button>`;
        }
        if (isOwnPost && !isOwnComment) {
            actionsHtml += `
                <button class="btn btn-sm p-0 border-0 text-secondary cq-comment-toggle-vis-btn" data-comment-id="${comment.id}" style="background:none; font-size:0.7rem;" title="${this.t('feed_comment_hide', 'Hide')}"><i class="mdi mdi-eye-off-outline"></i></button>`;
        }

        const replyBtn = !isReply
            ? `<button class="btn btn-sm p-0 border-0 text-light opacity-75 cq-comment-reply-btn" data-comment-id="${comment.id}" style="background:none; font-size:0.7rem;" title="${this.t('feed_comment_reply_btn', 'Reply')}"><i class="mdi mdi-reply"></i></button>`
            : '';

        return `
            <div class="cq-comment ${indent} d-flex gap-2 py-1 border-0 border-bottom border-1 border-secondary border-opacity-10" data-comment-id="${comment.id}">
                <div class="cq-comment-profile-link d-flex align-items-start flex-shrink-0"${profileClickAttr}>${photoHtml}</div>
                <div class="flex-grow-1" style="min-width:0;">
                    <div class="d-flex align-items-center gap-2">
                        <div style="min-width: 0; line-height:0.9rem;">
                            <div class="cq-comment-profile-link fw-bold text-light" style="font-size:0.8rem;${contactUrl ? ' cursor:pointer;' : ''}"${profileClickAttr}>${this._escapeHtml(commenterName)}</div>
                            <div class="text-light opacity-50" style="font-size: 0.65rem;">${commenterDomain}</div>
                        </div>
                        <span class="text-muted" style="font-size:0.7rem;">${timeAgo}</span>
                        ${replyBtn}
                        ${actionsHtml}
                    </div>
                    <div class="cq-comment-content text-light pt-2" style="font-size:0.9rem; word-break:break-word;">${this._escapeHtml(comment.content)}</div>
                </div>
            </div>`;
    }

    async _submitComment(postId) {
        const panel = this.container.querySelector(`.cq-feed-comments-panel[data-post-id="${postId}"]`);
        if (!panel) return;

        const input = panel.querySelector('.cq-comment-input');
        const submitBtn = panel.querySelector('.cq-comment-submit-btn');
        const content = input?.value?.trim();
        if (!content) return;

        const parentId = input.dataset.parentId || null;

        // Disable during submit
        if (input) input.disabled = true;
        if (submitBtn) submitBtn.disabled = true;

        try {
            const data = await this.api.addComment(postId, content, parentId);
            if (data.success) {
                input.value = '';
                delete input.dataset.parentId;
                input.placeholder = this.t('feed_comment_placeholder', 'Write a comment...');

                // Update stats
                if (data.stats) {
                    this._updatePostStats(postId, data.stats);
                }

                // Reload comments
                this._loadComments(postId);
            } else {
                window.toast?.error(data.message || this.t('feed_comment_failed', 'Comment failed'));
            }
        } catch (e) {
            console.error('CQFeedTimeline::_submitComment error', e);
            window.toast?.error(this.t('feed_comment_failed', 'Comment failed'));
        } finally {
            if (input) input.disabled = false;
            if (submitBtn) submitBtn.disabled = false;
            if (input) input.focus();
        }
    }

    _updatePostStats(postId, stats) {
        // Update comment count in the reactions bar
        const postEl = this.container.querySelector(`.cq-feed-post[data-post-id="${postId}"]`);
        if (!postEl) return;

        const countEl = postEl.querySelector('.cq-comment-count');
        if (countEl) {
            const count = stats.comments || 0;
            countEl.textContent = count > 0 ? count : '';
            countEl.className = `small ${count > 0 ? 'text-light opacity-75' : 'text-secondary'} cq-comment-count`;
        }

        // Update like/dislike counts too
        const reactionsEl = postEl.querySelector('.cq-feed-reactions');
        if (reactionsEl) {
            const likeCount = reactionsEl.querySelector('.cq-reaction-like .cq-reaction-count');
            if (likeCount) {
                likeCount.textContent = (stats.likes || 0) > 0 ? stats.likes : '';
            }
            const dislikeCount = reactionsEl.querySelector('.cq-reaction-dislike .cq-reaction-count');
            if (dislikeCount) {
                dislikeCount.textContent = (stats.dislikes || 0) > 0 ? stats.dislikes : '';
            }
        }

        // Update in-memory data
        const allPosts = [...this.ownPosts, ...this.timelinePosts];
        const postData = allPosts.find(p => p.id === postId);
        if (postData) {
            const existing = this._parseStats(postData.stats);
            postData.stats = JSON.stringify({ ...stats, my_reaction: existing.my_reaction ?? null });
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
                    dislikeCount.className = `small ${(stats.dislikes || 0) > 0 ? 'text-light opacity-75' : 'text-secondary'} cq-reaction-count`;
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
