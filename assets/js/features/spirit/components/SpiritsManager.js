/**
 * SpiritsManager - Manages Create/Delete Spirit modals
 * Spirit list is now server-rendered via Twig partial (_spirit_list.html.twig)
 */
import * as bootstrap from 'bootstrap';

export class SpiritsManager {
    constructor() {
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

        // Delete buttons in server-rendered spirit list (event delegation)
        document.addEventListener('click', (e) => {
            const deleteBtn = e.target.closest('.spirit-delete-btn');
            if (deleteBtn) {
                const spiritId = deleteBtn.dataset.spiritId;
                const spiritName = deleteBtn.dataset.spiritName;
                if (spiritId && spiritName) {
                    this.showDeleteModal(spiritId, spiritName);
                }
            }
        });
    }

    async createSpirit() {
        const nameInput = document.getElementById('spirit-name');
        const colorInput = document.getElementById('spirit-color');

        if (!nameInput || !nameInput.value.trim()) {
            if (window.toast) {
                window.toast.error('Please enter a spirit name');
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
                throw new Error('Failed to create spirit');
            }

            // Close modal
            const modal = bootstrap.Modal.getInstance(document.getElementById('createSpiritModal'));
            if (modal) {
                modal.hide();
            }

            // Show success and reload page to reflect new spirit
            if (window.toast) {
                window.toast.success('Spirit created successfully!');
            }
            setTimeout(() => window.location.reload(), 500);
        } catch (error) {
            console.error('Error creating spirit:', error);
            if (window.toast) {
                window.toast.error(error.message || 'Failed to create spirit');
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

        try {
            const response = await fetch(`/api/spirit/${this.spiritToDelete}`, {
                method: 'DELETE'
            });

            if (!response.ok) {
                const data = await response.json();
                throw new Error(data.error || 'Failed to delete spirit');
            }

            // Close modal
            const modal = bootstrap.Modal.getInstance(this.deleteSpiritModal);
            if (modal) {
                modal.hide();
            }

            // Show success
            if (window.toast) {
                window.toast.success('Spirit deleted successfully!');
            }

            const deletedId = this.spiritToDelete;
            this.spiritToDelete = null;

            // If we're on the deleted spirit's detail page, redirect to /spirits
            if (window.location.pathname.includes(`/spirit/${deletedId}`)) {
                setTimeout(() => window.location.href = '/spirits', 500);
            } else {
                setTimeout(() => window.location.reload(), 500);
            }
        } catch (error) {
            console.error('Error deleting spirit:', error);
            if (window.toast) {
                window.toast.error(error.message || 'Failed to delete spirit');
            }
        }
    }
}
