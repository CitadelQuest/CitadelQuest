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

        // Init composer
        if (this.composerContainer) {
            this.composer = new CQFeedPostComposer(this.composerContainer, this.api, this.trans);
            this.composer.onPostCreated = (post) => this._onPostCreated(post);
            await this.composer.init();
        }

        // Init timeline
        if (this.timelineContainer) {
            this.timeline = new CQFeedTimeline(this.timelineContainer, this.api, this.trans, {
                userPhotoUrl: this.config.userPhotoUrl || '',
                username: this.config.username || '',
            });
            await this.timeline.init();
        }

        // Fetch latest from subscribed feeds in background
        this._fetchSubscribedFeeds();
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
     * Background: fetch latest posts from all subscribed feeds
     */
    async _fetchSubscribedFeeds() {
        try {
            // Sync subscriptions first — discover feeds from contacts who created them after being followed
            await this.api.syncSubscriptions().catch(() => null);

            const data = await this.api.listSubscribed();
            if (!data.success) return;

            const feeds = data.feeds || [];
            const activeFeeds = feeds.filter(f => f.is_active);

            // Fetch in parallel (up to 5 concurrent)
            const batchSize = 5;
            for (let i = 0; i < activeFeeds.length; i += batchSize) {
                const batch = activeFeeds.slice(i, i + batchSize);
                await Promise.all(batch.map(f => this.api.fetchSubscribed(f.id).catch(() => null)));
            }

            // Reload timeline if any feeds were fetched
            if (activeFeeds.length > 0 && this.timeline) {
                await this.timeline.init();
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

        // Re-fetch from subscribed feeds, then reload timeline
        await this._fetchSubscribedFeeds();

        // Update the feedLastViewedAt on the timeline so borders reflect current state
        if (this.timeline) {
            this.timeline.feedLastViewedAt = localStorage.getItem('cqFeedLastViewedAt') || null;
            await this.timeline.init();
        }
    }
}
