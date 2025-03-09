import { DiaryManager } from '../js/features/diary/components/DiaryManager.js';

// Initialize diary manager when DOM is ready
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
    
    // Initialize the diary manager if the diary entries container exists
    if (document.querySelector('.diary-entries')) {
        new DiaryManager({
            translations: translations
        });
    }
});
