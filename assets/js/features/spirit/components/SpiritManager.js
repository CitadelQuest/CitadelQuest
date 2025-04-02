/**
 * SpiritManager - Manages the Spirit feature UI and API interactions
 */
import * as THREE from 'three';

export class SpiritManager {
    constructor(config) {
        this.translations = config.translations || {};
        this.apiEndpoints = config.apiEndpoints || {};
        this.spirit = null;
        this.abilities = [];
        this.interactions = [];
        
        // DOM elements
        this.spiritContainer = document.getElementById('spirit-container');
        this.createForm = document.getElementById('create-spirit-form');
        this.spiritDisplay = document.getElementById('spirit-display');
        this.spiritCanvas = document.getElementById('spirit-canvas');
        this.nameDisplay = document.getElementById('spirit-name-display');
        this.levelDisplay = document.getElementById('spirit-level');
        this.consciousnessDisplay = document.getElementById('spirit-consciousness');
        this.experienceBar = document.getElementById('spirit-experience-bar');
        this.experienceDisplay = document.getElementById('spirit-experience');
        this.nextLevelDisplay = document.getElementById('spirit-next-level');
        this.abilitiesContainer = document.getElementById('spirit-abilities');
        this.interactionsContainer = document.getElementById('spirit-interactions');
        
        // Initialize
        this.initEventListeners();
        this.loadSpirit();
    }
    
    /**
     * Initialize event listeners for user interactions
     */
    initEventListeners() {
        // Spirit creation form submission
        if (this.createForm) {
            this.createForm.addEventListener('submit', (e) => {
                e.preventDefault();
                const nameInput = this.createForm.querySelector('#spirit-name');
                if (nameInput && nameInput.value.trim()) {
                    this.createSpirit(nameInput.value.trim());
                }
            });
        }
        
        // Interaction form submission
        const interactForm = document.getElementById('spirit-interact-form');
        if (interactForm) {
            interactForm.addEventListener('submit', (e) => {
                e.preventDefault();
                const interactionType = interactForm.querySelector('#interaction-type').value;
                const context = interactForm.querySelector('#interaction-context').value;
                
                this.interactWithSpirit(interactionType, context);
                interactForm.reset();
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
                // No spirit found, show creation form
                this.showCreateForm();
                return;
            }
            
            if (!response.ok) {
                throw new Error('Failed to load spirit');
            }
            
            this.spirit = await response.json();
            this.showSpirit();
            this.loadAbilities();
            this.loadInteractions();
            
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
            this.loadAbilities();
            
        } catch (error) {
            console.error('Error creating spirit:', error);
            this.showError(this.translate('error.creating_spirit', 'Failed to create spirit'));
        }
    }
    
    /**
     * Interact with the spirit
     * @param {string} interactionType - The type of interaction
     * @param {string} context - Additional context for the interaction
     */
    async interactWithSpirit(interactionType, context) {
        try {
            // Show a loading indicator in the interactions container
            if (this.interactionsContainer) {
                this.interactionsContainer.innerHTML = '<div class="text-center"><div class="spinner-border text-primary" role="status"><span class="visually-hidden">Loading...</span></div></div>';
            }
            
            const response = await fetch(this.apiEndpoints.interact, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    interactionType,
                    context,
                    experienceGained: 5 // Default experience gain
                })
            });
            
            if (!response.ok) {
                throw new Error('Failed to interact with spirit');
            }
            
            const data = await response.json();
            this.spirit = data.spirit;
            this.updateSpiritDisplay();
            
            // Update the interactions immediately
            if (data.interaction) {
                // Add the new interaction to the beginning of the array
                this.interactions = [data.interaction, ...this.interactions];
                this.renderInteractions();
            } else {
                // If the interaction isn't returned in the response, fetch all interactions
                await this.loadInteractions();
            }
            
        } catch (error) {
            console.error('Error interacting with spirit:', error);
            this.showError(this.translate('error.interaction_failed', 'Failed to interact with spirit'));
        }
    }
    
    /**
     * Load the spirit's abilities
     */
    async loadAbilities() {
        try {
            const response = await fetch(this.apiEndpoints.abilities);
            
            if (!response.ok) {
                throw new Error('Failed to load abilities');
            }
            
            this.abilities = await response.json();
            this.renderAbilities();
            
        } catch (error) {
            console.error('Error loading abilities:', error);
            this.showError(this.translate('error.loading_abilities', 'Failed to load abilities'));
        }
    }
    
    /**
     * Load the spirit's recent interactions
     */
    async loadInteractions() {
        try {
            const response = await fetch(this.apiEndpoints.interactions);
            
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
     * Unlock a spirit ability
     * @param {string} abilityId - The ID of the ability to unlock
     */
    async unlockAbility(abilityId) {
        try {
            const url = this.apiEndpoints.unlockAbility.replace('{id}', abilityId);
            const response = await fetch(url, {
                method: 'POST'
            });
            
            if (!response.ok) {
                throw new Error('Failed to unlock ability');
            }
            
            // Refresh abilities
            this.loadAbilities();

            // Refresh interactions
            this.loadInteractions();

            // Refresh spirit
            await this.reloadSpirit();

            // Refresh display
            this.updateSpiritDisplay();
            
        } catch (error) {
            console.error('Error unlocking ability:', error);
            this.showError(this.translate('error.unlock_failed', 'Failed to unlock ability'));
        }
    }
    
    /**
     * Show the spirit creation form
     */
    showCreateForm() {
        // Hide loading indicator
        const loadingElement = document.getElementById('spirit-loading');
        if (loadingElement) {
            loadingElement.classList.add('d-none');
        }
        
        if (this.createForm) {
            this.createForm.classList.remove('d-none');
        }
        if (this.spiritDisplay) {
            this.spiritDisplay.classList.add('d-none');
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
        
        if (this.createForm) {
            this.createForm.classList.add('d-none');
        }
        if (this.spiritDisplay) {
            this.spiritDisplay.classList.remove('d-none');
            this.updateSpiritDisplay();
            this.initSpiritVisualization();
        }
    }
    
    /**
     * Update the spirit display with current data
     */
    updateSpiritDisplay() {
        if (!this.spirit) return;
        
        if (this.nameDisplay) {
            this.nameDisplay.textContent = this.spirit.name;
        }
        
        if (this.levelDisplay) {
            this.levelDisplay.textContent = this.spirit.level;
        }
        
        if (this.consciousnessDisplay) {
            this.consciousnessDisplay.textContent = this.spirit.consciousnessLevel;
        }
        
        if (this.experienceBar) {
            const nextLevelExp = this.spirit.level * 100;
            const percentage = (this.spirit.experience / nextLevelExp) * 100;
            this.experienceBar.style.width = `${percentage}%`;
            this.experienceBar.setAttribute('aria-valuenow', percentage);
        }
        
        if (this.experienceDisplay) {
            this.experienceDisplay.textContent = this.spirit.experience;
        }
        
        if (this.nextLevelDisplay) {
            this.nextLevelDisplay.textContent = this.spirit.level * 100;
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
     * Render the spirit's abilities
     */
    renderAbilities() {
        if (!this.abilitiesContainer || !this.abilities) return;
        
        if (this.abilities.length === 0) {
            this.abilitiesContainer.innerHTML = `<p>${this.translate('spirit.no_abilities', 'No abilities unlocked yet.')}</p>`;
            return;
        }
        
        const abilitiesList = document.createElement('ul');
        abilitiesList.className = 'list-group';
        
        this.abilities.forEach(ability => {
            const item = document.createElement('li');
            item.className = `list-group-item d-flex justify-content-between align-items-center ${ability.unlocked ? 'list-group-item-success' : 'list-group-item-secondary'}`;
            
            const nameSpan = document.createElement('span');
            nameSpan.textContent = ability.abilityName;
            item.appendChild(nameSpan);
            
            const badge = document.createElement('span');
            badge.className = `badge ${ability.unlocked ? 'bg-success' : 'bg-secondary'} rounded-pill`;
            badge.textContent = ability.unlocked ? this.translate('spirit.unlocked', 'Unlocked') : this.translate('spirit.locked', 'Locked');
            item.appendChild(badge);
            
            if (!ability.unlocked) {
                const unlockBtn = document.createElement('button');
                unlockBtn.className = 'btn btn-sm btn-outline-primary ms-2';
                unlockBtn.textContent = this.translate('spirit.unlock', 'Unlock');
                unlockBtn.addEventListener('click', () => this.unlockAbility(ability.id));
                item.appendChild(unlockBtn);
            }
            
            abilitiesList.appendChild(item);
        });
        
        this.abilitiesContainer.innerHTML = '';
        this.abilitiesContainer.appendChild(abilitiesList);
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
        interactionsList.className = 'list-group';
        
        this.interactions.forEach(interaction => {
            const item = document.createElement('li');
            item.className = 'list-group-item';
            
            const date = new Date(interaction.createdAt);
            const formattedDate = date.toLocaleString();
            
            const header = document.createElement('div');
            header.className = 'd-flex justify-content-between';
            
            const typeSpan = document.createElement('span');
            typeSpan.className = 'fw-bold';
            typeSpan.textContent = interaction.interactionType;
            header.appendChild(typeSpan);
            
            const dateSpan = document.createElement('small');
            dateSpan.className = 'text-muted';
            dateSpan.textContent = formattedDate;
            header.appendChild(dateSpan);
            
            item.appendChild(header);
            
            if (interaction.context) {
                const context = document.createElement('p');
                context.className = 'mb-1 mt-1';
                context.textContent = interaction.context;
                item.appendChild(context);
            }
            
            const expGained = document.createElement('small');
            expGained.className = 'text-success';
            expGained.textContent = `+${interaction.experienceGained} XP`;
            item.appendChild(expGained);
            
            interactionsList.appendChild(item);
        });
        
        this.interactionsContainer.innerHTML = '';
        this.interactionsContainer.appendChild(interactionsList);
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
}
