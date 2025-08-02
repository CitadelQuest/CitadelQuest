/**
 * File Preview component for CitadelQuest
 * Provides file preview functionality for different file types
 */
export class FilePreview {
    /**
     * @param {Object} options - Configuration options
     * @param {string} options.containerId - ID of the container element
     * @param {Object} options.translations - Translation strings
     * @param {FileBrowserApiService} options.apiService - API service instance
     */
    constructor(options) {
        this.containerId = options.containerId;
        this.translations = options.translations || {};
        this.apiService = options.apiService;
        
        this.currentFile = null;
        
        this.init();
    }
    
    /**
     * Initialize the preview component
     */
    init() {
        this.container = document.getElementById(this.containerId);
        if (!this.container) {
            console.error(`Container with ID "${this.containerId}" not found`);
            return;
        }
        
        this.container.classList.add('file-preview');
        
        // Create empty state
        this.showEmptyState();
    }
    
    /**
     * Show empty state when no file is selected
     */
    showEmptyState() {
        this.container.innerHTML = `
            <div class="file-preview-empty">
                <i class="mdi mdi-file display-1"></i>
                <p>${this.translations.no_file_selected || 'No file selected'}</p>
                <p class="text-muted">${this.translations.select_file_to_preview || 'Select a file to preview its contents'}</p>
            </div>
        `;
    }
    
    /**
     * Show loading state
     */
    showLoadingState() {
        this.container.innerHTML = `
            <div class="file-preview-loading">
                <div class="spinner-border text-cyber" role="status">
                    <span class="visually-hidden">${this.translations.loading || 'Loading...'}</span>
                </div>
                <p>${this.translations.loading_preview || 'Loading preview...'}</p>
            </div>
        `;
    }
    
    /**
     * Show error state
     * @param {string} message - Error message
     */
    showErrorState(message) {
        this.container.innerHTML = `
            <div class="file-preview-error">
                <i class="mdi mdi-alert-circle text-danger display-1"></i>
                <p class="text-danger">${this.translations.error_loading_preview || 'Error loading preview'}</p>
                <p class="text-muted">${message}</p>
            </div>
        `;
    }
    
    /**
     * Preview a file
     * @param {Object} file - File object to preview
     */
    async previewFile(file) {
        if (!file) {
            this.showEmptyState();
            return;
        }
        
        this.currentFile = file;
        
        // Don't try to preview directories
        if (file.isDirectory) {
            this.showDirectoryInfo(file);
            return;
        }
        
        // Show loading state
        this.showLoadingState();
        
        try {
            // Get file content
            const response = await this.apiService.getFileContent(file.id);
            const content = response.content;
            
            // Render preview based on file type
            this.renderFilePreview(file, content);
        } catch (error) {
            console.error('Error loading file preview:', error);
            this.showErrorState(error.message);
        }
    }
    
    /**
     * Show directory information
     * @param {Object} directory - Directory object
     */
    showDirectoryInfo(directory) {
        this.container.innerHTML = `
            <div class="file-preview-directory">
                <div class="file-preview-header">
                    <h5>
                        <i class="mdi mdi-folder"></i>
                        ${directory.name}
                    </h5>
                    <div class="file-info">
                        <span>${this.translations.directory || 'Directory'}</span>
                        <span>${new Date(directory.updatedAt).toLocaleString()}</span>
                    </div>
                </div>
                <div class="file-preview-content text-center p-5">
                    <i class="mdi mdi-folder display-1"></i>
                    <p>${this.translations.directory_info || 'This is a directory'}</p>
                </div>
            </div>
        `;
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
                <h5>
                    <i class="mdi ${this.getFileIcon(file.name)}"></i>
                    ${file.name}
                </h5>
                <div class="file-info">
                    <span>${this.formatFileSize(file.size)}</span>
                    <span>${new Date(file.updatedAt).toLocaleString()}</span>
                </div>
                <div class="file-actions">
                    <button class="btn btn-sm btn-outline-primary" data-action="download" data-file-id="${file.id}">
                        <i class="mdi mdi-cloud-download"></i> ${this.translations.download || 'Download'}
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
            previewHtml += `<img src="${content}" alt="${file.name}" class="img-fluid fp">`;
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
                    <i class="mdi ${this.getFileIcon(file.name)} display-1"></i>
                    <p>${this.translations.no_preview || 'No preview available for this file type'}</p>
                    <button class="btn btn-primary" data-action="download" data-file-id="${file.id}">
                        <i class="mdi mdi-download"></i> ${this.translations.download || 'Download'}
                    </button>
                </div>
            `;
        }
        
        previewHtml += '</div>';
        
        this.container.innerHTML = previewHtml;
        
        // Initialize event listeners for actions
        this.initActionListeners();
    }
    
    /**
     * Initialize event listeners for file actions
     */
    initActionListeners() {
        // Download button
        const downloadBtn = this.container.querySelector('[data-action="download"]');
        if (downloadBtn) {
            downloadBtn.addEventListener('click', () => {
                if (this.currentFile) {
                    this.apiService.downloadFile(this.currentFile.id);
                }
            });
        }
        
        // Delete button
        const deleteBtn = this.container.querySelector('[data-action="delete"]');
        if (deleteBtn) {
            deleteBtn.addEventListener('click', () => {
                if (this.currentFile) {
                    this.onDeleteClick(this.currentFile);
                }
            });
        }
    }
    
    /**
     * Handle delete button click
     * @param {Object} file - File to delete
     */
    onDeleteClick(file) {
        // This should be overridden by the parent component
        console.warn('Delete action not implemented');
    }
    
    /**
     * Set delete handler
     * @param {Function} handler - Delete handler function
     */
    setDeleteHandler(handler) {
        this.onDeleteClick = handler;
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
        
        return iconMap[extension] || 'mdi mdi-file';
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
}
