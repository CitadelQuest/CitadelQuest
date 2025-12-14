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
                `;
                
                // Add click handler for fullscreen
                thumbContainer.addEventListener('click', () => {
                    this.showFullImage(fileId, image);
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
                width: 100%;
                aspect-ratio: 1;
                display: flex;
                align-items: center;
                justify-content: center;
                background: rgba(0, 0, 0, 0.2);
                border-radius: 8px;
                overflow: hidden;
                cursor: pointer;
                transition: transform 0.2s ease, box-shadow 0.2s ease;
            }
            
            .image-gallery-thumb:hover {
                transform: scale(1.05);
                box-shadow: 0 4px 12px rgba(0, 255, 136, 0.2);
            }
            
            .image-gallery-img {
                width: 100%;
                height: 100%;
                object-fit: cover;
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
