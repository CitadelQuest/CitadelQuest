import { SpiritManager } from '../js/features/spirit/components/SpiritManager.js';
import { SpiritPromptBuilder } from '../js/features/spirit/components/SpiritPromptBuilder.js';
import '../styles/features/cq-memory.scss';

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
        const spiritManager = new SpiritManager({
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
        
        // Initialize the System Prompt Builder
        const promptBuilder = new SpiritPromptBuilder({
            spiritId: spiritId,
            translations: translations,
            apiEndpoints: {
                preview: '/api/spirit/{id}/system-prompt-preview',
                config: '/api/spirit/{id}/system-prompt-config'
            }
        });
        
        // Add event listener for the "Advanced Prompt Builder" button
        const openPromptBuilderBtn = document.getElementById('open-prompt-builder');
        if (openPromptBuilderBtn) {
            openPromptBuilderBtn.addEventListener('click', () => {
                // Get current spirit ID from the manager if available
                const currentSpiritId = spiritManager.spirit?.id || spiritId;
                if (currentSpiritId) {
                    promptBuilder.open(currentSpiritId);
                } else {
                    console.warn('No Spirit ID available to open Prompt Builder');
                    if (window.toast) {
                        window.toast.error(translations['error.loading_spirit'] || 'No Spirit found');
                    }
                }
            });
        }
    }
});
