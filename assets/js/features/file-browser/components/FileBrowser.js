import { FileBrowserApiService } from './FileBrowserApiService';
import { FileUploader } from './FileUploader';
import { FileTreeView } from './FileTreeView';
import * as animation from '../../../shared/animation';
import * as bootstrap from 'bootstrap';
import MarkdownIt from 'markdown-it';

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
        localStorage.setItem('fileBrowserPath:' + window.location.pathname, this.currentPath);
        this.selectedFile = null;
        this.files = [];
        this.breadcrumbs = [];
        
        // File Browser now uses Tree View only (List View removed for simplicity)
        
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
        
        // Initialize Tree View (List View removed for simplicity)
        this.showTreeView();
        this.updateBreadcrumbs();
        
        // Initial directory preview will be handled by FileTreeView onInit callback
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
                <i class="mdi mdi-folder-plus"></i> <span class="d-none d-md-inline">${this.translations.new_folder || 'New Folder'}</span>
            </button>
            <button class="btn btn-sm btn-outline-primary" data-action="new-file">
                <i class="mdi mdi-file-plus"></i> <span class="d-none d-md-inline">${this.translations.new_file || 'New File'}</span>
            </button>
            <button class="btn btn-sm btn-outline-primary" data-action="upload">
                <i class="mdi mdi-upload"></i> <span class="d-none d-md-inline">${this.translations.upload || 'Upload'}..</span>
            </button>
        `;
        
        // Create breadcrumbs
        this.breadcrumbsRootLabel = document.createElement('div');
        this.breadcrumbsRootLabel.className = 'breadcrumb-item active cursor-pointer';
        this.breadcrumbsRootLabel.innerHTML = `<span class="text-muted me-2"> <i class="mdi mdi-folder-refresh text-cyber" aria-hidden="true" style="font-size: 1.5rem"></i> Project:</span><span class="text-cyber">${this.projectId}</span>`;
        this.breadcrumbsRootLabel.addEventListener('click', () => {
            this.currentPath = '/';
            this.fileTreeView.setInitialExpandPath('/');
            this.fileTreeView.refresh();
            this.updateBreadcrumbs();
        });
        
        this.breadcrumbsElement = document.createElement('div');
        this.breadcrumbsElement.className = 'file-browser-breadcrumbs';
        this.breadcrumbsElement.classList.add('mb-2');

        this.breadcrumbsWrapper = document.createElement('div');
        this.breadcrumbsWrapper.className = 'file-browser-breadcrumbs-wrapper';
        this.breadcrumbsWrapper.appendChild(this.breadcrumbsRootLabel);
        this.breadcrumbsWrapper.appendChild(this.breadcrumbsElement);
        
        header.appendChild(this.breadcrumbsWrapper);
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
            onUpload: () => {
                // Refresh tree view after upload
                if (this.fileTreeView) {
                    this.fileTreeView.setInitialExpandPath(this.currentPath);
                    this.fileTreeView.refresh();
                }
            },
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
     * Update breadcrumbs based on current path
     */
    updateBreadcrumbs() {
        this.breadcrumbsElement.innerHTML = '</i>' + this.currentPath.replaceAll('/', '<span class="breadcrumb-separator">/</span>');
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
            'pdf': 'mdi mdi-file-pdf-box',
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
            'md': 'mdi mdi-file-document',
            
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
     * Show dialog to create a new folder
     */
    async showNewFolderDialog() {
        const folderName = prompt(this.translations.enter_folder_name || 'Enter folder name:');
        if (!folderName) return;
        
        try {
            await this.apiService.createDirectory(this.projectId, this.currentPath, folderName);
            
            // Refresh tree view after folder creation
            if (this.fileTreeView) {
                this.fileTreeView.setInitialExpandPath(this.currentPath);
                await this.fileTreeView.refresh();
            }
            
            // Show success message
            window.toast.success(this.translations.folder_created || 'Folder created successfully');
        } catch (error) {
            console.error('Error creating folder:', error);
            
            // Show error message
            window.toast.error(error.message);
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
            
            // Refresh tree view after file creation
            if (this.fileTreeView) {
                this.fileTreeView.setInitialExpandPath(this.currentPath);
                await this.fileTreeView.refresh();
            }
            
            // Show success message
            window.toast.success(this.translations.file_created || 'File created successfully');
        } catch (error) {
            console.error('Error creating file:', error);
            
            // Show error message
            window.toast.error(error.message);
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
            
            this.selectedFile = file;
            
            //await animation.slideUp(this.filePreviewElement, animation.DURATION.INSTANT);
            if (file.isDirectory) {
                // Show directory preview with actions
                this.renderDirectoryPreview(file);
            } else {
                // Show loading state for files
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
            }
            // Show file preview with animation
            //await animation.slideDown(this.filePreviewElement, animation.DURATION.QUICK);
            
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
            
            // Show error message
            window.toast.error(error.message);
        }
    }
    
    /**
     * Render preview for a directory
     * @param {Object} directory - The directory object
     */
    renderDirectoryPreview(directory) {
        let previewHtml = '';
        
        // Directory info header
        previewHtml += `
            <div class="file-preview-header">
                <h5 class="mb-1">
                    <i class="mdi mdi-folder text-cyber"></i>
                    ${directory.name}
                </h5>
                <div class="file-info mb-2 d-none d-md-flex">
                    <span>${this.translations.directory || 'Directory'}</span>
                    <span>
                        ${new Date(directory.updatedAt).toLocaleString('sk-SK', { year: 'numeric', month: '2-digit', day: '2-digit', timeZone: 'Europe/Prague'})}
                        <span class="text-cyber opacity-75">/</span>
                        ${new Date(directory.updatedAt).toLocaleString('sk-SK', { hour: '2-digit', minute: '2-digit', second: '2-digit', timeZone: 'Europe/Prague'})}
                    </span>
                </div>
                <div class="file-preview-actions">
                    <button class="btn btn-sm btn-outline-danger" data-action="delete" data-file-id="${directory.id}" 
                        style="padding: 0px 16px !important;">
                        <i class="mdi mdi-delete"></i> <span class="d-none d-md-inline small">${this.translations.delete || 'Delete'}</span>
                    </button>
                </div>
            </div>
        `;
        
        // Directory content info
        previewHtml += `
            <div class="file-preview-content">
                <div class="text-center p-0 p-md-4">
                    <i class="mdi mdi-folder display-1 d-none d-md-inline text-cyber"></i>
                    <h6 class="mt-3">${this.translations.directory_selected || 'Directory Selected'}</h6>
                    <p class="text-muted small">${this.translations.directory_preview_info || 'Use the tree view to navigate into this directory or use the delete button above to remove it.'}</p>
                </div>
            </div>
        `;
        
        this.filePreviewElement.innerHTML = previewHtml;

        // animate preview
        this.filePreviewElement.style.display = 'none';
        animation.fade(this.filePreviewElement, 'in', animation.DURATION.NORMAL);
    }
    
    /**
     * Render preview for a file based on its type
     * @param {Object} file - The file object
     * @param {string} content - The file content
     */
    renderFilePreview(file, content) {
        const extension = file.name.split('.').pop().toLowerCase();
        let previewHtml = '';
        const showcaseIcon = `<div class="content-showcase-icon position-absolute top-0 end-0 p-1 m-3 badge bg-dark bg-opacity-75 text-cyber cursor-pointer"><i class="mdi mdi-fullscreen"></i></div>`;
        
        // File info header
        previewHtml += `
            <div class="file-preview-header">
                <h5 class="mb-1">
                    <i class="${this.getFileIcon(file.name)}"></i>
                    ${file.name}
                </h5>
                <div class="file-info mb-2 d-none d-md-flex">
                    <span>${this.formatFileSize(file.size)}</span>
                    <span>
                        ${new Date(file.updatedAt).toLocaleString('sk-SK', { year: 'numeric', month: '2-digit', day: '2-digit', timeZone: 'Europe/Prague'})}
                        <span class="text-cyber opacity-75">/</span>
                        ${new Date(file.updatedAt).toLocaleString('sk-SK', { hour: '2-digit', minute: '2-digit', second: '2-digit', timeZone: 'Europe/Prague'})}
                    </span>
                </div>
                <div class="file-preview-actions">
                    <button class="btn btn-sm btn-outline-primary me-2" data-action="download" data-file-id="${file.id}" 
                        style="padding: 0px 16px !important;">
                        <i class="mdi mdi-download"></i> <span class="d-none d-md-inline small">${this.translations.download || 'Download'}</span>
                    </button>
                    <button class="btn btn-sm btn-outline-danger" data-action="delete" data-file-id="${file.id}" 
                        style="padding: 0px 16px !important;">
                        <i class="mdi mdi-delete"></i> <span class="d-none d-md-inline small">${this.translations.delete || 'Delete'}</span>
                    </button>
                </div>
            </div>
        `;
        
        // Preview content based on file type
        previewHtml += '<div class="file-preview-content rounded position-relative">';
        
        // Images
        if (['jpg', 'jpeg', 'png', 'gif', 'svg', 'webp', 'ico', 'bmp', 'avif', 'tiff'].includes(extension)) {
            previewHtml += `<img src="${content}" alt="${file.name}" class="img-fluid fb rounded">`;
            previewHtml += showcaseIcon;
        }
        // Text/code files
        else if (['txt', 'md', 'html', 'css', 'js', 'php', 'py', 'java', 'c', 'cpp', 'h', 'json', 'xml', 'anno', 'sql'].includes(extension)) {
            if (extension === 'md' || extension === 'anno') {
                let md = new MarkdownIt({
                    html: true,  // ← This enables HTML parsing
                    linkify: true, // Optional: converts URLs to links
                    typographer: true // Optional: improves typography (e.g., quotes, dashes)
                });
                let html = '';
                if (extension === 'anno') {
                    // Prettify JSON
                    content = JSON.stringify(JSON.parse(content), null, 4);
                    // Replace base64 image data with placeholder in JSON
                    // Example: "url":"data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAA..."
                    // Becomes: "url":"..."
                    content = content.replace(/"data:image\/[^;]+;base64,[^"]+"/g, `"...binary data..."`);
                    // Replace special characters with HTML entities
                    content = content.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
                    // Replace newlines with <br>
                    html = content.replace(/\n/g, '<br>');
                } else {
                    // Markdown to HTML
                    html = md.render(content);
                }
                previewHtml += `<pre class="code-preview vh-50" style="white-space: pre-wrap; word-wrap: break-word; text-wrap: wrap;">${html}</pre>`;
            } else {
                // HTML
                previewHtml += `<pre class="code-preview vh-50" style="white-space: pre-wrap; word-wrap: break-word; text-wrap: wrap;">${this.escapeHtml(content)}</pre>`;
            }
            previewHtml += showcaseIcon;
        }
        // PDF (embed)
        else if (extension === 'pdf') {
            previewHtml += `<div class="embed-container rounded h-100"><embed src="${content}" type="application/pdf" width="100%" height="97%" class="rounded"></div>`;
            previewHtml += showcaseIcon;
        }
        // Audio
        else if (['mp3', 'wav', 'ogg'].includes(extension)) {
            previewHtml += `
                <audio controls class="w-100">
                    <source src="${this.apiService.baseUrl}/${file.id}/download" type="audio/${extension}">
                    ${this.translations.audio_not_supported || 'Your browser does not support the audio element.'}
                </audio>
            `;
            previewHtml += showcaseIcon;
        }
        // Video
        else if (['mp4', 'webm', 'ogg'].includes(extension)) {
            previewHtml += `
                <video controls class="w-100 rounded">
                    <source src="${this.apiService.baseUrl}/${file.id}/download" type="video/${extension}">
                    ${this.translations.video_not_supported || 'Your browser does not support the video element.'}
                </video>
            `;
        }
        // Other file types
        else {
            previewHtml += `
                <div class="text-center p-0 p-md-5 vh-50">
                    <i class="${this.getFileIcon(file.name)} display-1 d-none d-md-inline"></i>
                    <p class="small">${this.translations.no_preview || 'No preview available for this file type'}</p>
                    <button class="btn btn-primary btn-sm" data-action="download" data-file-id="${file.id}">
                        <i class="mdi mdi-download"></i> ${this.translations.download || 'Download'}
                    </button>
                </div>
            `;
        }
        
        previewHtml += '</div>';
        
        this.filePreviewElement.innerHTML = previewHtml;

        // set content showcase modal
        this.contentShowcaseModal = document.getElementById('contentShowcaseModal');
        let contentShowcaseModalHeader = this.contentShowcaseModal.querySelector('.modal-header');
        if (contentShowcaseModalHeader) {
            contentShowcaseModalHeader.classList.remove('d-none');
            // set title
            let contentShowcaseModalTitle = this.contentShowcaseModal.querySelector('#contentShowcaseModalTitle');
            contentShowcaseModalTitle.innerHTML = file.name;
            // set icon
            let contentShowcaseModalHeaderIcon = this.contentShowcaseModal.querySelector('#contentShowcaseModalHeaderIcon');
            contentShowcaseModalHeaderIcon.className = this.getFileIcon(file.name) + ' me-2';
        }
        // add content showcase icon event listener
        this.filePreviewElement.querySelectorAll('.content-showcase-icon').forEach(el => {
            let showcase = el.parentElement;
            el.addEventListener('click', (e) => {
                if (this.contentShowcaseModal) {    
                    e.stopPropagation();
                    e.preventDefault();

                    // update modal content
                    this.contentShowcaseModal.querySelector('.contentShowcaseModal-content').innerHTML = showcase.innerHTML;

                    // remove icon
                    this.contentShowcaseModal.querySelector('.contentShowcaseModal-content').querySelector('.content-showcase-icon').remove();

                    // update modal embed height
                    let embedContainer = this.contentShowcaseModal.querySelector('.embed-container');
                    if (embedContainer) {
                        embedContainer.querySelector('embed')?.setAttribute('height', '100%');
                        embedContainer.classList.remove('d-none');
                        embedContainer.classList.add('h-100');
                        this.contentShowcaseModal.querySelector('.chat-file-preview-title')?.remove();
                    }

                    // update `chat-image-preview` class
                    this.contentShowcaseModal.querySelector('.chat-image-preview')?.classList.remove('ms-2');
                    
                    // show modal
                    const newContentShowcaseModal = new bootstrap.Modal(this.contentShowcaseModal);
                    newContentShowcaseModal.show();                    
                }
            });
        });

        // animate preview
        //animation.fade(this.filePreviewElement, 'in', 1000);
        //animation.slideDown(this.filePreviewElement, animation.DURATION.QUICK);
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
        // In Tree View, this.files might be empty, so get file info from API or selectedFile
        let file = this.files.find(f => f.id === fileId);
        
        // If not found in this.files (Tree View scenario), check selectedFile or get from API
        if (!file) {
            if (this.selectedFile && this.selectedFile.id === fileId) {
                file = this.selectedFile;
            } else {
                // Get file metadata from API as fallback
                try {
                    const response = await this.apiService.getFileMetadata(fileId);
                    file = response.file;
                } catch (error) {
                    console.error('Error getting file metadata for deletion:', error);
                    return;
                }
            }
        }
        
        if (!file) return;
        
        const confirmMessage = file.isDirectory
            ? (this.translations.confirm_delete_directory || `Are you sure you want to delete the directory "${file.name}" and all its contents?`)
            : (this.translations.confirm_delete_file || `Are you sure you want to delete the file "${file.name}"?`);
        
        if (!confirm(confirmMessage)) return;
        
        try {
            await this.apiService.deleteFile(fileId);
            
            // Handle post-deletion cleanup
            if (this.selectedFile && this.selectedFile.id === fileId) {
                this.selectedFile = null;
                this.filePreviewElement.innerHTML = '';
                
                if (file.isDirectory) {
                    this.currentPath = file.path;
                    
                    // Update localStorage with new current path
                    localStorage.setItem('fileBrowserPath:' + window.location.pathname, this.currentPath);
                }
                
                // Update breadcrumbs to reflect current path
                this.updateBreadcrumbs();
            }
            
            // Refresh the tree view
            if (this.fileTreeView) {
                // Set current path and refresh the tree
                this.fileTreeView.setInitialExpandPath(this.currentPath);
                await this.fileTreeView.refresh();
            }
            
            // Show success message
            window.toast.success(file.isDirectory
                ? (this.translations.directory_deleted || 'Directory deleted successfully')
                : (this.translations.file_deleted || 'File deleted successfully'));
        } catch (error) {
            console.error('Error deleting file:', error);
            
            // Show error message
            window.toast.error(error.message);
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
            
            // Refresh tree view after file upload
            if (this.fileTreeView) {
                this.fileTreeView.setInitialExpandPath(this.currentPath);
                await this.fileTreeView.refresh();
            }
            
            // Show success message
            window.toast.success(this.translations.file_uploaded || 'File uploaded successfully');
        } catch (error) {
            console.error('Error uploading file:', error);
            
            // Show error message
            window.toast.error(error.message);
        }
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
                onInit: (currentDirectory) => {
                    // Show initial directory preview for currentPath
                    if (currentDirectory) {
                        this.selectFile(currentDirectory.id);
                    }
                },
                onFileSelect: (file) => {
                    this.currentPath = file.path;
                    this.updateBreadcrumbs();
                    
                    // Save current path to localStorage
                    localStorage.setItem('fileBrowserPath:' + window.location.pathname, this.currentPath);
                    
                    // Select the file
                    this.selectFile(file.id);
                },
                onDirectorySelect: (directory) => {
                    // Update currentPath and show directory preview
                    this.currentPath = (directory.path == "/" ? '' : directory.path) + '/' + directory.name;
                    this.updateBreadcrumbs();
                    
                    // Save current path to localStorage
                    localStorage.setItem('fileBrowserPath:' + window.location.pathname, this.currentPath);
                    
                    // Show directory preview
                    this.selectFile(directory.id);
                },
                onDirectoryToggle: (directory) => {
                    // Update currentPath when a directory is expanded via toggle
                    this.currentPath = (directory.path == "/" ? '' : directory.path) + '/' + directory.name;
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
