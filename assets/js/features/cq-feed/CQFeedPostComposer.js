/**
 * CQ Feed Post Composer
 * Renders the "New Post" textarea + feed selector UI.
 * 
 */
export class CQFeedPostComposer {
    constructor(container, apiService, translations = {}) {
        this.container = container;
        this.api = apiService;
        this.trans = translations;
        this.feeds = [];
        this.selectedFeedId = null;
        this.onPostCreated = null; // callback
    }

    t(key, fallback) {
        return this.trans[key] || fallback || key;
    }

    async init() {
        await this.loadFeeds();
        this.render();
    }

    async loadFeeds() {
        try {
            const data = await this.api.listMyFeeds();
            if (data.success) {
                this.feeds = data.feeds || [];
                if (this.feeds.length > 0 && !this.selectedFeedId) {
                    this.selectedFeedId = this.feeds[0].id;
                }
            }
        } catch (e) {
            console.error('CQFeedPostComposer::loadFeeds error', e);
        }
    }

    render() {
        if (!this.container) return;

        this.container.innerHTML = `
            <div class="glass-panel p-3 mb-4">
                <div class="mb-2">
                    <textarea id="cqFeedPostContent" class="form-control glass-input" rows="3"
                        placeholder="${this.t('feed_post_placeholder', "What's on your mind?")}"
                        style="resize: vertical; min-height: 60px;"></textarea>
                </div>
                <div class="d-flex align-items-center justify-content-between">
                    <div class="d-flex align-items-center gap-2">
                        <a href="/settings/cq-feed/my-feeds" class="text-cyber opacity-75" title="${this.t('feed_settings', 'Feed Settings')}" style="font-size: 1.1rem; line-height: 1;">
                            <i class="mdi mdi-rss"></i>
                        </a>
                        <select id="cqFeedSelect" class="form-select form-select-sm glass-input" style="width: auto; min-width: 140px;">
                            ${this.feeds.map(f => `<option value="${f.id}" ${f.id === this.selectedFeedId ? 'selected' : ''}>${this._escapeHtml(f.title)}</option>`).join('')}
                        </select>
                        <span class="small text-muted opacity-50 d-none d-md-inline">
                            <i class="mdi mdi-markdown me-1"></i>${this.t('feed_markdown_hint', 'Markdown')}
                        </span>
                    </div>
                    <button id="cqFeedPostBtn" class="btn btn-sm btn-cyber" disabled>
                        <i class="mdi mdi-send me-1"></i>${this.t('feed_post_btn', 'Post')}
                    </button>
                </div>
            </div>
        `;

        this._bindEvents();
    }

    _bindEvents() {
        const textarea = this.container.querySelector('#cqFeedPostContent');
        const postBtn = this.container.querySelector('#cqFeedPostBtn');
        const feedSelect = this.container.querySelector('#cqFeedSelect');

        if (textarea && postBtn) {
            textarea.addEventListener('input', () => {
                postBtn.disabled = !textarea.value.trim();
            });

            // Ctrl+Enter to post
            textarea.addEventListener('keydown', (e) => {
                if (e.key === 'Enter' && (e.ctrlKey || e.metaKey) && textarea.value.trim()) {
                    e.preventDefault();
                    this._submitPost();
                }
            });

            postBtn.addEventListener('click', () => this._submitPost());
        }

        if (feedSelect) {
            feedSelect.addEventListener('change', (e) => {
                this.selectedFeedId = e.target.value;
            });
        }
    }

    async _submitPost() {
        const textarea = this.container.querySelector('#cqFeedPostContent');
        const postBtn = this.container.querySelector('#cqFeedPostBtn');
        const content = textarea?.value?.trim();

        if (!content || !this.selectedFeedId) return;

        postBtn.disabled = true;
        postBtn.innerHTML = `<i class="mdi mdi-loading mdi-spin me-1"></i>${this.t('feed_posting', 'Posting...')}`;

        try {
            const data = await this.api.createPost(this.selectedFeedId, content);
            if (data.success) {
                textarea.value = '';
                postBtn.disabled = true;
                postBtn.innerHTML = `<i class="mdi mdi-send me-1"></i>${this.t('feed_post_btn', 'Post')}`;
                window.toast?.success(this.t('feed_post_success', 'Post published!'));
                if (this.onPostCreated) {
                    this.onPostCreated(data.post);
                }
            } else {
                window.toast?.error(data.message || this.t('feed_post_error', 'Failed to publish post'));
                postBtn.disabled = false;
                postBtn.innerHTML = `<i class="mdi mdi-send me-1"></i>${this.t('feed_post_btn', 'Post')}`;
            }
        } catch (e) {
            console.error('CQFeedPostComposer::_submitPost error', e);
            window.toast?.error(this.t('feed_post_error', 'Failed to publish post'));
            postBtn.disabled = false;
            postBtn.innerHTML = `<i class="mdi mdi-send me-1"></i>${this.t('feed_post_btn', 'Post')}`;
        }
    }

    _escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = str || '';
        return div.innerHTML;
    }
}
