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
            }
        }
        return this.modal;
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
                    <div class="modal-body position-relative d-flex align-items-center justify-content-center">
                        <i class="mdi mdi-close text-cyber bg-dark bg-opacity-75 rounded p-1 cursor-pointer position-fixed end-0 top-0 me-1 fs-5" style="z-index: 1;" 
                            data-bs-dismiss="modal" aria-label="Close" title="Close"></i>
                        <div class="contentShowcaseModal-content w-100 h-100 position-relative d-flex align-items-center justify-content-center">
                        </div>
                    </div>
                </div>
            </div>
        `;
        
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
            el.addEventListener('click', (e) => {
                e.stopPropagation();
                e.preventDefault();
                this.show(showcase);
            });
        });
    }

    /**
     * Show the showcase modal with content from the showcase element
     * @param {HTMLElement} showcaseElement - The .content-showcase element
     */
    show(showcaseElement) {
        const modal = this.getModal();
        const contentContainer = modal.querySelector('.contentShowcaseModal-content');
        
        if (!contentContainer) return;
        
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
