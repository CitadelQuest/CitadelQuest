/**
 * SpiritDropdownManager - Manages the spirit dropdown in navigation
 */
import * as bootstrap from 'bootstrap';

export class SpiritDropdownManager {
    constructor() {
        this.spirits = [];
        this.spiritsList = document.getElementById('spirits-list');
        this.spiritChatButtonIcon = document.getElementById('spiritChatButtonIcon');
        this.selectedSpiritId = localStorage.getItem('selectedSpiritId') || null;

        this.init();
    }

    init() {
        this.loadSpirits();
    }

    async loadSpirits() {
        try {
            const response = await fetch('/api/spirit/list');
            if (!response.ok) {
                throw new Error('Failed to load spirits');
            }

            const data = await response.json();
            this.spirits = data.spirits || [];
            this.renderDropdown();
            this.updateSpiritIcon();
        } catch (error) {
            console.error('Error loading spirits:', error);
        }
    }

    renderDropdown() {
        if (!this.spiritsList) return;

        if (this.spirits.length === 0) {
            this.spiritsList.innerHTML = `
                <a class="dropdown-item text-muted" href="#">
                    No spirits yet
                </a>
            `;
            return;
        }

        this.spiritsList.innerHTML = '';
        this.spirits.forEach(spirit => {
            const item = this.createSpiritItem(spirit);
            this.spiritsList.appendChild(item);
        });
    }

    createSpiritItem(spirit) {
        const a = document.createElement('a');
        a.className = 'dropdown-item d-flex align-items-center justify-content-between';
        a.href = '#';
        a.dataset.spiritId = spirit.id;

        const progression = spirit.progression || {};
        const settings = spirit.settings || {};
        const isPrimary = spirit.isPrimary || false;
        const isSelected = this.selectedSpiritId === spirit.id;

        // Get spirit color for dynamic styling
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

        a.innerHTML = `
            <div class="d-flex align-items-center">
                <div class="spirit-icon me-3">
                    <i class="mdi mdi-ghost"></i>
                </div>
                <div>
                    <div class="fw-bold ${isSelected ? 'text-cyber' : ''}">
                        ${spirit.name}
                        ${isPrimary ? '<i class="mdi mdi-star text-warning ms-1" style="font-size: 0.75rem;"></i>' : ''}
                    </div>
                    <small class="text-muted">Level ${progression.level || 1} | ${progression.experience || 0} XP</small>
                </div>
            </div>
            ${isSelected ? '<i class="mdi mdi-check text-cyber"></i>' : ''}
        `;

        // Apply spirit color to the icon
        const iconDiv = a.querySelector('.spirit-icon');
        if (iconDiv) {
            iconDiv.style.backgroundColor = spiritColor + '21';
        }
        const icon = a.querySelector('.spirit-icon i');
        if (icon) {
            icon.style.color = spiritColor;
        }

        a.addEventListener('click', (e) => {
            e.preventDefault();
            this.selectSpirit(spirit.id);
        });

        return a;
    }

    selectSpirit(spiritId) {
        this.selectedSpiritId = spiritId;
        localStorage.setItem('selectedSpiritId', spiritId);

        // Update dropdown UI
        this.renderDropdown();
        this.updateSpiritIcon();

        // Notify SpiritChatManager if it exists
        if (window.spiritChatManager) {
            window.spiritChatManager.switchSpirit(spiritId);
        }

        // Close dropdown
        const dropdownElement = document.getElementById('spiritIcon');
        const dropdown = bootstrap.Dropdown.getInstance(dropdownElement);
        if (dropdown) {
            dropdown.hide();
        }

        // Open Spirit Chat modal directly
        const spiritChatModal = document.getElementById('spiritChatModal');
        if (spiritChatModal) {
            const modal = new bootstrap.Modal(spiritChatModal);
            modal.show();
        }
    }

    updateSpiritIcon() {
        if (!this.spiritChatButtonIcon) return;

        // Find the selected spirit or default to primary
        const selectedSpirit = this.spirits.find(s => s.id === this.selectedSpiritId);
        const primarySpirit = this.spirits.find(s => s.isPrimary);
        const spirit = selectedSpirit || primarySpirit;

        if (!spirit) return;

        // Update icon color based on spirit's visualState
        const settings = spirit.settings || {};
        try {
            const visualState = settings.visualState || 'initial';
            const parsed = JSON.parse(visualState);
            if (parsed.color) {
                this.spiritChatButtonIcon.style.color = parsed.color;
            }
        } catch (e) {
            // visualState might be just a string
            this.spiritChatButtonIcon.style.color = '#95ec86';
        }
    }

    getSelectedSpiritId() {
        // Return selected spirit or primary spirit
        if (this.selectedSpiritId) {
            return this.selectedSpiritId;
        }

        const primarySpirit = this.spirits.find(s => s.isPrimary);
        return primarySpirit ? primarySpirit.id : null;
    }
}
