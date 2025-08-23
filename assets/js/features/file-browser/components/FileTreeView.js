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
        
        // Create the tree element
        this.treeElement = document.createElement('div');
        this.treeElement.className = 'file-tree-view';
        this.container.appendChild(this.treeElement);
        
        // Add custom styles for the tree view
        this.addTreeStyles();
        
        // Load the tree data
        await this.loadTreeData();
    }
    
    /**
     * Add custom styles for the tree view
     */
    addTreeStyles() {
        // Check if styles already exist
        if (document.getElementById('file-tree-view-styles')) {
            return;
        }
        
        const style = document.createElement('style');
        style.id = 'file-tree-view-styles';
        style.textContent = `
            .file-tree-view {
                font-family: var(--bs-font-sans-serif);
                padding: 10px;
                overflow: auto;
                height: 100%;
            }
            
            .file-tree-node {
                padding: 3px 0;
                cursor: pointer;
                white-space: nowrap;
                display: flex;
                align-items: center;
            }
            
            .file-tree-node:hover {
                background-color: rgba(var(--bs-primary-rgb), 0.1);
                border-radius: 4px;
            }
            
            .file-tree-node.selected {
                background-color: rgba(var(--bs-primary-rgb), 0.2);
                border-radius: 4px;
            }
            
            .file-tree-toggle {
                display: inline-block;
                width: 16px;
                height: 16px;
                text-align: center;
                line-height: 16px;
                margin-right: 5px;
                cursor: pointer;
                font-size: 12px;
            }
            
            .file-tree-icon {
                margin-right: 5px;
                width: 16px;
                text-align: center;
            }
            
            .file-tree-label {
                flex-grow: 1;
                overflow: hidden;
                text-overflow: ellipsis;
            }
            
            .file-tree-meta {
                font-size: 0.8em;
                color: var(--bs-secondary);
                margin-left: 5px;
            }
            
            .file-tree-children {
                padding-left: 20px;
            }
            
            /* Cyber-scrollbar style */
            .file-tree-view::-webkit-scrollbar {
                width: 8px;
                height: 8px;
            }
            
            .file-tree-view::-webkit-scrollbar-track {
                background: rgba(0, 0, 0, 0.1);
                border-radius: 4px;
            }
            
            .file-tree-view::-webkit-scrollbar-thumb {
                background: rgba(var(--bs-primary-rgb), 0.5);
                border-radius: 4px;
            }
            
            .file-tree-view::-webkit-scrollbar-thumb:hover {
                background: rgba(var(--bs-primary-rgb), 0.7);
            }
        `;
        
        document.head.appendChild(style);
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
            this.treeElement.innerHTML = '<div class="alert alert-info">No data available</div>';
            return;
        }
        
        // Clear the tree
        this.treeElement.innerHTML = '';
        
        // Render the tree starting from root
        if (this.treeData.children && this.treeData.children.length > 0) {
            this.treeData.children.forEach(child => {
                const nodeElement = this.createTreeNode(child);
                this.treeElement.appendChild(nodeElement);
            });

            // After tree is rendered, expand to initial path if provided
            if (this.initialExpandPath) {
                this.expandToPath(this.initialExpandPath);

                // Call onInit callback with current directory data for initial preview. Before we: this.initialExpandPath = null
                //this.callOnInitCallback();
                
                this.initialExpandPath = null; // Clear after use
            }            
        } else {
            this.treeElement.innerHTML = '<div class="alert alert-info">No files found</div>';
        }
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
            iconElement.innerHTML = '<i class="mdi mdi-folder text-warning"></i>';
            
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
                
                // If expanding (not collapsing), notify about directory toggle
                if (!isVisible) {
                    this.onDirectoryToggle(node);
                }
            };
            
            // Add click handler for toggle arrow
            toggleElement.addEventListener('click', toggleDirectory);
            
            // Add click handler for directory name/icon - same behavior as toggle
            nodeElement.addEventListener('click', (e) => {
                this.selectNode(nodeElement);
                toggleDirectory(e);
                this.onDirectorySelect(node);
            });
            
            return wrapperElement;
        } else {
            // Set file icon based on file type
            iconElement.innerHTML = this.getFileIcon(node.name);
            
            // Add file size to metadata
            if (node.size) {
                metaElement.textContent = this.formatFileSize(node.size);
            }
            
            // Add click handler for file
            nodeElement.addEventListener('click', () => {
                this.selectNode(nodeElement);
                this.onFileSelect(node);
            });
            
            return nodeElement;
        }
    }
    
    /**
     * Select a node and deselect others
     * @param {HTMLElement} nodeElement - The node element to select
     */
    selectNode(nodeElement) {
        // Remove selection from all nodes
        const selectedNodes = this.treeElement.querySelectorAll('.file-tree-node.selected');
        selectedNodes.forEach(node => node.classList.remove('selected'));
        
        // Add selection to the clicked node
        nodeElement.classList.add('selected');
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
}
