/**
 * Context Menu component for File Browser
 * Provides right-click menu with file operations
 */
export class FileContextMenu {
    constructor(options = {}) {
        this.translations = options.translations || {};
        this.onCopy = options.onCopy || (() => {});
        this.onMove = options.onMove || (() => {});
        this.onRename = options.onRename || (() => {});
        this.onDelete = options.onDelete || (() => {});
        
        this.menuElement = null;
        this.currentItems = [];
        
        this.createMenu();
        this.attachGlobalListeners();
    }
    
    /**
     * Create the context menu element
     */
    createMenu() {
        this.menuElement = document.createElement('div');
        this.menuElement.className = 'file-context-menu';
        this.menuElement.style.cssText = `
            position: fixed;
            z-index: 10000;
            background: var(--bs-dark, #212529);
            border: 1px solid rgba(var(--bs-primary-rgb), 0.3);
            border-radius: 0.5rem;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.4);
            min-width: 160px;
            display: none;
            padding: 0.5rem 0;
        `;
        
        document.body.appendChild(this.menuElement);
    }
    
    /**
     * Attach global listeners to close menu
     */
    attachGlobalListeners() {
        // Close on click outside
        document.addEventListener('click', (e) => {
            if (!this.menuElement.contains(e.target)) {
                this.hide();
            }
        });
        
        // Close on escape
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                this.hide();
            }
        });
        
        // Close on scroll
        document.addEventListener('scroll', () => this.hide(), true);
    }
    
    /**
     * Show the context menu at position
     * @param {number} x - X coordinate
     * @param {number} y - Y coordinate
     * @param {Array} items - Selected items array
     */
    show(x, y, items) {
        this.currentItems = items;
        const count = items.length;
        const isSingle = count === 1;
        const hasDirectories = items.some(item => item.type === 'directory');
        
        // Build menu items
        let menuHtml = '';
        
        // Selection info
        if (count > 1) {
            menuHtml += `
                <div class="context-menu-header px-3 py-1 text-muted small border-bottom border-secondary mb-1">
                    <i class="mdi mdi-checkbox-multiple-marked-outline me-1"></i>
                    ${count} ${this.translations.items_selected || 'items selected'}
                </div>
            `;
        }
        
        // Copy option
        menuHtml += `
            <div class="context-menu-item" data-action="copy">
                <i class="mdi mdi-content-copy me-2"></i>
                ${this.translations.copy || 'Copy'}${count > 1 ? ` (${count})` : ''}
            </div>
        `;
        
        // Move option
        menuHtml += `
            <div class="context-menu-item" data-action="move">
                <i class="mdi mdi-folder-move me-2"></i>
                ${this.translations.move || 'Move'}${count > 1 ? ` (${count})` : ''}
            </div>
        `;
        
        // Rename option (only for single selection)
        if (isSingle) {
            menuHtml += `
                <div class="context-menu-item" data-action="rename">
                    <i class="mdi mdi-rename-box me-2"></i>
                    ${this.translations.rename || 'Rename'}
                </div>
            `;
        }
        
        // Separator
        menuHtml += '<div class="context-menu-separator my-1 border-top border-secondary"></div>';
        
        // Delete option
        menuHtml += `
            <div class="context-menu-item context-menu-item-danger" data-action="delete">
                <i class="mdi mdi-delete me-2"></i>
                ${this.translations.delete || 'Delete'}${count > 1 ? ` (${count})` : ''}
            </div>
        `;
        
        this.menuElement.innerHTML = menuHtml;
        
        // Add styles for menu items
        this.addMenuStyles();
        
        // Attach click handlers
        this.menuElement.querySelectorAll('.context-menu-item').forEach(item => {
            item.addEventListener('click', (e) => {
                const action = item.dataset.action;
                this.handleAction(action);
                this.hide();
            });
        });
        
        // Position the menu
        this.menuElement.style.display = 'block';
        
        // Adjust position if menu goes off screen
        const rect = this.menuElement.getBoundingClientRect();
        const viewportWidth = window.innerWidth;
        const viewportHeight = window.innerHeight;
        
        if (x + rect.width > viewportWidth) {
            x = viewportWidth - rect.width - 10;
        }
        if (y + rect.height > viewportHeight) {
            y = viewportHeight - rect.height - 10;
        }
        
        this.menuElement.style.left = `${x}px`;
        this.menuElement.style.top = `${y}px`;
    }
    
    /**
     * Add styles for menu items
     */
    addMenuStyles() {
        if (document.getElementById('file-context-menu-styles')) return;
        
        const style = document.createElement('style');
        style.id = 'file-context-menu-styles';
        style.textContent = `
            .context-menu-item {
                padding: 0.5rem 1rem;
                cursor: pointer;
                color: var(--bs-light, #f8f9fa);
                transition: background-color 0.15s;
                display: flex;
                align-items: center;
            }
            
            .context-menu-item:hover {
                background-color: rgba(var(--bs-primary-rgb), 0.2);
            }
            
            .context-menu-item-danger:hover {
                background-color: rgba(var(--bs-danger-rgb), 0.2);
                color: var(--bs-danger);
            }
            
            .context-menu-header {
                font-size: 0.85em;
            }
        `;
        document.head.appendChild(style);
    }
    
    /**
     * Handle menu action
     * @param {string} action - Action type
     */
    handleAction(action) {
        switch (action) {
            case 'copy':
                this.onCopy(this.currentItems);
                break;
            case 'move':
                this.onMove(this.currentItems);
                break;
            case 'rename':
                if (this.currentItems.length === 1) {
                    this.onRename(this.currentItems[0]);
                }
                break;
            case 'delete':
                this.onDelete(this.currentItems);
                break;
        }
    }
    
    /**
     * Hide the context menu
     */
    hide() {
        this.menuElement.style.display = 'none';
        this.currentItems = [];
    }
    
    /**
     * Destroy the context menu
     */
    destroy() {
        if (this.menuElement && this.menuElement.parentNode) {
            this.menuElement.parentNode.removeChild(this.menuElement);
        }
    }
}
