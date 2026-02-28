/**
 * File Tree View component for CitadelQuest
 * Provides a hierarchical tree view of project files and directories
 */
export class FileTreeView {
    /**
     * @param {Object} options - Configuration options
     * @param {string} options.containerId - ID of the container element
     * @param {FileBrowserApiService} options.apiService - API service instance
     * @param {string} options.projectId - ID of the project
     * @param {Function} options.onFileSelect - Callback when a file is selected
     * @param {Function} options.onDirectorySelect - Callback when a directory is selected
     * @param {Object} options.translations - Translation strings
     */
    constructor(options) {
        this.containerId = options.containerId;
        this.apiService = options.apiService;
        this.projectId = options.projectId;
        this.onFileSelect = options.onFileSelect || (() => {});
        this.onDirectorySelect = options.onDirectorySelect || (() => {});
        this.onDirectoryToggle = options.onDirectoryToggle || (() => {});
        this.onInit = options.onInit || (() => {});
        this.translations = options.translations || {};
        
        this.treeData = null;
        this.container = null;
        this.treeElement = null;
        this.searchInput = null;
        this.searchFilter = '';
        
        // Multi-select support
        this.selectedNodes = new Set();
        this.onContextMenu = options.onContextMenu || (() => {});
        
        this.init();
    }
    
    /**
     * Initialize the tree view
     */
    async init() {
        this.container = document.getElementById(this.containerId);
        if (!this.container) {
            console.error(`Container with ID "${this.containerId}" not found`);
            return;
        }
        
        // Create wrapper for tree and search
        const wrapper = document.createElement('div');
        wrapper.className = 'file-tree-wrapper';
        wrapper.style.cssText = 'display: flex; flex-direction: column; height: 100%;';
        
        // Create the tree element
        this.treeElement = document.createElement('div');
        this.treeElement.className = 'file-tree-view';
        this.treeElement.style.cssText = 'flex: 1; overflow: auto;';
        wrapper.appendChild(this.treeElement);
        
        // Create search input container
        this.createSearchInput(wrapper);
        
        this.container.appendChild(wrapper);
        
        // Load the tree data
        await this.loadTreeData();
    }
    
    /**
     * Create search input element
     * @param {HTMLElement} wrapper - The wrapper element to append to
     */
    createSearchInput(wrapper) {
        const searchContainer = document.createElement('div');
        searchContainer.className = 'file-tree-search';
        
        const inputGroup = document.createElement('div');
        inputGroup.className = 'input-group input-group-sm';
        
        const inputIcon = document.createElement('span');
        inputIcon.className = 'input-group-text bg-transparent border-secondary rounded-top-0 border-opacity-50';
        inputIcon.innerHTML = '<i class="mdi mdi-magnify text-cyber"></i>';
        
        this.searchInput = document.createElement('input');
        this.searchInput.type = 'text';
        this.searchInput.className = 'form-control form-control-sm bg-transparent border-secondary text-light rounded-top-0 border-opacity-50';
        this.searchInput.placeholder = this.translations.search_placeholder || 'Filter files...';
        this.searchInput.style.cssText = 'border-left: none;';
        
        // Create count badge (hidden by default)
        this.searchCountBadge = document.createElement('span');
        this.searchCountBadge.className = 'input-group-text bg-transparent border-secondary rounded-top-0 border-opacity-50 text-cyber';
        this.searchCountBadge.style.cssText = 'display: none; border-left: none; font-size: 0.75rem; padding: 0.25rem 0.5rem;';
        
        // Add input event listener with debounce
        let debounceTimer;
        this.searchInput.addEventListener('input', (e) => {
            clearTimeout(debounceTimer);
            debounceTimer = setTimeout(() => {
                this.searchFilter = e.target.value.toLowerCase().trim();
                this.renderTree();
            }, 150);
        });
        
        // Clear filter on Escape key
        this.searchInput.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                this.searchInput.value = '';
                this.searchFilter = '';
                this.renderTree();
            }
        });
        
        inputGroup.appendChild(inputIcon);
        inputGroup.appendChild(this.searchInput);
        inputGroup.appendChild(this.searchCountBadge);
        searchContainer.appendChild(inputGroup);
        wrapper.appendChild(searchContainer);
    }
    
    /**
     * Update the search count badge
     * @param {number} count - Number of filtered items
     */
    updateSearchCountBadge(count) {
        if (!this.searchCountBadge) return;
        
        if (this.searchFilter && count > 0) {
            this.searchCountBadge.textContent = count;
            this.searchCountBadge.style.display = 'flex';
        } else {
            this.searchCountBadge.style.display = 'none';
        }
    }
    
    /**
     * Load the tree data from the API
     */
    async loadTreeData() {
        try {
            this.treeElement.innerHTML = '<div class="text-center"><div class="spinner-border spinner-border-sm" role="status"></div> Loading...</div>';
            
            const response = await this.apiService.getProjectTree(this.projectId);
            this.treeData = response.tree;
            
            this.renderTree();
        } catch (error) {
            console.error('Error loading tree data:', error);
            this.treeElement.innerHTML = `<div class="alert alert-danger">${error.message || 'Failed to load project tree'}</div>`;
        }
    }
    
    /**
     * Render the tree view
     */
    renderTree() {
        if (!this.treeData) {
            this.treeElement.innerHTML = '<div class="alert alert-warning">No data available</div>';
            this.updateSearchCountBadge(0);
            return;
        }
        
        // Clear the tree
        this.treeElement.innerHTML = '';
        
        // Render the tree starting from root
        if (this.treeData.children && this.treeData.children.length > 0) {
            // Filter tree if search filter is active
            const filteredChildren = this.searchFilter 
                ? this.filterTreeNodes(this.treeData.children)
                : this.treeData.children;
            
            // Count filtered items (files only, not directories)
            const filteredCount = this.searchFilter 
                ? this.countFilteredItems(filteredChildren)
                : 0;
            this.updateSearchCountBadge(filteredCount);
            
            if (filteredChildren.length > 0) {
                filteredChildren.forEach(child => {
                    // When filtering, expand all directories to show matches
                    const nodeElement = this.createTreeNode(child, !!this.searchFilter);
                    if (nodeElement) {
                        this.treeElement.appendChild(nodeElement);
                    }
                });
            } else if (this.searchFilter) {
                this.treeElement.innerHTML = `<div class="alert alert-warning small py-2">${this.translations.no_results || 'No matching files'}</div>`;
            }

            // After tree is rendered, expand to initial path if provided (only when not filtering)
            if (this.initialExpandPath && !this.searchFilter) {
                this.expandToPath(this.initialExpandPath);

                // Call onInit callback with current directory data for initial preview. Before we: this.initialExpandPath = null
                //this.callOnInitCallback();
                
                this.initialExpandPath = null; // Clear after use
            }            
        } else {
            this.treeElement.innerHTML = '<div class="alert alert-warning">No files found</div>';
            this.updateSearchCountBadge(0);
        }
    }
    
    /**
     * Count items in filtered tree whose name actually matches the search filter
     * (excludes parent directories included only because they contain matching children)
     * @param {Array} nodes - Array of filtered tree nodes
     * @returns {number} - Total count of matching items
     */
    countFilteredItems(nodes) {
        let count = 0;
        for (const node of nodes) {
            if (node.name.toLowerCase().includes(this.searchFilter)) {
                count++; // Only count if name actually matches
            }
            if (node.type === 'directory' && node.children && node.children.length > 0) {
                count += this.countFilteredItems(node.children);
            }
        }
        return count;
    }
    
    /**
     * Filter tree nodes based on search filter
     * Returns nodes that match or have children that match
     * @param {Array} nodes - Array of tree nodes
     * @returns {Array} - Filtered nodes
     */
    filterTreeNodes(nodes) {
        const result = [];
        
        for (const node of nodes) {
            const nameMatches = node.name.toLowerCase().includes(this.searchFilter);
            
            if (node.type === 'directory' && node.children && node.children.length > 0) {
                // Recursively filter children
                const filteredChildren = this.filterTreeNodes(node.children);
                
                if (nameMatches || filteredChildren.length > 0) {
                    // Include directory if it matches or has matching children
                    result.push({
                        ...node,
                        children: nameMatches ? node.children : filteredChildren
                    });
                }
            } else if (nameMatches) {
                // Include file if it matches
                result.push(node);
            }
        }
        
        return result;
    }
    
    /**
     * Create a tree node element
     * @param {Object} node - The node data
     * @param {boolean} isExpanded - Whether the node is expanded
     * @returns {HTMLElement} - The tree node element
     */
    createTreeNode(node, isExpanded = false) {
        const nodeElement = document.createElement('div');
        nodeElement.className = 'file-tree-node';
        nodeElement.dataset.id = node.id;
        nodeElement.dataset.path = node.path;
        nodeElement.dataset.name = node.name;
        nodeElement.dataset.type = node.type;
        nodeElement.dataset.isRemote = node.isRemote ? '1' : '';
        nodeElement.dataset.isShared = node.isShared ? '1' : '';
        
        // Create toggle button for directories
        const toggleElement = document.createElement('span');
        toggleElement.className = 'file-tree-toggle';
        
        // Create icon element
        const iconElement = document.createElement('span');
        iconElement.className = 'file-tree-icon';
        
        // Create label element
        const labelElement = document.createElement('span');
        labelElement.className = 'file-tree-label';
        labelElement.textContent = node.name;
        
        // Create metadata element
        const metaElement = document.createElement('span');
        metaElement.className = 'file-tree-meta';
        
        // Add elements to node
        if (node.type === 'directory') {
            nodeElement.appendChild(toggleElement);
            nodeElement.dataset.path = (node.path == '/' ? '' : node.path) + '/' + node.name;
        }
        nodeElement.appendChild(iconElement);
        nodeElement.appendChild(labelElement);
        nodeElement.appendChild(metaElement);
        
        // Handle directories vs files
        if (node.type === 'directory') {
            // Set directory icon
            iconElement.innerHTML = isExpanded ? '<i class="mdi mdi-folder-open text-warning"></i>' : '<i class="mdi mdi-folder text-warning"></i>';
            
            // Set toggle icon
            toggleElement.innerHTML = isExpanded ? '<i class="mdi mdi-chevron-down"></i>' : '<i class="mdi mdi-chevron-right"></i>';
            
            // Create children container
            const childrenElement = document.createElement('div');
            childrenElement.className = 'file-tree-children';
            childrenElement.style.display = isExpanded ? 'block' : 'none';
            
            // Add children if they exist
            if (node.children && node.children.length > 0) {
                // Sort children: directories first, then files, both alphabetically
                const sortedChildren = [...node.children].sort((a, b) => {
                    if (a.type !== b.type) {
                        return a.type === 'directory' ? -1 : 1;
                    }
                    return a.name.localeCompare(b.name);
                });
                
                sortedChildren.forEach(child => {
                    const childNode = this.createTreeNode(child);
                    childrenElement.appendChild(childNode);
                });
            }
            
            // Create wrapper for node and its children
            const wrapperElement = document.createElement('div');
            wrapperElement.appendChild(nodeElement);
            wrapperElement.appendChild(childrenElement);
            
            // Define toggle function to be reused
            const toggleDirectory = (e) => {
                if (e) e.stopPropagation();
                const isVisible = childrenElement.style.display === 'block';
                childrenElement.style.display = isVisible ? 'none' : 'block';
                toggleElement.innerHTML = isVisible ? 
                    '<i class="mdi mdi-chevron-right"></i>' : 
                    '<i class="mdi mdi-chevron-down"></i>';
                iconElement.innerHTML = isVisible ?
                    '<i class="mdi mdi-folder text-warning"></i>' :
                    '<i class="mdi mdi-folder-open text-warning"></i>';
                
                // If expanding (not collapsing), notify about directory toggle
                if (!isVisible) {
                    this.onDirectoryToggle(node);
                }
            };
            
            // Add click handler for toggle arrow
            toggleElement.addEventListener('click', toggleDirectory);
            
            // Add click handler for directory name/icon - same behavior as toggle
            nodeElement.addEventListener('click', (e) => {
                this.selectNode(nodeElement, e.ctrlKey || e.metaKey);
                toggleDirectory(e);
                this.onDirectorySelect(node);
            });
            
            // Add context menu handler for directories
            nodeElement.addEventListener('contextmenu', (e) => {
                e.preventDefault();
                e.stopPropagation();
                if (!this.selectedNodes.has(nodeElement)) {
                    this.selectNode(nodeElement, false);
                }
                this.onContextMenu(e, this.getSelectedItems());
            });
            
            return wrapperElement;
        } else {
            // Set file icon based on file type
            iconElement.innerHTML = this.getFileIcon(node.name);

            // Add cloud icon for remote/synced files
            if (node.isRemote) {
                const remoteIcon = document.createElement('i');
                remoteIcon.className = 'mdi mdi-cloud-sync-outline text-cyber ms-1';
                remoteIcon.title = 'Synced from remote Citadel';
                remoteIcon.style.fontSize = '0.75rem';
                labelElement.appendChild(remoteIcon);
            }

            // Add share icon for shared files
            if (node.isShared) {
                const shareIcon = document.createElement('i');
                shareIcon.className = 'mdi mdi-share-variant text-success ms-1';
                shareIcon.title = 'Shared via CQ Share';
                shareIcon.style.fontSize = '0.75rem';
                labelElement.appendChild(shareIcon);
            }
            
            // Add file size to metadata
            if (node.size) {
                metaElement.textContent = this.formatFileSize(node.size);
            }
            
            // Add click handler for file
            nodeElement.addEventListener('click', (e) => {
                this.selectNode(nodeElement, e.ctrlKey || e.metaKey);
                this.onFileSelect(node);
            });
            
            // Add context menu handler
            nodeElement.addEventListener('contextmenu', (e) => {
                e.preventDefault();
                e.stopPropagation();
                // If right-clicking on unselected node, select it first
                if (!this.selectedNodes.has(nodeElement)) {
                    this.selectNode(nodeElement, false);
                }
                this.onContextMenu(e, this.getSelectedItems());
            });
            
            return nodeElement;
        }
    }
    
    /**
     * Select a node, with optional multi-select support
     * @param {HTMLElement} nodeElement - The node element to select
     * @param {boolean} multiSelect - Whether to add to selection (Ctrl+click)
     */
    selectNode(nodeElement, multiSelect = false) {
        if (multiSelect) {
            // Toggle selection for this node
            if (this.selectedNodes.has(nodeElement)) {
                this.selectedNodes.delete(nodeElement);
                nodeElement.classList.remove('selected', 'multi-selected');
            } else {
                this.selectedNodes.add(nodeElement);
                nodeElement.classList.add('multi-selected');
            }
            // Update visual state based on selection count
            this.updateSelectionVisuals();
        } else {
            // Single select - clear all and select this one
            this.clearSelection();
            this.selectedNodes.add(nodeElement);
            nodeElement.classList.add('selected');
        }
    }
    
    /**
     * Clear all selections
     */
    clearSelection() {
        this.selectedNodes.forEach(node => {
            node.classList.remove('selected', 'multi-selected');
        });
        this.selectedNodes.clear();
    }
    
    /**
     * Update visual state based on selection count
     */
    updateSelectionVisuals() {
        const count = this.selectedNodes.size;
        this.selectedNodes.forEach(node => {
            if (count > 1) {
                node.classList.remove('selected');
                node.classList.add('multi-selected');
            } else {
                node.classList.remove('multi-selected');
                node.classList.add('selected');
            }
        });
    }
    
    /**
     * Get array of selected items with their data
     * @returns {Array} Array of { path, name, type, id } objects
     */
    getSelectedItems() {
        return Array.from(this.selectedNodes).map(node => ({
            path: node.dataset.path,
            name: node.dataset.name,
            type: node.dataset.type,
            id: node.dataset.id,
            isRemote: !!node.dataset.isRemote,
            isShared: !!node.dataset.isShared
        }));
    }
    
    /**
     * Get appropriate icon for file type
     * @param {string} fileName - The file name
     * @returns {string} - HTML for the icon
     */
    getFileIcon(fileName) {
        const extension = fileName.split('.').pop().toLowerCase();

        const iconStyle = 'text-cyber';
        
        // Image files
        if (['jpg', 'jpeg', 'png', 'gif', 'bmp', 'svg', 'webp', 'ico'].includes(extension)) {
            return '<i class="mdi mdi-file-image ' + iconStyle + '"></i>';
        }
        
        // Text files
        if (['txt', 'md', 'rtf'].includes(extension)) {
            return '<i class="mdi mdi-file-document ' + iconStyle + '"></i>';
        }
        
        // Code files
        if (['html', 'css', 'js', 'php', 'py', 'java', 'c', 'cpp', 'h', 'cs', 'rb', 'go', 'ts', 'json', 'xml'].includes(extension)) {
            return '<i class="mdi mdi-file-code ' + iconStyle + '"></i>';
        }
        
        // PDF files
        if (extension === 'pdf') {
            return '<i class="mdi mdi-file-pdf-box ' + iconStyle + '"></i>';
        }
        
        // Archive files
        if (['zip', 'rar', 'tar', 'gz', '7z'].includes(extension)) {
            return '<i class="mdi mdi-zip-box ' + iconStyle + '"></i>';
        }
        
        // Audio files
        if (['mp3', 'wav', 'ogg', 'flac', 'aac'].includes(extension)) {
            return '<i class="mdi mdi-file-music ' + iconStyle + '"></i>';
        }
        
        // Video files
        if (['mp4', 'avi', 'mov', 'wmv', 'flv', 'mkv', 'webm'].includes(extension)) {
            return '<i class="mdi mdi-file-video ' + iconStyle + '"></i>';
        }
        
        // Default file icon
        return '<i class="mdi mdi-file ' + iconStyle + '"></i>';
    }
    
    /**
     * Format file size in human-readable format
     * @param {number} bytes - File size in bytes
     * @returns {string} - Formatted file size
     */
    formatFileSize(bytes) {
        if (bytes === 0) return '0 Bytes';
        
        const k = 1024;
        const sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        
        return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
    }
    
    /**
     * Set initial path to expand to when tree is rendered
     * @param {string} path - Path to expand to
     */
    setInitialExpandPath(path) {
        this.initialExpandPath = path;
    }
    
    /**
     * Refresh the tree view
     */
    async refresh() {
        // Store current expand path before refresh
        const currentExpandPath = this.initialExpandPath;
        
        // Reload tree data
        await this.loadTreeData();
        
        // Re-expand to the stored path after refresh
        if (currentExpandPath) {
            this.expandToPath(currentExpandPath);
        }
    }
    
    /**
     * Call onInit callback with current directory data for initial preview
     */
    callOnInitCallback() {
        if (!this.initialExpandPath || !this.treeData) {
            return;
        }        
        
        // Find the directory that matches the initial expand path
        const currentDirectory = this.findDirectoryByPath(this.treeData, this.initialExpandPath);
        
        if (currentDirectory) {
            // Call the onInit callback with the real directory data
            this.onInit(currentDirectory);
        }
    }
    
    /**
     * Find a directory in the tree structure by its path
     * @param {Object} tree - The tree structure
     * @param {string} targetPath - The path to find
     * @returns {Object|null} - The directory object or null if not found
     */
    findDirectoryByPath(tree, targetPath) {
        if (!tree) return null;
        
        // Check if this is the target directory
        if (tree.type === 'directory' && (/* tree.path == '/' ? '' : */ tree.path) + '/' + tree.name === targetPath) {
            return tree;
        }
        
        // Search in children if they exist
        if (tree.children && Array.isArray(tree.children)) {
            for (const child of tree.children) {
                const found = this.findDirectoryByPath(child, targetPath);
                if (found) return found;
            }
        }
        
        return null;
    }
    
    /**
     * Expand path to a specific file or directory
     * @param {string} path - The path to expand to
     */
    expandToPath(path) {
        if (!path) return;
        
        const pathParts = path.split('/').filter(part => part);
        
        // Start from the first path part since we don't have a root wrapper anymore
        const firstPart = pathParts[0];
        
        // Find the first directory in the tree
        let currentElement = this.treeElement.querySelector(`.file-tree-node[data-path="/${firstPart}"][data-type="directory"]`);
        
        if (!currentElement) return;

        // Traverse the path
        for (let i = 0; i < pathParts.length; i++) {
            // Find the wrapper element (parent of the node)
            const wrapperElement = currentElement.parentElement;
            
            // Expand this directory if it's not already expanded
            const toggleElement = currentElement.querySelector('.file-tree-toggle');
            const childrenElement = wrapperElement.querySelector('.file-tree-children');
            
            if (childrenElement && childrenElement.style.display !== 'block') {
                childrenElement.style.display = 'block';
                if (toggleElement) {
                    toggleElement.innerHTML = '<i class="mdi mdi-chevron-down"></i>';
                }
            }
            
            // If this is the last part, we're done expanding
            if (i === pathParts.length - 1) {
                break;
            }
            
            // Find the next element in the path
            if (childrenElement) {
                // Build the correct next path: take all parts up to i+1
                const nextPathParts = pathParts.slice(0, i + 2); // +2 because we want to include the next part
                const nextPathWithoutSlash = '/' + nextPathParts.join('/');
                
                // Try without slash first, then with slash
                currentElement = childrenElement.querySelector(`.file-tree-node[data-path="${nextPathWithoutSlash}"][data-type="directory"]`);
                
                if (!currentElement) {
                    break;
                }
            }
        }
        
        // Select the final element
        if (currentElement) {
            this.selectNode(currentElement);
            currentElement.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }

        this.callOnInitCallback();
    }
    
    /**
     * Remove a file from the tree DOM without full refresh
     * @param {string} fileId - The ID of the file to remove
     */
    removeFileFromTree(fileId) {
        const fileNode = this.treeElement.querySelector(`.file-tree-node[data-id="${fileId}"]`);
        if (fileNode) {
            // For files, the node is returned directly (not wrapped)
            // For directories, the node is inside a wrapper
            // Check if parent is a wrapper (has both node and children) or the children container
            const parent = fileNode.parentElement;
            if (parent && parent.classList.contains('file-tree-children')) {
                // Parent is the children container, just remove the file node itself
                fileNode.remove();
            } else if (parent) {
                // Parent is a wrapper element, remove the whole wrapper
                parent.remove();
            }
        }
    }
}
