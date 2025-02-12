// Import HTMX
import htmx from 'htmx.org';

// Initialize HTMX and extensions
function initializeHtmx() {
    // Make HTMX available globally
    window.htmx = htmx;

    // Configure HTMX defaults
    htmx.config = {
        ...htmx.config,
        defaultSwapStyle: 'innerHTML',
        useTemplateFragments: true,
    };

    // Import SSE extension
    import('./htmx-sse');
}

// Initialize when DOM is ready
document.addEventListener('DOMContentLoaded', initializeHtmx);

