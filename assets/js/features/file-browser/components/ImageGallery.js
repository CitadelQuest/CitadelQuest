import { ImageShowcase } from '../../../shared/image-showcase';

/**
 * ImageGallery component for displaying image thumbnails with lazy loading
 */
export class ImageGallery {
    /**
     * @param {Object} options - Configuration options
     * @param {HTMLElement} options.container - Container element for the gallery
     * @param {Object} options.apiService - FileBrowserApiService instance
     * @param {string} options.projectId - Project ID
     * @param {Object} options.translations - Translation strings
     * @param {ImageShowcase} options.imageShowcase - ImageShowcase instance for fullscreen
     */
    constructor(options) {
        this.container = options.container;
        this.apiService = options.apiService;
        this.projectId = options.projectId;
        this.translations = options.translations || {};
        this.imageShowcase = options.imageShowcase;
        this.onDelete = options.onDelete || null; // Callback for delete action
        
        this.images = [];
        this.loadedThumbnails = new Set();
        this.observer = null;
    }
    
    /**
     * Load and display images from a directory
     * @param {string} path - Directory path
     */
    async loadImages(path) {
        try {
            this.container.innerHTML = `
                <div class="text-center p-3">
                    <div class="spinner-border spinner-border-sm text-cyber" role="status">
                        <span class="visually-hidden">${this.translations.loading || 'Loading...'}</span>
                    </div>
                    <span class="ms-2 small">${this.translations.loading_images || 'Loading images...'}</span>
                </div>
            `;
            
            const response = await this.apiService.getImagesInDirectory(this.projectId, path);
            
            if (!response.success) {
                throw new Error(response.error || 'Failed to load images');
            }
            
            this.images = response.images;
            
            if (this.images.length === 0) {
                this.container.innerHTML = `
                    <div class="alert alert-info small py-2 mb-0">
                        <i class="mdi mdi-image-off me-1"></i>
                        ${this.translations.no_images || 'No images in this directory'}
                    </div>
                `;
                return;
            }
            
            this.renderGallery();
            this.setupLazyLoading();
            
        } catch (error) {
            console.error('Error loading images:', error);
            this.container.innerHTML = `
                <div class="alert alert-danger small py-2 mb-0">
                    <i class="mdi mdi-alert me-1"></i>
                    ${error.message}
                </div>
            `;
        }
    }
    
    /**
     * Render the gallery grid
     */
    renderGallery() {
        const galleryHtml = `
            <div class="image-gallery-grid">
                ${this.images.map((image, index) => `
                    <div class="image-gallery-item" data-file-id="${image.id}" data-index="${index}">
                        <div class="image-gallery-thumb">
                            <div class="image-gallery-placeholder">
                                <i class="mdi mdi-image text-muted"></i>
                            </div>
                        </div>
                        <div class="image-gallery-name" title="${image.name}">
                            ${this.truncateName(image.name)}
                        </div>
                    </div>
                `).join('')}
            </div>
        `;
        
        this.container.innerHTML = galleryHtml;
        this.addStyles();
    }
    
    /**
     * Setup IntersectionObserver for lazy loading
     */
    setupLazyLoading() {
        // Disconnect existing observer
        if (this.observer) {
            this.observer.disconnect();
        }
        
        // Create new observer
        this.observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    const item = entry.target;
                    const fileId = item.dataset.fileId;
                    
                    if (!this.loadedThumbnails.has(fileId)) {
                        this.loadThumbnail(item, fileId);
                    }
                }
            });
        }, {
            root: this.container.closest('.file-browser-preview'),
            rootMargin: '50px',
            threshold: 0.1
        });
        
        // Observe all gallery items
        this.container.querySelectorAll('.image-gallery-item').forEach(item => {
            this.observer.observe(item);
        });
    }
    
    /**
     * Load thumbnail for a gallery item
     * @param {HTMLElement} item - Gallery item element
     * @param {string} fileId - File ID
     */
    async loadThumbnail(item, fileId) {
        this.loadedThumbnails.add(fileId);
        
        try {
            const response = await this.apiService.getFileContent(fileId, true);
            
            if (response.success && response.content) {
                const thumbContainer = item.querySelector('.image-gallery-thumb');
                const image = this.images.find(img => img.id === fileId);
                
                thumbContainer.innerHTML = `
                    <img src="${response.content}" 
                         alt="${image?.name || ''}" 
                         class="image-gallery-img"
                         loading="lazy">
                    <div class="content-showcase-icon image-gallery-zoom position-absolute top-0 end-0 p-1 badge bg-dark bg-opacity-75 text-cyber cursor-pointer" title="${this.translations.fullscreen || 'Fullscreen'}">
                        <i class="mdi mdi-fullscreen"></i>
                    </div>
                    <div class="image-gallery-delete badge bg-dark bg-opacity-75 text-danger cursor-pointer" title="${this.translations.delete || 'Delete'}">
                        <i class="mdi mdi-delete"></i>
                    </div>
                `;
                
                // Add click handler for fullscreen (zoom button)
                const zoomBtn = thumbContainer.querySelector('.image-gallery-zoom');
                zoomBtn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    this.showFullImage(fileId, image);
                });
                
                // Add click handler for delete
                const deleteBtn = thumbContainer.querySelector('.image-gallery-delete');
                deleteBtn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    this.handleDelete(fileId, image);
                });
                
                // Add loaded class for animation
                item.classList.add('loaded');
            }
        } catch (error) {
            console.error('Error loading thumbnail:', error);
            const thumbContainer = item.querySelector('.image-gallery-thumb');
            thumbContainer.innerHTML = `
                <div class="image-gallery-error">
                    <i class="mdi mdi-image-broken text-danger"></i>
                </div>
            `;
        }
    }
    
    /**
     * Handle delete action for an image
     * @param {string} fileId - File ID
     * @param {Object} image - Image metadata
     */
    async handleDelete(fileId, image) {
        if (this.onDelete) {
            await this.onDelete(fileId, image);
        }
    }
    
    /**
     * Remove an image from the gallery (called after successful delete)
     * @param {string} fileId - File ID
     */
    removeImage(fileId) {
        const item = this.container.querySelector(`[data-file-id="${fileId}"]`);
        if (item) {
            item.remove();
        }
        this.images = this.images.filter(img => img.id !== fileId);
        this.loadedThumbnails.delete(fileId);
        
        // Show empty message if no images left
        if (this.images.length === 0) {
            this.container.innerHTML = `
                <div class="alert alert-info small py-2 mb-0">
                    <i class="mdi mdi-image-off me-1"></i>
                    ${this.translations.no_images || 'No images in this directory'}
                </div>
            `;
        }
    }
    
    /**
     * Show full image in ImageShowcase
     * @param {string} fileId - File ID
     * @param {Object} image - Image metadata
     */
    async showFullImage(fileId, image) {
        try {
            // Get full image content
            const response = await this.apiService.getFileContent(fileId, false);
            
            if (response.success && response.content) {
                // Create a temporary showcase element
                const showcaseEl = document.createElement('div');
                showcaseEl.className = 'content-showcase';
                showcaseEl.innerHTML = `
                    <img src="${response.content}" alt="${image?.name || ''}" class="img-fluid">
                `;
                
                // Show in ImageShowcase
                this.imageShowcase.show(showcaseEl);
            }
        } catch (error) {
            console.error('Error loading full image:', error);
            window.toast?.error(this.translations.failed_load || 'Failed to load image');
        }
    }
    
    /**
     * Truncate filename for display
     * @param {string} name - Full filename
     * @returns {string} - Truncated name
     */
    truncateName(name) {
        if (name.length <= 20) return name;
        const ext = name.split('.').pop();
        const base = name.slice(0, -(ext.length + 1));
        return base.slice(0, 14) + '...' + '.' + ext;
    }
    
    /**
     * Add gallery styles
     */
    addStyles() {
        if (document.getElementById('image-gallery-styles')) return;
        
        const style = document.createElement('style');
        style.id = 'image-gallery-styles';
        style.textContent = `
            .image-gallery-grid {
                display: grid;
                grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
                gap: 12px;
                padding: 12px;
            }
            
            .image-gallery-item {
                display: flex;
                flex-direction: column;
                align-items: center;
                opacity: 0.6;
                transition: opacity 0.3s ease;
            }
            
            .image-gallery-item.loaded {
                opacity: 1;
            }
            
            .image-gallery-thumb {
                width: 125px !important;
                aspect-ratio: 1;
                display: flex;
                align-items: center;
                justify-content: center;
                background: rgba(0, 0, 0, 0.2);
                border-radius: 8px;
                overflow: hidden;
                position: relative;
            }
            
            .image-gallery-img {
                width: 100%;
                height: 100%;
                object-fit: cover;
            }
            
            
            .image-gallery-delete {
                position: absolute;
                bottom: 0;
                left: 0;
                padding: 4px 6px !important;
            }
            
            .image-gallery-delete:hover {
                background: rgba(255, 107, 107, 0.3) !important;
            }
            
            .image-gallery-placeholder,
            .image-gallery-error {
                font-size: 2rem;
            }
            
            .image-gallery-name {
                font-size: 0.7rem;
                color: #aaa;
                text-align: center;
                margin-top: 4px;
                max-width: 100%;
                overflow: hidden;
                text-overflow: ellipsis;
                white-space: nowrap;
            }
            
            @media (max-width: 576px) {
                .image-gallery-grid {
                    grid-template-columns: repeat(auto-fill, minmax(80px, 1fr));
                    gap: 8px;
                    padding: 8px;
                }
            }
        `;
        document.head.appendChild(style);
    }
    
    /**
     * Cleanup observer
     */
    destroy() {
        if (this.observer) {
            this.observer.disconnect();
            this.observer = null;
        }
        this.loadedThumbnails.clear();
    }
}
