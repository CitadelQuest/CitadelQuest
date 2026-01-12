import { SpiritsManager } from '../js/features/spirit/components/SpiritsManager';

// Initialize spirits manager when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
    // Initialize the spirits manager if the spirits container exists
    if (document.querySelector('#spirits-container')) {
        new SpiritsManager();
    }
});
