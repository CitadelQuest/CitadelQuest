import { CQFeedApiService } from './CQFeedApiService';
import { CQFeedPostComposer } from './CQFeedPostComposer';
import { CQFeedTimeline } from './CQFeedTimeline';

/**
 * CQ Feed Manager
 * Main orchestrator for the CQ Feed tab in CQ Explorer.
 * Manages composer, timeline, and feed refresh.
 * 
 */
export class CQFeedManager {
    constructor(config = {}) {
        this.config = config;
        this.trans = config.translations || {};

        this.api = new CQFeedApiService();
        this.composer = null;
        this.timeline = null;
        this.isInitialized = false;

        this.composerContainer = document.getElementById('cqFeedComposer');
        this.timelineContainer = document.getElementById('cqFeedTimeline');
    }

    t(key, fallback) {
        return this.trans[key] || fallback || key;
    }

    /**
     * Initialize the feed tab (lazy — only on first tab activation)
     */
    async init() {
        if (this.isInitialized) return;
        this.isInitialized = true;

        // Show loading
        if (this.timelineContainer) {
            this.timelineContainer.innerHTML = `
                <div class="text-center py-5">
                    <i class="mdi mdi-loading mdi-spin text-cyber" style="font-size: 2rem;"></i>
                </div>
            `;
        }

        // Fetch my feeds ONCE — shared by composer + timeline
        const feeds = await this._fetchMyFeeds();

        // Init composer with pre-fetched feeds
        if (this.composerContainer) {
            this.composer = new CQFeedPostComposer(this.composerContainer, this.api, this.trans);
            this.composer.onPostCreated = (post) => this._onPostCreated(post);
            this.composer.initWithFeeds(feeds);
        }

        // Init timeline with pre-fetched feeds
        if (this.timelineContainer) {
            this.timeline = new CQFeedTimeline(this.timelineContainer, this.api, this.trans, {
                userPhotoUrl: this.config.userPhotoUrl || '',
                username: this.config.username || '',
            });

            // If navigating to a specific post (from notification), await feed fetch
            // so all re-renders finish before we scroll/highlight/open comments
            if (this.pendingScrollToPostId) {
                this._initializing = true;
                await this.timeline.initWithFeeds(feeds);
                await this._fetchSubscribedFeeds();
                this._initializing = false;
                this.timeline._handlePendingScrollToPost();
            } else {
                await this.timeline.initWithFeeds(feeds);
                // Fetch latest from subscribed feeds in background
                this._fetchSubscribedFeeds();
            }
        } else {
            // No timeline container — still fetch in background
            this._fetchSubscribedFeeds();
        }
    }

    /**
     * Fetch my feeds list (single API call, shared by composer + timeline)
     */
    async _fetchMyFeeds() {
        try {
            const data = await this.api.listMyFeeds();
            return (data.success) ? (data.feeds || []) : [];
        } catch (e) {
            return [];
        }
    }

    /**
     * Called when user creates a new post — prepend it to the timeline
     */
    _onPostCreated(post) {
        if (!this.timeline || !this.composer) return;

        // Find the feed title for the selected feed
        const selectedFeed = this.composer.feeds.find(f => f.id === this.composer.selectedFeedId);
        const feedTitle = selectedFeed ? selectedFeed.title : '';

        this.timeline.prependOwnPost(post, feedTitle);
    }

    /**
     * Background: fetch latest posts from all subscribed feeds (single parallel call)
     */
    async _fetchSubscribedFeeds() {
        try {
            // Sync subscriptions first — discover feeds from contacts who created them after being followed
            await this.api.syncSubscriptions().catch(() => null);

            // Single backend call — fires all federation requests in parallel server-side
            const data = await this.api.fetchAllSubscribed().catch(() => null);

            // Reload only federated posts if new data arrived (own posts unchanged)
            if (data?.feeds_checked > 0 && this.timeline) {
                await this.timeline.reloadFederatedPosts();
            }
        } catch (e) {
            console.error('CQFeedManager::_fetchSubscribedFeeds error', e);
        }
    }

    /**
     * Refresh the feed (re-fetch subscribed + reload timeline)
     */
    async refresh() {
        if (!this.isInitialized) return;

        // Sync + fetch from subscribed feeds (without auto-reload — full reload below)
        try {
            await this.api.syncSubscriptions().catch(() => null);
            await this.api.fetchAllSubscribed().catch(() => null);
        } catch (e) {}

        // Full timeline reload (user-triggered, may need fresh own posts too)
        if (this.timeline) {
            this.timeline.feedLastViewedAt = localStorage.getItem('cqFeedLastViewedAt') || null;
            await this.timeline.init();
        }
    }
}
