/**
 * SpiritsManager - Manages the Spirits list page UI and API interactions
 */
import * as bootstrap from 'bootstrap';

export class SpiritsManager {
    constructor() {
        this.spirits = [];
        this.spiritsContainer = document.getElementById('spirits-container');
        this.createSpiritForm = document.getElementById('create-spirit-form');
        this.createSpiritBtn = document.getElementById('create-spirit-btn');
        this.deleteSpiritModal = document.getElementById('deleteSpiritModal');
        this.deleteSpiritName = document.getElementById('delete-spirit-name');
        this.confirmDeleteSpiritBtn = document.getElementById('confirm-delete-spirit-btn');
        this.spiritToDelete = null;

        this.init();
    }

    init() {
        this.initEventListeners();
        this.loadSpirits();
    }

    initEventListeners() {
        // Create spirit button
        if (this.createSpiritBtn) {
            this.createSpiritBtn.addEventListener('click', () => this.createSpirit());
        }

        // Confirm delete button
        if (this.confirmDeleteSpiritBtn) {
            this.confirmDeleteSpiritBtn.addEventListener('click', () => this.deleteSpirit());
        }
    }

    async loadSpirits() {
        try {
            const response = await fetch('/api/spirit/list');
            if (!response.ok) {
                throw new Error('Failed to load spirits');
            }

            const data = await response.json();
            this.spirits = data.spirits || [];
            this.renderSpirits();
        } catch (error) {
            console.error('Error loading spirits:', error);
            this.showError('Failed to load spirits');
        }
    }

    renderSpirits() {
        if (!this.spiritsContainer) return;

        if (this.spirits.length === 0) {
            const noSpirits = window.translations && window.translations['spirits.no_spirits'] ? window.translations['spirits.no_spirits'] : 'No spirits yet';
            const noSpiritsDesc = window.translations && window.translations['spirits.no_spirits_desc'] ? window.translations['spirits.no_spirits_desc'] : 'Create your first spirit to get started!';
            const spiritsCreate = window.translations && window.translations['spirits.create'] ? window.translations['spirits.create'] : 'Create Spirit';
            const uiView = window.translations && window.translations['ui.view'] ? window.translations['ui.view'] : 'View';
            const spiritsPrimary = window.translations && window.translations['spirits.primary'] ? window.translations['spirits.primary'] : 'Primary Spirit';
            const spiritsSpirit = window.translations && window.translations['spirits.spirit'] ? window.translations['spirits.spirit'] : 'Spirit';
            const spiritsLevel = window.translations && window.translations['spirits.level'] ? window.translations['spirits.level'] : 'Level';
            const spiritsNextLevel = window.translations && window.translations['spirits.next_level'] ? window.translations['spirits.next_level'] : 'Next level';
            const spiritsLastInteraction = window.translations && window.translations['spirits.last_interaction'] ? window.translations['spirits.last_interaction'] : 'Last:';

            this.spiritsContainer.innerHTML = `
                <div class="col-12 text-center py-5">
                    <i class="mdi mdi-ghost-outline" style="font-size: 4rem; color: #95ec86;"></i>
                    <h4 class="mt-3">${noSpirits}</h4>
                    <p class="text-muted">${noSpiritsDesc}</p>
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createSpiritModal">
                        <i class="mdi mdi-plus me-2"></i>${spiritsCreate}
                    </button>
                </div>
            `;
            return;
        }

        this.spiritsContainer.innerHTML = '';
        this.spirits.forEach(spirit => {
            const card = this.createSpiritCard(spirit);
            this.spiritsContainer.appendChild(card);
        });
    }

    createSpiritCard(spirit) {
        const col = document.createElement('div');
        col.className = 'col-md-6 col-lg-4 mb-4';

        const progression = spirit.progression || {};
        const settings = spirit.settings || {};
        const isPrimary = spirit.isPrimary || false;

        // Get translations
        const uiView = window.translations && window.translations['ui.view'] ? window.translations['ui.view'] : 'View';
        const spiritsPrimary = window.translations && window.translations['spirits.primary'] ? window.translations['spirits.primary'] : 'Primary Spirit';
        const spiritsSpirit = window.translations && window.translations['spirits.spirit'] ? window.translations['spirits.spirit'] : 'Spirit';
        const spiritsLevel = window.translations && window.translations['spirits.level'] ? window.translations['spirits.level'] : 'Level';
        const spiritsNextLevel = window.translations && window.translations['spirits.next_level'] ? window.translations['spirits.next_level'] : 'Next level';
        const spiritsLastInteraction = window.translations && window.translations['spirits.last_interaction'] ? window.translations['spirits.last_interaction'] : 'Last:';

        // Get spirit color
        let spiritColor = '#95ec86';
        try {
            const visualState = settings.visualState || 'initial';
            const parsed = JSON.parse(visualState);
            if (parsed.color) {
                spiritColor = parsed.color;
            }
        } catch (e) {
            // visualState might be just a string
        }

        const formattedDate = new Date(spirit.lastInteraction).toLocaleDateString();

        col.innerHTML = `
            <div class="card h-100 spirit-card glass-panel ${isPrimary ? 'glass-panel-glow' : ''}">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start mb-3">
                        <div class="d-flex align-items-center">
                            <div class="spirit-avatar me-3" style="width: 50px; height: 50px; border-radius: 50%; background: ${spiritColor}; display: flex; align-items: center; justify-content-center;">
                                <i class="mdi mdi-ghost" style="color: white; font-size: 1.5rem;"></i>
                            </div>
                            <div>
                                <h5 class="card-title mb-0">
                                    ${spirit.name}
                                </h5>
                                <small class="text-muted">${isPrimary ? spiritsPrimary : spiritsSpirit}</small>
                                ${isPrimary ? '<span class="badge bg-dark bg-opacity-25 ms-2 text-warning">★</span>' : ''}
                            </div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <div class="d-flex justify-content-between mb-1">
                            <span class="fw-bold">${spiritsLevel} ${progression.level || 1}</span>
                            <span class="text-muted">${progression.experience || 0} XP</span>
                        </div>
                        <div class="progress" style="height: 8px;">
                            <div class="progress-bar bg-cyber" role="progressbar" style="width: ${progression.percentage || 0}%"></div>
                        </div>
                        <small class="text-muted">${spiritsNextLevel}: ${progression.nextLevelThreshold || 100} XP</small>
                    </div>

                    <div class="d-flex justify-content-between align-items-center">
                        <small class="text-muted">
                            <i class="mdi mdi-clock me-1"></i>
                            ${spiritsLastInteraction} ${formattedDate}
                        </small>
                    </div>
                </div>
                <div class="card-footer bg-transparent border-0">
                    <div class="d-flex gap-2">
                        <a href="/spirit/${spirit.id}" class="btn btn-sm btn-cyber flex-grow-1">
                            <i class="mdi mdi-eye me-1"></i> ${uiView}
                        </a>
                        <button class="btn btn-sm btn-outline-danger spirit-delete-btn ${isPrimary ? 'd-none' : ''}" data-spirit-id="${spirit.id}" data-spirit-name="${spirit.name}" ${isPrimary ? 'disabled' : ''}>
                            <i class="mdi mdi-delete"></i>
                        </button>
                    </div>
                </div>
            </div>
        `;

        // Add delete button event listener
        const deleteBtn = col.querySelector('.spirit-delete-btn');
        if (deleteBtn && !isPrimary) {
            deleteBtn.addEventListener('click', () => {
                this.showDeleteModal(spirit.id, spirit.name);
            });
        }

        return col;
    }

    async createSpirit() {
        const nameInput = document.getElementById('spirit-name');
        const colorInput = document.getElementById('spirit-color');

        const errorNameRequired = window.translations && window.translations['spirits.error_name_required'] ? window.translations['spirits.error_name_required'] : 'Please enter a spirit name';
        const errorCreateFailed = window.translations && window.translations['spirits.error_create_failed'] ? window.translations['spirits.error_create_failed'] : 'Failed to create spirit';
        const createdSuccess = window.translations && window.translations['spirits.created_success'] ? window.translations['spirits.created_success'] : 'Spirit created successfully!';

        if (!nameInput || !nameInput.value.trim()) {
            if (window.toast) {
                window.toast.error(errorNameRequired);
            }
            return;
        }

        const name = nameInput.value.trim();
        const color = colorInput ? colorInput.value : null;

        try {
            const response = await fetch('/api/spirit', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({ name, color })
            });

            if (!response.ok) {
                throw new Error(errorCreateFailed);
            }

            // Close modal
            const modal = bootstrap.Modal.getInstance(document.getElementById('createSpiritModal'));
            if (modal) {
                modal.hide();
            }

            // Reset form
            if (this.createSpiritForm) {
                this.createSpiritForm.reset();
            }

            // Show success message
            if (window.toast) {
                window.toast.success(createdSuccess);
            }

            // Reload spirits list
            await this.loadSpirits();
        } catch (error) {
            console.error('Error creating spirit:', error);
            if (window.toast) {
                window.toast.error(error.message || errorCreateFailed);
            }
        }
    }

    showDeleteModal(spiritId, spiritName) {
        this.spiritToDelete = spiritId;
        if (this.deleteSpiritName) {
            this.deleteSpiritName.textContent = spiritName;
        }

        const modal = new bootstrap.Modal(this.deleteSpiritModal);
        modal.show();
    }

    async deleteSpirit() {
        if (!this.spiritToDelete) return;

        const errorDeleteFailed = window.translations && window.translations['spirits.error_delete_failed'] ? window.translations['spirits.error_delete_failed'] : 'Failed to delete spirit';
        const deletedSuccess = window.translations && window.translations['spirits.deleted_success'] ? window.translations['spirits.deleted_success'] : 'Spirit deleted successfully!';

        try {
            const response = await fetch(`/api/spirit/${this.spiritToDelete}`, {
                method: 'DELETE'
            });

            if (!response.ok) {
                const data = await response.json();
                throw new Error(data.error || errorDeleteFailed);
            }

            // Close modal
            const modal = bootstrap.Modal.getInstance(this.deleteSpiritModal);
            if (modal) {
                modal.hide();
            }

            // Show success message
            if (window.toast) {
                window.toast.success(deletedSuccess);
            }

            // Reset
            this.spiritToDelete = null;

            // Reload spirits list
            await this.loadSpirits();
        } catch (error) {
            console.error('Error deleting spirit:', error);
            if (window.toast) {
                window.toast.error(error.message || errorDeleteFailed);
            }
        }
    }

    showError(message) {
        const errorLoadFailed = window.translations && window.translations['spirits.error_load_failed'] ? window.translations['spirits.error_load_failed'] : 'Failed to load spirits';
        const errorMessage = message || errorLoadFailed;

        if (!this.spiritsContainer) return;

        this.spiritsContainer.innerHTML = `
            <div class="col-12">
                <div class="alert alert-danger" role="alert">
                    ${errorMessage}
                </div>
            </div>
        `;
    }
}
