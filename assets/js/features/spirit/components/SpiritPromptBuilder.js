import * as bootstrap from 'bootstrap';
/**
 * SpiritPromptBuilder - Manages the Spirit System Prompt Builder modal
 * Provides transparency and control over Spirit's system prompt structure
 */
export class SpiritPromptBuilder {
    constructor(config = {}) {
        this.spiritId = config.spiritId || null;
        this.translations = config.translations || {};
        this.apiEndpoints = config.apiEndpoints || {
            preview: '/api/spirit/{id}/system-prompt-preview',
            config: '/api/spirit/{id}/system-prompt-config'
        };
        
        this.modal = null;
        this.bsModal = null;
        this.promptData = null;
        this.config = {};
        this.hasChanges = false;
        
        this.init();
    }
    
    init() {
        this.modal = document.getElementById('systemPromptBuilderModal');
        if (!this.modal) {
            console.warn('SpiritPromptBuilder: Modal element not found');
            return;
        }
        
        this.bsModal = new bootstrap.Modal(this.modal);
        this.initEventListeners();
    }
    
    initEventListeners() {
        // View mode toggle
        const viewModeRadios = this.modal.querySelectorAll('input[name="promptViewMode"]');
        viewModeRadios.forEach(radio => {
            radio.addEventListener('change', (e) => this.handleViewModeChange(e.target.value));
        });
        
        // Toggle switches for optional sections
        const toggleSystemInfo = document.getElementById('toggleSystemInfo');
        const toggleMemory = document.getElementById('toggleMemory');
        const toggleTools = document.getElementById('toggleTools');
        const toggleLanguage = document.getElementById('toggleLanguage');
        
        if (toggleSystemInfo) {
            toggleSystemInfo.addEventListener('change', (e) => {
                this.config.includeSystemInfo = e.target.checked;
                this.updateSectionVisibility('sectionSystemInfoBody', e.target.checked);
                this.markAsChanged();
            });
        }
        
        if (toggleMemory) {
            toggleMemory.addEventListener('change', (e) => {
                this.config.includeMemory = e.target.checked;
                this.updateSectionVisibility('sectionMemoryBody', e.target.checked);
                this.markAsChanged();
            });
        }
        
        // Memory Type select
        const memoryTypeSelect = document.getElementById('memoryTypeSelect');
        if (memoryTypeSelect) {
            memoryTypeSelect.addEventListener('change', (e) => {
                this.config.memoryType = parseInt(e.target.value, 10);
                this.renderMemorySection(this.promptData?.sections?.memory);
                this.markAsChanged();
            });
        }
        
        
        if (toggleTools) {
            toggleTools.addEventListener('change', (e) => {
                this.config.includeTools = e.target.checked;
                this.updateSectionVisibility('sectionToolsBody', e.target.checked);
                this.markAsChanged();
            });
        }
        
        if (toggleLanguage) {
            toggleLanguage.addEventListener('change', (e) => {
                this.config.includeLanguage = e.target.checked;
                this.updateSectionVisibility('sectionLanguageBody', e.target.checked);
                this.markAsChanged();
            });
        }
        
        // Custom prompt textarea
        const customPromptTextarea = document.getElementById('sectionCustomPrompt');
        if (customPromptTextarea) {
            customPromptTextarea.addEventListener('input', () => this.markAsChanged());
        }
        
        // Save button
        const saveBtn = document.getElementById('savePromptConfigBtn');
        if (saveBtn) {
            saveBtn.addEventListener('click', () => this.saveConfiguration());
        }
        
        // Copy button
        const copyBtn = document.getElementById('copyPromptBtn');
        if (copyBtn) {
            copyBtn.addEventListener('click', () => this.copyFullPrompt());
        }
        
        // Modal hidden event - reset state
        this.modal.addEventListener('hidden.bs.modal', () => {
            this.hasChanges = false;
            this.promptData = null;
        });
    }
    
    /**
     * Open the modal for a specific Spirit
     */
    async open(spiritId) {
        if (spiritId) {
            this.spiritId = spiritId;
        }
        
        if (!this.spiritId) {
            console.error('SpiritPromptBuilder: No Spirit ID provided');
            return;
        }
        
        // Show loading state
        this.showLoading(true);
        this.hasChanges = false;
        
        // Show modal
        this.bsModal.show();
        
        // Load data
        await this.loadPromptData();
    }
    
    /**
     * Load prompt preview data from API
     */
    async loadPromptData() {
        try {
            const url = this.apiEndpoints.preview.replace('{id}', this.spiritId);
            const response = await fetch(url);
            
            if (!response.ok) {
                throw new Error('Failed to load prompt data');
            }
            
            this.promptData = await response.json();
            this.config = { ...this.promptData.config };
            
            this.renderStructuredView();
            this.renderFinalOutput();
            this.showLoading(false);
            
        } catch (error) {
            console.error('Error loading prompt data:', error);
            this.showError(this.translate('error.loading_prompt', 'Failed to load prompt data'));
        }
    }
    
    /**
     * Render the structured view with all sections
     */
    renderStructuredView() {
        if (!this.promptData) return;
        
        const sections = this.promptData.sections;
        
        // Spirit name
        const nameDisplay = document.getElementById('promptBuilderSpiritName');
        if (nameDisplay) {
            nameDisplay.textContent = this.promptData.spiritName;
        }
        
        // 1. Identity section
        const identityEl = document.getElementById('sectionIdentity');
        if (identityEl && sections.identity) {
            identityEl.textContent = sections.identity.content;
        }
        
        // 2. Custom prompt
        const customPromptEl = document.getElementById('sectionCustomPrompt');
        if (customPromptEl && sections.customPrompt) {
            customPromptEl.value = sections.customPrompt.content || '';
        }
        
        // 3. System info
        const systemInfoEl = document.getElementById('sectionSystemInfo');
        const toggleSystemInfo = document.getElementById('toggleSystemInfo');
        if (systemInfoEl && sections.systemInfo) {
            systemInfoEl.textContent = sections.systemInfo.content;
            if (toggleSystemInfo) {
                toggleSystemInfo.checked = sections.systemInfo.enabled;
                this.updateSectionVisibility('sectionSystemInfoBody', sections.systemInfo.enabled);
            }
        }
        
        // 4. Memory
        this.renderMemorySection(sections.memory);
        const toggleMemory = document.getElementById('toggleMemory');
        if (toggleMemory && sections.memory) {
            toggleMemory.checked = sections.memory.enabled;
            this.updateSectionVisibility('sectionMemoryBody', sections.memory.enabled);
        }
        const memoryTypeSelect = document.getElementById('memoryTypeSelect');
        if (memoryTypeSelect && sections.memory) {
            memoryTypeSelect.value = String(sections.memory.memoryType ?? 2);
        }
        
        // 5. Tools
        const toolsEl = document.getElementById('sectionTools');
        const toggleTools = document.getElementById('toggleTools');
        if (toolsEl && sections.tools) {
            toolsEl.textContent = sections.tools.content || this.translate('spirit.prompt_builder.no_tools', 'No AI tools available');
            if (toggleTools) {
                toggleTools.checked = sections.tools.enabled;
                this.updateSectionVisibility('sectionToolsBody', sections.tools.enabled);
            }
        }
        
        // 6. Language
        const languageEl = document.getElementById('sectionLanguage');
        const toggleLanguage = document.getElementById('toggleLanguage');
        if (languageEl && sections.language) {
            languageEl.textContent = sections.language.content;
            if (toggleLanguage) {
                toggleLanguage.checked = sections.language.enabled;
                this.updateSectionVisibility('sectionLanguageBody', sections.language.enabled);
            }
        }
        
        // Reset save button state
        const saveBtn = document.getElementById('savePromptConfigBtn');
        if (saveBtn) {
            saveBtn.disabled = true;
        }
    }
    
    /**
     * Render Spirit Memory section based on current memoryType
     */
    renderMemorySection(memorySection) {
        const container = document.getElementById('memoryFilesList');
        if (!container || !memorySection) return;
        
        container.innerHTML = '';
        
        const memoryType = this.config.memoryType ?? memorySection.memoryType ?? 2;
        const stats = memorySection.stats || {};
        const statsEl = document.createElement('div');
        
        if (memoryType === -1) {
            // Legacy .md File Memory
            statsEl.innerHTML = `
                <div class="p-3 bg-dark bg-opacity-25 rounded">
                    <div class="d-flex align-items-center mb-2">
                        <i class="mdi mdi-file-document-outline me-2 text-warning"></i>
                        <strong>.md File Memory</strong>
                        <span class="text-muted ms-2">(Legacy markdown files)</span>
                    </div>
                    <p class="small text-muted mb-2">
                        Spirit reads and writes 3 markdown files in File Browser:
                        <code>conversations.md</code>, <code>inner-thoughts.md</code>, <code>knowledge-base.md</code>
                    </p>
                    <p class="small text-warning mb-0">
                        <i class="mdi mdi-alert-outline me-1"></i>
                        File contents are dumped into the system prompt each message — uses more tokens.
                        Consider switching to <strong>Reflexes</strong> for smarter, on-demand memory recall.
                    </p>
                </div>
            `;
        } else if (memoryType === 2) {
            // Memory Agent — Reflexes + AI Sub-Agent synthesis
            statsEl.innerHTML = `
                <div class="p-3 bg-dark bg-opacity-25 rounded">
                    <div class="d-flex align-items-center mb-2">
                        <i class="mdi mdi-robot-outline me-2 text-cyber"></i>
                        <strong>Memory Agent</strong>
                        <span class="text-muted ms-2">(Beta)</span>
                    </div>
                    <p class="small text-muted mb-2">
                        <i class="mdi mdi-information-outline me-1"></i>
                        Combines <strong>Reflexes</strong> (FTS5 keyword search) with an <strong>AI Sub-Agent</strong> that evaluates recalled memories and synthesizes contextual summaries for the Spirit.
                    </p>
                    <p class="small text-muted mb-0">
                        <i class="mdi mdi-brain me-1 text-cyber"></i>
                        Three-step flow: Reflexes recall → AI synthesis → Spirit response. Adds ~$0.0003 per message.
                    </p>
                </div>
            `;
        } else {
            // Reflexes (default, memoryType === 1)
            const totalMemories = stats.totalMemories || 0;
            const categories = stats.categories || {};
            const tagsCount = stats.tagsCount || 0;
            const relationshipsCount = stats.relationshipsCount || 0;
            
            let categoryBadges = '';
            for (const [cat, count] of Object.entries(categories)) {
                const badgeClass = this.getCategoryBadgeClass(cat);
                categoryBadges += `<span class="badge ${badgeClass} me-1">${cat}: ${count}</span>`;
            }
            if (!categoryBadges) {
                categoryBadges = '<span class="text-muted small">No memories yet</span>';
            }
            
            statsEl.innerHTML = `
                <div class="p-3 bg-dark bg-opacity-25 rounded">
                    <div class="d-flex align-items-center mb-2">
                        <i class="mdi mdi-brain me-2 text-cyber"></i>
                        <strong>Reflexes</strong>
                        <span class="text-muted ms-2">(Graph-based knowledge system)</span>
                    </div>
                    
                    <div class="row g-2 mt-2">
                        <div class="col-auto">
                            <div class="p-2 bg-dark bg-opacity-50 rounded text-center" style="min-width: 80px;">
                                <div class="h4 mb-0 text-cyber">${totalMemories}</div>
                                <small class="text-muted">Memories</small>
                            </div>
                        </div>
                        <div class="col-auto">
                            <div class="p-2 bg-dark bg-opacity-50 rounded text-center" style="min-width: 80px;">
                                <div class="h4 mb-0 text-info">${tagsCount}</div>
                                <small class="text-muted">Tags</small>
                            </div>
                        </div>
                        <div class="col-auto">
                            <div class="p-2 bg-dark bg-opacity-50 rounded text-center" style="min-width: 80px;">
                                <div class="h4 mb-0 text-warning">${relationshipsCount}</div>
                                <small class="text-muted">Links</small>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mt-3">
                        <small class="text-muted d-block mb-1">Categories:</small>
                        ${categoryBadges}
                    </div>
                </div>
                
                <p class="small text-muted mt-2 mb-0">
                    <i class="mdi mdi-information-outline me-1"></i>
                    Reflexes uses AI tools (memoryStore, memoryRecall, etc.) and automatic recall.
                    Toggle OFF to disable memory context in system prompt.
                </p>
            `;
        }
        
        container.appendChild(statsEl);
    }
    
    /**
     * Get badge class for memory category
     */
    getCategoryBadgeClass(category) {
        const classes = {
            'conversation': 'bg-primary',
            'thought': 'bg-info',
            'knowledge': 'bg-success',
            'fact': 'bg-warning text-dark',
            'preference': 'bg-danger'
        };
        return classes[category] || 'bg-secondary';
    }
    
    /**
     * Render the final output view
     */
    renderFinalOutput() {
        if (!this.promptData) return;
        
        const outputEl = document.getElementById('finalPromptOutput');
        const tokenCountEl = document.getElementById('tokenCount');
        
        if (outputEl) {
            outputEl.textContent = this.promptData.fullPrompt;
        }
        
        if (tokenCountEl) {
            tokenCountEl.textContent = this.promptData.estimatedTokens.toLocaleString();
        }
    }
    
    /**
     * Handle view mode change
     */
    handleViewModeChange(mode) {
        const structuredView = document.getElementById('structuredView');
        const finalOutputView = document.getElementById('finalOutputView');
        
        if (mode === 'structured') {
            structuredView.classList.remove('d-none');
            finalOutputView.classList.add('d-none');
        } else {
            structuredView.classList.add('d-none');
            finalOutputView.classList.remove('d-none');
            // Refresh final output when switching to this view
            this.updateFinalOutput();
        }
    }
    
    /**
     * Update final output based on current config
     */
    async updateFinalOutput() {
        // Re-fetch with current config to get updated full prompt
        // For now, we just show the cached version
        // In future, we could make a preview request with temp config
        this.renderFinalOutput();
    }
    
    /**
     * Update section visibility based on toggle state
     */
    updateSectionVisibility(sectionId, visible) {
        const section = document.getElementById(sectionId);
        if (section) {
            section.style.opacity = visible ? '1' : '0.4';
            section.style.pointerEvents = visible ? 'auto' : 'none';
        }
    }
    
    
    /**
     * Mark the form as having unsaved changes
     */
    markAsChanged() {
        this.hasChanges = true;
        const saveBtn = document.getElementById('savePromptConfigBtn');
        if (saveBtn) {
            saveBtn.disabled = false;
        }
    }
    
    /**
     * Save the configuration
     */
    async saveConfiguration() {
        if (!this.spiritId) return;
        
        const saveBtn = document.getElementById('savePromptConfigBtn');
        if (saveBtn) {
            saveBtn.disabled = true;
            saveBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>' + 
                this.translate('ui.saving', 'Saving...');
        }
        
        try {
            // Get custom prompt value
            const customPromptEl = document.getElementById('sectionCustomPrompt');
            const customPrompt = customPromptEl ? customPromptEl.value : '';
            
            // Save custom prompt via existing settings endpoint
            await fetch(`/api/spirit/${this.spiritId}/settings`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ systemPrompt: customPrompt })
            });
            
            // Save config toggles
            const url = this.apiEndpoints.config.replace('{id}', this.spiritId);
            const response = await fetch(url, {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(this.config)
            });
            
            if (!response.ok) {
                throw new Error('Failed to save configuration');
            }
            
            this.hasChanges = false;
            
            // Show success message
            if (window.toast) {
                window.toast.success(this.translate('spirit.prompt_builder.saved', 'Configuration saved successfully'));
            }
            
            // Reload data to get fresh preview
            await this.loadPromptData();
            
        } catch (error) {
            console.error('Error saving configuration:', error);
            if (window.toast) {
                window.toast.error(this.translate('error.saving_config', 'Failed to save configuration'));
            }
        } finally {
            if (saveBtn) {
                saveBtn.disabled = !this.hasChanges;
                saveBtn.innerHTML = '<i class="mdi mdi-content-save me-1"></i>' + 
                    this.translate('ui.save', 'Save');
            }
        }
    }
    
    /**
     * Copy full prompt to clipboard
     */
    async copyFullPrompt() {
        if (!this.promptData) return;
        
        try {
            await navigator.clipboard.writeText(this.promptData.fullPrompt);
            
            if (window.toast) {
                window.toast.success(this.translate('ui.copied', 'Copied to clipboard'));
            }
            
            // Visual feedback on button
            const copyBtn = document.getElementById('copyPromptBtn');
            if (copyBtn) {
                const originalHtml = copyBtn.innerHTML;
                copyBtn.innerHTML = '<i class="mdi mdi-check me-1"></i>' + this.translate('ui.copied', 'Copied!');
                setTimeout(() => {
                    copyBtn.innerHTML = originalHtml;
                }, 2000);
            }
        } catch (error) {
            console.error('Failed to copy:', error);
            if (window.toast) {
                window.toast.error(this.translate('error.copy_failed', 'Failed to copy to clipboard'));
            }
        }
    }
    
    /**
     * Show/hide loading state
     */
    showLoading(show) {
        const loadingEl = document.getElementById('promptBuilderLoading');
        const contentEl = document.getElementById('promptBuilderContent');
        
        if (loadingEl) {
            loadingEl.classList.toggle('d-none', !show);
        }
        if (contentEl) {
            contentEl.classList.toggle('d-none', show);
        }
    }
    
    /**
     * Show error message
     */
    showError(message) {
        this.showLoading(false);
        const contentEl = document.getElementById('promptBuilderContent');
        if (contentEl) {
            contentEl.innerHTML = `
                <div class="alert alert-danger">
                    <i class="mdi mdi-alert-circle me-2"></i>
                    ${message}
                </div>
            `;
            contentEl.classList.remove('d-none');
        }
    }
    
    /**
     * Format bytes to human readable string
     */
    formatBytes(bytes) {
        if (bytes === 0) return '0 B';
        const k = 1024;
        const sizes = ['B', 'KB', 'MB', 'GB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
    }
    
    /**
     * Escape HTML to prevent XSS
     */
    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    
    /**
     * Translate a key
     */
    translate(key, fallback) {
        return this.translations[key] || fallback;
    }
}
