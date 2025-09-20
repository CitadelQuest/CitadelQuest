/**
 * CQ Chat API Service
 * Handles all API calls related to CitadelQuest chat functionality
 */
export class CqChatApiService {
    constructor() {
        this.baseUrl = '/api/cq-chat';
    }
    
    /**
     * Get all chats
     * @returns {Promise<Array>} - List of chats
     */
    async getChats() {
        try {
            const response = await fetch(`${this.baseUrl}`);
            if (!response.ok) {
                const error = await response.json();
                throw new Error(error.error || 'Failed to fetch chats');
            }
            return await response.json();
        } catch (error) {
            console.error('Error fetching chats:', error);
            throw error;
        }
    }
    
    /**
     * Get a specific chat
     * @param {string} chatId - The ID of the chat
     * @returns {Promise<Object>} - The chat
     */
    async getChat(chatId) {
        try {
            const response = await fetch(`${this.baseUrl}/${chatId}`);
            if (!response.ok) {
                const error = await response.json();
                throw new Error(error.error || 'Failed to fetch chat');
            }
            return await response.json();
        } catch (error) {
            console.error('Error fetching chat:', error);
            throw error;
        }
    }

    /**
     * Get new messages count
     * @returns {Promise<number>} - The new messages count
     */
    async getNewMsgsCount() {
        try {
            const response = await fetch(`${this.baseUrl}/new-msgs-count`);
            if (!response.ok) {
                const error = await response.json();
                throw new Error(error.error || 'Failed to fetch new messages count');
            }
            return await response.json();
        } catch (error) {
            console.error('Error fetching new messages count:', error);
            throw error;
        }
    }
    
    /**
     * Get messages for a specific chat
     * @param {string} chatId - The ID of the chat
     * @param {number} limit - Maximum number of messages to retrieve
     * @param {number} offset - Offset for pagination
     * @returns {Promise<Object>} - The messages and pagination info
     */
    async getMessages(chatId, limit = 50, offset = 0) {
        try {
            const response = await fetch(`${this.baseUrl}/${chatId}/messages?limit=${limit}&offset=${offset}`);
            if (!response.ok) {
                const error = await response.json();
                throw new Error(error.error || 'Failed to fetch messages');
            }
            return await response.json();
        } catch (error) {
            console.error('Error fetching messages:', error);
            throw error;
        }
    }
    
    /**
     * Send a message in a chat
     * @param {string} chatId - The ID of the chat
     * @param {string} content - The message content
     * @param {Array} attachments - Optional attachments
     * @returns {Promise<Object>} - The created message
     */
    async sendMessage(chatId, content, attachments = null) {
        try {
            const response = await fetch(`${this.baseUrl}/${chatId}/messages`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({ 
                    content,
                    attachments
                })
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
     * Toggle star status for a chat
     * @param {string} chatId - The ID of the chat
     * @returns {Promise<Object>} - Success status
     */
    async toggleStar(chatId) {
        try {
            const response = await fetch(`${this.baseUrl}/${chatId}/toggle-star`, {
                method: 'POST'
            });
            
            if (!response.ok) {
                const error = await response.json();
                throw new Error(error.error || 'Failed to toggle star');
            }
            
            return await response.json();
        } catch (error) {
            console.error('Error toggling star:', error);
            throw error;
        }
    }
    
    /**
     * Toggle pin status for a chat
     * @param {string} chatId - The ID of the chat
     * @returns {Promise<Object>} - Success status
     */
    async togglePin(chatId) {
        try {
            const response = await fetch(`${this.baseUrl}/${chatId}/toggle-pin`, {
                method: 'POST'
            });
            
            if (!response.ok) {
                const error = await response.json();
                throw new Error(error.error || 'Failed to toggle pin');
            }
            
            return await response.json();
        } catch (error) {
            console.error('Error toggling pin:', error);
            throw error;
        }
    }
    
    /**
     * Toggle mute status for a chat
     * @param {string} chatId - The ID of the chat
     * @returns {Promise<Object>} - Success status
     */
    async toggleMute(chatId) {
        try {
            const response = await fetch(`${this.baseUrl}/${chatId}/toggle-mute`, {
                method: 'POST'
            });
            
            if (!response.ok) {
                const error = await response.json();
                throw new Error(error.error || 'Failed to toggle mute');
            }
            
            return await response.json();
        } catch (error) {
            console.error('Error toggling mute:', error);
            throw error;
        }
    }
    
    /**
     * Delete a chat
     * @param {string} chatId - The ID of the chat
     * @returns {Promise<Object>} - Success status
     */
    async deleteChat(chatId) {
        try {
            const response = await fetch(`${this.baseUrl}/${chatId}`, {
                method: 'DELETE'
            });
            
            if (!response.ok) {
                const error = await response.json();
                throw new Error(error.error || 'Failed to delete chat');
            }
            
            return await response.json();
        } catch (error) {
            console.error('Error deleting chat:', error);
            throw error;
        }
    }
    
    /**
     * Get contacts for chat creation
     * @returns {Promise<Array>} - List of contacts
     */
    async getContacts() {
        try {
            const response = await fetch('/api/cq-contact');
            if (!response.ok) {
                const error = await response.json();
                throw new Error(error.error || 'Failed to fetch contacts');
            }
            return await response.json();
        } catch (error) {
            console.error('Error fetching contacts:', error);
            throw error;
        }
    }
}
