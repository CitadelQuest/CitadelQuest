import { FileBrowserModal } from '../file-browser/components/FileBrowserModal';

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
        this.attachments = []; // [{project_file_id, file_name, display_style, isImage}]
        this.fileBrowserModal = null;
    }

    t(key, fallback) {
        return this.trans[key] || fallback || key;
    }

    async init() {
        await this.loadFeeds();
        this.render();
    }

    /**
     * Initialize with pre-fetched feeds (avoids duplicate API call)
     */
    initWithFeeds(feeds) {
        this.feeds = feeds || [];
        if (this.feeds.length > 0 && !this.selectedFeedId) {
            const sorted = [...this.feeds].sort((a, b) => (a.title || '').localeCompare(b.title || ''));
            this.selectedFeedId = sorted[0].id;
        }
        this.render();
    }

    async loadFeeds() {
        try {
            const data = await this.api.listMyFeeds();
            if (data.success) {
                this.feeds = data.feeds || [];
                if (this.feeds.length > 0 && !this.selectedFeedId) {
                    const sorted = [...this.feeds].sort((a, b) => (a.title || '').localeCompare(b.title || ''));
                    this.selectedFeedId = sorted[0].id;
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
                        placeholder="${this._randomPlaceholder()}"
                        style="resize: vertical; min-height: 60px;"></textarea>
                </div>
                <div id="cqFeedAttachmentList"></div>
                <div class="d-flex align-items-center gap-2 justify-content-between">
                    <div class="d-flex align-items-center gap-2">
                        <a href="/settings/cq-feed/my-feeds" class="text-cyber opacity-75" title="${this.t('feed_settings', 'Feed Settings')}" style="font-size: 1.1rem; line-height: 1;">
                            <i class="mdi mdi-rss"></i>
                        </a>
                        <select id="cqFeedSelect" class="form-select form-select-sm glass-input" style="width: auto; min-width: 140px;">
                            ${[...this.feeds].sort((a, b) => (a.title || '').localeCompare(b.title || '')).map(f => `<option value="${f.id}" ${f.id === this.selectedFeedId ? 'selected' : ''}>${this._escapeHtml(f.title)}</option>`).join('')}
                        </select>
                        <button id="cqFeedAttachBtn" class="btn btn-sm btn-outline-secondary opacity-75" type="button" title="${this.t('feed_attach', 'Attachment')}">
                            <i class="mdi mdi-paperclip"></i><span class="d-none d-sm-inline-block ms-1">${this.t('feed_attach', 'Attachment')}</span>
                        </button>
                        <span class="small text-muted opacity-50 d-none d-md-inline">
                            <i class="mdi mdi-markdown"></i><span class="d-none d-sm-inline-block ms-1">${this.t('feed_markdown_hint', 'Markdown')}</span>
                        </span>
                    </div>
                    <button id="cqFeedPostBtn" class="btn btn-sm btn-cyber" disabled>
                        <i class="mdi mdi-send"></i><span class="d-none d-sm-inline-block ms-1">${this.t('feed_post_btn', 'Post')}</span>
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
        const attachBtn = this.container.querySelector('#cqFeedAttachBtn');

        if (textarea && postBtn) {
            textarea.addEventListener('input', () => {
                this._updatePostBtnState();
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

        if (attachBtn) {
            attachBtn.addEventListener('click', () => this._openFileBrowser());
        }
    }

    _updatePostBtnState() {
        const textarea = this.container.querySelector('#cqFeedPostContent');
        const postBtn = this.container.querySelector('#cqFeedPostBtn');
        if (textarea && postBtn) {
            postBtn.disabled = !textarea.value.trim();
        }
    }

    async _openFileBrowser() {
        if (!this.fileBrowserModal) {
            this.fileBrowserModal = new FileBrowserModal({
                translations: this.trans,
            });
        }

        const file = await this.fileBrowserModal.open();
        if (!file) return;

        // Avoid duplicate
        if (this.attachments.some(a => a.project_file_id === file.id)) {
            window.toast?.info(this.t('feed_attach_duplicate', 'File already attached'));
            return;
        }

        this.attachments.push({
            project_file_id: file.id,
            file_name: file.name,
            display_style: 1, // default: preview
            isImage: file.isImage,
        });

        this._renderAttachments();
    }

    _renderAttachments() {
        const list = this.container.querySelector('#cqFeedAttachmentList');
        if (!list) return;

        if (this.attachments.length === 0) {
            list.innerHTML = '';
            return;
        }

        const displayStyles = [
            { value: 0, label: this.t('display_off', 'Header only') },
            { value: 1, label: this.t('display_preview', 'Preview') },
            { value: 2, label: this.t('display_full', 'Full') },
        ];

        list.innerHTML = this.attachments.map((att, idx) => {
            const icon = att.isImage ? 'mdi-image' : 'mdi-file';
            const styleOptions = displayStyles.map(s =>
                `<option value="${s.value}" ${att.display_style === s.value ? 'selected' : ''}>${this._escapeHtml(s.label)}</option>`
            ).join('');

            return `
                <div class="d-flex align-items-center gap-2 mb-2 p-2 rounded" style="background: rgba(0,255,136,0.05); border: 1px solid rgba(0,255,136,0.15);">
                    <i class="mdi ${icon} text-cyber"></i>
                    <span class="small text-light text-truncate flex-grow-1" style="max-width: 200px;" title="${this._escapeHtml(att.file_name)}">${this._escapeHtml(att.file_name)}</span>
                    <select class="form-select form-select-sm glass-input cq-att-style" data-idx="${idx}" style="width: auto; min-width: 100px; font-size: 0.75rem; padding: 2px 24px 2px 6px;">
                        ${styleOptions}
                    </select>
                    <button type="button" class="btn btn-sm btn-link text-danger p-0 cq-att-remove" data-idx="${idx}" title="${this.t('feed_attach_remove', 'Remove')}">
                        <i class="mdi mdi-close-circle"></i>
                    </button>
                </div>
            `;
        }).join('');

        // Bind events
        list.querySelectorAll('.cq-att-style').forEach(sel => {
            sel.addEventListener('change', (e) => {
                const idx = parseInt(e.target.dataset.idx);
                if (this.attachments[idx]) {
                    this.attachments[idx].display_style = parseInt(e.target.value);
                }
            });
        });

        list.querySelectorAll('.cq-att-remove').forEach(btn => {
            btn.addEventListener('click', (e) => {
                const idx = parseInt(e.currentTarget.dataset.idx);
                this.attachments.splice(idx, 1);
                this._renderAttachments();
            });
        });
    }

    async _submitPost() {
        const textarea = this.container.querySelector('#cqFeedPostContent');
        const postBtn = this.container.querySelector('#cqFeedPostBtn');
        const content = textarea?.value?.trim();

        if (!content || !this.selectedFeedId) return;

        postBtn.disabled = true;
        postBtn.innerHTML = `<i class="mdi mdi-loading mdi-spin me-1"></i>${this.t('feed_posting', 'Posting...')}`;

        try {
            // Build attachments payload
            const attachments = this.attachments.map(a => ({
                project_file_id: a.project_file_id,
                file_name: a.file_name,
                display_style: a.display_style,
            }));

            const data = await this.api.createPost(this.selectedFeedId, content, attachments);
            if (data.success) {
                textarea.value = '';
                this.attachments = [];
                this._renderAttachments();
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

    _randomPlaceholder() {
        const placeholders = [
            this.t('feed_placeholder_1', 'Share something worth remembering'),
            this.t('feed_placeholder_2', "What's your Citadel building today?"),
            this.t('feed_placeholder_3', 'Speak freely — this is your fortress'),
            this.t('feed_placeholder_4', 'Drop some wisdom on the federation'),
            this.t('feed_placeholder_5', 'What would your Spirit say about this?'),
            this.t('feed_placeholder_6', 'Make the feed legendary'),
            this.t('feed_placeholder_7', 'Your Citadel, your voice'),
            this.t('feed_placeholder_8', 'Inspire the network'),
        ];
        return placeholders[Math.floor(Math.random() * placeholders.length)];
    }

    _escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = str || '';
        return div.innerHTML;
    }
}
