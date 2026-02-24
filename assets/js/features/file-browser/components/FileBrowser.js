import { FileBrowserApiService } from './FileBrowserApiService';
import { FileUploader } from './FileUploader';
import { FileTreeView } from './FileTreeView';
import { FileContextMenu } from './FileContextMenu';
import { FileOperationModal } from './FileOperationModal';
import { ImageGallery } from './ImageGallery';
import * as animation from '../../../shared/animation';
import * as bootstrap from 'bootstrap';
import MarkdownIt from 'markdown-it';
import { ImageShowcase } from '../../../shared/image-showcase';

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
        
        // Image showcase for fullscreen viewing
        this.imageShowcase = new ImageShowcase('contentShowcaseModal');
        this.imageShowcase.setApiService(this.apiService);
        
        // Flag to prevent preview re-render during gallery operations
        this.isGalleryDeleteInProgress = false;
        
        // Context menu for file operations
        this.contextMenu = new FileContextMenu({
            translations: this.translations,
            onCopy: (items) => this.handleCopyItems(items),
            onMove: (items) => this.handleMoveItems(items),
            onRename: (item) => this.handleRenameItem(item),
            onShare: (item) => this.handleShareFile(item.id, item.name, (item.name || '').split('.').pop()),
            onDelete: (items) => this.handleDeleteItems(items)
        });
        
        // Operation modal for copy/move/rename
        this.operationModal = new FileOperationModal({
            translations: this.translations
        });
        
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
        this.breadcrumbsRootLabel.innerHTML = `<span class="text-muted me-2"> <i class="mdi mdi-folder-refresh text-cyber" aria-hidden="true" style="font-size: 1.5rem"></i> Project:</span><span class="text-cyber">${this.projectId}</span><span class="text-muted ms-2" id="project-size-badge">...</span>`;
        this.breadcrumbsRootLabel.addEventListener('click', () => {
            this.currentPath = '/';
            this.fileTreeView.setInitialExpandPath('/');
            this.fileTreeView.refresh();
            this.updateBreadcrumbs();
        });
        
        // Load and display project size
        this.loadProjectSize();
        
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
                    
                case 'share':
                    const shareFileId = actionButton.dataset.fileId;
                    const shareFileName = actionButton.dataset.fileName;
                    const shareFileType = actionButton.dataset.fileType;
                    if (shareFileId) {
                        this.handleShareFile(shareFileId, shareFileName, shareFileType);
                    }
                    break;
                    
                case 'delete':
                    const deleteFileId = actionButton.dataset.fileId;
                    if (deleteFileId) {
                        await this.confirmAndDeleteFile(deleteFileId);
                    }
                    break;
                    
                case 'edit':
                    const editFileId = actionButton.dataset.fileId;
                    if (editFileId) {
                        await this.showEditModal(editFileId);
                    }
                    break;
                    
                case 'show-gallery':
                    const directoryPath = actionButton.dataset.directoryPath;
                    if (directoryPath) {
                        await this.showImageGallery(directoryPath);
                    }
                    break;
                    
                case 'download-zip':
                    const zipFileId = actionButton.dataset.fileId;
                    if (zipFileId) {
                        this.downloadDirectoryAsZip(zipFileId);
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
        if (!this.currentPath || this.currentPath === '/') {
            this.breadcrumbsElement.innerHTML = '';
            return;
        }
        
        const parts = this.currentPath.replace(/^\//, '').split('/');
        let html = '';
        let accumulated = '';
        
        parts.forEach((part, i) => {
            accumulated += '/' + part;
            const pathSnapshot = accumulated;
            const isLast = i === parts.length - 1;
            html += `<span class="breadcrumb-separator text-muted opacity-50 mx-1">/</span>`;
            html += isLast
                ? `<span class="text-cyber">${part}</span>`
                : `<span class="text-muted cursor-pointer breadcrumb-path-part" data-path="${pathSnapshot}">${part}</span>`;
        });
        
        this.breadcrumbsElement.innerHTML = html;
        
        // Attach click handlers for intermediate path segments
        this.breadcrumbsElement.querySelectorAll('.breadcrumb-path-part').forEach(el => {
            el.addEventListener('click', () => {
                this.currentPath = el.dataset.path;
                localStorage.setItem('fileBrowserPath:' + window.location.pathname, this.currentPath);
                this.fileTreeView.setInitialExpandPath(this.currentPath);
                this.fileTreeView.refresh();
                this.updateBreadcrumbs();
            });
        });
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
     * Check if file is a text file that can be edited
     * @param {string} extension - File extension
     * @returns {boolean}
     */
    isTextFile(extension) {
        const textExtensions = ['txt', 'md', 'html', 'css', 'js', 'php', 'py', 'java', 'c', 'cpp', 'h', 'json', 'xml', 'anno', 'sql', 'sh', 'yml', 'yaml', 'ini', 'conf', 'env', 'htaccess', 'gitignore'];
        return textExtensions.includes(extension.toLowerCase());
    }
    
    /**
     * Show edit modal for text file
     * @param {string} fileId - File ID to edit
     */
    async showEditModal(fileId) {
        try {
            // Get file metadata and content
            const metaResponse = await this.apiService.getFileMetadata(fileId);
            const file = metaResponse.file;
            const contentResponse = await this.apiService.getFileContent(fileId);
            const content = contentResponse.content;
            
            // Create or get edit modal
            let modal = document.getElementById('fileEditModal');
            if (!modal) {
                modal = document.createElement('div');
                modal.id = 'fileEditModal';
                modal.className = 'modal fade';
                modal.tabIndex = -1;
                modal.innerHTML = `
                    <div class="modal-dialog modal-fullscreen">
                        <div class="modal-content bg-dark text-light">
                            <div class="modal-header border-secondary">
                                <h5 class="modal-title">
                                    <i class="mdi mdi-pencil me-2"></i>
                                    <span id="fileEditModalTitle"></span>
                                </h5>
                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body p-0">
                                <textarea id="fileEditTextarea" class="form-control bg-dark text-light border-0 h-100 rounded-0" 
                                    style="resize: none; font-family: monospace; font-size: 14px;"></textarea>
                            </div>
                            <div class="modal-footer border-secondary justify-content-between">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                                    <i class="mdi mdi-close me-1"></i>${this.translations.cancel || 'Cancel'}
                                </button>
                                <button type="button" class="btn btn-cyber" id="fileEditSaveBtn">
                                    <i class="mdi mdi-content-save me-1"></i>${this.translations.save || 'Save'}
                                </button>
                            </div>
                        </div>
                    </div>
                `;
                document.body.appendChild(modal);
            }
            
            // Set modal content
            modal.querySelector('#fileEditModalTitle').textContent = file.name;
            modal.querySelector('#fileEditTextarea').value = content;
            
            // Store file info for save
            modal.dataset.fileId = fileId;
            modal.dataset.filePath = file.path;
            modal.dataset.fileName = file.name;
            
            // Setup save button handler
            const saveBtn = modal.querySelector('#fileEditSaveBtn');
            const newSaveBtn = saveBtn.cloneNode(true);
            saveBtn.parentNode.replaceChild(newSaveBtn, saveBtn);
            
            newSaveBtn.addEventListener('click', async () => {
                await this.saveEditedFile(modal);
            });
            
            // Show modal
            const bsModal = new bootstrap.Modal(modal);
            bsModal.show();
            
            // Focus textarea
            modal.addEventListener('shown.bs.modal', () => {
                modal.querySelector('#fileEditTextarea').focus();
            }, { once: true });
            
        } catch (error) {
            console.error('Error opening edit modal:', error);
            window.toast.error(error.message);
        }
    }
    
    /**
     * Save edited file content
     * @param {HTMLElement} modal - The edit modal element
     */
    async saveEditedFile(modal) {
        const fileId = modal.dataset.fileId;
        const content = modal.querySelector('#fileEditTextarea').value;
        
        try {
            await this.apiService.updateFile(fileId, content);
            
            // Close modal
            const bsModal = bootstrap.Modal.getInstance(modal);
            bsModal.hide();
            
            // Refresh file preview
            await this.selectFile(fileId);
            
            // Refresh project size
            await this.loadProjectSize();
            
            window.toast.success(this.translations.file_saved || 'File saved successfully');
        } catch (error) {
            console.error('Error saving file:', error);
            window.toast.error(error.message);
        }
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
            
            // Refresh project size
            await this.loadProjectSize();
            
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
     * @param {boolean} skipIfGalleryOpen - Skip rendering if gallery is open (for delete operations)
     */
    async selectFile(fileId, skipIfGalleryOpen = false) {
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
                // Skip re-rendering if gallery delete is in progress
                if (this.isGalleryDeleteInProgress && this.imageGallery) {
                    return;
                }
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
                
                // Check if it's an image - use thumbnail for preview
                const extension = file.name.split('.').pop().toLowerCase();
                const isImage = ['jpg', 'jpeg', 'png', 'gif', 'svg', 'webp', 'ico', 'bmp', 'avif', 'tiff'].includes(extension);
                
                // Get file content (thumbnail for images, full for others)
                const response = await this.apiService.getFileContent(fileId, isImage);
                const content = response.content;
                
                // Render preview based on file type
                this.renderFilePreview(file, content, isImage);
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
        // Build directory path for gallery
        const directoryPath = (directory.path === '/' ? '' : directory.path) + '/' + directory.name;
        
        let previewHtml = '';
        
        // Directory info header
        previewHtml += `
            <div class="file-preview-header">
                <span class="mb-1 fw-bold">
                    <i class="mdi mdi-folder text-warning"></i>
                    ${directory.name}
                </span>
                <div class="file-info mb-2 d-none d-md-flex">
                    <span>${this.translations.directory || 'Directory'}</span>
                    <span>
                        ${new Date(directory.updatedAt).toLocaleString('sk-SK', { year: 'numeric', month: '2-digit', day: '2-digit', timeZone: 'Europe/Prague'})}
                        <span class="text-cyber opacity-75">/</span>
                        ${new Date(directory.updatedAt).toLocaleString('sk-SK', { hour: '2-digit', minute: '2-digit', second: '2-digit', timeZone: 'Europe/Prague'})}
                    </span>
                </div>
                <div class="file-preview-actions">
                    <button class="btn btn-sm btn-outline-primary me-2" data-action="show-gallery" data-directory-path="${directoryPath}" 
                        style="padding: 0px 16px !important;">
                        <i class="mdi mdi-image-multiple"></i> <span class="d-none d-md-inline small">${this.translations.show_gallery || 'Image Gallery'}</span>
                    </button>
                    <button class="btn btn-sm btn-outline-cyber me-2" data-action="download-zip" data-file-id="${directory.id}" 
                        style="padding: 0px 16px !important;">
                        <i class="mdi mdi-folder-zip"></i> <span class="d-none d-md-inline small">${this.translations.download_zip || 'Download ZIP'}</span>
                    </button>
                    <button class="btn btn-sm btn-outline-danger" data-action="delete" data-file-id="${directory.id}" 
                        style="padding: 0px 16px !important;">
                        <i class="mdi mdi-delete"></i> <span class="d-none d-md-inline small">${this.translations.delete || 'Delete'}</span>
                    </button>
                </div>
            </div>
        `;
        
        // Directory content info with gallery container
        previewHtml += `
            <div class="file-preview-content">
                <div class="directory-info text-center p-0 p-md-4">
                    <i class="mdi mdi-folder display-1 d-none d-md-inline text-cyber"></i>
                    <h6 class="mt-3">${this.translations.directory_selected || 'Directory Selected'}</h6>
                    <p class="text-muted small">${this.translations.directory_preview_info || 'Use the tree view to navigate into this directory or use the delete button above to remove it.'}</p>
                </div>
                <div class="image-gallery-container" style="display: none;"></div>
            </div>
        `;
        
        this.filePreviewElement.innerHTML = previewHtml;

        // animate preview
        this.filePreviewElement.style.display = 'none';
        animation.fade(this.filePreviewElement, 'in', animation.DURATION.NORMAL);
    }
    
    /**
     * Show image gallery for a directory
     * @param {string} directoryPath - Path to the directory
     */
    async showImageGallery(directoryPath) {
        const galleryContainer = this.filePreviewElement.querySelector('.image-gallery-container');
        const directoryInfo = this.filePreviewElement.querySelector('.directory-info');
        const galleryButton = this.filePreviewElement.querySelector('[data-action="show-gallery"]');
        
        if (!galleryContainer) return;
        
        // Toggle gallery visibility
        if (galleryContainer.style.display !== 'none') {
            galleryContainer.style.display = 'none';
            directoryInfo.style.display = 'block';
            galleryButton.innerHTML = `<i class="mdi mdi-image-multiple"></i> <span class="d-none d-md-inline small">${this.translations.show_gallery || 'Image Gallery'}</span>`;
            
            // Cleanup gallery
            if (this.imageGallery) {
                this.imageGallery.destroy();
                this.imageGallery = null;
            }
            return;
        }
        
        // Show gallery, hide directory info
        directoryInfo.style.display = 'none';
        galleryContainer.style.display = 'block';
        galleryButton.innerHTML = `<i class="mdi mdi-folder"></i> <span class="d-none d-md-inline small">${this.translations.hide_gallery || 'Hide Gallery'}</span>`;
        
        // Create and load gallery
        this.imageGallery = new ImageGallery({
            container: galleryContainer,
            apiService: this.apiService,
            projectId: this.projectId,
            translations: this.translations,
            imageShowcase: this.imageShowcase,
            onDelete: async (fileId, image) => {
                this.isGalleryDeleteInProgress = true;
                await this.confirmAndDeleteFile(fileId, () => {
                    this.imageGallery?.removeImage(fileId);
                    // Remove from tree DOM without full refresh
                    this.fileTreeView?.removeFileFromTree(fileId);
                    this.isGalleryDeleteInProgress = false;
                }, true); // skipPreviewUpdate = true to keep gallery visible
                this.isGalleryDeleteInProgress = false; // Also reset if user cancels
            }
        });
        
        await this.imageGallery.loadImages(directoryPath);
    }
    
    /**
     * Download directory as ZIP file
     * @param {string} fileId - The directory ID
     */
    downloadDirectoryAsZip(fileId) {
        // Show loading toast
        window.toast.info(this.translations.preparing_zip || 'Preparing ZIP download...');
        
        // Trigger download via hidden link
        const downloadUrl = `/api/project-file/${fileId}/download-zip`;
        const link = document.createElement('a');
        link.href = downloadUrl;
        link.style.display = 'none';
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
    }
    
    /**
     * Render preview for a file based on its type
     * @param {Object} file - The file object
     * @param {string} content - The file content
     * @param {boolean} isThumbnail - Whether content is a thumbnail (for images)
     */
    renderFilePreview(file, content, isThumbnail = false) {
        const extension = file.name.split('.').pop().toLowerCase();
        let previewHtml = '';
        const showcaseIcon = `<div class="content-showcase-icon position-absolute top-0 end-0 p-1 badge bg-dark bg-opacity-75 text-cyber cursor-pointer"><i class="mdi mdi-fullscreen"></i></div>`;
        
        // File info header
        previewHtml += `
            <div class="file-preview-header">
                <span class="mb-1 fw-bold">
                    <i class="${this.getFileIcon(file.name)}"></i>
                    ${file.name}
                </span>
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
                    <button class="btn btn-sm btn-outline-success me-2" data-action="share" data-file-id="${file.id}" data-file-name="${file.name}" data-file-type="${extension}"
                        style="padding: 0px 16px !important;">
                        <i class="mdi mdi-share-variant"></i> <span class="d-none d-md-inline small">${this.translations.share || 'Share'}</span>
                    </button>
                    ${this.isTextFile(extension) ? `
                    <button class="btn btn-sm btn-outline-primary me-3" data-action="edit" data-file-id="${file.id}" 
                        style="padding: 0px 16px !important;">
                        <i class="mdi mdi-pencil"></i> <span class="d-none d-md-inline small">${this.translations.edit || 'Edit'}</span>
                    </button>` : ''}
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
            previewHtml += `
                <div class="content-showcase position-relative d-inline-block">
                    <img src="${content}" alt="${file.name}" class="img-fluid fb rounded" data-file-id="${file.id}" data-is-thumbnail="${isThumbnail}">
                    ${showcaseIcon}
                </div>
            `;
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
                previewHtml += `<pre class="code-preview h-100 m-0" style="white-space: pre-wrap; word-wrap: break-word; text-wrap: wrap;">${html}</pre>`;
            } else {
                // HTML
                previewHtml += `<pre class="code-preview h-100 m-0" style="white-space: pre-wrap; word-wrap: break-word; text-wrap: wrap;">${this.escapeHtml(content)}</pre>`;
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

        // Set up content showcase modal header with file info
        const contentShowcaseModal = document.getElementById('contentShowcaseModal');
        if (contentShowcaseModal) {
            const modalHeader = contentShowcaseModal.querySelector('.modal-header');
            if (modalHeader) {
                modalHeader.classList.remove('d-none');
                const titleEl = contentShowcaseModal.querySelector('#contentShowcaseModalTitle');
                if (titleEl) titleEl.innerHTML = file.name;
                const iconEl = contentShowcaseModal.querySelector('#contentShowcaseModalHeaderIcon');
                if (iconEl) iconEl.className = this.getFileIcon(file.name) + ' me-2';
            }
        }
        
        // Initialize image showcase for fullscreen viewing
        this.imageShowcase.init(this.filePreviewElement);

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
     * @param {Function} onSuccess - Optional callback after successful deletion
     * @param {boolean} skipPreviewUpdate - Skip updating the preview (for gallery deletions)
     */
    async confirmAndDeleteFile(fileId, onSuccess = null, skipPreviewUpdate = false) {
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
            
            // Handle post-deletion cleanup (skip if deleting from gallery)
            if (!skipPreviewUpdate && this.selectedFile && this.selectedFile.id === fileId) {
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
            
            // Refresh project size
            await this.loadProjectSize();
            
            // Refresh the tree view (skip if deleting from gallery - handled by removeFileFromTree)
            if (this.fileTreeView && !skipPreviewUpdate) {
                // Set current path and refresh the tree (keeps folder expanded)
                this.fileTreeView.setInitialExpandPath(this.currentPath);
                await this.fileTreeView.refresh();
            }
            
            // Call onSuccess callback if provided
            if (onSuccess) {
                onSuccess();
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
     * Handle copy items from context menu
     * @param {Array} items - Items to copy
     */
    handleCopyItems(items) {
        this.operationModal.showCopy(items, async ({ destination, newName }) => {
            try {
                for (const item of items) {
                    // For files: item.path is parent directory
                    // For directories: item.path includes the directory name, need to get parent
                    const sourcePath = this.getSourcePath(item);
                    const destName = (items.length === 1 && newName) ? newName : item.name;
                    
                    await this.apiService.copyFile(this.projectId, 
                        { path: sourcePath, name: item.name },
                        { path: destination, name: destName }
                    );
                }
                
                await this.refreshAfterOperation();
                window.toast.success(items.length > 1 
                    ? `${items.length} ${this.translations.items_copied || 'items copied'}`
                    : (this.translations.file_copied || 'File copied successfully'));
            } catch (error) {
                console.error('Error copying files:', error);
                window.toast.error(error.message);
            }
        });
    }
    
    /**
     * Handle move items from context menu
     * @param {Array} items - Items to move
     */
    handleMoveItems(items) {
        this.operationModal.showMove(items, async ({ destination, newName }) => {
            try {
                for (const item of items) {
                    const sourcePath = this.getSourcePath(item);
                    const destName = (items.length === 1 && newName) ? newName : item.name;
                    
                    await this.apiService.moveFile(this.projectId,
                        { path: sourcePath, name: item.name },
                        { path: destination, name: destName }
                    );
                }
                
                await this.refreshAfterOperation();
                window.toast.success(items.length > 1
                    ? `${items.length} ${this.translations.items_moved || 'items moved'}`
                    : (this.translations.file_moved || 'File moved successfully'));
            } catch (error) {
                console.error('Error moving files:', error);
                window.toast.error(error.message);
            }
        });
    }
    
    /**
     * Handle rename item from context menu
     * @param {Object} item - Item to rename
     */
    handleRenameItem(item) {
        this.operationModal.showRename(item, async ({ newName }) => {
            try {
                const sourcePath = this.getSourcePath(item);
                
                await this.apiService.renameFile(this.projectId, sourcePath, item.name, newName);
                
                await this.refreshAfterOperation();
                window.toast.success(this.translations.file_renamed || 'File renamed successfully');
            } catch (error) {
                console.error('Error renaming file:', error);
                window.toast.error(error.message);
            }
        });
    }
    
    /**
     * Handle share file — creates a CQ Share for the selected file
     * @param {string} fileId - File ID to share
     * @param {string} fileName - File name
     * @param {string} fileType - File extension
     */
    async handleShareFile(fileId, fileName, fileType) {
        const sourceType = fileType === 'cqmpack' ? 'cqmpack' : 'file';
        const title = fileName || 'Shared file';
        const slug = fileName
            ? fileName.replace(/[^a-zA-Z0-9.-]/g, '-').replace(/-+/g, '-').replace(/^-|-$/g, '')
            : fileId.substring(0, 8);

        try {
            const response = await fetch('/api/share', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    source_type: sourceType,
                    source_id: fileId,
                    title: title,
                    share_url: slug,
                    scope: 1
                })
            });
            const data = await response.json();

            if (data.success && data.share) {
                const username = document.querySelector('.js-user')?.dataset?.username || '';
                const shareLink = `${window.location.origin}/${username}/share/${data.share.share_url}`;
                // Copy link to clipboard
                try {
                    await navigator.clipboard.writeText(shareLink);
                    window.toast.success(`Share created! Link copied: ${shareLink}`);
                } catch {
                    window.toast.success(`Share created: ${shareLink}`);
                }
            } else {
                window.toast.error(data.message || 'Failed to create share');
            }
        } catch (error) {
            console.error('Error creating share:', error);
            window.toast.error('Failed to create share');
        }
    }

    /**
     * Handle delete items from context menu
     * @param {Array} items - Items to delete
     */
    async handleDeleteItems(items) {
        const confirmMsg = items.length > 1
            ? `${this.translations.confirm_delete_multiple || 'Delete'} ${items.length} ${this.translations.items || 'items'}?`
            : `${this.translations.confirm_delete || 'Delete'} "${items[0].name}"?`;
        
        if (!confirm(confirmMsg)) return;
        
        try {
            for (const item of items) {
                await this.apiService.deleteFile(item.id);
            }
            
            await this.refreshAfterOperation();
            window.toast.success(items.length > 1
                ? `${items.length} ${this.translations.items_deleted || 'items deleted'}`
                : (this.translations.file_deleted || 'File deleted successfully'));
        } catch (error) {
            console.error('Error deleting files:', error);
            window.toast.error(error.message);
        }
    }
    
    /**
     * Get source path for file operations
     * For files: item.path is already the parent directory
     * For directories: item.path includes the directory name, need to extract parent
     * @param {Object} item - Item with path, name, type
     * @returns {string} Parent path where the item resides
     */
    getSourcePath(item) {
        if (item.type === 'directory') {
            // For directories, path is like "/parent/dirname", need to get "/parent"
            const fullPath = item.path;
            if (!fullPath || fullPath === '/' || fullPath === `/${item.name}`) return '/';
            const parts = fullPath.split('/').filter(p => p);
            parts.pop(); // Remove the directory name
            return '/' + parts.join('/') || '/';
        } else {
            // For files, path is already the parent directory
            return item.path || '/';
        }
    }
    
    /**
     * Get parent path from a full path
     * @param {string} fullPath - Full path like "/folder/subfolder/file.txt"
     * @returns {string} Parent path like "/folder/subfolder"
     */
    getParentPath(fullPath) {
        if (!fullPath || fullPath === '/') return '/';
        const parts = fullPath.split('/').filter(p => p);
        parts.pop(); // Remove the last part (filename)
        return '/' + parts.join('/') || '/';
    }
    
    /**
     * Refresh tree view and project size after file operation
     */
    async refreshAfterOperation() {
        // Clear selection in tree view
        if (this.fileTreeView) {
            this.fileTreeView.clearSelection();
            this.fileTreeView.setInitialExpandPath(this.currentPath);
            await this.fileTreeView.refresh();
        }
        
        // Refresh project size
        await this.loadProjectSize();
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
            const uploadResult = await this.apiService.uploadFile(this.projectId, this.currentPath, file);
            
            // For PDF files, trigger background annotation generation
            if (uploadResult?.file?.id && file.name.toLowerCase().endsWith('.pdf')) {
                this.apiService.generateAnnotations(uploadResult.file.id).catch(() => {});
            }
            
            // Refresh project size
            await this.loadProjectSize();
            
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
                },
                onContextMenu: (e, items) => {
                    // Update operation modal with tree data for folder autocomplete
                    if (this.fileTreeView && this.fileTreeView.treeData) {
                        this.operationModal.setTreeData(this.fileTreeView.treeData);
                    }
                    // Show context menu
                    this.contextMenu.show(e.clientX, e.clientY, items);
                }
            });
        } else {
            // Refresh the tree view
            this.fileTreeView.refresh();
        }
    }
    
    /**
     * Load and display project size
     */
    async loadProjectSize() {
        try {
            const response = await fetch(`/api/project-file/stats/${this.projectId}`);
            const data = await response.json();
            
            if (data.success) {
                const badge = document.getElementById('project-size-badge');
                if (badge) {
                    badge.textContent = `(${data.formattedSize})`;
                }
            }
        } catch (error) {
            console.error('Error loading project size:', error);
        }
    }
}
