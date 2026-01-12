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

    // Get spirit ID from hidden input
    const spiritIdInput = document.getElementById('spirit-id');
    const spiritId = spiritIdInput ? spiritIdInput.value : null;

    // Initialize the spirit manager if the spirit container exists
    if (document.querySelector('#spirit-container')) {
        new SpiritManager({
            spiritId: spiritId,
            translations: translations,
            apiEndpoints: {
                get: spiritId ? `/api/spirit/${spiritId}` : '/api/spirit',
                create: '/api/spirit',
                update: '/api/spirit/update',
                interactions: '/api/spirit/interactions',
                settings: '/api/spirit/{id}/settings',
                updateSettings: '/api/spirit/{id}/settings',
                conversations: '/api/spirit-conversation/list/{id}'
            }
        });
    }
});
