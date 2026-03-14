import { SpiritsManager } from '../js/features/spirit/components/SpiritsManager';

// Initialize spirits manager when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
    // Initialize if Create/Delete spirit modals exist (shared partial _spirit_list.html.twig)
    if (document.getElementById('createSpiritModal') || document.getElementById('deleteSpiritModal')) {
        new SpiritsManager();
    }
});
