import { FileBrowserApiService } from './FileBrowserApiService';
import { FileUploader } from './FileUploader';
import { FileTreeView } from './FileTreeView';

/**
 * File Browser component for CitadelQuest
 * Provides a reusable interface for browsing and managing files
 */
export class FileBrowser {
    /**
     * @param {Object} options - Configuration options
     * @param {string} options.containerId - ID of the container element
     * @param {string} options.projectId - ID of the project
     * @param {Object} options.translations - Translation strings
     */
    constructor(options) {
        // Required options
        this.containerId = options.containerId;
        this.projectId = options.projectId;
        this.translations = options.translations || {};
        
        // Initialize state
        this.currentPath = localStorage.getItem('fileBrowserPath:' + window.location.pathname, '/') || '/';
        this.selectedFile = null;
        this.files = [];
        this.breadcrumbs = [];
        
        // View mode: 'list' or 'tree'(default)
        this.viewMode = localStorage.getItem('fileBrowserViewMode:' + window.location.pathname) || 'tree';
        
        // Initialize API service
        this.apiService = new FileBrowserApiService({
            translations: this.translations
        });
        
        // Initialize FileUploader
        this.fileUploader = null;
        
        // Initialize FileTreeView
        this.fileTreeView = null;
        
        // Initialize the component
        this.init();
    }
    
    /**
     * Initialize the file browser
     */
    async init() {
        this.container = document.getElementById(this.containerId);
        if (!this.container) {
            console.error(`Container with ID "${this.containerId}" not found`);
            return;
        }
        
        // Create the UI structure
        this.createUIStructure();
        
        // Initialize event listeners
        this.initEventListeners();
        
        // Initialize the view based on saved preference
        if (this.viewMode === 'tree') {
            // Initialize tree view first, then hide list view
            this.showTreeView();
        } else {
            // Load initial files in list view (default)
            this.showListView();
        }
        this.updateBreadcrumbs();
    }
    
    /**
     * Create the UI structure
     */
    createUIStructure() {
        this.container.classList.add('file-browser');
        
        // Create the header with actions and breadcrumbs
        const header = document.createElement('div');
        header.className = 'file-browser-header';
        
        // Create action buttons
        const actions = document.createElement('div');
        actions.className = 'file-browser-actions';
        actions.innerHTML = `
            <button class="btn btn-sm btn-outline-primary" data-action="new-folder">
                <i class="mdi mdi-folder-plus"></i> ${this.translations.new_folder || 'New Folder'}
            </button>
            <button class="btn btn-sm btn-outline-primary" data-action="new-file">
                <i class="mdi mdi-file-plus"></i> ${this.translations.new_file || 'New File'}
            </button>
            <button class="btn btn-sm btn-outline-primary" data-action="upload">
                <i class="mdi mdi-upload"></i> ${this.translations.upload || 'Upload'}
            </button>
            <button class="btn btn-sm btn-outline-primary ms-2 d-none" data-action="toggle-view">
                <i class="mdi ${this.viewMode === 'list' ? 'mdi-file-tree' : 'mdi-format-list-bulleted'}"></i> 
                ${this.viewMode === 'list' ? (this.translations.tree_view || 'Tree View') : (this.translations.list_view || 'List View')}
            </button>
        `;
        
        // Create breadcrumbs
        this.breadcrumbsElement = document.createElement('div');
        this.breadcrumbsElement.className = 'file-browser-breadcrumbs';
        this.breadcrumbsElement.classList.add('mb-2');
        
        header.appendChild(this.breadcrumbsElement);
        header.appendChild(actions);
        
        // Create uploader container - positioned after the actions but before the file list
        this.uploaderContainer = document.createElement('div');
        this.uploaderContainer.id = `${this.containerId}-uploader`;
        this.uploaderContainer.className = 'file-browser-uploader';
        this.uploaderContainer.style.display = 'none';
        this.uploaderContainer.style.width = '100%';
        this.uploaderContainer.style.marginTop = '10px';
        this.uploaderContainer.style.marginBottom = '10px';
        this.uploaderContainer.style.gridArea = 'uploader';
        this.container.appendChild(this.uploaderContainer);
        
        // Create the file list container
        this.fileListElement = document.createElement('div');
        this.fileListElement.className = 'file-browser-list';
        
        // Create the file preview container
        this.filePreviewElement = document.createElement('div');
        this.filePreviewElement.className = 'file-browser-preview';
        
        // Add elements to the container
        this.container.appendChild(header);
        this.container.appendChild(this.fileListElement);
        this.container.appendChild(this.filePreviewElement);
        
        // Create hidden file input for uploads (fallback)
        this.fileInput = document.createElement('input');
        this.fileInput.type = 'file';
        this.fileInput.multiple = true; // Allow multiple file selection
        this.fileInput.style.display = 'none';
        this.container.appendChild(this.fileInput);
        
        // Initialize the FileUploader component
        this.fileUploader = new FileUploader({
            containerId: `${this.containerId}-uploader`,
            onUpload: () => this.loadFiles(this.currentPath),
            translations: this.translations,
            apiService: this.apiService,
            projectId: this.projectId,
            currentPath: this.currentPath
        });
    }
    
    /**
     * Initialize event listeners
     */
    initEventListeners() {
        // Global event delegation for file browser actions
        this.container.addEventListener('click', async (e) => {
            const actionButton = e.target.closest('[data-action]');
            if (!actionButton) return;
            
            const action = actionButton.dataset.action;
            
            switch (action) {
                case 'new-folder':
                    await this.showNewFolderDialog();
                    break;
                    
                case 'new-file':
                    await this.showNewFileDialog();
                    break;
                    
                case 'upload':
                    this.toggleUploader();
                    break;
                    
                case 'toggle-view':
                    this.toggleViewMode();
                    break;
                    
                case 'navigate':
                    const path = actionButton.dataset.path;
                    if (path) {
                        await this.loadFiles(path);
                    }
                    break;
                    
                case 'select-file':
                    const fileId = actionButton.dataset.fileId;
                    if (fileId) {
                        await this.selectFile(fileId);
                    }
                    break;
                    
                case 'download':
                    const downloadFileId = actionButton.dataset.fileId;
                    if (downloadFileId) {
                        this.apiService.downloadFile(downloadFileId);
                    }
                    break;
                    
                case 'delete':
                    const deleteFileId = actionButton.dataset.fileId;
                    if (deleteFileId) {
                        await this.confirmAndDeleteFile(deleteFileId);
                    }
                    break;
            }
        });
        
        // File upload handler (fallback)
        this.fileInput.addEventListener('change', async (e) => {
            if (e.target.files.length > 0) {
                // Handle multiple files
                for (let i = 0; i < e.target.files.length; i++) {
                    await this.uploadFile(e.target.files[i]);
                }
            }
        });
    }
    
    /**
     * Load files from the specified path
     * @param {string} path - The path to load files from
     */
    async loadFiles(path) {
        try {
            this.currentPath = path;
            localStorage.setItem('fileBrowserPath:' + window.location.pathname, this.currentPath);
            this.updateBreadcrumbs();
            
            // Update the path in the file uploader
            if (this.fileUploader) {
                this.fileUploader.updatePath(this.currentPath);
            }
            
            // Load files from API
            const response = await this.apiService.listFiles(this.projectId, path);
            this.files = response.files || [];
            
            // Update UI
            this.renderFileList();
            this.updateBreadcrumbs();
            
            // Clear preview if needed
            if (this.selectedFile) {
                const fileStillExists = this.files.find(f => f.id === this.selectedFile.id);
                if (!fileStillExists) {
                    this.selectedFile = null;
                    this.filePreviewElement.innerHTML = '';
                }
            }
        } catch (error) {
            console.error('Error loading files:', error);
            this.showError(error.message);
        }
    }
    
    /**
     * Render the file list
     */
    renderFileList() {
        if (this.files.length === 0) {
            this.fileListElement.innerHTML = `
                <div class="text-center p-4">
                    <p>${this.translations.empty_directory || 'This directory is empty'}</p>
                </div>
            `;
            return;
        }
        
        // Sort files: directories first, then by name
        const sortedFiles = [...this.files].sort((a, b) => {
            if (a.isDirectory && !b.isDirectory) return -1;
            if (!a.isDirectory && b.isDirectory) return 1;
            return a.name.localeCompare(b.name);
        });
        
        const html = sortedFiles.map(file => {
            const icon = file.isDirectory ? 'mdi mdi-folder' : this.getFileIcon(file.name);
            const fileActions = file.isDirectory ? '' : `
                <button class="btn btn-sm btn-outline-primary me-1" data-action="download" data-file-id="${file.id}">
                    <i class="mdi mdi-download"></i>
                </button>
            `;
            
            return `
                <div class="file-item" data-file-id="${file.id}">
                    <div class="file-item-icon">
                        <i class="mdi ${icon}"></i>
                    </div>
                    <div class="file-item-name tutu" data-action="${file.isDirectory ? 'navigate' : 'select-file'}" 
                         data-path="${file.isDirectory ? (file.path=='/'?'':file.path) + '/' + file.name + '/' : ''}" 
                         data-file-id="${file.id}">
                        ${file.name}
                    </div>
                    <div class="file-item-actions">
                        ${fileActions}
                        <button class="btn btn-sm btn-outline-danger" data-action="delete" data-file-id="${file.id}">
                            <i class="mdi mdi-delete"></i>
                        </button>
                    </div>
                </div>
            `;
        }).join('');
        
        this.fileListElement.innerHTML = `<div class="file-list-container">${html}</div>`;
    }
    
    /**
     * Update breadcrumbs based on current path
     */
    updateBreadcrumbs() {
        // Split the path into segments
        const pathSegments = this.currentPath.split('/').filter(segment => segment !== '');
        
        // Build breadcrumbs array with paths
        this.breadcrumbs = [
            { name: this.projectId, path: '/' }
        ];
        
        let currentPath = '/';
        for (const segment of pathSegments) {
            currentPath += segment + '/';
            this.breadcrumbs.push({
                name: segment,
                path: currentPath
            });
        }
        
        // Render breadcrumbs
        const html = this.breadcrumbs.map((crumb, index) => {
            const isLast = index === this.breadcrumbs.length - 1;
            return `
                <span class="breadcrumb-item ${isLast ? 'active' : ''}" 
                      ${!isLast ? `data-action="navigate" data-path="${crumb.path}"` : ''}>
                    ${crumb.name}
                </span>
                ${!isLast ? '<span class="breadcrumb-separator">/</span>' : ''}
            `;
        }).join('');
        
        this.breadcrumbsElement.innerHTML = html;
    }
    
    /**
     * Get appropriate icon for file type
     * @param {string} fileName - The file name
     * @returns {string} - Bootstrap icon class
     */
    getFileIcon(fileName) {
        const extension = fileName.split('.').pop().toLowerCase();
        
        const iconMap = {
            // Images
            'jpg': 'mdi mdi-file-image',
            'jpeg': 'mdi mdi-file-image',
            'png': 'mdi mdi-file-image',
            'gif': 'mdi mdi-file-image',
            'svg': 'mdi mdi-file-image',
            'webp': 'mdi mdi-file-image',
            'ico': 'mdi mdi-file-image',
            'bmp': 'mdi mdi-file-image',
            'avif': 'mdi mdi-file-image',
            'tiff': 'mdi mdi-file-image',
            
            // Documents
            'pdf': 'mdi mdi-file-pdf',
            'doc': 'mdi mdi-file-word',
            'docx': 'mdi mdi-file-word',
            'xls': 'mdi mdi-file-excel',
            'xlsx': 'mdi mdi-file-excel',
            'ppt': 'mdi mdi-file-powerpoint',
            'pptx': 'mdi mdi-file-powerpoint',
            
            // Code
            'html': 'mdi mdi-language-html5',
            'css': 'mdi mdi-language-css3',
            'js': 'mdi mdi-language-javascript',
            'php': 'mdi mdi-language-php',
            'py': 'mdi mdi-language-python',
            'java': 'mdi mdi-code-braces',
            'c': 'mdi mdi-code-braces',
            'cpp': 'mdi mdi-code-braces',
            'h': 'mdi mdi-code-braces',
            'json': 'mdi mdi-code-json',
            'xml': 'mdi mdi-file-xml',
            
            // Text
            'txt': 'mdi mdi-file-document',
            'md': 'mdi mdi-language-markdown',
            
            // Archives
            'zip': 'mdi mdi-zip-box',
            'rar': 'mdi mdi-zip-box',
            'tar': 'mdi mdi-zip-box',
            'gz': 'mdi mdi-zip-box',
            
            // Audio
            'mp3': 'mdi mdi-file-music',
            'wav': 'mdi mdi-file-music',
            'ogg': 'mdi mdi-file-music',
            
            // Video
            'mp4': 'mdi mdi-file-video',
            'avi': 'mdi mdi-file-video',
            'mov': 'mdi mdi-file-video',
            'wmv': 'mdi mdi-file-video'
        };
        
        return (iconMap[extension] || 'mdi mdi-file') + ' text-cyber';
    }
    
    /**
     * Show an error message
     * @param {string} message - The error message
     */
    showError(message) {
        // For now, just use console.error
        // In the future, this could show a toast or alert
        console.error(message);
    }
    
    /**
     * Show a success message
     * @param {string} message - The success message
     */
    showSuccess(message) {
        // For now, just use console.log
        // In the future, this could show a toast or alert
        console.log(message);
    }
    
    /**
     * Show dialog to create a new folder
     */
    async showNewFolderDialog() {
        const folderName = prompt(this.translations.enter_folder_name || 'Enter folder name:');
        if (!folderName) return;
        
        try {
            console.log('currentPath', this.currentPath);
            console.log('folderName', folderName);

            await this.apiService.createDirectory(this.projectId, this.currentPath, folderName);
            await this.loadFiles(this.currentPath);
            
            // Show success message using the toast system
            if (window.toast) {
                window.toast.success(this.translations.folder_created || 'Folder created successfully');
            } else {
                this.showSuccess(this.translations.folder_created || 'Folder created successfully');
            }
        } catch (error) {
            console.error('Error creating folder:', error);
            
            // Show error message using the toast system
            if (window.toast) {
                window.toast.error(error.message);
            } else {
                this.showError(error.message);
            }
        }
    }
    
    /**
     * Show dialog to create a new file
     */
    async showNewFileDialog() {
        const fileName = prompt(this.translations.enter_file_name || 'Enter file name:');
        if (!fileName) return;
        
        const content = prompt(this.translations.enter_file_content || 'Enter file content:');
        if (content === null) return; // Allow empty content but not cancelled dialog
        
        try {
            await this.apiService.createFile(this.projectId, this.currentPath, fileName, content);
            await this.loadFiles(this.currentPath);
            
            // Show success message using the toast system
            if (window.toast) {
                window.toast.success(this.translations.file_created || 'File created successfully');
            } else {
                this.showSuccess(this.translations.file_created || 'File created successfully');
            }
        } catch (error) {
            console.error('Error creating file:', error);
            
            // Show error message using the toast system
            if (window.toast) {
                window.toast.error(error.message);
            } else {
                this.showError(error.message);
            }
        }
    }
    
    /**
     * Select a file and show its preview
     * @param {string} fileId - The ID of the file to select
     */
    async selectFile(fileId) {
        try {
            // First try to find file in current files list (for list view)
            let file = this.files.find(f => f.id === fileId);
            
            // If not found in current files, get file metadata from API (for tree view)
            if (!file) {
                const response = await this.apiService.getFileMetadata(fileId);
                file = response.file;
            }
            
            if (!file) throw new Error('File not found');
            
            // Don't try to preview directories
            if (file.isDirectory) {
                await this.loadFiles(file.path=='/'?'':file.path + file.name + '/');
                return;
            }
            
            this.selectedFile = file;
            
            // Show loading state
            this.filePreviewElement.innerHTML = `
                <div class="text-center p-4">
                    <div class="spinner-border text-cyber" role="status">
                        <span class="visually-hidden">${this.translations.loading || 'Loading...'}</span>
                    </div>
                </div>
            `;
            
            // Get file content
            const response = await this.apiService.getFileContent(fileId);
            const content = response.content;
            
            // Render preview based on file type
            this.renderFilePreview(file, content);
            
            // Highlight the selected file in the list (only works in list view)
            const fileItems = this.fileListElement.querySelectorAll('.file-item');
            fileItems.forEach(item => {
                if (item.dataset.fileId === fileId) {
                    item.classList.add('selected');
                } else {
                    item.classList.remove('selected');
                }
            });
        } catch (error) {
            console.error('Error selecting file:', error);
            
            // Show error message using the toast system
            if (window.toast) {
                window.toast.error(error.message);
            } else {
                this.showError(error.message);
            }
        }
    }
    
    /**
     * Render preview for a file based on its type
     * @param {Object} file - The file object
     * @param {string} content - The file content
     */
    renderFilePreview(file, content) {
        const extension = file.name.split('.').pop().toLowerCase();
        let previewHtml = '';
        
        // File info header
        previewHtml += `
            <div class="file-preview-header">
                <h5 class="mb-1">
                    <i class="${this.getFileIcon(file.name)}"></i>
                    ${file.name}
                </h5>
                <div class="file-info mb-2">
                    <span>${this.formatFileSize(file.size)}</span>
                    <span>${new Date(file.updatedAt).toLocaleString()}</span>
                </div>
                <div class="file-preview-actions">
                    <button class="btn btn-sm btn-outline-primary me-2" data-action="download" data-file-id="${file.id}">
                        <i class="mdi mdi-download"></i> ${this.translations.download || 'Download'}
                    </button>
                    <button class="btn btn-sm btn-outline-danger" data-action="delete" data-file-id="${file.id}">
                        <i class="mdi mdi-delete"></i> ${this.translations.delete || 'Delete'}
                    </button>
                </div>
            </div>
        `;
        
        // Preview content based on file type
        previewHtml += '<div class="file-preview-content">';
        
        // Images
        if (['jpg', 'jpeg', 'png', 'gif', 'svg', 'webp', 'ico', 'bmp', 'avif', 'tiff'].includes(extension)) {
            previewHtml += `<img src="${content}" alt="${file.name}" class="img-fluid fb">`;
        }
        // Text/code files
        else if (['txt', 'md', 'html', 'css', 'js', 'php', 'py', 'java', 'c', 'cpp', 'h', 'json', 'xml'].includes(extension)) {
            previewHtml += `<pre class="code-preview">${this.escapeHtml(content)}</pre>`;
        }
        // PDF (embed)
        else if (extension === 'pdf') {
            previewHtml += `<embed src="${this.apiService.baseUrl}/${file.id}/download" type="application/pdf" width="100%" height="500px">`;
        }
        // Audio
        else if (['mp3', 'wav', 'ogg'].includes(extension)) {
            previewHtml += `
                <audio controls>
                    <source src="${this.apiService.baseUrl}/${file.id}/download" type="audio/${extension}">
                    ${this.translations.audio_not_supported || 'Your browser does not support the audio element.'}
                </audio>
            `;
        }
        // Video
        else if (['mp4', 'webm', 'ogg'].includes(extension)) {
            previewHtml += `
                <video controls class="img-fluid">
                    <source src="${this.apiService.baseUrl}/${file.id}/download" type="video/${extension}">
                    ${this.translations.video_not_supported || 'Your browser does not support the video element.'}
                </video>
            `;
        }
        // Other file types
        else {
            previewHtml += `
                <div class="text-center p-5">
                    <i class="${this.getFileIcon(file.name)} display-1"></i>
                    <p>${this.translations.no_preview || 'No preview available for this file type'}</p>
                    <button class="btn btn-primary" data-action="download" data-file-id="${file.id}">
                        <i class="mdi mdi-download"></i> ${this.translations.download || 'Download'}
                    </button>
                </div>
            `;
        }
        
        previewHtml += '</div>';
        
        this.filePreviewElement.innerHTML = previewHtml;
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
     * Escape HTML to prevent XSS
     * @param {string} html - The HTML to escape
     * @returns {string} - Escaped HTML
     */
    escapeHtml(html) {
        const div = document.createElement('div');
        div.textContent = html;
        return div.innerHTML;
    }
    
    /**
     * Confirm and delete a file or directory
     * @param {string} fileId - The ID of the file to delete
     */
    async confirmAndDeleteFile(fileId) {
        const file = this.files.find(f => f.id === fileId);
        if (!file) return;
        
        const confirmMessage = file.isDirectory
            ? (this.translations.confirm_delete_directory || `Are you sure you want to delete the directory "${file.name}" and all its contents?`)
            : (this.translations.confirm_delete_file || `Are you sure you want to delete the file "${file.name}"?`);
        
        if (!confirm(confirmMessage)) return;
        
        try {
            await this.apiService.deleteFile(fileId);
            
            // If the deleted file was selected, clear the preview
            if (this.selectedFile && this.selectedFile.id === fileId) {
                this.selectedFile = null;
                this.filePreviewElement.innerHTML = '';
            }
            
            // Reload the file list
            await this.loadFiles(this.currentPath);
            
            // Show success message using the toast system
            if (window.toast) {
                window.toast.success(file.isDirectory
                    ? (this.translations.directory_deleted || 'Directory deleted successfully')
                    : (this.translations.file_deleted || 'File deleted successfully'));
            } else {
                this.showSuccess(file.isDirectory
                    ? (this.translations.directory_deleted || 'Directory deleted successfully')
                    : (this.translations.file_deleted || 'File deleted successfully'));
            }
        } catch (error) {
            console.error('Error deleting file:', error);
            
            // Show error message using the toast system
            if (window.toast) {
                window.toast.error(error.message);
            } else {
                this.showError(error.message);
            }
        }
    }
    
    /**
     * Toggle the uploader visibility
     */
    toggleUploader() {
        if (this.uploaderContainer.style.display === 'none') {
            this.uploaderContainer.style.display = 'block';
            // Scroll to make uploader visible
            this.uploaderContainer.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            // Update the current path in the uploader
            this.fileUploader.updatePath(this.currentPath);
        } else {
            this.uploaderContainer.style.display = 'none';
        }
    }
    
    /**
     * Upload a file (fallback method when not using the uploader component)
     * @param {File} file - The file to upload
     */
    async uploadFile(file) {
        try {
            await this.apiService.uploadFile(this.projectId, this.currentPath, file);
            await this.loadFiles(this.currentPath);
            
            // Show success message using the toast system
            if (window.toast) {
                window.toast.success(this.translations.file_uploaded || 'File uploaded successfully');
            } else {
                this.showSuccess(this.translations.file_uploaded || 'File uploaded successfully');
            }
        } catch (error) {
            console.error('Error uploading file:', error);
            
            // Show error message using the toast system
            if (window.toast) {
                window.toast.error(error.message);
            } else {
                this.showError(error.message);
            }
        }
    }
    
    /**
     * Toggle between list view and tree view
     */
    toggleViewMode() {
        // Toggle view mode
        this.viewMode = this.viewMode === 'list' ? 'tree' : 'list';
        
        // Save preference to localStorage
        localStorage.setItem('fileBrowserViewMode:' + window.location.pathname, this.viewMode);
        
        // Update toggle button
        const toggleButton = this.container.querySelector('[data-action="toggle-view"]');
        if (toggleButton) {
            toggleButton.innerHTML = this.viewMode === 'tree' 
                ? `<i class="mdi mdi-format-list-bulleted"></i> ${this.translations.listView || 'List View'}`
                : `<i class="mdi mdi-file-tree"></i> ${this.translations.treeView || 'Tree View'}`;
        }
        
        if (this.viewMode === 'list') {
            // Show list view
            this.showListView();
        } else {
            // Show tree view
            this.showTreeView();
        }
    }
    
    /**
     * Show list view
     */
    showListView() {
        // Clear and reload file list
        this.fileListElement.innerHTML = '';
        this.loadFiles(this.currentPath);
    }
    
    /**
     * Show tree view
     */
    showTreeView() {
        this.initTreeView();
        
        // Set initial expand path if we have a current path
        if (this.fileTreeView && this.currentPath && this.currentPath !== '/') {
            this.fileTreeView.setInitialExpandPath(this.currentPath);
        }
    }
    
    /**
     * Initialize the tree view
     */
    initTreeView() {
        // Clear the file list content
        this.fileListElement.innerHTML = '';
        
        // Create tree view container if it doesn't exist
        if (!this.treeViewContainer) {
            this.treeViewContainer = document.createElement('div');
            this.treeViewContainer.className = 'file-browser-tree-view';
            this.treeViewContainer.id = `${this.containerId}-tree-view`;
            this.treeViewContainer.style.cssText = `
                height: 100%;
                overflow-y: auto;
            `;
        }
        
        // Add tree view container to file list area
        this.fileListElement.appendChild(this.treeViewContainer);
        
        if (!this.fileTreeView) {
            // Initialize the tree view
            this.fileTreeView = new FileTreeView({
                containerId: this.treeViewContainer.id,
                apiService: this.apiService,
                projectId: this.projectId,
                translations: this.translations,
                onFileSelect: (file) => {
                    this.selectFile(file.id);
                },
                onDirectorySelect: (directory) => {
                    // In tree view, just update breadcrumbs (toggle is handled by FileTreeView)
                    this.currentPath = directory.path;
                    this.updateBreadcrumbs();
                    
                    // Save current path to localStorage
                    localStorage.setItem('fileBrowserPath:' + window.location.pathname, this.currentPath);
                }
            });
        } else {
            // Refresh the tree view
            this.fileTreeView.refresh();
        }
    }
}
