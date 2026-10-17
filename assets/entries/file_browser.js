// File Browser entry point
import { FileBrowser } from '../js/features/file-browser/components/FileBrowser';
import { ToastService } from '../js/shared/toast';

// Initialize toast service if not already available
if (!window.toast) {
    window.toast = new ToastService();
}

// Initialize file browser when DOM is loaded
document.addEventListener('DOMContentLoaded', () => {
    // Get container element
    const container = document.getElementById('file-browser-container');
    if (!container) {
        console.error('File browser container not found');
        return;
    }
    
    // Get data attributes from container
    const projectId = container.dataset.projectId;
    const projectTitle = container.dataset.projectTitle || projectId;
    const translations = JSON.parse(container.dataset.translations || '{}');
    
    // Initialize file browser
    const fileBrowser = new FileBrowser({
        containerId: 'file-browser-container',
        projectId: projectId,
        projectTitle: projectTitle,
        translations: translations
    });
    
    // Make file browser available globally for debugging
    window.fileBrowser = fileBrowser;
});
