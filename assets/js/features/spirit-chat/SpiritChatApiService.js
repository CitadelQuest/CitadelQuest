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
     * Get a specific conversation with pagination
     * @param {string} conversationId - The ID of the conversation
     * @param {number} limit - Number of messages to fetch (default: 5)
     * @param {number} offset - Offset from the end (newest messages)
     * @returns {Promise<Object>} - The conversation with messages and pagination info
     */
    async getConversation(conversationId, limit = 5, offset = 0) {
        try {
            const params = new URLSearchParams({ limit, offset });
            const response = await fetch(`${this.baseUrl}/${conversationId}?${params}`);
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
     * Get conversation tokens
     * @param {string} conversationId - The ID of the conversation
     * @returns {Promise<number>} - The conversation tokens
     */
    async getConversationTokens(conversationId) {
        try {
            const response = await fetch(`${this.baseUrl}/${conversationId}/tokens`);
            if (!response.ok) {
                const error = await response.json();
                throw new Error(error.error || 'Failed to fetch conversation tokens');
            }
            return await response.json();
        } catch (error) {
            console.error('Error fetching conversation tokens:', error);
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

    /**
     * Get credit balance
     * @returns {Promise<number>} - The credit balance
     */
    async getCreditBalance() {
        try {
            const response = await fetch(`${this.baseUrl}/credit-balance`);
            if (!response.ok) {
                const error = await response.json();
                throw new Error(error.error || 'Failed to fetch credit balance');
            }
            return await response.json();
        } catch (error) {
            console.error('Error fetching credit balance:', error);
            throw error;
        }
    }

    /**
     * Get setting
     * @param {string} key - The key of the setting
     * @returns {string} - The setting value
     */
    async getSetting(key) {
        try {
            const response = await fetch(`/api/settings/${key}`);
            if (!response.ok) {
                const error = await response.json();
                throw new Error(error.error || 'Failed to fetch setting');
            }
            return await response.json().value;
        } catch (error) {
            console.error('Error fetching setting:', error);
            throw error;
        }
    }

    /**
     * Pre-send: run Reflexes recall, cache system prompt, return recalled nodes
     * Phase 3.5: Separates recall from AI send for visual feedback
     * @param {string} conversationId - The ID of the conversation
     * @param {string} messageText - The raw message text (not saved yet)
     * @returns {Promise<Object>} - Response with recalledNodes, keywords, packInfo, cached
     */
    async preSend(conversationId, messageText) {
        try {
            const response = await fetch(`${this.baseUrl}/${conversationId}/pre-send`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                credentials: 'include',
                body: JSON.stringify({ message: messageText })
            });
            
            if (!response.ok) {
                const error = await response.json();
                throw new Error(error?.error || 'Pre-send failed');
            }
            
            return await response.json();
        } catch (error) {
            console.error('Error in pre-send:', error);
            throw error;
        }
    }

    /**
     * Send a message (returns immediately without executing tools)
     * @param {string} conversationId - The ID of the conversation
     * @param {string|Array} message - The message to send
     * @param {number} maxOutput - The maximum output tokens for the AI response
     * @returns {Promise<Object>} - Response with message, type, toolCalls, requiresToolExecution
     */
    async sendMessageAsync(conversationId, message, maxOutput = 500, temperature = 0.7) {
        try {
            const response = await fetch(`${this.baseUrl}/${conversationId}/send-async`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                credentials: 'include',
                body: JSON.stringify({ 
                    message,
                    max_output: maxOutput,
                    temperature
                })
            });
            
            if (!response.ok) {
                const error = await response.json();
                throw new Error(error?.error || 'Failed to send message');
            }
            
            return await response.json();
        } catch (error) {
            console.error('Error sending message async:', error);
            throw error;
        }
    }

    /**
     * Execute tools and get AI's next response
     * @param {string} conversationId - The ID of the conversation
     * @param {string} assistantMessageId - The ID of the assistant message that requested tools
     * @param {Array} toolCalls - The tool calls to execute
     * @returns {Promise<Object>} - Response with message, type, toolCalls, toolResults, requiresToolExecution
     */
    async executeTools(conversationId, assistantMessageId, toolCalls, maxOutput = 500, temperature = 0.7) {
        try {
            const response = await fetch(`${this.baseUrl}/${conversationId}/execute-tools`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                credentials: 'include',
                body: JSON.stringify({
                    assistantMessageId,
                    toolCalls,
                    max_output: maxOutput,
                    temperature
                })
            });
            
            if (!response.ok) {
                const error = await response.json();
                throw new Error(error?.error || 'Failed to execute tools');
            }
            
            return await response.json();
        } catch (error) {
            console.error('Error executing tools:', error);
            throw error;
        }
    }

    /**
     * Stop tool execution chain
     * @param {string} conversationId - The ID of the conversation
     * @returns {Promise<Object>} - Success status
     */
    async stopExecution(conversationId) {
        try {
            const response = await fetch(`${this.baseUrl}/${conversationId}/stop-execution`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                credentials: 'include'
            });
            
            if (!response.ok) {
                const error = await response.json();
                throw new Error(error?.error || 'Failed to stop execution');
            }
            
            return await response.json();
        } catch (error) {
            console.error('Error stopping execution:', error);
            throw error;
        }
    }
}
