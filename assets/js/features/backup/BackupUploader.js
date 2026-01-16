/**
 * Backup Uploader component for CitadelQuest
 * Provides drag and drop backup file upload functionality
 */

// Maximum file size in bytes (1000MB for backup files)
const MAX_BACKUP_SIZE = 1048576000;
const ALLOWED_EXTENSION = '.citadel';
// Chunk size for uploads (25MB - stays under Cloudflare's 100MB limit with margin)
const CHUNK_SIZE = 25 * 1024 * 1024;

export class BackupUploader {
    /**
     * @param {Object} options - Configuration options
     * @param {string} options.containerId - ID of the container element
     * @param {Function} options.onUploadSuccess - Callback function when upload succeeds
     * @param {Object} options.translations - Translation strings
     */
    constructor(options) {
        this.containerId = options.containerId;
        this.onUploadSuccess = options.onUploadSuccess || (() => {});
        this.translations = options.translations || {};
        
        this.isUploading = false;
        
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
        this.container.classList.add('backup-uploader');
        
        // Create the drop zone
        this.dropZone = document.createElement('div');
        this.dropZone.className = 'backup-uploader-drop-zone py-3';
        this.dropZone.innerHTML = `
            <div class="backup-uploader-icon">
                <i class="mdi mdi-cloud-upload"></i>
            </div>
            <div class="backup-uploader-text">
                ${this.translations.drag_backup || 'Drag backup file here'}
            </div>
            <div class="backup-uploader-text-small">
                ${this.translations.or || 'or'}
            </div>
            <button class="btn btn-outline-primary btn-sm">
                ${this.translations.browse_backup || 'Browse Files'}
            </button>
            <div class="backup-uploader-hint mt-2">
                <small class="text-secondary">${this.translations.accepted_format || 'Accepted format: .citadel'}</small>
            </div>
        `;
        
        // Create file input
        this.fileInput = document.createElement('input');
        this.fileInput.type = 'file';
        this.fileInput.accept = ALLOWED_EXTENSION;
        this.fileInput.style.display = 'none';
        
        // Create progress container
        this.progressContainer = document.createElement('div');
        this.progressContainer.className = 'backup-uploader-progress';
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
                this.handleFile(e.target.files[0]);
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
                this.handleFile(e.dataTransfer.files[0]);
            }
        });
    }
    
    /**
     * Handle file selected for upload
     * @param {File} file - File to upload
     */
    handleFile(file) {
        // Validate file extension
        if (!file.name.toLowerCase().endsWith(ALLOWED_EXTENSION)) {
            this.showError(file, this.translations.invalid_format || 'Invalid file format. Only .citadel files are accepted.');
            return;
        }
        
        // Validate file size
        if (file.size > MAX_BACKUP_SIZE) {
            this.showError(file, `${this.translations.file_too_large || 'File is too large. Maximum size is'} ${this.formatFileSize(MAX_BACKUP_SIZE)}.`);
            return;
        }
        
        // Start upload
        this.uploadFile(file);
    }
    
    /**
     * Upload file using chunked upload for large files
     * @param {File} file - File to upload
     */
    async uploadFile(file) {
        if (this.isUploading) {
            return;
        }
        
        this.isUploading = true;
        this.currentUploadId = null;
        
        // Show progress container
        this.progressContainer.style.display = 'block';
        this.progressContainer.innerHTML = `
            <div class="backup-uploader-progress-item">
                <div class="backup-uploader-progress-info">
                    <span class="backup-uploader-progress-name">${file.name}</span>
                    <span class="backup-uploader-progress-size">${this.formatFileSize(file.size)}</span>
                </div>
                <div class="progress">
                    <div class="progress-bar bg-cyber" role="progressbar" style="width: 0%"></div>
                </div>
                <div class="backup-uploader-progress-status">
                    <small class="text-secondary">${this.translations.uploading || 'Uploading...'} 0%</small>
                </div>
            </div>
        `;
        
        const progressBar = this.progressContainer.querySelector('.progress-bar');
        const statusText = this.progressContainer.querySelector('.backup-uploader-progress-status small');
        
        const totalChunks = Math.ceil(file.size / CHUNK_SIZE);
        console.log('[BackupUploader] Starting chunked upload:', file.name, 'size:', file.size, 'chunks:', totalChunks);
        
        try {
            // Step 1: Initialize upload session
            statusText.textContent = this.translations.initializing || 'Initializing upload...';
            const initResponse = await fetch('/backup/upload/init', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify({
                    filename: file.name,
                    totalSize: file.size,
                    totalChunks: totalChunks
                })
            });
            
            const initData = await initResponse.json();
            if (!initData.success) {
                throw new Error(initData.error || 'Failed to initialize upload');
            }
            
            this.currentUploadId = initData.uploadId;
            console.log('[BackupUploader] Upload initialized, ID:', this.currentUploadId);
            
            // Step 2: Upload chunks sequentially
            for (let i = 0; i < totalChunks; i++) {
                const start = i * CHUNK_SIZE;
                const end = Math.min(start + CHUNK_SIZE, file.size);
                const chunk = file.slice(start, end);
                
                const chunkFormData = new FormData();
                chunkFormData.append('uploadId', this.currentUploadId);
                chunkFormData.append('chunkIndex', i);
                chunkFormData.append('chunk', chunk);
                
                // Upload chunk with retry logic
                let retries = 3;
                let chunkSuccess = false;
                
                while (retries > 0 && !chunkSuccess) {
                    try {
                        const chunkResponse = await fetch('/backup/upload/chunk', {
                            method: 'POST',
                            headers: {
                                'X-Requested-With': 'XMLHttpRequest'
                            },
                            body: chunkFormData
                        });
                        
                        const chunkData = await chunkResponse.json();
                        if (!chunkData.success) {
                            throw new Error(chunkData.error || 'Chunk upload failed');
                        }
                        
                        chunkSuccess = true;
                        console.log('[BackupUploader] Chunk', i + 1, 'of', totalChunks, 'uploaded');
                        
                    } catch (chunkError) {
                        retries--;
                        console.warn('[BackupUploader] Chunk', i, 'failed, retries left:', retries, chunkError);
                        if (retries === 0) {
                            throw new Error(`Chunk ${i + 1} failed after 3 attempts: ${chunkError.message}`);
                        }
                        // Wait before retry
                        await new Promise(resolve => setTimeout(resolve, 1000));
                    }
                }
                
                // Update progress
                const percent = Math.round(((i + 1) / totalChunks) * 100);
                progressBar.style.width = `${percent}%`;
                statusText.textContent = `${this.translations.uploading || 'Uploading...'} ${percent}% (${i + 1}/${totalChunks})`;
            }
            
            // Step 3: Finalize upload
            statusText.textContent = this.translations.finalizing || 'Finalizing upload...';
            const finalizeResponse = await fetch('/backup/upload/finalize', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify({
                    uploadId: this.currentUploadId
                })
            });
            
            const finalizeData = await finalizeResponse.json();
            if (!finalizeData.success) {
                throw new Error(finalizeData.error || 'Failed to finalize upload');
            }
            
            console.log('[BackupUploader] Upload finalized successfully');
            this.showSuccess(file);
            this.onUploadSuccess(finalizeData);
            
        } catch (error) {
            console.error('[BackupUploader] Upload failed:', error);
            
            // Cleanup: cancel the upload session
            if (this.currentUploadId) {
                try {
                    await fetch('/backup/upload/cancel', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        body: JSON.stringify({ uploadId: this.currentUploadId })
                    });
                } catch (cancelError) {
                    console.warn('[BackupUploader] Failed to cancel upload:', cancelError);
                }
            }
            
            this.showUploadError(file, error.message || this.translations.upload_failed || 'Upload failed');
        } finally {
            this.isUploading = false;
            this.currentUploadId = null;
        }
    }
    
    /**
     * Show success state
     * @param {File} file - The uploaded file
     */
    showSuccess(file) {
        const progressItem = this.progressContainer.querySelector('.backup-uploader-progress-item');
        progressItem.classList.add('success');
        
        const progressBar = progressItem.querySelector('.progress-bar');
        progressBar.style.width = '100%';
        
        const statusText = progressItem.querySelector('.backup-uploader-progress-status small');
        statusText.innerHTML = `<i class="mdi mdi-check-circle text-success"></i> ${this.translations.upload_success || 'Upload complete!'}`;
        
        // Reload page after delay to show updated backup list
        setTimeout(() => {
            window.location.reload();
        }, 1500);
    }
    
    /**
     * Show upload error
     * @param {File} file - The file that failed
     * @param {string} errorMessage - Error message
     */
    showUploadError(file, errorMessage) {
        const progressItem = this.progressContainer.querySelector('.backup-uploader-progress-item');
        progressItem.classList.add('error');
        
        const statusText = progressItem.querySelector('.backup-uploader-progress-status small');
        statusText.innerHTML = `<i class="mdi mdi-close-circle text-danger"></i> ${errorMessage}`;
        statusText.classList.remove('text-secondary');
        statusText.classList.add('text-danger');
        
        // Add dismiss button
        const dismissBtn = document.createElement('button');
        dismissBtn.className = 'btn btn-sm btn-link text-secondary ms-2';
        dismissBtn.innerHTML = '<i class="mdi mdi-close"></i>';
        dismissBtn.addEventListener('click', () => {
            this.progressContainer.style.display = 'none';
            this.fileInput.value = '';
        });
        statusText.appendChild(dismissBtn);
    }
    
    /**
     * Show validation error (before upload)
     * @param {File} file - The file that failed validation
     * @param {string} errorMessage - Error message
     */
    showError(file, errorMessage) {
        // Show progress container with error
        this.progressContainer.style.display = 'block';
        this.progressContainer.innerHTML = `
            <div class="backup-uploader-progress-item error">
                <div class="backup-uploader-progress-info">
                    <span class="backup-uploader-progress-name">${file.name}</span>
                    <span class="backup-uploader-progress-size">${this.formatFileSize(file.size)}</span>
                </div>
                <div class="backup-uploader-progress-status">
                    <small class="text-danger"><i class="mdi mdi-close-circle"></i> ${errorMessage}</small>
                </div>
            </div>
        `;
        
        // Add dismiss functionality
        const progressItem = this.progressContainer.querySelector('.backup-uploader-progress-item');
        const dismissBtn = document.createElement('button');
        dismissBtn.className = 'btn btn-sm btn-link text-secondary';
        dismissBtn.innerHTML = '<i class="mdi mdi-close"></i>';
        dismissBtn.addEventListener('click', () => {
            this.progressContainer.style.display = 'none';
            this.fileInput.value = '';
        });
        progressItem.querySelector('.backup-uploader-progress-status').appendChild(dismissBtn);
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
}
