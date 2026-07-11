/**
 * SpiritManager - Manages the Spirit feature UI and API interactions
 */
//import * as THREE from 'three';
import { ProfileMemoryGraph } from './ProfileMemoryGraph.js';
import * as bootstrap from 'bootstrap';
import { attachCqFileButton } from '../../../shared/cqfile-attach.js';

export class SpiritManager {
    constructor(config) {
        this.translations = config.translations || {};
        this.apiEndpoints = config.apiEndpoints || {};
        this.spirit = null;
        this.interactions = [];
        this.conversations = [];
        this.profileMemoryGraph = null;
        
        // DOM elements
        this.spiritContainer = document.getElementById('spirit-container');
        this.spiritDisplay = document.getElementById('spirit-display');
        this.spiritCanvas = document.getElementById('spirit-canvas');
        this.nameDisplay = document.getElementById('spirit-name-display');
        this.levelDisplay = document.getElementById('spirit-level');
        this.experienceBar = document.getElementById('spirit-experience-bar');
        this.experienceDisplay = document.getElementById('spirit-experience');
        this.nextLevelDisplay = document.getElementById('spirit-next-level');
        this.interactionsContainer = document.getElementById('spirit-interactions');
        this.sidebarInfo = document.getElementById('spirit-sidebar-info');
        this.overviewName = document.getElementById('spirit-overview-name');
        this.overviewLevel = document.getElementById('spirit-overview-level');
        this.overviewExperience = document.getElementById('spirit-overview-experience');
        this.overviewNextLevel = document.getElementById('spirit-overview-next-level');
        this.overviewExperienceBar = document.getElementById('spirit-overview-experience-bar');
        this.overviewExperienceText = document.getElementById('spirit-overview-experience-text');
        this.overviewNextLevelText = document.getElementById('spirit-overview-next-level-text');
        this.systemPromptInput = document.getElementById('spirit-system-prompt');
        this.aiModelSelect = document.getElementById('spirit-ai-model');
        this.subconsciousnessAgentAiModelSelect = document.getElementById('spirit-subconsciousness-agent-ai-model');
        this.temperatureInput = document.getElementById('spirit-temperature');
        this.temperatureValue = document.getElementById('spirit-temperature-value');
        this.updateSettingsBtn = document.getElementById('update-spirit-settings');
        this.aiToolsTab = document.getElementById('tab-ai-tools');
        this.aiToolsDataOptimization = document.getElementById('spirit-ai-tools-data-optimization');
        this.includeAiTools = document.getElementById('spirit-include-ai-tools');
        this.aiToolsList = document.getElementById('spirit-ai-tools-list');
        this.conversationsList = document.getElementById('conversations-list');
        this.aiToolsData = []; // cached AI tools with active state
        this.currentToolId = null;

        // Memory pack lists
        this.memoryLibraryPacksList = document.getElementById('spirit-memory-library-packs');
        this.memoryAvailablePacksList = document.getElementById('spirit-memory-available-packs');
        this.memoryLibraryPacksFilter = document.getElementById('spirit-memory-library-packs-filter');
        this.memoryAvailablePacksFilter = document.getElementById('spirit-memory-available-packs-filter');
        this.memoryPacksLoaded = false;

        // Tool settings modal refs
        this.toolSettingsModal = document.getElementById('spiritToolSettingsModal');
        this.toolSettingsModalTitle = document.getElementById('spiritToolSettingsModalTitle');
        this.toolSettingsModalBody = document.getElementById('spiritToolSettingsModalBody');
        this.saveToolSettingsBtn = document.getElementById('spirit-btn-save-tool-settings');

        // Category display config for AI Tools
        this.categoryLabels = {
            file: this.translate('ai_tools.category.file', 'File Management'),
            web: this.translate('ai_tools.category.web', 'Web Tools'),
            image: this.translate('ai_tools.category.image', 'Image Generation'),
            memory: this.translate('ai_tools.category.memory', 'Memory'),
            profile: this.translate('ai_tools.category.profile', 'Profile'),
            development: this.translate('ai_tools.category.development', 'Development'),
            spirit: this.translate('ai_tools.category.spirit', 'Spirit'),
            utility: this.translate('ai_tools.category.utility', 'Utility'),
            general: this.translate('ai_tools.category.general', 'General'),
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
        
        // Initialize
        this.initEventListeners();
        this.loadSpirit();
    }
    
    /**
     * Initialize event listeners for user interactions
     */
    initEventListeners() {
        // Spirit settings update button
        if (this.updateSettingsBtn) {
            this.updateSettingsBtn.addEventListener('click', () => {
                this.updateSpiritSettings();
            });
        }

        // "Add file" button next to the system-prompt textarea
        // Inserts a `cqfile://<id>#<name>` token at the cursor; backend
        // (SpiritConversationService::buildSpiritIdentity) expands tokens
        // to file content when building the system prompt.
        const addFileBtn = document.getElementById('spirit-system-prompt-add-file');
        if (addFileBtn && this.systemPromptInput) {
            attachCqFileButton({
                textarea: this.systemPromptInput,
                button: addFileBtn,
                translations: this.translations,
            });
        }
        
        // Conversation item clicks - open Spirit Chat modal with conversation loaded
        document.addEventListener('click', (e) => {
            const conversationItem = e.target.closest('.conversation-item');
            if (conversationItem) {
                const conversationId = conversationItem.dataset.conversationId;
                if (conversationId) {
                    this.openConversation(conversationId);
                }
            }
        });
        
        // New Conversation button in profile
        const newConversationBtnProfile = document.getElementById('newConversationBtnProfile');
        if (newConversationBtnProfile) {
            newConversationBtnProfile.addEventListener('click', () => {
                this.openSpiritChatModal();
            });
        }
        
        // Spirit icon click - open Spirit Chat modal
        const spiritAvatar = document.getElementById('spiritChatAvatar');
        if (spiritAvatar) {
            spiritAvatar.addEventListener('click', () => {
                this.openSpiritChat();
            });
        }
        
        // Chat buttons in sidebar and overview tab
        const sidebarChatBtn = document.getElementById('spirit-sidebar-chat-btn');
        if (sidebarChatBtn) {
            sidebarChatBtn.addEventListener('click', () => {
                this.openSpiritChat();
            });
        }
        const overviewChatBtn = document.getElementById('spirit-overview-chat-btn');
        if (overviewChatBtn) {
            overviewChatBtn.addEventListener('click', () => {
                this.openSpiritChat();
            });
        }
        
        // Initialize memory graph/stats, memory type and pack lists when the Memory tab becomes visible
        const memoryTab = document.getElementById('tab-memory');
        if (memoryTab) {
            memoryTab.addEventListener('shown.bs.tab', () => {
                this.initProfileMemoryType();
                this.initProfileMemoryGraphIfVisible();
                this.loadMemoryPacks();
            });
        }

        // Memory pack list filters
        if (this.memoryLibraryPacksFilter) {
            this.memoryLibraryPacksFilter.addEventListener('input', () => {
                this.filterMemoryPacks(this.memoryLibraryPacksFilter.value, this.memoryLibraryPacksList);
            });
        }
        if (this.memoryAvailablePacksFilter) {
            this.memoryAvailablePacksFilter.addEventListener('input', () => {
                this.filterMemoryPacks(this.memoryAvailablePacksFilter.value, this.memoryAvailablePacksList);
            });
        }

        // Persist active tab to localStorage whenever the user switches tabs
        const spiritTabs = document.getElementById('spirit-tabs');
        if (spiritTabs) {
            spiritTabs.addEventListener('shown.bs.tab', (e) => {
                localStorage.setItem('spirit-last-active-tab', e.target.id);
            });
        }
        
        // Live temperature value update and enable save button
        if (this.temperatureInput) {
            this.temperatureInput.addEventListener('input', () => {
                if (this.temperatureValue) {
                    this.temperatureValue.textContent = this.temperatureInput.value;
                }
                if (this.updateSettingsBtn) {
                    this.updateSettingsBtn.disabled = false;
                }
            });

            // Auto-save temperature when user releases the slider
            this.temperatureInput.addEventListener('change', () => {
                this.saveTemperature();
            });
        }

        // Load AI Tools config and tools when the AI Tools tab becomes visible
        if (this.aiToolsTab) {
            this.aiToolsTab.addEventListener('shown.bs.tab', () => {
                this.loadAiToolsTab();
            });
        }

        // AI Tool settings save button
        if (this.saveToolSettingsBtn) {
            this.saveToolSettingsBtn.addEventListener('click', () => {
                this.saveToolSettings();
            });
        }
    }
    
    /**
     * Open Spirit Chat modal and load a specific conversation
     */
    openConversation(conversationId) {
        if (!window.spiritChatManager) {
            console.error('Spirit Chat Manager not initialized');
            return;
        }
        
        // Get current spirit ID
        const spiritId = this.spirit?.id;
        if (!spiritId) {
            console.error('Spirit ID not available');
            return;
        }
        
        // Store selected spirit ID
        localStorage.setItem('selectedSpiritId', spiritId);
        
        // Switch to the correct spirit first
        window.spiritChatManager.switchSpirit(spiritId);
        
        // Open the modal
        const spiritChatModal = document.getElementById('spiritChatModal');
        if (spiritChatModal) {
            const modal = new bootstrap.Modal(spiritChatModal);
            modal.show();
            
            // Load conversation immediately after modal is shown (better UX - don't wait for list)
            spiritChatModal.addEventListener('shown.bs.modal', () => {
                window.spiritChatManager.loadConversation(conversationId);
            }, { once: true });
        }
    }
    
    /**
     * Open Spirit Chat modal (just chat, no new conversation)
     */
    openSpiritChat() {
        // Get current spirit ID
        const spiritId = this.spirit?.id;
        if (!spiritId) {
            console.error('Spirit ID not available');
            return;
        }
        
        // Store selected spirit ID
        localStorage.setItem('selectedSpiritId', spiritId);
        
        // Switch to the correct spirit
        if (window.spiritChatManager) {
            window.spiritChatManager.switchSpirit(spiritId);
        }
        
        const spiritChatModal = document.getElementById('spiritChatModal');
        if (spiritChatModal) {
            const modal = new bootstrap.Modal(spiritChatModal);
            modal.show();
        }
    }
    
    /**
     * Open Spirit Chat modal and New Conversation modal
     */
    openSpiritChatModal() {
        // Get current spirit ID
        const spiritId = this.spirit?.id;
        if (!spiritId) {
            console.error('Spirit ID not available');
            return;
        }
        
        // Store selected spirit ID
        localStorage.setItem('selectedSpiritId', spiritId);
        
        // Switch to the correct spirit
        if (window.spiritChatManager) {
            window.spiritChatManager.switchSpirit(spiritId);
        }
        
        const spiritChatModal = document.getElementById('spiritChatModal');
        if (spiritChatModal) {
            const modal = new bootstrap.Modal(spiritChatModal);
            modal.show();
            
            // After Spirit Chat modal is shown, open New Conversation modal on top
            spiritChatModal.addEventListener('shown.bs.modal', () => {
                const newConversationModal = document.getElementById('newConversationModal');
                if (newConversationModal) {
                    const newConvModal = new bootstrap.Modal(newConversationModal);
                    newConvModal.show();
                    
                    // Focus on title input when modal is shown
                    newConversationModal.addEventListener('shown.bs.modal', () => {
                        const conversationTitle = document.getElementById('conversationTitle');
                        if (conversationTitle) {
                            conversationTitle.focus();
                        }
                    }, { once: true });
                }
            }, { once: true });
        }
    }
    
    /**
     * Load the user's spirit from the API
     */
    async loadSpirit() {
        try {
            const response = await fetch(this.apiEndpoints.get);
            
            if (response.status === 404) {
                return;
            }
            
            if (!response.ok) {
                throw new Error('Failed to load spirit');
            }
            
            this.spirit = await response.json();
            
            this.showSpirit();
            
        } catch (error) {
            console.error('Error loading spirit:', error);
            this.showError(this.translate('error.loading_spirit', 'Failed to load spirit'));
        }
    }

    /**
     * Reload the user's spirit from the API
     */
    async reloadSpirit() {
        try {
            const response = await fetch(this.apiEndpoints.get);
            
            if (!response.ok) {
                throw new Error('Failed to reload spirit');
            }
            
            this.spirit = await response.json();
        } catch (error) {
            console.error('Error reloading spirit:', error);
            this.showError(this.translate('error.reloading_spirit', 'Failed to reload spirit'));
        }
    }
    
    /**
     * Create a new spirit
     * @param {string} name - The spirit's name
     */
    async createSpirit(name) {
        try {
            const response = await fetch(this.apiEndpoints.create, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({ name })
            });
            
            if (!response.ok) {
                throw new Error('Failed to create spirit');
            }
            
            this.spirit = await response.json();
            this.showSpirit();
            
        } catch (error) {
            console.error('Error creating spirit:', error);
            this.showError(this.translate('error.creating_spirit', 'Failed to create spirit'));
        }
    }

    /**
     * Show the spirit display and hide the creation form
     */
    showSpirit() {
        // Hide loading indicator
        const loadingElement = document.getElementById('spirit-loading');
        if (loadingElement) {
            loadingElement.classList.add('d-none');
        }
        
        // Show sidebar info card
        if (this.sidebarInfo) {
            this.sidebarInfo.classList.remove('d-none');
        }
        
        if (this.spiritDisplay) {
            this.spiritDisplay.classList.remove('d-none');
            this.updateSpiritDisplay();
            //this.initSpiritVisualization();
        }

        // Restore last active tab after async content is rendered
        this.restoreActiveTab();
    }

    /**
     * Restore the last active spirit tab from localStorage
     */
    restoreActiveTab() {
        const lastTabId = localStorage.getItem('spirit-last-active-tab');
        if (!lastTabId) return;

        const tabTrigger = document.getElementById(lastTabId);
        if (!tabTrigger) return;

        if (typeof bootstrap !== 'undefined' && bootstrap.Tab) {
            bootstrap.Tab.getOrCreateInstance(tabTrigger).show();
        }
    }
    
    /**
     * Update the spirit display with current data
     */
    updateSpiritDisplay() {
        if (!this.spirit) return;

        const progression = this.spirit.progression || {};
        const settings = this.spirit.settings || {};

        // Extract spirit color from settings
        let visualState = settings.visualState || '{"color":"#95ec86"}';
        let color = null;
        try {
            color = JSON.parse(visualState)?.color || null;
        } catch (e) {
            color = '#95ec86';
        }

        // Update Spirit icon color
        const spiritIcon = document.querySelector('#spiritChatAvatar .spirit-detail-icon');
        if (spiritIcon && color) {
            spiritIcon.style.color = color;
        }

        // Update overview avatar icon color
        const overviewIcon = document.querySelector('#spiritOverviewAvatar .spirit-detail-icon');
        if (overviewIcon && color) {
            overviewIcon.style.color = color;
        }

        // Update basic info
        if (this.nameDisplay) {
            this.nameDisplay.textContent = this.spirit.name;
            document.title = document.title + ": " + this.spirit.name;
        }

        if (this.levelDisplay) {
            this.levelDisplay.textContent = progression.level;
        }

        // Update experience bar
        if (this.experienceBar && progression.percentage !== undefined) {
            this.experienceBar.style.width = `${progression.percentage}%`;
            this.experienceBar.setAttribute('aria-valuenow', progression.percentage);
        }

        if (this.experienceDisplay) {
            this.experienceDisplay.textContent = progression.experience;
        }

        if (this.nextLevelDisplay) {
            this.nextLevelDisplay.textContent = progression.nextLevelThreshold;
        }

        // Update overview tab fields
        if (this.overviewName) {
            this.overviewName.textContent = this.spirit.name;
        }
        if (this.overviewLevel) {
            this.overviewLevel.textContent = progression.level;
        }
        if (this.overviewExperience) {
            this.overviewExperience.textContent = progression.experience;
        }
        if (this.overviewNextLevel) {
            this.overviewNextLevel.textContent = progression.nextLevelThreshold;
        }
        if (this.overviewExperienceBar && progression.percentage !== undefined) {
            this.overviewExperienceBar.style.width = `${progression.percentage}%`;
            this.overviewExperienceBar.setAttribute('aria-valuenow', progression.percentage);
        }
        if (this.overviewExperienceText) {
            this.overviewExperienceText.textContent = progression.experience;
        }
        if (this.overviewNextLevelText) {
            this.overviewNextLevelText.textContent = progression.nextLevelThreshold;
        }

        // Initialize Profile Memory Graph only when its tab is visible
        this.initProfileMemoryGraphIfVisible();

        // Update system prompt and AI model fields
        if (this.systemPromptInput) {
            this.systemPromptInput.value = settings.systemPrompt || '';
        }

        if (this.aiModelSelect) {
            // Set the value of the hidden input (no longer a select dropdown)
            const modelId = settings.aiModel || '';
            this.aiModelSelect.value = modelId;
            
            // Update the display button text with model name
            this.updateAiModelDisplay(modelId);
        }

        if (this.subconsciousnessAgentAiModelSelect) {
            // Set the value of the hidden input for the sub-consciousness agent model
            const modelId = settings.subconsciousnessAgentAiModel || '';
            this.subconsciousnessAgentAiModelSelect.value = modelId;
            
            // Update the display button text with model name
            this.updateSubconsciousnessAgentAiModelDisplay(modelId);
        }

        // Set temperature value
        if (this.temperatureInput) {
            const temperature = settings.temperature ?? '0.7';
            this.temperatureInput.value = temperature;
            if (this.temperatureValue) {
                this.temperatureValue.textContent = temperature;
            }
        }

        // Disable save button after loading
        if (this.updateSettingsBtn) {
            this.updateSettingsBtn.disabled = true;
        }
    }

    /**
     * Update AI model display button text
     */
    async updateAiModelDisplay(modelId) {
        const displayElement = document.getElementById('spirit-ai-model-display');
        if (!displayElement) return;
        
        if (!modelId) {
            displayElement.textContent = this.translations.use_primary_model || 'Use Primary AI Model';
            return;
        }
        
        try {
            // Fetch model info from API
            const response = await fetch(`/api/ai/model/selector?type=primary`);
            if (!response.ok) return;
            
            const data = await response.json();
            if (data.success && data.models) {
                const model = data.models.find(m => m.id === modelId);
                if (model) {
                    displayElement.textContent = model.modelName;
                } else {
                    displayElement.textContent = this.translations.use_primary_model || 'Use Primary AI Model';
                }
            }
        } catch (error) {
            console.error('Error fetching model info:', error);
        }
    }

    /**
     * Update sub-consciousness agent AI model display button text
     */
    async updateSubconsciousnessAgentAiModelDisplay(modelId) {
        const displayElement = document.getElementById('spirit-subconsciousness-agent-ai-model-display');
        if (!displayElement) return;
        
        if (!modelId) {
            displayElement.textContent = this.translations.use_primary_model || 'Use Primary AI Model';
            return;
        }
        
        try {
            // Fetch model info from API
            const response = await fetch(`/api/ai/model/selector?type=primary`);
            if (!response.ok) return;
            
            const data = await response.json();
            if (data.success && data.models) {
                const model = data.models.find(m => m.id === modelId);
                if (model) {
                    displayElement.textContent = model.modelName;
                } else {
                    displayElement.textContent = this.translations.use_primary_model || 'Use Primary AI Model';
                }
            }
        } catch (error) {
            console.error('Error fetching sub-agent model info:', error);
        }
    }

    /**
     * Initialize or refresh the profile memory stats/graph loader
     */
    initProfileMemoryGraph() {
        if (!this.spirit?.id) {
            return;
        }

        if (this.profileMemoryGraph) {
            // Already initialized; just refresh stats
            this.profileMemoryGraph.refresh();
            return;
        }

        // Initialize the memory graph/stats loader
        this.profileMemoryGraph = new ProfileMemoryGraph(this.spirit.id);
    }

    /**
     * Initialize the profile memory graph when the Memory tab is visible
     */
    initProfileMemoryGraphIfVisible() {
        if (!this.spirit?.id) {
            return;
        }

        this.initProfileMemoryGraph();
    }

    /**
     * Initialize the profile Memory Type select
     * Loads current config from API and wires up change handler
     */
    async initProfileMemoryType() {
        const select = document.getElementById('profileMemoryTypeSelect');
        const infoEl = document.getElementById('profileMemoryTypeInfo');
        if (!select || !this.spirit?.id) return;

        // Load current prompt config
        try {
            const response = await fetch(`/api/spirit/${this.spirit.id}/system-prompt-preview`);
            if (response.ok) {
                const data = await response.json();
                const includeMemory = data.config?.includeMemory ?? true;
                const memoryType = data.config?.memoryType ?? 2;
                // If memory is disabled, show "No memory" regardless of memoryType
                select.value = includeMemory ? String(memoryType) : '0';
                this.updateMemoryTypeInfo(includeMemory ? memoryType : 0, infoEl);
            }
        } catch (e) {
            console.error('Failed to load memory type config:', e);
        }

        // Handle change — save immediately with both includeMemory and memoryType
        select.addEventListener('change', async (e) => {
            const val = parseInt(e.target.value, 10);
            const includeMemory = val !== 0;
            const memoryType = includeMemory ? val : 2; // keep last real type as default
            this.updateMemoryTypeInfo(val, infoEl);

            try {
                const response = await fetch(`/api/spirit/${this.spirit.id}/system-prompt-config`, {
                    method: 'PUT',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ includeMemory, memoryType })
                });
                if (response.ok && window.toast) {
                    window.toast.success(this.translate('spirit.memory.type_saved', 'Memory type updated'));
                }
            } catch (err) {
                console.error('Failed to save memory type:', err);
                if (window.toast) {
                    window.toast.error(this.translate('error.saving_memory_type', 'Failed to save memory type'));
                }
            }
        });
    }

    /**
     * Update the Memory Type info text below the select
     */
    updateMemoryTypeInfo(memoryType, infoEl) {
        if (!infoEl) return;

        const descriptions = {
            '0': '<i class="mdi mdi-cancel me-1 text-secondary"></i>Memory disabled. Spirit will not use any memory context.',
            '-1': '<i class="mdi mdi-file-document-outline me-1 text-warning"></i>Legacy .md files dumped into system prompt each message — uses more tokens.',
            '1': '<i class="mdi mdi-brain me-1 text-cyber"></i>FTS5 keyword search with automatic recall. On-demand, token-efficient.',
            '2': '<i class="mdi mdi-robot-outline me-1 text-cyber"></i>Reflexes + AI Sub-Agent synthesis. Most contextual, adds ~0.7 Credit/message.'
        };

        infoEl.innerHTML = descriptions[String(memoryType)] || '';
    }

    /**
     * Load both library packs and available packs for the Memory tab
     */
    async loadMemoryPacks() {
        if (!this.spirit?.id || this.memoryPacksLoaded) return;

        await Promise.all([
            this.loadLibraryPacks(),
            this.loadAvailablePacks()
        ]);

        this.memoryPacksLoaded = true;
    }

    /**
     * Load packs currently in the Spirit's memory library
     */
    async loadLibraryPacks() {
        if (!this.memoryLibraryPacksList || !this.spirit?.id) return;

        try {
            const response = await fetch(this.apiEndpoints.memoryLibraryPacks.replace('{id}', this.spirit.id));
            if (!response.ok) throw new Error('Failed to load library packs');

            const data = await response.json();
            this.renderLibraryPacks(data.packs || []);
        } catch (error) {
            console.error('Error loading library packs:', error);
            this.memoryLibraryPacksList.innerHTML = `
                <div class="text-secondary small p-3">
                    <i class="mdi mdi-alert-circle-outline me-1"></i>${this.translate('error.loading_memory_packs', 'Failed to load memory packs')}
                </div>
            `;
        }
    }

    /**
     * Load all available memory packs across the project
     */
    async loadAvailablePacks() {
        if (!this.memoryAvailablePacksList || !this.spirit?.id) return;

        try {
            const response = await fetch(this.apiEndpoints.memoryAvailablePacks.replace('{id}', this.spirit.id));
            if (!response.ok) throw new Error('Failed to load available packs');

            const data = await response.json();
            this.renderAvailablePacks(data.packs || []);
        } catch (error) {
            console.error('Error loading available packs:', error);
            this.memoryAvailablePacksList.innerHTML = `
                <div class="text-secondary small p-3">
                    <i class="mdi mdi-alert-circle-outline me-1"></i>${this.translate('error.loading_memory_packs', 'Failed to load memory packs')}
                </div>
            `;
        }
    }

    /**
     * Render the Spirit memory library pack list
     */
    renderLibraryPacks(packs) {
        if (!this.memoryLibraryPacksList) return;

        if (packs.length === 0) {
            this.memoryLibraryPacksList.innerHTML = `
                <div class="text-secondary small p-3">
                    ${this.translate('spirit.memory.no_library_packs', 'No packs in library')}
                </div>
            `;
            return;
        }

        this.memoryLibraryPacksList.innerHTML = packs.map(pack => {
            const packRelPath = pack.path || '';
            const fileName = packRelPath.split('/').pop() || '';
            const displayName = pack.name || pack.displayName || fileName.replace(/\.[^.]+$/, '');
            const nodes = pack.nodes ?? pack.totalNodes ?? 0;
            const relationships = pack.relationships ?? pack.totalRelationships ?? 0;

            return `
                <div class="list-group-item bg-dark bg-opacity-25 text-light border-secondary border-opacity-10 d-flex justify-content-between align-items-center">
                    <div class="me-2 overflow-hidden">
                        <div class="small fw-medium text-truncate">${this.escapeHtml(displayName)}</div>
                        <div class="text-secondary small">
                            <i class="mdi mdi-circle-multiple me-1 text-cyber opacity-50"></i>${nodes}x ${this.translate('spirit.memory.nodes', 'nodes')}
                            <i class="mdi mdi-link-variant ms-2 me-1 text-cyber opacity-50"></i>${relationships}x ${this.translate('spirit.memory.relationships', 'relationships')}
                        </div>
                    </div>
                    <button type="button" class="btn btn-sm btn-outline-warning border-0" title="${this.translate('spirit.memory.remove_from_library', 'Remove from library')}"
                            data-pack-path="${this.escapeHtml(packRelPath)}">
                        <i class="mdi mdi-book-remove-outline"></i>
                    </button>
                </div>
            `;
        }).join('');

        this.memoryLibraryPacksList.querySelectorAll('button[data-pack-path]').forEach(btn => {
            btn.addEventListener('click', () => {
                const packRelPath = btn.dataset.packPath;
                if (packRelPath) this.removePackFromLibrary(packRelPath);
            });
        });

        if (this.memoryLibraryPacksFilter) {
            this.filterMemoryPacks(this.memoryLibraryPacksFilter.value, this.memoryLibraryPacksList);
        }
    }

    /**
     * Render the available memory pack list
     */
    renderAvailablePacks(packs) {
        if (!this.memoryAvailablePacksList) return;

        if (packs.length === 0) {
            this.memoryAvailablePacksList.innerHTML = `
                <div class="text-secondary small p-3">
                    ${this.translate('spirit.memory.no_available_packs', 'No available packs')}
                </div>
            `;
            return;
        }

        this.memoryAvailablePacksList.innerHTML = packs.map(pack => {
            const packPath = pack.path || '';
            const packName = pack.name || '';
            const displayName = pack.displayName || packName.replace('.' + (pack.name?.split('.').pop() || ''), '') || packName;
            const nodes = pack.totalNodes ?? 0;
            const relationships = pack.totalRelationships ?? 0;
            const inLibrary = pack.inLibrary;

            return `
                <div class="list-group-item bg-dark bg-opacity-25 text-light border-secondary border-opacity-10 d-flex justify-content-between align-items-center ${inLibrary ? 'opacity-75' : ''}">
                    <div class="me-2 overflow-hidden">
                        <div class="small fw-medium text-truncate">${this.escapeHtml(displayName)}</div>
                        <div class="text-secondary small">
                            <i class="mdi mdi-circle-multiple me-1 text-cyber opacity-50"></i>${nodes}x ${this.translate('spirit.memory.nodes', 'nodes')}
                            <i class="mdi mdi-link-variant ms-2 me-1 text-cyber opacity-50"></i>${relationships}x ${this.translate('spirit.memory.relationships', 'relationships')}
                        </div>
                    </div>
                    <button type="button" class="btn btn-sm ${inLibrary ? 'btn-outline-secondary' : 'btn-outline-success'} border-0" title="${this.translate('spirit.memory.add_to_library', 'Add to library')}"
                            data-pack-path="${this.escapeHtml(packPath)}" data-pack-name="${this.escapeHtml(packName)}" ${inLibrary ? 'disabled' : ''}>
                        <i class="mdi ${inLibrary ? 'mdi-check' : 'mdi-book-plus-outline'}"></i>
                    </button>
                </div>
            `;
        }).join('');

        this.memoryAvailablePacksList.querySelectorAll('button[data-pack-path]:not([disabled])').forEach(btn => {
            btn.addEventListener('click', () => {
                const packPath = btn.dataset.packPath;
                const packName = btn.dataset.packName;
                if (packPath && packName) this.addPackToLibrary(packPath, packName);
            });
        });

        if (this.memoryAvailablePacksFilter) {
            this.filterMemoryPacks(this.memoryAvailablePacksFilter.value, this.memoryAvailablePacksList);
        }
    }

    /**
     * Add a pack to the Spirit's memory library
     */
    async addPackToLibrary(packPath, packName) {
        if (!this.spirit?.id || !packPath || !packName) return;

        try {
            const response = await fetch(this.apiEndpoints.memoryAddPack.replace('{id}', this.spirit.id), {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ packPath, packName })
            });

            if (!response.ok) throw new Error('Failed to add pack');

            if (window.toast) {
                window.toast.success(this.translate('spirit.memory.add_to_library', 'Pack added to library'));
            }

            // Refresh both lists
            this.memoryPacksLoaded = false;
            await this.loadMemoryPacks();
            // Also refresh the memory stats at the top
            this.initProfileMemoryGraph();
        } catch (error) {
            console.error('Error adding pack to library:', error);
            if (window.toast) {
                window.toast.error(this.translate('error.adding_memory_pack', 'Failed to add pack to library'));
            }
        }
    }

    /**
     * Remove a pack from the Spirit's memory library
     */
    async removePackFromLibrary(packRelPath) {
        if (!this.spirit?.id || !packRelPath) return;

        try {
            const response = await fetch(this.apiEndpoints.memoryRemovePack.replace('{id}', this.spirit.id), {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ packPath: packRelPath })
            });

            if (!response.ok) throw new Error('Failed to remove pack');

            if (window.toast) {
                window.toast.success(this.translate('spirit.memory.remove_from_library', 'Pack removed from library'));
            }

            // Refresh both lists
            this.memoryPacksLoaded = false;
            await this.loadMemoryPacks();
            // Also refresh the memory stats at the top
            this.initProfileMemoryGraph();
        } catch (error) {
            console.error('Error removing pack from library:', error);
            if (window.toast) {
                window.toast.error(this.translate('error.removing_memory_pack', 'Failed to remove pack from library'));
            }
        }
    }

    /**
     * Filter a memory pack list by display name (case-insensitive)
     */
    filterMemoryPacks(query, listElement) {
        if (!listElement) return;

        const term = (query || '').toLowerCase().trim();
        listElement.querySelectorAll('.list-group-item').forEach(item => {
            const nameEl = item.querySelector('.fw-medium');
            const name = nameEl ? nameEl.textContent.toLowerCase() : '';
            item.classList.toggle('d-none', term && !name.includes(term));
        });
    }

    /**
     * Escape HTML special characters to prevent XSS
     */
    escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    /**
     * Initialize the spirit visualization using Three.js
     */
    initSpiritVisualization() {
        if (!this.spiritCanvas) return;
        
        try {
            const scene = new THREE.Scene();
            const camera = new THREE.PerspectiveCamera(75, this.spiritCanvas.width / this.spiritCanvas.height, 0.1, 1000);
            const renderer = new THREE.WebGLRenderer({ canvas: this.spiritCanvas, alpha: true });
            
            // Create a simple spirit visualization based on level and consciousness
            const geometry = new THREE.SphereGeometry(1, 32, 32);
            const material = new THREE.MeshPhongMaterial({
                color: 0x00ffff,
                emissive: 0x006666,
                shininess: 100,
                transparent: true,
                opacity: 0.8
            });
            
            const spirit = new THREE.Mesh(geometry, material);
            scene.add(spirit);
            
            // Add ambient light
            const ambientLight = new THREE.AmbientLight(0x404040);
            scene.add(ambientLight);
            
            // Add directional light
            const directionalLight = new THREE.DirectionalLight(0xffffff, 1);
            directionalLight.position.set(1, 1, 1);
            scene.add(directionalLight);
            
            camera.position.z = 5;
            
            // Animation loop
            const animate = () => {
                requestAnimationFrame(animate);
                
                spirit.rotation.x += 0.01;
                spirit.rotation.y += 0.01;
                
                renderer.render(scene, camera);
            };
            
            animate();
        } catch(error) {
            console.error('Error initializing Three.js visualization:', error);
            // Fallback to a simple colored div
            this.spiritCanvas.style.display = 'none';
            const fallback = document.createElement('div');
            fallback.style.width = '250px';
            fallback.style.height = '250px';
            fallback.style.borderRadius = '50%';
            fallback.style.background = 'radial-gradient(circle, #00ffff, #006666)';
            fallback.style.boxShadow = '0 0 20px #00ffff';
            this.spiritCanvas.parentNode.appendChild(fallback);
        }
    }


    /**
     * Show an error message
     * @param {string} message - The error message to display
     */
    showError(message) {
        const errorAlert = document.createElement('div');
        errorAlert.className = 'alert alert-danger alert-dismissible fade show';
        errorAlert.role = 'alert';
        errorAlert.innerHTML = `
            ${message}
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        `;
        
        if (this.spiritContainer) {
            this.spiritContainer.prepend(errorAlert);
            
            // Auto-dismiss after 5 seconds
            setTimeout(() => {
                errorAlert.classList.remove('show');
                setTimeout(() => errorAlert.remove(), 150);
            }, 5000);
        }
    }
    
    /**
     * Translate a key using the provided translations
     * @param {string} key - The translation key
     * @param {string} fallback - Fallback text if translation is not found
     * @returns {string} - The translated text or fallback
     */
    translate(key, fallback) {
        return this.translations[key] || fallback;
    }
    
    /**
     * Update the spirit settings (system prompt and AI model)
     */
    async updateSpiritSettings() {
        if (!this.spirit || !this.systemPromptInput || !this.aiModelSelect) return;
        
        try {
            const systemPrompt = this.systemPromptInput.value.trim();
            const aiModel = this.aiModelSelect.value;
            const subconsciousnessAgentAiModel = this.subconsciousnessAgentAiModelSelect?.value ?? '';
            const temperature = this.temperatureInput?.value ?? '0.7';
            
            // Send the update to the server
            const response = await fetch(this.apiEndpoints.updateSettings.replace('{id}', this.spirit.id), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    systemPrompt,
                    aiModel,
                    subconsciousnessAgentAiModel,
                    temperature
                })
            });
            
            if (!response.ok) {
                throw new Error('Failed to update spirit settings');
            }
            
            // Show success message using toast if available
            if (window.toast) {
                window.toast.success(this.translate('spirit.settings_updated', 'Spirit settings updated successfully'));
            }
            
            // Reload the spirit to get the latest data
            await this.reloadSpirit();
            this.updateSpiritDisplay();
            
            // Update Chat Settings modal AI model name (no HTTP request needed)
            if (window.spiritChatManager) {
                window.spiritChatManager.updateModelNameFromProfile();
            }
            
        } catch (error) {
            console.error('Error updating spirit settings:', error);
            this.showError(this.translate('error.updating_settings', 'Failed to update spirit settings'));
        }
    }

    /**
     * Auto-save only the temperature setting when the slider changes
     */
    async saveTemperature() {
        if (!this.spirit || !this.temperatureInput) return;

        try {
            const temperature = this.temperatureInput.value;
            const response = await fetch(this.apiEndpoints.updateSettings.replace('{id}', this.spirit.id), {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ temperature })
            });

            if (!response.ok) {
                throw new Error('Failed to update temperature');
            }

            if (window.toast) {
                window.toast.success(this.translate('spirit.temperature_saved', 'Temperature saved'));
            }
        } catch (error) {
            console.error('Error saving temperature:', error);
            if (window.toast) {
                window.toast.error(this.translate('error.saving_temperature', 'Failed to save temperature'));
            }
        }
    }

    /**
     * Load AI Tools config and tool list when the AI Tools tab is shown
     */
    async loadAiToolsTab() {
        if (!this.spirit) return;
        const spiritId = this.spirit.id;

        // Show loading state
        if (this.aiToolsList) {
            this.aiToolsList.innerHTML = `
                <div class="text-center p-3">
                    <div class="spinner-border spinner-border-sm text-cyber" role="status"></div>
                </div>
            `;
        }

        // Load prompt config
        try {
            const configResponse = await fetch(this.apiEndpoints.systemPromptPreview.replace('{id}', spiritId));
            if (configResponse.ok) {
                const configData = await configResponse.json();
                const config = configData.config || {};

                if (this.aiToolsDataOptimization) {
                    this.aiToolsDataOptimization.checked = !!config.aiToolsDataOptimization;
                }
                if (this.includeAiTools) {
                    this.includeAiTools.checked = config.includeTools ?? true;
                }
            }
        } catch (error) {
            console.error('Error loading AI tools config:', error);
        }

        // Load tools
        try {
            const toolsResponse = await fetch(this.apiEndpoints.tools.replace('{id}', spiritId));
            if (toolsResponse.ok) {
                const data = await toolsResponse.json();
                this.aiToolsData = data.tools || [];
                this.renderAiTools();
            }
        } catch (error) {
            console.error('Error loading AI tools:', error);
            if (this.aiToolsList) {
                this.aiToolsList.innerHTML = `
                    <div class="alert alert-danger small py-1 mb-0">${error.message || 'Failed to load tools'}</div>
                `;
            }
        }

        // Wire up master toggle to show/hide tool list and auto-save
        if (this.includeAiTools) {
            this.includeAiTools.onchange = () => {
                this.renderAiTools();
                this.updateAiTools();
            };
        }
        if (this.aiToolsDataOptimization) {
            this.aiToolsDataOptimization.onchange = () => {
                this.updateAiTools();
            };
        }
    }

    /**
     * Render AI Tools list in the Spirit AI Tools tab, grouped by category
     */
    renderAiTools() {
        if (!this.aiToolsList) return;

        const includeTools = this.includeAiTools ? this.includeAiTools.checked : true;
        if (!includeTools) {
            this.aiToolsList.classList.add('d-none');
            this.aiToolsList.classList.remove('d-flex');
            return;
        }
        this.aiToolsList.classList.remove('d-none');
        this.aiToolsList.classList.add('d-flex');

        if (!this.aiToolsData || this.aiToolsData.length === 0) {
            this.aiToolsList.innerHTML = `
                <div class="text-secondary small py-2">${this.translate('ai_tools.settings.no_tools', 'No AI tools available')}</div>
            `;
            return;
        }

        this.aiToolsList.innerHTML = '';

        // Group by category
        const groups = {};
        this.aiToolsData.forEach(tool => {
            const cat = tool.category || 'general';
            if (!groups[cat]) groups[cat] = [];
            groups[cat].push(tool);
        });

        // Render each category group in preferred order
        const categoryOrder = ['file', 'web', 'image', 'memory', 'profile', 'development', 'spirit', 'utility', 'general'];
        categoryOrder.forEach(cat => {
            if (!groups[cat] || groups[cat].length === 0) return;
            this.renderAiToolsCategoryGroup(cat, groups[cat]);
        });

        // Render any remaining categories not in the predefined order
        Object.keys(groups).forEach(cat => {
            if (!categoryOrder.includes(cat)) {
                this.renderAiToolsCategoryGroup(cat, groups[cat]);
            }
        });
    }

    renderAiToolsCategoryGroup(category, tools) {
        const groupEl = document.createElement('div');
        groupEl.className = 'mb-3';

        const icon = this.categoryIcons[category] || 'mdi-tools';
        const label = this.categoryLabels[category] || category;

        groupEl.innerHTML = `
            <div class="text-cyber mb-2 d-flex align-items-center gap-2">
                <i class="mdi ${icon}"></i>
                <span class="text-light opacity-75 small">${label}</span>
                <span class="badge bg-secondary bg-opacity-25 text-secondary small">${tools.length}</span>
            </div>
        `;

        const toolsContainer = document.createElement('div');
        toolsContainer.className = 'd-flex flex-column gap-1';

        tools.sort((a, b) => a.name.localeCompare(b.name)).forEach(tool => {
            toolsContainer.appendChild(this.renderAiToolCard(tool));
        });

        groupEl.appendChild(toolsContainer);
        this.aiToolsList.appendChild(groupEl);
    }

    renderAiToolCard(tool) {
        const cardEl = document.createElement('div');
        cardEl.className = `border rounded p-2 ${tool.isActiveForSpirit ? 'border-success border-opacity-25 bg-success bg-opacity-10' : 'border-secondary border-opacity-25 bg-secondary bg-opacity-10'}`;
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
                    <span class="cursor-pointer tool-name-toggle text-cyber fw-semibold small" data-tool-id="${tool.id}" title="Show details">
                        <i class="mdi mdi-chevron-right tool-chevron me-1 text-secondary" style="transition: transform 0.2s;"></i>${tool.name}
                    </span>
                    <button class="btn btn-sm btn-outline-info border-0 tool-settings-btn d-none py-0 px-1" data-tool-id="${tool.id}" title="${this.translate('ai_tools.settings.tool_settings', 'Tool Settings')}">
                        <i class="mdi mdi-cog"></i>
                    </button>
                </div>
                <div class="form-check form-switch mb-0 ms-1">
                    <input class="form-check-input tool-active-toggle" type="checkbox" role="switch"
                        data-tool-id="${tool.id}" ${tool.isActiveForSpirit ? 'checked' : ''}>
                </div>
            </div>
            <div class="tool-details d-none mt-2 ms-3 ps-2 border-start border-secondary border-opacity-25">
                <div class="text-light opacity-75 small mb-1">${tool.description}</div>
                ${paramsHtml ? `
                    <div class="mt-1">
                        <div class="text-secondary small mb-1"><i class="mdi mdi-code-json me-1"></i>${this.translate('ai_tools.settings.parameters', 'Parameters')}</div>
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

        // Toggle active state
        const activeToggle = cardEl.querySelector('.tool-active-toggle');
        activeToggle.addEventListener('change', (e) => {
            tool.isActiveForSpirit = e.target.checked;
            if (e.target.checked) {
                cardEl.className = cardEl.className.replace('border-secondary', 'border-success').replace('bg-secondary', 'bg-success');
            } else {
                cardEl.className = cardEl.className.replace('border-success', 'border-secondary').replace('bg-success', 'bg-secondary');
            }
            this.updateAiTools();
        });

        // Settings button — load settings to check if tool has any
        this.checkToolSettings(tool.id, cardEl.querySelector('.tool-settings-btn'));

        return cardEl;
    }

    // =====================
    // Tool Settings Modal
    // =====================

    async checkToolSettings(toolId, settingsBtn) {
        if (!settingsBtn) return;
        try {
            const response = await fetch(`${this.apiEndpoints.aiTool}/${toolId}/settings`);
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
        if (!this.toolSettingsModal) return;
        this.currentToolId = toolId;

        const tool = this.aiToolsData.find(t => t.id === toolId);
        if (this.toolSettingsModalTitle) {
            this.toolSettingsModalTitle.innerHTML = `<i class="mdi mdi-cog me-2"></i>${tool ? tool.name : this.translate('ai_tools.settings.tool_settings', 'Tool Settings')}`;
        }

        if (this.toolSettingsModalBody) {
            this.toolSettingsModalBody.innerHTML = `
                <div class="text-center p-3">
                    <div class="spinner-border spinner-border-sm text-cyber" role="status"></div>
                </div>
            `;
        }

        const modal = new bootstrap.Modal(this.toolSettingsModal);
        modal.show();

        try {
            const response = await fetch(`${this.apiEndpoints.aiTool}/${toolId}/settings`);
            const data = await response.json();
            this.renderToolSettingsForm(data.settings || []);
        } catch (error) {
            console.error('Error loading tool settings:', error);
            if (this.toolSettingsModalBody) {
                this.toolSettingsModalBody.innerHTML = `<div class="alert alert-danger small py-2 mb-0">${error.message || 'Failed to load settings'}</div>`;
            }
        }
    }

    renderToolSettingsForm(settings) {
        if (!this.toolSettingsModalBody) return;

        if (settings.length === 0) {
            this.toolSettingsModalBody.innerHTML = `<div class="text-secondary small py-2">${this.translate('ai_tools.settings.no_settings', 'No configurable settings for this tool')}</div>`;
            return;
        }

        let html = '';
        settings.forEach(setting => {
            html += this.renderToolSettingInput(setting);
        });

        this.toolSettingsModalBody.innerHTML = html;
        this.bindAiModelSelectors(settings);
    }

    bindAiModelSelectors(settings) {
        this.toolSettingsModalBody.querySelectorAll('.ai-model-select-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                const inputId = btn.dataset.inputId;
                const hiddenInput = document.getElementById(inputId);
                const displayEl = document.getElementById(`${inputId}-display`);
                const currentModelId = hiddenInput ? hiddenInput.value : null;

                if (window.aiModelSelector) {
                    const toolModal = bootstrap.Modal.getInstance(this.toolSettingsModal);
                    if (toolModal) toolModal.hide();

                    window.aiModelSelector.open('primary', (model) => {
                        if (hiddenInput) hiddenInput.value = model.id;
                        if (displayEl) displayEl.textContent = model.modelName;
                        setTimeout(() => {
                            const toolModal2 = new bootstrap.Modal(this.toolSettingsModal);
                            toolModal2.show();
                        }, 300);
                    }, currentModelId || null);
                }
            });
        });

        this.toolSettingsModalBody.querySelectorAll('.ai-model-clear-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                const inputId = btn.dataset.inputId;
                const hiddenInput = document.getElementById(inputId);
                const displayEl = document.getElementById(`${inputId}-display`);
                if (hiddenInput) hiddenInput.value = '';
                if (displayEl) displayEl.textContent = this.translate('ai_tools.settings.default_model', 'Default (auto)');
                btn.remove();
            });
        });

        settings.filter(s => s.type === 'aiModel' && s.value).forEach(setting => {
            this.resolveModelName(setting.key, setting.value);
        });
    }

    async resolveModelName(settingKey, modelId) {
        if (!modelId) return;
        const displayEl = document.getElementById(`tool-setting-${settingKey}-display`);
        if (!displayEl) return;

        try {
            const response = await fetch(`/api/ai/model/${modelId}`);
            const data = await response.json();
            if (data.model && data.model.modelName) {
                displayEl.textContent = data.model.modelName;
            } else {
                displayEl.textContent = modelId.substring(0, 12) + '...';
            }
        } catch (e) {
            displayEl.textContent = modelId.substring(0, 12) + '...';
        }
    }

    renderToolSettingInput(setting) {
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
                        <label class="form-label" for="${inputId}"><i class="mdi mdi-numeric me-1 text-cyber"></i>${label}</label>
                        <input type="number" class="form-control form-control-sm glass-input tool-setting-input" id="${inputId}"
                            data-key="${setting.key}" data-type="number" value="${setting.value || ''}">
                        ${desc}
                    </div>
                `;

            case 'textarea':
                return `
                    <div class="mb-3">
                        <label class="form-label" for="${inputId}"><i class="mdi mdi-text me-1 text-cyber"></i>${label}</label>
                        <textarea class="form-control form-control-sm glass-input tool-setting-input" id="${inputId}"
                            data-key="${setting.key}" data-type="textarea" rows="8">${setting.value || ''}</textarea>
                        ${desc}
                    </div>
                `;

            case 'aiModel':
                return `
                    <div class="mb-3">
                        <label class="form-label"><i class="mdi mdi-robot me-1 text-cyber"></i>${label}</label>
                        <input type="hidden" class="tool-setting-input" id="${inputId}"
                            data-key="${setting.key}" data-type="aiModel" value="${setting.value || ''}">
                        <div class="d-flex align-items-center gap-2">
                            <span class="text-light small ai-model-display" id="${inputId}-display">${setting.value ? '...' : this.translate('ai_tools.settings.default_model', 'Default (auto)')}</span>
                            <button type="button" class="btn btn-sm btn-outline-cyber ai-model-select-btn" data-input-id="${inputId}">
                                <i class="mdi mdi-swap-horizontal me-1"></i>${this.translate('ai_tools.settings.select_model', 'Select Model')}
                            </button>
                            ${setting.value ? `<button type="button" class="btn btn-sm btn-outline-secondary ai-model-clear-btn" data-input-id="${inputId}" title="Reset to default">
                                <i class="mdi mdi-close"></i>
                            </button>` : ''}
                        </div>
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
                        <label class="form-label" for="${inputId}"><i class="mdi mdi-menu me-1 text-cyber"></i>${label}</label>
                        <select class="form-select form-select-sm glass-input tool-setting-input" id="${inputId}"
                            data-key="${setting.key}" data-type="select">
                            ${options}
                        </select>
                    </div>
                `;

            case 'json':
                return `
                    <div class="mb-3">
                        <label class="form-label" for="${inputId}"><i class="mdi mdi-code-json me-1 text-cyber"></i>${label}</label>
                        <textarea class="form-control form-control-sm glass-input font-monospace tool-setting-input" id="${inputId}"
                            data-key="${setting.key}" data-type="json" rows="4">${setting.value || ''}</textarea>
                        ${desc}
                    </div>
                `;

            default: // text
                return `
                    <div class="mb-3">
                        <label class="form-label" for="${inputId}"><i class="mdi mdi-form-textbox me-1 text-cyber"></i>${label}</label>
                        <input type="text" class="form-control form-control-sm glass-input tool-setting-input" id="${inputId}"
                            data-key="${setting.key}" data-type="text" value="${setting.value || ''}">
                        ${desc}
                    </div>
                `;
        }
    }

    async saveToolSettings() {
        if (!this.currentToolId) return;

        const inputs = this.toolSettingsModalBody.querySelectorAll('.tool-setting-input');
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

        if (this.saveToolSettingsBtn) {
            this.saveToolSettingsBtn.disabled = true;
            this.saveToolSettingsBtn.querySelector('.spinner-border')?.classList.remove('d-none');
        }

        try {
            const response = await fetch(`${this.apiEndpoints.aiTool}/${this.currentToolId}/settings`, {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ settings })
            });

            const data = await response.json();
            if (data.success) {
                if (window.toast) {
                    window.toast.success(this.translate('ai_tools.settings.saved', 'Settings saved'));
                }
                const modalInstance = bootstrap.Modal.getInstance(this.toolSettingsModal);
                if (modalInstance) modalInstance.hide();
            } else {
                if (window.toast) {
                    window.toast.error(data.error || this.translate('ai_tools.settings.error', 'Failed to save settings'));
                }
            }
        } catch (error) {
            console.error('Error saving tool settings:', error);
            if (window.toast) {
                window.toast.error(this.translate('ai_tools.settings.error', 'Failed to save settings'));
            }
        } finally {
            if (this.saveToolSettingsBtn) {
                this.saveToolSettingsBtn.disabled = false;
                this.saveToolSettingsBtn.querySelector('.spinner-border')?.classList.add('d-none');
            }
        }
    }

    /**
     * Update AI Tools config and active tools
     */
    async updateAiTools() {
        if (!this.spirit) return;
        const spiritId = this.spirit.id;

        const includeTools = this.includeAiTools ? this.includeAiTools.checked : true;
        const aiToolsDataOptimization = this.aiToolsDataOptimization ? this.aiToolsDataOptimization.checked : false;
        const activeToolIds = this.aiToolsData
            .filter(t => t.isActiveForSpirit)
            .map(t => t.id);

        try {
            // Save prompt config (includeTools + aiToolsDataOptimization)
            // We preserve memory config by fetching first, or just send what the endpoint expects
            const configResponse = await fetch(this.apiEndpoints.systemPromptConfig.replace('{id}', spiritId), {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ includeTools, aiToolsDataOptimization })
            });

            if (!configResponse.ok) {
                throw new Error('Failed to update AI tools config');
            }

            // Save active tools
            const toolsResponse = await fetch(this.apiEndpoints.tools.replace('{id}', spiritId), {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ activeToolIds })
            });

            if (!toolsResponse.ok) {
                throw new Error('Failed to update active tools');
            }

            if (window.toast) {
                window.toast.success(this.translate('spirit.ai_tools_updated', 'AI Tools updated successfully'));
            }
        } catch (error) {
            console.error('Error updating AI tools:', error);
            this.showError(this.translate('error.updating_ai_tools', 'Failed to update AI tools'));
        }
    }
}
