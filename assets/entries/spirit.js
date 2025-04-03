import { SpiritManager } from '../js/features/spirit/components/SpiritManager.js';

// Initialize spirit manager when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
    // Get translations from the data-translations attribute
    let translations = {};
    const translationsElement = document.querySelector('[data-translations]');
    if (translationsElement) {
        try {
            translations = JSON.parse(translationsElement.getAttribute('data-translations'));
        } catch (error) {
            console.error('Failed to parse translations:', error);
        }
    }
    
    // Initialize the spirit manager if the spirit container exists
    if (document.querySelector('#spirit-container')) {
        new SpiritManager({
            translations: translations,
            apiEndpoints: {
                get: '/api/spirit',
                create: '/api/spirit',
                update: '/api/spirit/update',
                interact: '/api/spirit/interact',
                interactions: '/api/spirit/interactions',
                abilities: '/api/spirit/abilities',
                unlockAbility: '/api/spirit/abilities/{id}/unlock'
            }
        });
    }
});
