import * as bootstrap from 'bootstrap';

/**
 * ImageShowcase - Centralized utility for full-screen image viewing
 * 
 * Usage:
 * 1. Include the showcase modal in your template (or use createModal())
 * 2. Use wrapImage() to wrap images with the showcase icon
 * 3. Call init() to attach event listeners to a container
 */
export class ImageShowcase {
    constructor(modalId = 'contentShowcaseModal') {
        this.modalId = modalId;
        this.modal = null;
        this.bsModal = null;
        
        // Gallery navigation state
        this.galleryImages = null;
        this.galleryCurrentIndex = -1;
        this.galleryApiService = null;
    }

    /**
     * Get or create the modal element
     */
    getModal() {
        if (!this.modal) {
            this.modal = document.getElementById(this.modalId);
            
            // Create modal if it doesn't exist
            if (!this.modal) {
                this.modal = this.createModal();
                document.body.appendChild(this.modal);
            } else {
                // Enhance existing modal with gallery navigation if not already done
                this.enhanceExistingModal(this.modal);
            }
        }
        return this.modal;
    }
    
    /**
     * Enhance an existing modal (from template) with gallery navigation buttons
     * @param {HTMLElement} modal - The existing modal element
     */
    enhanceExistingModal(modal) {
        const modalBody = modal.querySelector('.modal-body');
        if (!modalBody) return;
        
        // Add flex centering to modal-body for centered image display
        modalBody.classList.add('d-flex', 'align-items-center', 'justify-content-center');
        
        // Also add flex centering to the content container
        const contentContainer = modalBody.querySelector('.contentShowcaseModal-content');
        if (contentContainer) {
            contentContainer.classList.add('d-flex', 'align-items-center', 'justify-content-center');
        }
        
        // Add nav buttons if they don't exist
        if (!modalBody.querySelector('.showcase-nav-prev')) {
            const prevBtn = document.createElement('button');
            prevBtn.className = 'btn btn-dark bg-opacity-75 position-absolute start-0 bottom-0 ms-2 mb-2 showcase-nav-prev';
            prevBtn.style.cssText = 'z-index: 2; display: none; font-size: 1.5rem; padding: 8px 12px;';
            prevBtn.title = 'Previous';
            prevBtn.innerHTML = '<i class="mdi mdi-chevron-left text-cyber"></i>';
            prevBtn.addEventListener('click', () => this.navigateGallery(-1));
            modalBody.appendChild(prevBtn);
        }
        
        if (!modalBody.querySelector('.showcase-nav-next')) {
            const nextBtn = document.createElement('button');
            nextBtn.className = 'btn btn-dark bg-opacity-75 position-absolute end-0 bottom-0 me-2 mb-2 showcase-nav-next';
            nextBtn.style.cssText = 'z-index: 2; display: none; font-size: 1.5rem; padding: 8px 12px;';
            nextBtn.title = 'Next';
            nextBtn.innerHTML = '<i class="mdi mdi-chevron-right text-cyber"></i>';
            nextBtn.addEventListener('click', () => this.navigateGallery(1));
            modalBody.appendChild(nextBtn);
        }
        
        // Add keyboard navigation
        modal.addEventListener('keydown', (e) => {
            if (e.key === 'ArrowLeft') this.navigateGallery(-1);
            if (e.key === 'ArrowRight') this.navigateGallery(1);
        });
    }

    /**
     * Create the showcase modal element
     */
    createModal() {
        const modal = document.createElement('div');
        modal.className = 'modal fade';
        modal.id = this.modalId;
        modal.tabIndex = -1;
        modal.setAttribute('aria-hidden', 'true');
        modal.setAttribute('data-bs-backdrop', 'static');
        
        modal.innerHTML = `
            <div class="modal-dialog modal-dialog-centered modal-fullscreen">
                <div class="modal-content bg-dark">
                    <div class="modal-header border-0 position-absolute w-100" style="z-index: 2; background: linear-gradient(to bottom, rgba(0,0,0,0.6), transparent); pointer-events: none;">
                        <span class="modal-title text-light small" id="contentShowcaseModalTitle" style="pointer-events: auto;"></span>
                        <i class="mdi mdi-close text-cyber bg-dark bg-opacity-75 rounded p-1 cursor-pointer fs-5" style="pointer-events: auto;" 
                            data-bs-dismiss="modal" aria-label="Close" title="Close"></i>
                    </div>
                    <div class="modal-body position-relative d-flex align-items-center justify-content-center p-0">
                        <div class="contentShowcaseModal-content w-100 h-100 position-relative d-flex align-items-center justify-content-center">
                        </div>
                        <button class="btn btn-dark bg-opacity-75 position-absolute start-0 bottom-0 ms-2 mb-2 showcase-nav-prev" style="z-index: 2; display: none; font-size: 1.5rem; padding: 8px 12px;" title="Previous">
                            <i class="mdi mdi-chevron-left text-cyber"></i>
                        </button>
                        <button class="btn btn-dark bg-opacity-75 position-absolute end-0 bottom-0 me-2 mb-2 showcase-nav-next" style="z-index: 2; display: none; font-size: 1.5rem; padding: 8px 12px;" title="Next">
                            <i class="mdi mdi-chevron-right text-cyber"></i>
                        </button>
                    </div>
                </div>
            </div>
        `;
        
        // Bind navigation buttons
        modal.querySelector('.showcase-nav-prev').addEventListener('click', () => this.navigateGallery(-1));
        modal.querySelector('.showcase-nav-next').addEventListener('click', () => this.navigateGallery(1));
        
        // Keyboard navigation
        modal.addEventListener('keydown', (e) => {
            if (e.key === 'ArrowLeft') this.navigateGallery(-1);
            if (e.key === 'ArrowRight') this.navigateGallery(1);
        });
        
        return modal;
    }

    /**
     * Wrap an image with the showcase container and icon
     * @param {string} src - Image source (URL or base64)
     * @param {string} alt - Alt text for the image
     * @param {string} additionalClasses - Additional CSS classes for the image
     * @returns {string} HTML string with wrapped image
     */
    static wrapImage(src, alt = '', additionalClasses = '') {
        const escapedAlt = alt.replace(/"/g, '&quot;');
        return `
            <div class="content-showcase position-relative d-inline-block">
                <img src="${src}" alt="${escapedAlt}" title="${escapedAlt}" class="${additionalClasses}">
                <div class="content-showcase-icon position-absolute top-0 end-0 p-1 badge bg-dark bg-opacity-75 text-cyber cursor-pointer">
                    <i class="mdi mdi-fullscreen"></i>
                </div>
            </div>
        `;
    }

    /**
     * Set API service for loading full images
     * @param {Object} apiService - API service with getFileContent method
     */
    setApiService(apiService) {
        this.apiService = apiService;
    }
    
    /**
     * Initialize event listeners for showcase icons within a container
     * @param {HTMLElement} container - Container element to search for showcase icons
     */
    init(container) {
        if (!container) return;
        
        container.querySelectorAll('.content-showcase-icon').forEach(el => {
            // Skip if already initialized
            if (el.dataset.showcaseInit) return;
            el.dataset.showcaseInit = 'true';
            
            const showcase = el.parentElement;
            el.addEventListener('click', async (e) => {
                e.stopPropagation();
                e.preventDefault();
                
                // Check if image is a thumbnail and needs full version
                const img = showcase.querySelector('img[data-is-thumbnail="true"]');
                if (img && img.dataset.fileId && this.apiService) {
                    await this.showFullImage(img.dataset.fileId, img.alt);
                } else {
                    this.show(showcase);
                }
            });
        });
    }
    
    /**
     * Show full image by loading it from API
     * @param {string} fileId - File ID to load
     * @param {string} alt - Alt text for the image
     */
    async showFullImage(fileId, alt = '') {
        const modal = this.getModal();
        const contentContainer = modal.querySelector('.contentShowcaseModal-content');
        
        if (!contentContainer) return;
        
        // Hide gallery nav buttons for single image view
        this.hideGalleryNav();
        
        // Update title
        const titleEl = modal.querySelector('#contentShowcaseModalTitle');
        if (titleEl) titleEl.textContent = alt;
        
        // Show loading state
        contentContainer.innerHTML = `
            <div class="spinner-border text-cyber" role="status">
                <span class="visually-hidden">Loading...</span>
            </div>
        `;
        
        // Show the modal with loading state
        if (!this.bsModal) {
            this.bsModal = new bootstrap.Modal(modal);
        }
        this.bsModal.show();
        
        try {
            // Load full image
            const response = await this.apiService.getFileContent(fileId, false);
            
            if (response.success && response.content) {
                contentContainer.innerHTML = `
                    <img src="${response.content}" alt="${alt}" 
                         style="max-width: 100%; max-height: 90vh; object-fit: contain;">
                `;
            } else {
                throw new Error('Failed to load image');
            }
        } catch (error) {
            console.error('Error loading full image:', error);
            contentContainer.innerHTML = `
                <div class="alert alert-danger">
                    <i class="mdi mdi-alert me-1"></i>
                    Failed to load image
                </div>
            `;
        }
    }
    
    /**
     * Show a gallery image with prev/next navigation
     * @param {Array} images - Array of image objects with id and name
     * @param {number} currentIndex - Index of the current image
     * @param {Object} apiService - API service for loading full images
     */
    async showGalleryImage(images, currentIndex, apiService) {
        this.galleryImages = images;
        this.galleryCurrentIndex = currentIndex;
        this.galleryApiService = apiService;
        
        await this.loadGalleryImage();
    }
    
    /**
     * Load the current gallery image
     */
    async loadGalleryImage() {
        if (!this.galleryImages || this.galleryCurrentIndex < 0) return;
        
        const image = this.galleryImages[this.galleryCurrentIndex];
        if (!image) return;
        
        const modal = this.getModal();
        const contentContainer = modal.querySelector('.contentShowcaseModal-content');
        if (!contentContainer) return;
        
        // Update title with image name and position
        const titleEl = modal.querySelector('#contentShowcaseModalTitle');
        if (titleEl) {
            titleEl.textContent = `${image.name} (${this.galleryCurrentIndex + 1}/${this.galleryImages.length})`;
        }
        
        // Show/hide nav buttons
        this.updateGalleryNav();
        
        // Show loading state
        contentContainer.innerHTML = `
            <div class="spinner-border text-cyber" role="status">
                <span class="visually-hidden">Loading...</span>
            </div>
        `;
        
        // Show modal if not already visible
        if (!this.bsModal) {
            this.bsModal = new bootstrap.Modal(modal);
        }
        this.bsModal.show();
        
        try {
            const apiService = this.galleryApiService || this.apiService;
            const response = await apiService.getFileContent(image.id, false);
            
            if (response.success && response.content) {
                contentContainer.innerHTML = `
                    <img src="${response.content}" alt="${image.name || ''}" 
                         style="max-width: 100%; max-height: 90vh; object-fit: contain;">
                `;
            } else {
                throw new Error('Failed to load image');
            }
        } catch (error) {
            console.error('Error loading gallery image:', error);
            contentContainer.innerHTML = `
                <div class="alert alert-danger">
                    <i class="mdi mdi-alert me-1"></i>
                    Failed to load image
                </div>
            `;
        }
    }
    
    /**
     * Navigate gallery by offset (-1 for prev, +1 for next)
     * @param {number} offset - Navigation offset
     */
    navigateGallery(offset) {
        if (!this.galleryImages) return;
        
        const newIndex = this.galleryCurrentIndex + offset;
        if (newIndex < 0 || newIndex >= this.galleryImages.length) return;
        
        this.galleryCurrentIndex = newIndex;
        this.loadGalleryImage();
    }
    
    /**
     * Update gallery navigation button visibility
     */
    updateGalleryNav() {
        const modal = this.getModal();
        const prevBtn = modal.querySelector('.showcase-nav-prev');
        const nextBtn = modal.querySelector('.showcase-nav-next');
        
        if (prevBtn) prevBtn.style.display = this.galleryCurrentIndex > 0 ? 'block' : 'none';
        if (nextBtn) nextBtn.style.display = this.galleryCurrentIndex < this.galleryImages.length - 1 ? 'block' : 'none';
    }
    
    /**
     * Hide gallery navigation buttons
     */
    hideGalleryNav() {
        this.galleryImages = null;
        this.galleryCurrentIndex = -1;
        
        const modal = this.getModal();
        const prevBtn = modal.querySelector('.showcase-nav-prev');
        const nextBtn = modal.querySelector('.showcase-nav-next');
        
        if (prevBtn) prevBtn.style.display = 'none';
        if (nextBtn) nextBtn.style.display = 'none';
    }

    /**
     * Show the showcase modal with content from the showcase element
     * @param {HTMLElement} showcaseElement - The .content-showcase element
     */
    show(showcaseElement) {
        const modal = this.getModal();
        const contentContainer = modal.querySelector('.contentShowcaseModal-content');
        
        if (!contentContainer) return;
        
        // Hide gallery nav buttons for single content view
        this.hideGalleryNav();
        
        // Clone the content
        contentContainer.innerHTML = showcaseElement.innerHTML;
        
        // Remove the icon from the modal content
        const icon = contentContainer.querySelector('.content-showcase-icon');
        if (icon) icon.remove();
        
        // Style the image for fullscreen viewing
        const img = contentContainer.querySelector('img');
        if (img) {
            img.style.maxWidth = '100%';
            img.style.maxHeight = '90vh';
            img.style.objectFit = 'contain';
            // Remove margin classes that don't make sense in fullscreen
            img.classList.remove('ms-2', 'mb-2');
        }
        
        // Handle embed containers (PDFs)
        const embedContainer = contentContainer.querySelector('.embed-container');
        if (embedContainer) {
            const embed = embedContainer.querySelector('embed');
            if (embed) {
                embed.setAttribute('height', '100%');
            }
            embedContainer.classList.remove('d-none');
            embedContainer.classList.add('h-100');
            // Remove the file preview title in fullscreen
            const fileTitle = contentContainer.querySelector('.chat-file-preview-title');
            if (fileTitle) fileTitle.remove();
        }
        
        // Show the modal
        if (!this.bsModal) {
            this.bsModal = new bootstrap.Modal(modal);
        }
        this.bsModal.show();
    }
}

// Export a singleton instance for easy use
export const imageShowcase = new ImageShowcase();
