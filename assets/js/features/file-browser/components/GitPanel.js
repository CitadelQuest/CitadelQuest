/**
 * GitPanel - user-facing git control panel for the File Browser.
 * Rendered inside the file preview panel when a directory is selected.
 * Uses /api/git endpoints which delegate to AIToolGitService
 * (the same functionality AI Spirits use via gitOperation / gitSetCredentials AI Tools).
 */
export class GitPanel {
    /**
     * @param {Object} options
     * @param {HTMLElement} options.container - Element to render into
     * @param {string} options.projectId - Project ID
     * @param {string} options.repoPath - Directory path relative to project root (e.g. "repo")
     * @param {Object} options.translations - Translation strings
     * @param {Function} options.onTreeRefresh - Callback to refresh the file tree after mutations
     */
    constructor(options) {
        this.container = options.container;
        this.projectId = options.projectId;
        this.repoPath = options.repoPath;
        this.translations = options.translations || {};
        this.onTreeRefresh = options.onTreeRefresh || null;

        this.status = null;
        this.isRepo = false;
        this.isBusy = false;
    }

    /**
     * Fetch repo status and render the panel
     */
    async load() {
        this.renderLoading();
        try {
            const response = await fetch(
                `/api/git/status/${this.projectId}?repoPath=${encodeURIComponent(this.repoPath)}`
            );
            const data = await response.json();

            if (!data.success) {
                this.renderError(data.error || 'Git status check failed');
                return;
            }

            this.isRepo = data.isRepo;
            this.status = data.isRepo ? data : null;
            this.render();
        } catch (error) {
            this.renderError(error.message);
        }
    }

    renderLoading() {
        this.container.innerHTML = `
            <div class="git-panel">
                <div class="small text-muted p-2">
                    <i class="mdi mdi-loading mdi-spin me-1"></i>${this.t('git.checking', 'Checking git repository...')}
                </div>
            </div>
        `;
    }

    renderError(message) {
        this.container.innerHTML = `
            <div class="git-panel">
                <div class="small text-danger p-2">
                    <i class="mdi mdi-alert-circle me-1"></i>${this.escapeHtml(message)}
                </div>
            </div>
        `;
    }

    render() {
        this.container.innerHTML = this.isRepo ? this.repoHtml() : this.notRepoHtml();

        // For git repositories, the "Directory Selected" placeholder is redundant - hide it.
        // d-none uses !important, so the gallery toggle's inline display style can't re-show it.
        if (this.isRepo) {
            this.container.closest('.file-preview-content')
                ?.querySelector('.directory-info')
                ?.classList.add('d-none');
        }

        this.bindEvents();
    }

    /**
     * Compact view for directories that are not git repositories
     */
    notRepoHtml() {
        return `
            <div class="git-panel">
                <div class="git-panel-header" data-git-action="toggle-clone">
                    <i class="mdi mdi-git text-cyber me-2"></i>
                    <span class="small">${this.t('git.not_a_repo', 'Not a git repository')}</span>
                    <span class="ms-auto small text-cyber">
                        <i class="mdi mdi-source-repository me-1"></i>${this.t('git.clone_here', 'Clone into this directory')}
                        <i class="mdi mdi-chevron-down ms-1 git-clone-chev"></i>
                    </span>
                </div>
                <div class="git-panel-clone-form d-none p-2">
                    <div class="mb-2">
                        <input type="text" class="form-control form-control-sm bg-dark text-light border-secondary"
                            data-git-field="cloneRepoUrl" placeholder="${this.t('git.repo_url', 'Repository URL (https:// or git@)')}">
                    </div>
                    <div class="row g-2 mb-2">
                        <div class="col-8">
                            <input type="text" class="form-control form-control-sm bg-dark text-light border-secondary"
                                data-git-field="branch" placeholder="${this.t('git.branch_optional', 'Branch (optional)')}">
                        </div>
                        <div class="col-4">
                            <input type="number" class="form-control form-control-sm bg-dark text-light border-secondary"
                                data-git-field="cloneDepth" min="1" placeholder="${this.t('git.depth', 'Depth')}">
                        </div>
                    </div>
                    <button class="btn btn-sm btn-cyber w-100" data-git-action="clone">
                        <i class="mdi mdi-source-repository me-1"></i>${this.t('git.clone', 'Clone Repository')}
                    </button>
                    <div class="small text-muted mt-2">
                        <i class="mdi mdi-information-outline me-1"></i>${this.t('git.clone_hint', 'For private repositories, clone first and then set credentials in the git panel settings.')}
                    </div>
                </div>
                <div class="git-panel-result"></div>
            </div>
        `;
    }

    /**
     * Full git control panel for repositories
     */
    repoHtml() {
        const s = this.status;
        const totalChanges = s.totalChanges || 0;

        return `
            <div class="git-panel">
                <div class="git-panel-header">
                    <i class="mdi mdi-git text-cyber me-2"></i>
                    <span class="badge bg-cyber text-dark">
                        <i class="mdi mdi-source-branch me-1"></i>${this.escapeHtml(s.branch || '?')}
                    </span>
                    ${totalChanges === 0
                        ? `<span class="small text-success ms-2"><i class="mdi mdi-check-circle me-1"></i>${this.t('git.clean', 'Clean')}</span>`
                        : `<span class="small text-warning ms-2">${totalChanges} ${this.t('git.changes', 'change(s)')}</span>`}
                    <span class="ms-auto">
                        <button class="btn btn-sm btn-outline-secondary git-icon-btn" data-git-action="refresh" title="${this.t('git.refresh', 'Refresh')}">
                            <i class="mdi mdi-refresh"></i>
                        </button>
                        <button class="btn btn-sm btn-outline-secondary git-icon-btn" data-git-action="toggle-credentials" title="${this.t('git.credentials', 'Credentials')}">
                            <i class="mdi mdi-key"></i>
                        </button>
                    </span>
                </div>

                ${this.changesHtml(s)}
                ${this.credentialsFormHtml()}

                <div class="git-panel-actions p-2 pt-0">
                    <button class="btn btn-sm btn-outline-cyber" data-git-action="pull">
                        <i class="mdi mdi-source-pull me-1"></i>${this.t('git.pull', 'Pull')}
                    </button>
                    <button class="btn btn-sm btn-outline-cyber" data-git-action="toggle-commit">
                        <i class="mdi mdi-source-commit me-1"></i>${this.t('git.commit_push', 'Commit & Push')}
                    </button>
                    <button class="btn btn-sm btn-outline-secondary" data-git-action="diff">
                        <i class="mdi mdi-file-compare me-1"></i>${this.t('git.diff', 'Diff')}
                    </button>
                    <button class="btn btn-sm btn-outline-secondary" data-git-action="log">
                        <i class="mdi mdi-history me-1"></i>${this.t('git.log', 'Log')}
                    </button>
                </div>

                ${this.commitFormHtml()}
                <div class="git-panel-result"></div>
            </div>
        `;
    }

    changesHtml(s) {
        const sections = [
            { key: 'staged', label: this.t('git.staged', 'staged'), icon: 'mdi-check', color: 'text-success' },
            { key: 'modified', label: this.t('git.modified', 'modified'), icon: 'mdi-pencil', color: 'text-warning' },
            { key: 'untracked', label: this.t('git.untracked', 'untracked'), icon: 'mdi-file-question', color: 'text-info' },
            { key: 'deleted', label: this.t('git.deleted', 'deleted'), icon: 'mdi-delete', color: 'text-danger' }
        ];

        let html = '';
        for (const sec of sections) {
            const files = s[sec.key] || [];
            if (files.length === 0) continue;

            const items = files.map(f =>
                `<div class="small text-muted"><i class="mdi mdi-file-outline me-1"></i><code>${this.escapeHtml(f)}</code></div>`
            ).join('');

            html += `
                <div class="cq-collapsible px-2">
                    <div class="small cursor-pointer d-flex align-items-center" data-git-action="toggle-section">
                        <i class="mdi mdi-chevron-right cq-chev me-1"></i>
                        <span class="${sec.color}"><i class="mdi ${sec.icon} me-1"></i><strong>${sec.label}</strong> (${files.length})</span>
                    </div>
                    <div class="d-none mt-1 ps-3">${items}</div>
                </div>
            `;
        }

        return html ? `<div class="git-panel-changes pb-2">${html}</div>` : '';
    }

    commitFormHtml() {
        return `
            <div class="git-panel-commit-form d-none p-2 border-top border-secondary border-opacity-25">
                <div class="mb-2">
                    <input type="text" class="form-control form-control-sm bg-dark text-light border-secondary"
                        data-git-field="commitMessage" placeholder="${this.t('git.commit_message', 'Commit message')}">
                </div>
                <div class="mb-2">
                    <input type="text" class="form-control form-control-sm bg-dark text-light border-secondary"
                        data-git-field="commitFiles" value="all" placeholder="${this.t('git.commit_files', 'Files (comma-separated, or "all")')}">
                </div>
                <div class="d-flex align-items-center">
                    <div class="form-check form-switch small">
                        <input class="form-check-input" type="checkbox" data-git-field="commitAndPush" checked id="git-push-check-${this.projectId}">
                        <label class="form-check-label text-muted" for="git-push-check-${this.projectId}">${this.t('git.push_after_commit', 'Push after commit')}</label>
                    </div>
                    <button class="btn btn-sm btn-cyber ms-auto" data-git-action="commit">
                        <i class="mdi mdi-source-commit me-1"></i>${this.t('git.commit', 'Commit')}
                    </button>
                </div>
            </div>
        `;
    }

    credentialsFormHtml() {
        return `
            <div class="git-panel-credentials-form d-none p-2 border-top border-bottom border-secondary border-opacity-25">
                <div class="small text-muted mb-2"><i class="mdi mdi-key me-1"></i>${this.t('git.credentials_for', 'Credentials for')} <code>/${this.escapeHtml(this.repoPath)}</code></div>
                <div class="btn-group btn-group-sm w-100 mb-2" role="group">
                    <input type="radio" class="btn-check" name="git-auth-method-${this.projectId}" id="git-auth-https-${this.projectId}" value="https" checked>
                    <label class="btn btn-outline-secondary" for="git-auth-https-${this.projectId}">HTTPS</label>
                    <input type="radio" class="btn-check" name="git-auth-method-${this.projectId}" id="git-auth-ssh-${this.projectId}" value="ssh">
                    <label class="btn btn-outline-secondary" for="git-auth-ssh-${this.projectId}">SSH</label>
                </div>
                <div data-git-auth-section="https">
                    <div class="mb-2">
                        <input type="text" class="form-control form-control-sm bg-dark text-light border-secondary"
                            data-git-field="username" placeholder="${this.t('git.username', 'Username')}">
                    </div>
                    <div class="mb-2">
                        <input type="password" class="form-control form-control-sm bg-dark text-light border-secondary"
                            data-git-field="token" placeholder="${this.t('git.token', 'Access token')}">
                    </div>
                </div>
                <div data-git-auth-section="ssh" class="d-none">
                    <div class="mb-2">
                        <textarea class="form-control form-control-sm bg-dark text-light border-secondary font-monospace"
                            data-git-field="sshPrivateKey" rows="4" placeholder="-----BEGIN OPENSSH PRIVATE KEY-----"></textarea>
                    </div>
                </div>
                <div class="row g-2 mb-2">
                    <div class="col-6">
                        <input type="text" class="form-control form-control-sm bg-dark text-light border-secondary"
                            data-git-field="userName" placeholder="${this.t('git.user_name', 'Git user name')}">
                    </div>
                    <div class="col-6">
                        <input type="email" class="form-control form-control-sm bg-dark text-light border-secondary"
                            data-git-field="userEmail" placeholder="${this.t('git.user_email', 'Git user email')}">
                    </div>
                </div>
                <button class="btn btn-sm btn-cyber w-100" data-git-action="save-credentials">
                    <i class="mdi mdi-content-save me-1"></i>${this.t('git.save_credentials', 'Save Credentials')}
                </button>
            </div>
        `;
    }

    bindEvents() {
        this.container.querySelectorAll('[data-git-action]').forEach(el => {
            el.addEventListener('click', (e) => this.handleAction(e.currentTarget.dataset.gitAction, e));
        });

        // Auth method toggle (https / ssh sections)
        this.container.querySelectorAll(`input[name="git-auth-method-${this.projectId}"]`).forEach(radio => {
            radio.addEventListener('change', () => {
                this.container.querySelectorAll('[data-git-auth-section]').forEach(sec => {
                    sec.classList.toggle('d-none', sec.dataset.gitAuthSection !== radio.value);
                });
            });
        });
    }

    async handleAction(action, event) {
        switch (action) {
            case 'toggle-clone':
                this.toggle(this.container.querySelector('.git-panel-clone-form'));
                this.container.querySelector('.git-clone-chev')?.classList.toggle('mdi-chevron-down');
                this.container.querySelector('.git-clone-chev')?.classList.toggle('mdi-chevron-up');
                break;
            case 'toggle-commit':
                this.toggle(this.container.querySelector('.git-panel-commit-form'));
                break;
            case 'toggle-credentials':
                this.toggle(this.container.querySelector('.git-panel-credentials-form'));
                break;
            case 'toggle-section':
                this.toggleSection(event.currentTarget);
                break;
            case 'refresh':
                await this.load();
                break;
            case 'clone':
                await this.runClone();
                break;
            case 'pull':
                await this.runOperation({ operation: 'pull' }, true);
                break;
            case 'commit':
                await this.runCommit();
                break;
            case 'diff':
                await this.runOperation({ operation: 'diff' });
                break;
            case 'log':
                await this.runLog();
                break;
            case 'save-credentials':
                await this.saveCredentials();
                break;
        }
    }

    async runClone() {
        const repoUrl = this.fieldValue('cloneRepoUrl');
        if (!repoUrl) {
            window.toast?.error(this.t('git.repo_url_required', 'Repository URL is required'));
            return;
        }

        const params = { operation: 'clone', cloneRepoUrl: repoUrl };
        const branch = this.fieldValue('branch');
        const depth = this.fieldValue('cloneDepth');
        if (branch) params.branch = branch;
        if (depth) params.cloneDepth = parseInt(depth, 10);

        const result = await this.runOperation(params, true);
        if (result?.success) {
            await this.load();
        }
    }

    async runCommit() {
        const message = this.fieldValue('commitMessage');
        if (!message) {
            window.toast?.error(this.t('git.commit_message_required', 'Commit message is required'));
            return;
        }

        const params = {
            operation: 'commitAndPush',
            commitMessage: message,
            commitFiles: this.fieldValue('commitFiles') || 'all',
            commitAndPush: this.container.querySelector('[data-git-field="commitAndPush"]')?.checked ?? true
        };

        const result = await this.runOperation(params, true);
        if (result?.success) {
            await this.load();
        }
    }

    async runLog() {
        const result = await this.runOperation({ operation: 'log', logCount: 15 });
        if (result?.success && result.commits) {
            const commitsHtml = result.commits.map(c => `
                <div class="git-commit-row py-1 border-bottom border-secondary border-opacity-25">
                    <div class="d-flex align-items-center">
                        <code class="text-cyber me-2">${this.escapeHtml((c.hash || '').substring(0, 7))}</code>
                        <span class="small text-light text-truncate">${this.escapeHtml(c.message)}</span>
                    </div>
                    <div class="small text-muted">
                        <i class="mdi mdi-account me-1"></i>${this.escapeHtml(c.author)}
                        <span class="ms-2"><i class="mdi mdi-clock-outline me-1"></i>${this.escapeHtml(c.date)}</span>
                    </div>
                </div>
            `).join('');

            this.showResult(`
                <div class="bg-dark bg-opacity-50 rounded p-2">
                    <div class="d-flex align-items-center mb-1">
                        <i class="mdi mdi-history text-cyber me-2"></i>
                        <strong>${result.count} ${this.t('git.commits', 'commits')}</strong>
                    </div>
                    ${commitsHtml}
                </div>
            `);
        }
    }

    /**
     * Run a git operation and show its result.
     * Reuses the _frontendData HTML built by AIToolGitService (same as Spirit Chat).
     */
    async runOperation(params, refreshTree = false) {
        if (this.isBusy) return null;
        this.isBusy = true;
        this.setBusy(true);

        try {
            const response = await fetch(`/api/git/operation/${this.projectId}`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ ...params, localRepoPath: this.repoPath })
            });
            const result = await response.json();

            if (result.success) {
                if (result._frontendData) {
                    this.showResult(result._frontendData);
                }
                window.toast?.success(result.message || this.t('git.operation_done', 'Git operation completed'));
                if (refreshTree && this.onTreeRefresh) {
                    this.onTreeRefresh();
                }
            } else {
                this.showResult(`
                    <div class="bg-dark bg-opacity-50 rounded p-2 small text-danger">
                        <i class="mdi mdi-alert-circle me-1"></i>${this.escapeHtml(result.error || 'Operation failed')}
                    </div>
                `);
                window.toast?.error(result.error || this.t('git.operation_failed', 'Git operation failed'));
            }

            return result;
        } catch (error) {
            this.showResult(`
                <div class="bg-dark bg-opacity-50 rounded p-2 small text-danger">
                    <i class="mdi mdi-alert-circle me-1"></i>${this.escapeHtml(error.message)}
                </div>
            `);
            window.toast?.error(error.message);
            return null;
        } finally {
            this.isBusy = false;
            this.setBusy(false);
        }
    }

    async saveCredentials() {
        const authMethod = this.container.querySelector(`input[name="git-auth-method-${this.projectId}"]:checked`)?.value || 'https';

        const data = {
            authMethod,
            localRepoPath: this.repoPath
        };

        if (authMethod === 'https') {
            data.username = this.fieldValue('username');
            data.token = this.fieldValue('token');
        } else {
            data.sshPrivateKey = this.fieldValue('sshPrivateKey');
        }

        const userName = this.fieldValue('userName');
        const userEmail = this.fieldValue('userEmail');
        if (userName) data.userName = userName;
        if (userEmail) data.userEmail = userEmail;

        try {
            const response = await fetch(`/api/git/credentials/${this.projectId}`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(data)
            });
            const result = await response.json();

            if (result.success) {
                window.toast?.success(result.message || this.t('git.credentials_saved', 'Credentials saved'));
                if (result._frontendData) {
                    this.showResult(result._frontendData);
                }
                this.toggle(this.container.querySelector('.git-panel-credentials-form'));
            } else {
                window.toast?.error(result.error || this.t('git.credentials_failed', 'Failed to save credentials'));
            }
        } catch (error) {
            window.toast?.error(error.message);
        }
    }

    showResult(html) {
        const resultEl = this.container.querySelector('.git-panel-result');
        if (resultEl) {
            resultEl.innerHTML = `<div class="p-2">${html}</div>`;
        }
    }

    setBusy(busy) {
        this.container.querySelectorAll('[data-git-action]').forEach(el => {
            if (el.tagName === 'BUTTON') el.disabled = busy;
        });
    }

    toggle(el) {
        el?.classList.toggle('d-none');
    }

    toggleSection(headerEl) {
        headerEl.querySelector('.cq-chev')?.classList.toggle('mdi-chevron-down');
        headerEl.querySelector('.cq-chev')?.classList.toggle('mdi-chevron-right');
        headerEl.nextElementSibling?.classList.toggle('d-none');
    }

    fieldValue(field) {
        return this.container.querySelector(`[data-git-field="${field}"]`)?.value?.trim() || '';
    }

    escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = String(str ?? '');
        return div.innerHTML;
    }

    t(key, fallback) {
        return this.translations[key] || fallback;
    }
}
