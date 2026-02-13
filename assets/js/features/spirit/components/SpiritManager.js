/**
 * SpiritManager - Manages the Spirit feature UI and API interactions
 */
//import * as THREE from 'three';
import { ProfileMemoryGraph } from './ProfileMemoryGraph.js';
import * as bootstrap from 'bootstrap';

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
        this.systemPromptInput = document.getElementById('spirit-system-prompt');
        this.aiModelSelect = document.getElementById('spirit-ai-model');
        this.updateSettingsBtn = document.getElementById('update-spirit-settings');
        this.conversationsList = document.getElementById('conversations-list');
        
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
        
        if (this.spiritDisplay) {
            this.spiritDisplay.classList.remove('d-none');
            this.updateSpiritDisplay();
            //this.initSpiritVisualization();
        }
    }
    
    /**
     * Update the spirit display with current data
     */
    updateSpiritDisplay() {
        if (!this.spirit) return;

        const progression = this.spirit.progression || {};
        const settings = this.spirit.settings || {};

        // Update Spirit icon color
        const spiritIcon = document.querySelector('#spiritChatAvatar .spirit-detail-icon');
        if (spiritIcon) {
            let visualState = settings.visualState || '{"color":"#95ec86"}';
            let color = null;
            try {
                color = JSON.parse(visualState)?.color || null;
            } catch (e) {
                color = '#95ec86';
            }
            if (color) {
                spiritIcon.style.color = color;
            }
        }

        // Update basic info
        if (this.nameDisplay) {
            this.nameDisplay.textContent = this.spirit.name;
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

        // Initialize Profile Memory Graph after UI is rendered
        this.initProfileMemoryGraph();

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
     * Initialize the profile memory graph visualization
     */
    initProfileMemoryGraph() {
        // Only initialize once
        if (this.profileMemoryGraph) {
            return;
        }

        // Check if memory canvas exists
        const memoryCanvas = document.getElementById('profile-memory-canvas');
        if (!memoryCanvas || !this.spirit?.id) {
            return;
        }

        // Initialize the memory graph
        this.profileMemoryGraph = new ProfileMemoryGraph(this.spirit.id);

        // Initialize Memory Type select
        this.initProfileMemoryType();
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
                    if (item.dataset.id) {
                        // wait for loading to finish
                        while (window.spiritChatManager.isLoadingConversations || window.spiritChatManager.isLoadingMessages) {
                            await new Promise(resolve => setTimeout(resolve, 100));
                        }
                        window.spiritChatManager.loadConversation(item.dataset.id);
                    }
                }, { once: true });

            });
            conversationsList.appendChild(item);
        });
        
        this.conversationsList.innerHTML = '';
        this.conversationsList.appendChild(conversationsList);
    }

    /**
     
    
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
            
            // Send the update to the server
            const response = await fetch(this.apiEndpoints.updateSettings.replace('{id}', this.spirit.id), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    systemPrompt,
                    aiModel
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
            
        } catch (error) {
            console.error('Error updating spirit settings:', error);
            this.showError(this.translate('error.updating_settings', 'Failed to update spirit settings'));
        }
    }
}
