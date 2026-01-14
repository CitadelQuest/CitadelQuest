/**
 * SpiritManager - Manages the Spirit feature UI and API interactions
 */
//import * as THREE from 'three';

export class SpiritManager {
    constructor(config) {
        this.translations = config.translations || {};
        this.apiEndpoints = config.apiEndpoints || {};
        this.spirit = null;
        this.interactions = [];
        this.conversations = [];
        
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
            
            // Load spirit settings
            await this.loadSpiritSettings();
            
            this.showSpirit();
            this.loadInteractions();

            // Load spirit chat conversations list
            this.loadConversations();
            
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
            await this.loadSpiritSettings();
        } catch (error) {
            console.error('Error reloading spirit:', error);
            this.showError(this.translate('error.reloading_spirit', 'Failed to reload spirit'));
        }
    }
    
    /**
     * Load the spirit's settings
     */
    async loadSpiritSettings() {
        try {
            const response = await fetch(this.apiEndpoints.settings.replace('{id}', this.spirit.id));
            
            if (!response.ok) {
                throw new Error('Failed to load spirit settings');
            }
            
            this.spirit.settings = await response.json();
        } catch (error) {
            console.error('Error loading spirit settings:', error);
            this.spirit.settings = {};
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
     * Load the spirit's recent interactions
     */
    async loadInteractions() {
        try {
            let url = this.apiEndpoints.interactions;
            if (this.spirit && this.spirit.id) {
                url += `?spiritId=${this.spirit.id}`;
            }
            const response = await fetch(url);
            
            if (!response.ok) {
                throw new Error('Failed to load interactions');
            }
            
            this.interactions = await response.json();
            this.renderInteractions();
            
        } catch (error) {
            console.error('Error loading interactions:', error);
            this.showError(this.translate('error.loading_interactions', 'Failed to load interactions'));
        }
    }
    
    /**
     * Load the spirit's conversations
     */
    async loadConversations() {
        try {
            const response = await fetch(this.apiEndpoints.conversations.replace('{id}', this.spirit.id));
            
            if (!response.ok) {
                throw new Error('Failed to load conversations');
            }
            
            this.conversations = await response.json();
            this.renderConversations();
            
        } catch (error) {
            console.error('Error loading conversations:', error);
            this.showError(this.translate('error.loading_conversations', 'Failed to load conversations'));
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

        // Update system prompt and AI model fields
        if (this.systemPromptInput) {
            this.systemPromptInput.value = settings.systemPrompt || '';
        }

        if (this.aiModelSelect) {
            // Set the selected option based on the spirit's AI model
            const aiModel = settings.aiModel || '';
            const options = this.aiModelSelect.options;
            for (let i = 0; i < options.length; i++) {
                if (options[i].value === aiModel) {
                    this.aiModelSelect.selectedIndex = i;
                    break;
                }
            }
        }

        // Disable save button after loading
        if (this.updateSettingsBtn) {
            this.updateSettingsBtn.disabled = true;
        }
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
     * Render the spirit's recent interactions
     */
    renderInteractions() {
        if (!this.interactionsContainer || !this.interactions) return;
        
        if (this.interactions.length === 0) {
            this.interactionsContainer.innerHTML = `<p>${this.translate('spirit.no_interactions', 'No interactions recorded yet.')}</p>`;
            return;
        }
        
        const interactionsList = document.createElement('ul');
        interactionsList.className = 'list-group bg-secondary bg-opacity-25';
        
        this.interactions.forEach(interaction => {
            const item = document.createElement('li');
            item.className = 'list-group-item small';
            
            const date = new Date(interaction.createdAt);
            const formattedDate = date.toLocaleString();
            
            const header = document.createElement('div');
            header.className = 'd-flex justify-content-between';
            
            const dateSpan = document.createElement('small');
            dateSpan.className = 'text-muted';
            dateSpan.textContent = formattedDate;
            header.appendChild(dateSpan);
            
            const typeSpan = document.createElement('span');
            typeSpan.className = '';
            typeSpan.textContent = interaction.interactionType;
            header.appendChild(typeSpan);
            
            const expGained = document.createElement('small');
            expGained.className = 'text-success';
            expGained.textContent = `+${interaction.experienceGained} XP`;
            header.appendChild(expGained);
            
            item.appendChild(header);
            
            if (interaction.context) {
                const context = document.createElement('p');
                context.className = '';
                context.textContent = interaction.context;
                item.appendChild(context);
            }
            
            interactionsList.appendChild(item);
        });
        
        this.interactionsContainer.innerHTML = '';
        this.interactionsContainer.appendChild(interactionsList);
    }

    /**
     * Render the conversations list
     */
    renderConversations() {
        if (!this.conversationsList) return;

        if (this.conversations.length === 0) {
            this.conversationsList.innerHTML = '<div class="text-center">No conversations available</div>';
            return;
        }

        const conversationsList = document.createElement('ul');
        conversationsList.className = 'list-group';
        
        this.conversations.forEach(conversation => {
            const item = document.createElement('li');
            item.className = 'list-group-item';
            
            const date = new Date(conversation.createdAt);
            const formattedDate = date.toLocaleString();

            const messagesCount = conversation.messagesCount;
            
            item.innerHTML = `
                <div class="cursor-pointer">
                    <div><i class="mdi mdi-forum me-2 mt-1 text-cyber"></i> ${conversation.title}</div>
                    <div class="float-end">
                        <small class="text-muted me-2">${formattedDate}</small>
                        <span class="badge bg-dark bg-opacity-50 text-cyber">${messagesCount}</span>
                    </div>
                </div>
            `;

            item.dataset.id = conversation.id;
            item.addEventListener('click', async () => {
                // Select this spirit in the dropdown before opening modal
                if (this.spirit && window.spiritDropdownManager) {
                    window.spiritDropdownManager.selectSpirit(this.spirit.id);
                }

                // Load conversation when modal is shown
                document.getElementById('spiritChatModal').addEventListener('shown.bs.modal', async () => {
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
