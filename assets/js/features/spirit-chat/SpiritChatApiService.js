/**
 * Spirit Chat API Service
 * Handles all API calls related to Spirit conversations
 */
export class SpiritChatApiService {
    constructor() {
        this.baseUrl = '/api/spirit-conversation';
    }
    
    /**
     * Get all conversations for a spirit
     * @param {string} spiritId - The ID of the spirit
     * @returns {Promise<Array>} - List of conversations
     */
    async getConversations(spiritId) {
        try {
            const response = await fetch(`${this.baseUrl}/list/${spiritId}`);
            if (!response.ok) {
                const error = await response.json();
                throw new Error(error.error || 'Failed to fetch conversations');
            }
            return await response.json();
        } catch (error) {
            console.error('Error fetching conversations:', error);
            throw error;
        }
    }
    
    /**
     * Create a new conversation
     * @param {string} spiritId - The ID of the spirit
     * @param {string} title - The title of the conversation
     * @returns {Promise<Object>} - The created conversation
     */
    async createConversation(spiritId, title) {
        try {
            const response = await fetch(`${this.baseUrl}/create`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({ spiritId, title })
            });
            
            if (!response.ok) {
                const error = await response.json();
                throw new Error(error.error || 'Failed to create conversation');
            }
            
            return await response.json();
        } catch (error) {
            console.error('Error creating conversation:', error);
            throw error;
        }
    }
    
    /**
     * Get a specific conversation
     * @param {string} conversationId - The ID of the conversation
     * @returns {Promise<Object>} - The conversation
     */
    async getConversation(conversationId) {
        try {
            const response = await fetch(`${this.baseUrl}/${conversationId}`);
            if (!response.ok) {
                const error = await response.json();
                throw new Error(error.error || 'Failed to fetch conversation');
            }
            return await response.json();
        } catch (error) {
            console.error('Error fetching conversation:', error);
            throw error;
        }
    }
    
    /**
     * Send a message in a conversation
     * @param {string} conversationId - The ID of the conversation
     * @param {string} message - The message to send
     * @returns {Promise<Object>} - The updated messages
     */
    async sendMessage(conversationId, message) {
        try {
            const response = await fetch(`${this.baseUrl}/${conversationId}/send`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({ message })
            });
            
            if (!response.ok) {
                const error = await response.json();
                throw new Error(error.error || 'Failed to send message');
            }
            
            return await response.json();
        } catch (error) {
            console.error('Error sending message:', error);
            throw error;
        }
    }
    
    /**
     * Delete a conversation
     * @param {string} conversationId - The ID of the conversation
     * @returns {Promise<Object>} - Success status
     */
    async deleteConversation(conversationId) {
        try {
            const response = await fetch(`${this.baseUrl}/${conversationId}`, {
                method: 'DELETE'
            });
            
            if (!response.ok) {
                const error = await response.json();
                throw new Error(error.error || 'Failed to delete conversation');
            }
            
            return await response.json();
        } catch (error) {
            console.error('Error deleting conversation:', error);
            throw error;
        }
    }
}
