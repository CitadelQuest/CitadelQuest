/**
 * AiToolsSettingsManager
 * 
 * Manages the Settings → AI Tools page.
 * Lists all AI tools grouped by category with global active toggle,
 * expandable details (description + parameters), and tool-specific settings modal.
 */
export default class AiToolsSettingsManager {
    constructor(containerEl) {
        this.containerEl = containerEl;
        this.apiUrl = containerEl.dataset.apiUrl;
        this.translations = JSON.parse(containerEl.dataset.translations || '{}');

        this.loadingEl = document.getElementById('ai-tools-loading');
        this.listEl = document.getElementById('ai-tools-list');
        this.emptyEl = document.getElementById('ai-tools-empty');
        this.countEl = document.getElementById('ai-tools-count');

        // Modal
        this.settingsModal = document.getElementById('toolSettingsModal');
        this.settingsModalTitle = document.getElementById('toolSettingsModalTitle');
        this.settingsModalBody = document.getElementById('toolSettingsModalBody');
        this.saveSettingsBtn = document.getElementById('btn-save-tool-settings');

        this.tools = [];
        this.currentToolId = null;

        // Category display config
        this.categoryLabels = {
            file: this.translations.category_file || 'File Management',
            web: this.translations.category_web || 'Web Tools',
            image: this.translations.category_image || 'Image Generation',
            memory: this.translations.category_memory || 'Memory',
            profile: this.translations.category_profile || 'Profile',
            development: this.translations.category_development || 'Development',
            spirit: this.translations.category_spirit || 'Spirit',
            utility: this.translations.category_utility || 'Utility',
            general: this.translations.category_general || 'General',
        };

        this.categoryIcons = {
            file: 'mdi-folder-open',
            web: 'mdi-web',
            image: 'mdi-image',
            memory: 'mdi-brain',
            profile: 'mdi-account-box',
            development: 'mdi-code-braces',
            spirit: 'mdi-ghost',
            utility: 'mdi-wrench',
            general: 'mdi-tools',
        };
    }

    async init() {
        this.bindEvents();
        await this.loadTools();
    }

    bindEvents() {
        if (this.saveSettingsBtn) {
            this.saveSettingsBtn.addEventListener('click', () => this.saveToolSettings());
        }
    }

    // =====================
    // Data Loading
    // =====================

    async loadTools() {
        this.showLoading(true);
        try {
            const response = await fetch(this.apiUrl);
            const data = await response.json();
            this.tools = data.tools || [];
            this.renderTools();
        } catch (error) {
            console.error('Error loading AI tools:', error);
            this.showError(error.message);
        } finally {
            this.showLoading(false);
        }
    }

    // =====================
    // Rendering
    // =====================

    renderTools() {
        if (!this.listEl) return;

        if (this.tools.length === 0) {
            this.listEl.classList.add('d-none');
            if (this.emptyEl) this.emptyEl.classList.remove('d-none');
            return;
        }

        if (this.emptyEl) this.emptyEl.classList.add('d-none');
        this.listEl.classList.remove('d-none');
        this.listEl.innerHTML = '';

        // Update counter
        const activeCount = this.tools.filter(t => t.isActive).length;
        if (this.countEl) {
            this.countEl.textContent = `${activeCount} / ${this.tools.length}`;
        }

        // Group by category
        const groups = {};
        this.tools.forEach(tool => {
            const cat = tool.category || 'general';
            if (!groups[cat]) groups[cat] = [];
            groups[cat].push(tool);
        });

        // Render each category group
        const categoryOrder = ['file', 'web', 'image', 'memory', 'profile', 'development', 'spirit', 'utility', 'general'];
        categoryOrder.forEach(cat => {
            if (!groups[cat] || groups[cat].length === 0) return;
            this.renderCategoryGroup(cat, groups[cat]);
        });

        // Render any remaining categories not in the predefined order
        Object.keys(groups).forEach(cat => {
            if (!categoryOrder.includes(cat)) {
                this.renderCategoryGroup(cat, groups[cat]);
            }
        });
    }

    renderCategoryGroup(category, tools) {
        const groupEl = document.createElement('div');
        groupEl.className = 'mb-4';

        const icon = this.categoryIcons[category] || 'mdi-tools';
        const label = this.categoryLabels[category] || category;

        groupEl.innerHTML = `
            <div class="text-cyber mb-3 d-flex align-items-center gap-2">
                <i class="mdi ${icon}"></i>
                <span class="text-light opacity-75">${label}</span>
                <span class="badge bg-secondary bg-opacity-25 text-secondary small">${tools.length}</span>
            </div>
        `;

        const toolsContainer = document.createElement('div');
        toolsContainer.className = 'd-flex flex-column gap-2';

        tools.forEach(tool => {
            toolsContainer.appendChild(this.renderToolCard(tool));
        });

        groupEl.appendChild(toolsContainer);
        this.listEl.appendChild(groupEl);
    }

    renderToolCard(tool) {
        const cardEl = document.createElement('div');
        cardEl.className = `glass-panel border rounded p-3 ${tool.isActive ? 'border-success border-opacity-25 bg-success_bg-opacity-10' : 'border-secondary border-opacity-25 bg-secondary_bg-opacity-10'}`;
        cardEl.dataset.toolId = tool.id;

        // Format parameters for display
        let paramsHtml = '';
        const params = tool.parameters;
        if (params && params.properties) {
            const props = params.properties;
            const required = params.required || [];
            paramsHtml = Object.entries(props).map(([name, prop]) => {
                const isRequired = required.includes(name);
                return `
                    <div class="d-flex gap-2 align-items-start py-1">
                        <code class="text-cyber small">${name}</code>
                        <span class="text-secondary" style="font-size: 0.7rem;">${prop.type || ''}${isRequired ? ' <span class="text-warning">*</span>' : ''}</span>
                    </div>
                    ${prop.description ? `<div class="text-light opacity-50" style="font-size: 0.7rem; margin-top: -4px; margin-bottom: 4px;">${prop.description}</div>` : ''}
                `;
            }).join('');
        }

        cardEl.innerHTML = `
            <div class="d-flex justify-content-between align-items-center">
                <div class="d-flex align-items-center gap-2 flex-grow-1">
                    <span class="cursor-pointer tool-name-toggle text-cyber fw-semibold" data-tool-id="${tool.id}" title="Show details">
                        <i class="mdi mdi-chevron-right tool-chevron me-1 text-secondary" style="transition: transform 0.2s;"></i>${tool.name}
                    </span>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <button class="btn btn-sm btn-outline-secondary border-0 tool-settings-btn d-none" data-tool-id="${tool.id}" title="${this.translations.tool_settings || 'Settings'}">
                        <i class="mdi mdi-cog"></i>
                    </button>
                    <div class="form-check form-switch mb-0 ms-1">
                        <input class="form-check-input tool-active-toggle" type="checkbox" role="switch" 
                            data-tool-id="${tool.id}" ${tool.isActive ? 'checked' : ''}>
                    </div>
                </div>
            </div>
            <div class="tool-details d-none mt-2 ms-3 ps-2 border-start border-secondary border-opacity-25">
                <div class="text-light opacity-75 small mb-2">${tool.description}</div>
                ${paramsHtml ? `
                    <div class="mt-2">
                        <div class="text-secondary small mb-1"><i class="mdi mdi-code-json me-1"></i>${this.translations.parameters || 'Parameters'}</div>
                        <div class="d-flex flex-column">${paramsHtml}</div>
                    </div>
                ` : ''}
            </div>
        `;

        // Toggle details on name click
        const nameToggle = cardEl.querySelector('.tool-name-toggle');
        const details = cardEl.querySelector('.tool-details');
        const chevron = cardEl.querySelector('.tool-chevron');
        nameToggle.addEventListener('click', () => {
            const isOpen = !details.classList.contains('d-none');
            details.classList.toggle('d-none');
            chevron.style.transform = isOpen ? '' : 'rotate(90deg)';
        });

        // Active toggle
        const activeToggle = cardEl.querySelector('.tool-active-toggle');
        activeToggle.addEventListener('change', (e) => this.toggleToolActive(tool.id, e.target.checked, cardEl));

        // Settings button — load settings to check if tool has any
        this.checkToolSettings(tool.id, cardEl.querySelector('.tool-settings-btn'));

        return cardEl;
    }

    // =====================
    // Tool Active Toggle
    // =====================

    async toggleToolActive(toolId, isActive, cardEl) {
        try {
            const response = await fetch(`${this.apiUrl}/${toolId}`, {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ isActive })
            });

            const data = await response.json();
            if (data.tool) {
                // Update local state
                const idx = this.tools.findIndex(t => t.id === toolId);
                if (idx !== -1) this.tools[idx] = data.tool;

                // Update card styling
                if (isActive) {
                    cardEl.className = cardEl.className.replace('border-secondary', 'border-success').replace('bg-secondary', 'bg-success');
                } else {
                    cardEl.className = cardEl.className.replace('border-success', 'border-secondary').replace('bg-success', 'bg-secondary');
                }

                // Update counter
                const activeCount = this.tools.filter(t => t.isActive).length;
                if (this.countEl) {
                    this.countEl.textContent = `${activeCount} / ${this.tools.length}`;
                }

                this.showToast(this.translations.saved || 'Saved', 'success');
            }
        } catch (error) {
            console.error('Error toggling tool:', error);
            this.showToast(this.translations.error || 'Error', 'danger');
        }
    }

    // =====================
    // Tool Settings
    // =====================

    async checkToolSettings(toolId, settingsBtn) {
        if (!settingsBtn) return;
        try {
            const response = await fetch(`${this.apiUrl}/${toolId}/settings`);
            const data = await response.json();
            if (data.settings && data.settings.length > 0) {
                settingsBtn.classList.remove('d-none');
                settingsBtn.addEventListener('click', () => this.openToolSettingsModal(toolId));
            }
        } catch (error) {
            // No settings available — button stays hidden
        }
    }

    async openToolSettingsModal(toolId) {
        if (!this.settingsModal) return;
        this.currentToolId = toolId;

        const tool = this.tools.find(t => t.id === toolId);
        if (this.settingsModalTitle) {
            this.settingsModalTitle.innerHTML = `<i class="mdi mdi-cog me-2"></i>${tool ? tool.name : (this.translations.tool_settings || 'Settings')}`;
        }

        if (this.settingsModalBody) {
            this.settingsModalBody.innerHTML = `
                <div class="text-center p-3">
                    <div class="spinner-border spinner-border-sm text-cyber" role="status"></div>
                </div>
            `;
        }

        const modal = new bootstrap.Modal(this.settingsModal);
        modal.show();

        try {
            const response = await fetch(`${this.apiUrl}/${toolId}/settings`);
            const data = await response.json();
            this.renderToolSettingsForm(data.settings || []);
        } catch (error) {
            console.error('Error loading tool settings:', error);
            if (this.settingsModalBody) {
                this.settingsModalBody.innerHTML = `<div class="alert alert-danger small py-2 mb-0">${error.message || 'Failed to load settings'}</div>`;
            }
        }
    }

    renderToolSettingsForm(settings) {
        if (!this.settingsModalBody) return;

        if (settings.length === 0) {
            this.settingsModalBody.innerHTML = `<div class="text-secondary small py-2">${this.translations.no_settings || 'No configurable settings'}</div>`;
            return;
        }

        let html = '';
        settings.forEach(setting => {
            html += this.renderSettingInput(setting);
        });

        this.settingsModalBody.innerHTML = html;
    }

    renderSettingInput(setting) {
        const label = setting.label || setting.key;
        const desc = setting.description ? `<div class="form-text text-muted small">${setting.description}</div>` : '';
        const inputId = `tool-setting-${setting.key}`;

        switch (setting.type) {
            case 'boolean':
                return `
                    <div class="mb-3">
                        <div class="form-check form-switch">
                            <input class="form-check-input tool-setting-input" type="checkbox" id="${inputId}" 
                                data-key="${setting.key}" data-type="boolean" ${setting.value === '1' || setting.value === 'true' ? 'checked' : ''}>
                            <label class="form-check-label text-light small" for="${inputId}">${label}</label>
                        </div>
                        ${desc}
                    </div>
                `;

            case 'number':
                return `
                    <div class="mb-3">
                        <label class="form-label small" for="${inputId}"><i class="mdi mdi-numeric me-1 text-cyber"></i>${label}</label>
                        <input type="number" class="form-control form-control-sm glass-input tool-setting-input" id="${inputId}"
                            data-key="${setting.key}" data-type="number" value="${setting.value || ''}">
                        ${desc}
                    </div>
                `;

            case 'textarea':
                return `
                    <div class="mb-3">
                        <label class="form-label small" for="${inputId}"><i class="mdi mdi-text me-1 text-cyber"></i>${label}</label>
                        <textarea class="form-control form-control-sm glass-input tool-setting-input" id="${inputId}"
                            data-key="${setting.key}" data-type="textarea" rows="3">${setting.value || ''}</textarea>
                        ${desc}
                    </div>
                `;

            case 'select':
                let options = '';
                try {
                    const choices = JSON.parse(setting.description || '{}');
                    if (typeof choices === 'object' && !Array.isArray(choices)) {
                        Object.entries(choices).forEach(([val, lbl]) => {
                            options += `<option value="${val}" ${setting.value === val ? 'selected' : ''}>${lbl}</option>`;
                        });
                    }
                } catch (e) {
                    // fallback: no options
                }
                return `
                    <div class="mb-3">
                        <label class="form-label small" for="${inputId}"><i class="mdi mdi-menu me-1 text-cyber"></i>${label}</label>
                        <select class="form-select form-select-sm glass-input tool-setting-input" id="${inputId}"
                            data-key="${setting.key}" data-type="select">
                            ${options}
                        </select>
                    </div>
                `;

            case 'json':
                return `
                    <div class="mb-3">
                        <label class="form-label small" for="${inputId}"><i class="mdi mdi-code-json me-1 text-cyber"></i>${label}</label>
                        <textarea class="form-control form-control-sm glass-input font-monospace tool-setting-input" id="${inputId}"
                            data-key="${setting.key}" data-type="json" rows="4">${setting.value || ''}</textarea>
                        ${desc}
                    </div>
                `;

            default: // text
                return `
                    <div class="mb-3">
                        <label class="form-label small" for="${inputId}"><i class="mdi mdi-form-textbox me-1 text-cyber"></i>${label}</label>
                        <input type="text" class="form-control form-control-sm glass-input tool-setting-input" id="${inputId}"
                            data-key="${setting.key}" data-type="text" value="${setting.value || ''}">
                        ${desc}
                    </div>
                `;
        }
    }

    async saveToolSettings() {
        if (!this.currentToolId) return;

        const inputs = this.settingsModalBody.querySelectorAll('.tool-setting-input');
        const settings = {};

        inputs.forEach(input => {
            const key = input.dataset.key;
            const type = input.dataset.type;

            if (type === 'boolean') {
                settings[key] = input.checked ? '1' : '0';
            } else {
                settings[key] = input.value;
            }
        });

        // Disable save button
        if (this.saveSettingsBtn) {
            this.saveSettingsBtn.disabled = true;
            this.saveSettingsBtn.querySelector('.spinner-border')?.classList.remove('d-none');
        }

        try {
            const response = await fetch(`${this.apiUrl}/${this.currentToolId}/settings`, {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ settings })
            });

            const data = await response.json();
            if (data.success) {
                this.showToast(this.translations.saved || 'Saved', 'success');
                // Close modal
                const modalInstance = bootstrap.Modal.getInstance(this.settingsModal);
                if (modalInstance) modalInstance.hide();
            } else {
                this.showToast(data.error || this.translations.error || 'Error', 'danger');
            }
        } catch (error) {
            console.error('Error saving tool settings:', error);
            this.showToast(this.translations.error || 'Error', 'danger');
        } finally {
            if (this.saveSettingsBtn) {
                this.saveSettingsBtn.disabled = false;
                this.saveSettingsBtn.querySelector('.spinner-border')?.classList.add('d-none');
            }
        }
    }

    // =====================
    // UI Helpers
    // =====================

    showLoading(show) {
        if (this.loadingEl) {
            this.loadingEl.classList.toggle('d-none', !show);
        }
    }

    showError(message) {
        if (this.listEl) {
            this.listEl.innerHTML = `<div class="alert alert-danger small py-2">${message}</div>`;
            this.listEl.classList.remove('d-none');
        }
    }

    showToast(message, type = 'success') {
        // Use global toast if available
        if (window.toast) {
            if (type == 'success' ) {
                window.toast.success(message);
            } else if (type == 'error' ) {
                window.toast.error(message);
            }
            return;
        }
        // Fallback: simple inline notification
        console.log(`[${type}] ${message}`);
    }
}
