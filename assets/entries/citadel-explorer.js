import { CitadelExplorer } from '../js/features/cq-contact/CitadelExplorer.js';
import { ExplorerSidebar } from '../js/features/cq-contact/ExplorerSidebar.js';

// Initialize when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
    window.citadelExplorer = new CitadelExplorer();
    window.explorerSidebar = new ExplorerSidebar(window.explorerSidebarConfig || {});
});
