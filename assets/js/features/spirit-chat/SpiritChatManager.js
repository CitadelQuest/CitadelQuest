import { SpiritChatApiService } from './SpiritChatApiService';
import * as bootstrap from 'bootstrap';

/**
 * Spirit Chat Manager
 * Manages the Spirit chat UI and interactions
 */
export class SpiritChatManager {
    constructor() {
        this.apiService = new SpiritChatApiService();
        this.currentSpiritId = null;
        this.currentConversationId = null;
        this.isLoadingMessages = false;
        
        // DOM Elements
        this.spiritIcon = document.getElementById('spiritIcon');
        this.spiritChatModal = document.getElementById('spiritChatModal');
        this.spiritChatButton = document.getElementById('spiritChatButton');
        this.spiritName = document.getElementById('spiritName');
        this.spiritLevel = document.getElementById('spiritLevel');
        this.spiritChatAvatar = document.getElementById('spiritChatAvatar');
        this.conversationsList = document.getElementById('conversationsList');
        this.chatContainer = document.getElementById('chatContainer');
        this.chatMessages = document.getElementById('chatMessages');
        this.chatForm = document.getElementById('chatForm');
        this.messageInput = document.getElementById('messageInput');
        this.newConversationBtn = document.getElementById('newConversationBtn');
        this.newConversationModal = document.getElementById('newConversationModal');
        this.newConversationForm = document.getElementById('newConversationForm');
        this.conversationTitle = document.getElementById('conversationTitle');
    }
    
    /**
     * Initialize the Spirit chat functionality
     */
    init() {
        if (!this.spiritIcon) return;
        
        // Initialize event listeners
        this.initEventListeners();
        
        // Fetch the user's primary spirit
        this.fetchPrimarySpirit();
    }
    
    /**
     * Initialize event listeners
     */
    initEventListeners() {
        // Spirit chat modal events
        if (this.spiritChatModal) {
            this.spiritChatModal.addEventListener('shown.bs.modal', () => {
                this.loadConversations();
            });
        }
        
        // Chat form submission
        if (this.chatForm) {
            this.chatForm.addEventListener('submit', (e) => {
                e.preventDefault();
                this.sendMessage();
            });
        }
        
        // New conversation button
        if (this.newConversationBtn) {
            this.newConversationBtn.addEventListener('click', () => {
                const newConversationModal = new bootstrap.Modal(this.newConversationModal);
                newConversationModal.show();
            });
        }
        
        // New conversation form
        if (this.newConversationForm) {
            this.newConversationForm.addEventListener('submit', (e) => {
                e.preventDefault();
                this.createNewConversation();
            });
        }
    }
    
    /**
     * Fetch the user's primary spirit
     */
    async fetchPrimarySpirit() {
        try {
            const response = await fetch('/api/spirit');
            if (!response.ok) {
                throw new Error('Failed to fetch primary spirit');
            }
            
            const spirit = await response.json();
            this.currentSpiritId = spirit.id;
            
            // Update UI with spirit info
            if (this.spiritName) {
                this.spiritName.textContent = spirit.name;
            }
            
            if (this.spiritLevel) {
                const levelText = window.translations && window.translations['spirit.level'] ? window.translations['spirit.level'] : 'Level';
                this.spiritLevel.textContent = `${levelText}: ${spirit.level}`;
            }
            
            // Initialize 3D avatar if available
            if (this.spiritChatAvatar && window.SpiritVisualizer) {
                const visualizer = new window.SpiritVisualizer(this.spiritChatAvatar, {
                    size: 120,
                    visualState: spirit.visualState,
                    level: spirit.level
                });
                visualizer.init();
            }
            
        } catch (error) {
            console.error('Error fetching primary spirit:', error);
            // Show error message
            window.toast.error(error.message || 'Failed to load spirit information');
        }
    }
    
    /**
     * Load conversations for the current spirit
     */
    async loadConversations() {
        if (!this.currentSpiritId || !this.conversationsList) return;
        
        try {
            // Show loading indicator
            this.conversationsList.innerHTML = `
                <div class="text-center p-3">
                    <div class="spinner-border text-cyber" role="status">
                        <span class="visually-hidden">${window.translations && window.translations['loading'] ? window.translations['loading'] : 'Loading...'}</span>
                    </div>
                </div>
            `;
            
            const conversations = await this.apiService.getConversations(this.currentSpiritId);
            
            // Clear loading and render conversations
            this.conversationsList.innerHTML = '';
            
            if (conversations.length === 0) {
                this.conversationsList.innerHTML = `
                    <div class="text-center p-3">
                        <p>${window.translations && window.translations['spirit.chat.no_conversations'] ? window.translations['spirit.chat.no_conversations'] : 'No conversations yet'}</p>
                    </div>
                `;
                return;
            }
            
            // Render each conversation
            conversations.forEach(conversation => {
                const item = document.createElement('a');
                item.href = '#';
                item.className = 'list-group-item list-group-item-action d-flex justify-content-between align-items-center';
                item.dataset.id = conversation.id;
                
                // Format date
                const date = new Date(conversation.lastInteraction);
                const formattedDate = date.toLocaleDateString();
                
                item.innerHTML = `
                    <div>
                        <div class="fw-bold text-secondary">${conversation.title}</div>
                        <small class="text-muted">${formattedDate}</small>
                    </div>
                    <span class="badge bg-cyber rounded-pill">${conversation.messages.length}</span>
                `;
                
                item.addEventListener('click', (e) => {
                    e.preventDefault();
                    this.loadConversation(conversation.id);
                });
                
                this.conversationsList.appendChild(item);
            });
            
        } catch (error) {
            console.error('Error loading conversations:', error);
            this.conversationsList.innerHTML = `
                <div class="alert alert-danger">
                    ${error.message || 'Failed to load conversations'}
                </div>
            `;
        }
    }
    
    /**
     * Load a specific conversation
     * @param {string} conversationId - The ID of the conversation to load
     */
    async loadConversation(conversationId) {
        if (this.isLoadingMessages) return;
        this.isLoadingMessages = true;
        
        try {
            // Show chat container
            if (this.chatContainer) {
                this.chatContainer.classList.remove('d-none');
            }
            
            // Show loading indicator
            if (this.chatMessages) {
                this.chatMessages.innerHTML = `
                    <div class="text-center p-3 loading-indicator">
                        <div class="spinner-border text-cyber" role="status">
                            <span class="visually-hidden">${window.translations && window.translations['loading'] ? window.translations['loading'] : 'Loading...'}</span>
                        </div>
                    </div>
                `;
            }
            
            // Update active conversation in list
            if (this.conversationsList) {
                const items = this.conversationsList.querySelectorAll('.list-group-item');
                items.forEach(item => {
                    item.classList.remove('active');
                    if (item.dataset.id === conversationId) {
                        item.classList.add('active');
                    }
                });
            }
            
            // Fetch conversation
            const conversation = await this.apiService.getConversation(conversationId);
            this.currentConversationId = conversationId;
            
            // Render messages
            this.renderMessages(conversation.messages);
            
        } catch (error) {
            console.error('Error loading conversation:', error);
            if (this.chatMessages) {
                this.chatMessages.innerHTML = `
                    <div class="alert alert-danger">
                        ${error.message || 'Failed to load conversation'}
                    </div>
                `;
            }
        } finally {
            this.isLoadingMessages = false;
        }
    }
    
    /**
     * Render messages in the chat container
     * @param {Array} messages - Array of message objects
     */
    renderMessages(messages) {
        if (!this.chatMessages) return;
        
        // Clear loading indicator
        this.chatMessages.innerHTML = '';
        
        if (messages.length === 0) {
            this.chatMessages.innerHTML = `
                <div class="text-center p-3">
                    <p>${window.translations && window.translations['spirit.chat.no_messages'] ? window.translations['spirit.chat.no_messages'] : 'No messages yet'}</p>
                </div>
            `;
            return;
        }
        
        // Render each message
        messages.forEach(message => {
            const messageEl = document.createElement('div');
            messageEl.className = `chat-message ${message.role === 'user' ? 'chat-message-user' : 'chat-message-assistant'}`;
            
            // Format timestamp
            let timestampHtml = '';
            if (message.timestamp) {
                const date = new Date(message.timestamp);
                const formattedTime = date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
                timestampHtml = `<div class="chat-timestamp">${formattedTime}</div>`;
            }
            
            messageEl.innerHTML = `
                <div class="chat-bubble">
                    <div class="chat-content">${this.formatMessageContent(message.content)}</div>
                    ${timestampHtml}
                </div>
            `;
            
            this.chatMessages.appendChild(messageEl);
        });
        
        // Scroll to bottom
        this.chatMessages.scrollTop = this.chatMessages.scrollHeight;
        
        // Focus input
        if (this.messageInput) {
            this.messageInput.focus();
        }
    }
    
    /**
     * Format message content with Markdown-like syntax
     * @param {string} content - The message content
     * @returns {string} - Formatted HTML content
     */
    formatMessageContent(content) {
        if (!content) return '';
        
        // Convert line breaks to <br>
        let formatted = content.replace(/\n/g, '<br>');
        
        // Convert **bold** to <strong>
        formatted = formatted.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
        
        // Convert *italic* to <em>
        formatted = formatted.replace(/\*(.+?)\*/g, '<em>$1</em>');
        
        // Convert `code` to <code>
        formatted = formatted.replace(/`(.+?)`/g, '<code>$1</code>');
        
        return formatted;
    }
    
    /**
     * Send a message in the current conversation
     */
    async sendMessage() {
        if (!this.currentConversationId || !this.messageInput || !this.messageInput.value.trim()) return;
        
        const message = this.messageInput.value.trim();
        this.messageInput.value = '';
        
        try {
            // Add user message to UI immediately
            const userMessage = {
                role: 'user',
                content: message,
                timestamp: new Date().toISOString()
            };
            
            if (this.chatMessages) {
                const messageEl = document.createElement('div');
                messageEl.className = 'chat-message chat-message-user';
                
                const date = new Date(userMessage.timestamp);
                const formattedTime = date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
                
                messageEl.innerHTML = `
                    <div class="chat-bubble">
                        <div class="chat-content">${this.formatMessageContent(userMessage.content)}</div>
                        <div class="chat-timestamp">${formattedTime}</div>
                    </div>
                `;
                
                this.chatMessages.appendChild(messageEl);
                this.chatMessages.scrollTop = this.chatMessages.scrollHeight;
            }
            
            // Add loading indicator for assistant response
            const loadingEl = document.createElement('div');
            loadingEl.className = 'chat-message chat-message-assistant';
            loadingEl.innerHTML = `
                <div class="chat-bubble">
                    <div class="chat-content">
                        <div class="spinner-border spinner-border-sm text-cyber" role="status">
                            <span class="visually-hidden">${window.translations && window.translations['loading'] ? window.translations['loading'] : 'Loading...'}</span>
                        </div>
                    </div>
                </div>
            `;
            
            if (this.chatMessages) {
                this.chatMessages.appendChild(loadingEl);
                this.chatMessages.scrollTop = this.chatMessages.scrollHeight;
            }
            
            // Send message to API
            const response = await this.apiService.sendMessage(this.currentConversationId, message);
            
            // Remove loading indicator
            if (this.chatMessages && loadingEl) {
                this.chatMessages.removeChild(loadingEl);
            }
            
            // Render updated messages
            this.renderMessages(response.messages);
            
            // Update conversation list to reflect changes
            this.loadConversations();
            
        } catch (error) {
            console.error('Error sending message:', error);
            window.toast.error(error.message || 'Failed to send message');
        }
    }
    
    /**
     * Create a new conversation
     */
    async createNewConversation() {
        if (!this.currentSpiritId || !this.conversationTitle || !this.conversationTitle.value.trim()) return;
        
        const title = this.conversationTitle.value.trim();
        
        try {
            // Create conversation
            const conversation = await this.apiService.createConversation(this.currentSpiritId, title);
            
            // Close modal
            const modal = bootstrap.Modal.getInstance(this.newConversationModal);
            if (modal) {
                modal.hide();
            }
            
            // Clear form
            this.conversationTitle.value = '';
            
            // Refresh conversations list
            this.loadConversations();
            
            // Load the new conversation
            this.loadConversation(conversation.id);
            
            // Show success message
            window.toast.success(window.translations && window.translations['spirit.chat.conversation_created'] ? window.translations['spirit.chat.conversation_created'] : 'Conversation created');
            
        } catch (error) {
            console.error('Error creating conversation:', error);
            window.toast.error(error.message || 'Failed to create conversation');
        }
    }
}
