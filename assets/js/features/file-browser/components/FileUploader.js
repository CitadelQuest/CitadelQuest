/**
 * File Uploader component for CitadelQuest
 * Provides drag and drop file upload functionality for the File Browser
 */

// Maximum file size in bytes (200MB - matching backend limit)
const MAX_FILE_SIZE = 209715200;
export class FileUploader {
    /**
     * @param {Object} options - Configuration options
     * @param {string} options.containerId - ID of the container element
     * @param {Function} options.onUpload - Callback function when files are uploaded
     * @param {Object} options.translations - Translation strings
     * @param {FileBrowserApiService} options.apiService - API service instance
     * @param {string} options.projectId - Project ID
     * @param {string} options.currentPath - Current directory path
     */
    constructor(options) {
        this.containerId = options.containerId;
        this.onUpload = options.onUpload || (() => {});
        this.translations = options.translations || {};
        this.apiService = options.apiService;
        this.projectId = options.projectId;
        this.currentPath = options.currentPath || '/';
        
        this.isUploading = false;
        this.uploadQueue = [];
        
        this.init();
    }
    
    /**
     * Initialize the uploader
     */
    init() {
        this.container = document.getElementById(this.containerId);
        if (!this.container) {
            console.error(`Container with ID "${this.containerId}" not found`);
            return;
        }
        
        this.createUploaderUI();
        this.initEventListeners();
    }
    
    /**
     * Create the uploader UI
     */
    createUploaderUI() {
        this.container.classList.add('file-uploader');
        
        // Create the drop zone
        this.dropZone = document.createElement('div');
        this.dropZone.className = 'file-uploader-drop-zone';
        this.dropZone.innerHTML = `
            <div class="file-uploader-icon">
                <i class="mdi mdi-cloud-upload"></i>
            </div>
            <div class="file-uploader-text">
                ${this.translations.drag_files || 'Drag files here to upload'}
            </div>
            <div class="file-uploader-text-small">
                ${this.translations.or || 'or'}
            </div>
            <button class="btn btn-outline-primary">
                ${this.translations.browse_files || 'Browse Files'}
            </button>
        `;
        
        // Create file input
        this.fileInput = document.createElement('input');
        this.fileInput.type = 'file';
        this.fileInput.multiple = true;
        this.fileInput.style.display = 'none';
        
        // Create progress container
        this.progressContainer = document.createElement('div');
        this.progressContainer.className = 'file-uploader-progress';
        this.progressContainer.style.display = 'none';
        
        // Add elements to container
        this.container.appendChild(this.dropZone);
        this.container.appendChild(this.fileInput);
        this.container.appendChild(this.progressContainer);
    }
    
    /**
     * Initialize event listeners
     */
    initEventListeners() {
        // File input change
        this.fileInput.addEventListener('change', (e) => {
            if (e.target.files.length > 0) {
                this.handleFiles(e.target.files);
            }
        });
        
        // Browse button click
        this.dropZone.querySelector('button').addEventListener('click', () => {
            this.fileInput.click();
        });
        
        // Drag and drop events
        this.dropZone.addEventListener('dragover', (e) => {
            e.preventDefault();
            e.stopPropagation();
            this.dropZone.classList.add('dragover');
        });
        
        this.dropZone.addEventListener('dragleave', (e) => {
            e.preventDefault();
            e.stopPropagation();
            this.dropZone.classList.remove('dragover');
        });
        
        this.dropZone.addEventListener('drop', (e) => {
            e.preventDefault();
            e.stopPropagation();
            this.dropZone.classList.remove('dragover');
            
            if (e.dataTransfer.files.length > 0) {
                this.handleFiles(e.dataTransfer.files);
            }
        });
    }
    
    /**
     * Handle files selected for upload
     * @param {FileList} files - Files to upload
     */
    handleFiles(files) {
        // Convert FileList to array and add to queue
        Array.from(files).forEach(file => {
            // Validate file size
            if (file.size > MAX_FILE_SIZE) {
                // Create a progress item for the error
                this.showFileError(file, `File is too large. Maximum size is ${this.formatFileSize(MAX_FILE_SIZE)}.`);
            } else {
                this.uploadQueue.push(file);
            }
        });
        
        // Start upload if not already uploading
        if (!this.isUploading) {
            this.processUploadQueue();
        }
    }
    
    /**
     * Process the upload queue
     */
    async processUploadQueue() {
        if (this.isUploading || this.uploadQueue.length === 0) {
            return;
        }
        
        this.isUploading = true;
        
        // Show progress container - always keep it visible once files are being processed
        this.progressContainer.style.display = 'block';
        
        // Get next file from queue
        const file = this.uploadQueue.shift();
        
        const progressItem = document.createElement('div');
        progressItem.className = 'file-uploader-progress-item';
        progressItem.innerHTML = `
            <div class="file-uploader-progress-info">
                <span class="file-uploader-progress-name">${file.name}</span>
                <span class="file-uploader-progress-size">${this.formatFileSize(file.size)}</span>
            </div>
            <div class="progress">
                <div class="progress-bar bg-cyber" role="progressbar" style="width: 0%"></div>
            </div>
        `;
        
        this.progressContainer.appendChild(progressItem);
        const progressBar = progressItem.querySelector('.progress-bar');
        
        try {
            // Upload with progress tracking
            await this.uploadFileWithProgress(file, (progress) => {
                progressBar.style.width = `${progress}%`;
            });
            
            // Success
            progressItem.classList.add('success');
            progressBar.style.width = '100%';
            
            // Add success icon
            const infoElement = progressItem.querySelector('.file-uploader-progress-info');
            const successIcon = document.createElement('i');
            successIcon.className = 'mdi mdi-check-circle text-success';
            infoElement.appendChild(successIcon);
            
            // Remove success items after delay
            setTimeout(() => {
                progressItem.remove();
                
                // Hide progress container only if empty or if there are no error items
                const hasErrorItems = Array.from(this.progressContainer.children).some(item => item.classList.contains('error'));
                if (this.progressContainer.children.length === 0 || !hasErrorItems) {
                    this.progressContainer.style.display = 'none';
                }
            }, 5000);
            
            // Call onUpload callback
            this.onUpload(file);
        } catch (error) {
            // Error
            progressItem.classList.add('error');
            
            // Add error icon with dismiss functionality
            const infoElement = progressItem.querySelector('.file-uploader-progress-info');
            const errorIcon = document.createElement('i');
            errorIcon.className = 'mdi mdi-close-circle text-danger';
            errorIcon.style.cursor = 'pointer';
            errorIcon.title = 'Dismiss';
            
            // Add click event to the error icon for dismissal
            errorIcon.addEventListener('click', () => {
                progressItem.remove();
                
                // Hide progress container only if empty or if there are no error items
                const hasErrorItems = Array.from(this.progressContainer.children).some(item => item.classList.contains('error'));
                if (this.progressContainer.children.length === 0 || !hasErrorItems) {
                    this.progressContainer.style.display = 'none';
                }
            });
            
            infoElement.appendChild(errorIcon);
            
            // Add error message
            const errorMessage = document.createElement('div');
            errorMessage.className = 'file-uploader-progress-error';
            errorMessage.textContent = error.message;
            progressItem.appendChild(errorMessage);
            
            console.error('Error uploading file:', error);
            
            // Don't auto-remove error messages - let user dismiss them manually
        }
        
        // Reset uploading flag
        this.isUploading = false;
        
        // Process next file in queue
        this.processUploadQueue();
    }
    
    /**
     * Upload a file with progress tracking
     * @param {File} file - File to upload
     * @param {Function} progressCallback - Progress callback
     * @returns {Promise} - Upload promise
     */
    uploadFileWithProgress(file, progressCallback) {
        return this.apiService.uploadFile(this.projectId, this.currentPath, file, progressCallback);
    }
    
    /**
     * Show file error in the progress container
     * @param {File} file - The file that caused the error
     * @param {string} errorMessage - The error message to display
     */
    showFileError(file, errorMessage) {
        // Show progress container
        this.progressContainer.style.display = 'block';
        
        // Create progress item for the error
        const progressItem = document.createElement('div');
        progressItem.className = 'file-uploader-progress-item error';
        progressItem.innerHTML = `
            <div class="file-uploader-progress-info">
                <span class="file-uploader-progress-name">${file.name}</span>
                <span class="file-uploader-progress-size">${this.formatFileSize(file.size)}</span>
            </div>
        `;
        
        // Add error icon with dismiss functionality
        const infoElement = progressItem.querySelector('.file-uploader-progress-info');
        const errorIcon = document.createElement('i');
        errorIcon.className = 'mdi mdi-close-circle text-danger';
        errorIcon.style.cursor = 'pointer';
        errorIcon.title = 'Dismiss';
        
        // Add click event to the error icon for dismissal
        errorIcon.addEventListener('click', () => {
            progressItem.remove();
            
            // Hide progress container only if empty or if there are no error items
            const hasErrorItems = Array.from(this.progressContainer.children).some(item => item.classList.contains('error'));
            if (this.progressContainer.children.length === 0 || !hasErrorItems) {
                this.progressContainer.style.display = 'none';
            }
        });
        
        infoElement.appendChild(errorIcon);
        
        // Add error message
        const errorMessageElement = document.createElement('div');
        errorMessageElement.className = 'file-uploader-progress-error';
        errorMessageElement.textContent = errorMessage;
        progressItem.appendChild(errorMessageElement);
        
        this.progressContainer.appendChild(progressItem);
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
     * Update the current path
     * @param {string} path - New path
     */
    updatePath(path) {
        this.currentPath = path;
    }
}
