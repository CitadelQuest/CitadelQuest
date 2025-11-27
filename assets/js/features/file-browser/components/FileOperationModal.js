import * as bootstrap from 'bootstrap';

/**
 * Modal for file operations (Copy/Move/Rename)
 * Features folder autocomplete from tree data
 */
export class FileOperationModal {
    constructor(options = {}) {
        this.translations = options.translations || {};
        this.modalId = 'fileOperationModal';
        this.modal = null;
        this.bsModal = null;
        this.treeData = null;
        this.folderPaths = [];
        this.currentOperation = null;
        this.currentItems = [];
        this.onConfirm = null;
        
        this.createModal();
    }
    
    /**
     * Create the modal element
     */
    createModal() {
        // Remove existing modal if present
        const existing = document.getElementById(this.modalId);
        if (existing) existing.remove();
        
        this.modal = document.createElement('div');
        this.modal.className = 'modal fade';
        this.modal.id = this.modalId;
        this.modal.tabIndex = -1;
        this.modal.setAttribute('aria-hidden', 'true');
        
        this.modal.innerHTML = `
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content bg-dark text-light">
                    <div class="modal-header border-secondary">
                        <h5 class="modal-title">
                            <i class="mdi me-2" id="fileOpModalIcon"></i>
                            <span id="fileOpModalTitle"></span>
                        </h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div id="fileOpModalInfo" class="mb-3 small text-muted"></div>
                        
                        <!-- Destination folder input (for copy/move) -->
                        <div id="fileOpDestinationGroup" class="mb-3">
                            <label class="form-label">${this.translations.destination_folder || 'Destination Folder'}</label>
                            <div class="position-relative">
                                <input type="text" class="form-control bg-dark text-light border-secondary" 
                                    id="fileOpDestinationInput" 
                                    placeholder="/" 
                                    autocomplete="off">
                                <div id="fileOpAutocomplete" class="autocomplete-dropdown"></div>
                            </div>
                        </div>
                        
                        <!-- New name input (for rename, or optional for copy/move) -->
                        <div id="fileOpNameGroup" class="mb-3">
                            <label class="form-label">${this.translations.new_name || 'New Name'}</label>
                            <input type="text" class="form-control bg-dark text-light border-secondary" 
                                id="fileOpNameInput" 
                                placeholder="">
                        </div>
                        
                        <!-- Error message -->
                        <div id="fileOpError" class="alert alert-danger d-none"></div>
                    </div>
                    <div class="modal-footer border-secondary">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
                            ${this.translations.cancel || 'Cancel'}
                        </button>
                        <button type="button" class="btn btn-primary" id="fileOpConfirmBtn">
                            <i class="mdi me-1" id="fileOpConfirmIcon"></i>
                            <span id="fileOpConfirmText"></span>
                        </button>
                    </div>
                </div>
            </div>
        `;
        
        document.body.appendChild(this.modal);
        this.addStyles();
        this.attachEventListeners();
    }
    
    /**
     * Add styles for autocomplete
     */
    addStyles() {
        if (document.getElementById('file-operation-modal-styles')) return;
        
        const style = document.createElement('style');
        style.id = 'file-operation-modal-styles';
        style.textContent = `
            .autocomplete-dropdown {
                position: absolute;
                top: 100%;
                left: 0;
                right: 0;
                max-height: 200px;
                overflow-y: auto;
                background: var(--bs-dark);
                border: 1px solid rgba(var(--bs-primary-rgb), 0.3);
                border-top: none;
                border-radius: 0 0 0.375rem 0.375rem;
                z-index: 1000;
                display: none;
            }
            
            .autocomplete-dropdown.show {
                display: block;
            }
            
            .autocomplete-item {
                padding: 0.5rem 0.75rem;
                cursor: pointer;
                display: flex;
                align-items: center;
                color: var(--bs-light);
            }
            
            .autocomplete-item:hover,
            .autocomplete-item.active {
                background: rgba(var(--bs-primary-rgb), 0.2);
            }
            
            .autocomplete-item i {
                margin-right: 0.5rem;
                color: var(--bs-warning);
            }
        `;
        document.head.appendChild(style);
    }
    
    /**
     * Attach event listeners
     */
    attachEventListeners() {
        const destinationInput = this.modal.querySelector('#fileOpDestinationInput');
        const nameInput = this.modal.querySelector('#fileOpNameInput');
        const confirmBtn = this.modal.querySelector('#fileOpConfirmBtn');
        const autocomplete = this.modal.querySelector('#fileOpAutocomplete');
        
        // Autocomplete for destination
        destinationInput.addEventListener('input', () => {
            this.updateAutocomplete(destinationInput.value);
        });
        
        destinationInput.addEventListener('focus', () => {
            this.updateAutocomplete(destinationInput.value);
        });
        
        // Hide autocomplete on blur (with delay for click)
        destinationInput.addEventListener('blur', () => {
            setTimeout(() => autocomplete.classList.remove('show'), 200);
        });
        
        // Confirm button
        confirmBtn.addEventListener('click', () => this.handleConfirm());
        
        // Enter key to confirm
        nameInput.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') this.handleConfirm();
        });
        
        destinationInput.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') this.handleConfirm();
        });
        
        // Clear error on input
        [destinationInput, nameInput].forEach(input => {
            input.addEventListener('input', () => this.hideError());
        });
    }
    
    /**
     * Update autocomplete suggestions
     * @param {string} query - Current input value
     */
    updateAutocomplete(query) {
        const autocomplete = this.modal.querySelector('#fileOpAutocomplete');
        const normalizedQuery = query.toLowerCase();
        
        // Filter folders that match query
        const matches = this.folderPaths.filter(path => 
            path.toLowerCase().includes(normalizedQuery)
        ).slice(0, 10); // Limit to 10 suggestions
        
        if (matches.length === 0) {
            autocomplete.classList.remove('show');
            return;
        }
        
        autocomplete.innerHTML = matches.map(path => `
            <div class="autocomplete-item" data-path="${path}">
                <i class="mdi mdi-folder"></i>
                ${path}
            </div>
        `).join('');
        
        // Attach click handlers
        autocomplete.querySelectorAll('.autocomplete-item').forEach(item => {
            item.addEventListener('click', () => {
                this.modal.querySelector('#fileOpDestinationInput').value = item.dataset.path;
                autocomplete.classList.remove('show');
            });
        });
        
        autocomplete.classList.add('show');
    }
    
    /**
     * Set tree data and extract folder paths
     * @param {Object} treeData - Tree data from API
     */
    setTreeData(treeData) {
        this.treeData = treeData;
        this.folderPaths = this.extractFolderPaths(treeData);
    }
    
    /**
     * Extract all folder paths from tree data
     * @param {Object} node - Tree node
     * @param {string} currentPath - Current path
     * @returns {Array} Array of folder paths
     */
    extractFolderPaths(node, currentPath = '') {
        let paths = ['/'];  // Root is always available
        
        if (!node || !node.children) return paths;
        
        const processNode = (n, path) => {
            if (n.type === 'directory') {
                const fullPath = path === '/' ? `/${n.name}` : `${path}/${n.name}`;
                paths.push(fullPath);
                
                if (n.children) {
                    n.children.forEach(child => processNode(child, fullPath));
                }
            }
        };
        
        node.children.forEach(child => processNode(child, ''));
        
        return paths;
    }
    
    /**
     * Show modal for Copy operation
     * @param {Array} items - Items to copy
     * @param {Function} onConfirm - Callback with { destination }
     */
    showCopy(items, onConfirm) {
        this.currentOperation = 'copy';
        this.currentItems = items;
        this.onConfirm = onConfirm;
        
        const isSingle = items.length === 1;
        const title = isSingle 
            ? `${this.translations.copy || 'Copy'}: ${items[0].name}`
            : `${this.translations.copy || 'Copy'} ${items.length} ${this.translations.items || 'items'}`;
        
        this.setupModal({
            title,
            icon: 'mdi-content-copy',
            confirmText: this.translations.copy || 'Copy',
            confirmIcon: 'mdi-content-copy',
            showDestination: true,
            showName: isSingle,
            defaultName: isSingle ? items[0].name : '',
            nameLabel: this.translations.new_name_optional || 'New Name (optional)'
        });
        
        this.show();
    }
    
    /**
     * Show modal for Move operation
     * @param {Array} items - Items to move
     * @param {Function} onConfirm - Callback with { destination }
     */
    showMove(items, onConfirm) {
        this.currentOperation = 'move';
        this.currentItems = items;
        this.onConfirm = onConfirm;
        
        const isSingle = items.length === 1;
        const title = isSingle 
            ? `${this.translations.move || 'Move'}: ${items[0].name}`
            : `${this.translations.move || 'Move'} ${items.length} ${this.translations.items || 'items'}`;
        
        this.setupModal({
            title,
            icon: 'mdi-folder-move',
            confirmText: this.translations.move || 'Move',
            confirmIcon: 'mdi-folder-move',
            showDestination: true,
            showName: isSingle,
            defaultName: isSingle ? items[0].name : '',
            nameLabel: this.translations.new_name_optional || 'New Name (optional)'
        });
        
        this.show();
    }
    
    /**
     * Show modal for Rename operation
     * @param {Object} item - Item to rename
     * @param {Function} onConfirm - Callback with { newName }
     */
    showRename(item, onConfirm) {
        this.currentOperation = 'rename';
        this.currentItems = [item];
        this.onConfirm = onConfirm;
        
        this.setupModal({
            title: `${this.translations.rename || 'Rename'}: ${item.name}`,
            icon: 'mdi-rename-box',
            confirmText: this.translations.rename || 'Rename',
            confirmIcon: 'mdi-rename-box',
            showDestination: false,
            showName: true,
            defaultName: item.name,
            nameLabel: this.translations.new_name || 'New Name'
        });
        
        this.show();
        
        // Select filename without extension for easier editing
        const nameInput = this.modal.querySelector('#fileOpNameInput');
        const dotIndex = item.name.lastIndexOf('.');
        if (dotIndex > 0 && item.type !== 'directory') {
            nameInput.setSelectionRange(0, dotIndex);
        } else {
            nameInput.select();
        }
    }
    
    /**
     * Setup modal UI based on operation
     */
    setupModal(config) {
        this.modal.querySelector('#fileOpModalTitle').textContent = config.title;
        this.modal.querySelector('#fileOpModalIcon').className = `mdi me-2 ${config.icon}`;
        this.modal.querySelector('#fileOpConfirmText').textContent = config.confirmText;
        this.modal.querySelector('#fileOpConfirmIcon').className = `mdi me-1 ${config.confirmIcon}`;
        
        // Show/hide destination group
        const destGroup = this.modal.querySelector('#fileOpDestinationGroup');
        destGroup.style.display = config.showDestination ? 'block' : 'none';
        if (config.showDestination) {
            this.modal.querySelector('#fileOpDestinationInput').value = '/';
        }
        
        // Show/hide name group
        const nameGroup = this.modal.querySelector('#fileOpNameGroup');
        nameGroup.style.display = config.showName ? 'block' : 'none';
        if (config.showName) {
            this.modal.querySelector('#fileOpNameInput').value = config.defaultName || '';
            nameGroup.querySelector('label').textContent = config.nameLabel;
        }
        
        // Info text
        const infoEl = this.modal.querySelector('#fileOpModalInfo');
        if (this.currentItems.length > 1) {
            infoEl.innerHTML = this.currentItems.map(item => 
                `<i class="mdi ${item.type === 'directory' ? 'mdi-folder text-warning' : 'mdi-file text-cyber'} me-1"></i>${item.name}`
            ).join('<br>');
            infoEl.classList.remove('d-none');
        } else {
            infoEl.classList.add('d-none');
        }
        
        this.hideError();
    }
    
    /**
     * Show the modal
     */
    show() {
        if (!this.bsModal) {
            this.bsModal = new bootstrap.Modal(this.modal);
        }
        this.bsModal.show();
        
        // Focus appropriate input
        setTimeout(() => {
            if (this.currentOperation === 'rename') {
                this.modal.querySelector('#fileOpNameInput').focus();
            } else {
                this.modal.querySelector('#fileOpDestinationInput').focus();
            }
        }, 200);
    }
    
    /**
     * Hide the modal
     */
    hide() {
        if (this.bsModal) {
            this.bsModal.hide();
        }
    }
    
    /**
     * Handle confirm button click
     */
    handleConfirm() {
        const destinationInput = this.modal.querySelector('#fileOpDestinationInput');
        const nameInput = this.modal.querySelector('#fileOpNameInput');
        
        if (this.currentOperation === 'rename') {
            const newName = nameInput.value.trim();
            if (!newName) {
                this.showError(this.translations.name_required || 'Name is required');
                return;
            }
            if (newName === this.currentItems[0].name) {
                this.showError(this.translations.name_unchanged || 'Name is unchanged');
                return;
            }
            this.onConfirm({ newName });
        } else {
            // Copy or Move
            const destination = destinationInput.value.trim() || '/';
            const newName = nameInput.value.trim();
            
            this.onConfirm({ 
                destination, 
                newName: newName || null 
            });
        }
        
        this.hide();
    }
    
    /**
     * Show error message
     * @param {string} message - Error message
     */
    showError(message) {
        const errorEl = this.modal.querySelector('#fileOpError');
        errorEl.textContent = message;
        errorEl.classList.remove('d-none');
    }
    
    /**
     * Hide error message
     */
    hideError() {
        this.modal.querySelector('#fileOpError').classList.add('d-none');
    }
}
