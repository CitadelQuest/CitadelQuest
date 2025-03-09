/**
 * DiaryApiService - Handles all API calls for the diary feature
 */
export class DiaryApiService {
    constructor(translations) {
        this.translations = translations;
    }

    /**
     * Fetch all diary entries
     * @returns {Promise<Object>} - JSON response with entries
     */
    async fetchEntries() {
        const response = await fetch('/api/diary');
        if (!response.ok) throw new Error(this.translations.failed_load);
        return await response.json();
    }

    /**
     * Fetch a single diary entry by ID
     * @param {string} entryId - The ID of the entry to fetch
     * @returns {Promise<Object>} - JSON response with entry
     */
    async fetchEntry(entryId) {
        const response = await fetch(`/api/diary/${entryId}`);
        if (!response.ok) throw new Error(this.translations.failed_load);
        return await response.json();
    }

    /**
     * Create a new diary entry
     * @param {Object} formData - The form data for the new entry
     * @returns {Promise<Object>} - JSON response with the created entry
     */
    async createEntry(formData) {
        const response = await fetch('/api/diary', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify(formData)
        });
        
        if (!response.ok) throw new Error(this.translations.failed_save);
        return await response.json();
    }

    /**
     * Update an existing diary entry
     * @param {string} entryId - The ID of the entry to update
     * @param {Object} formData - The updated form data
     * @returns {Promise<Object>} - JSON response with the updated entry
     */
    async updateEntry(entryId, formData) {
        const response = await fetch(`/api/diary/${entryId}`, {
            method: 'PUT',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify(formData)
        });
        
        if (!response.ok) throw new Error(this.translations.failed_save);
        return await response.json();
    }

    /**
     * Delete a diary entry
     * @param {string} entryId - The ID of the entry to delete
     * @returns {Promise<boolean>} - True if successful
     */
    async deleteEntry(entryId) {
        const response = await fetch(`/api/diary/${entryId}`, {
            method: 'DELETE',
        });

        if (!response.ok) throw new Error(this.translations.failed_delete);
        return true;
    }

    /**
     * Toggle the favorite status of an entry
     * @param {string} entryId - The ID of the entry to toggle
     * @returns {Promise<Object>} - JSON response with updated entry
     */
    async toggleFavorite(entryId) {
        const response = await fetch(`/api/diary/${entryId}/favorite`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            }
        });

        if (!response.ok) throw new Error(this.translations.failed_favorite);
        return await response.json();
    }
}
