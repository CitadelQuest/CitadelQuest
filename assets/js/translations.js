// Global Translator service
export const Translator = {
    // Get translations from global object
    get translations() {
        return window.CITADEL_TRANSLATIONS || {};
    },
    
    // Get translation by key
    trans(key) {
        return this.translations[key] || key;
    }
};

// Export as default for convenience
export default Translator;
