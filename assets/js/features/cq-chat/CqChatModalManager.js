import { CqChatApiService } from './CqChatApiService.js';
import * as bootstrap from 'bootstrap';

/**
 * CQ Chat Modal Manager
 * Manages the CQ Chat modal and dropdown functionality
 */
export class CqChatModalManager {
    constructor() {
        this.apiService = new CqChatApiService();
        this.currentChatId = null;
        this.currentChat = null;
        this.chats = [];
        this.pollingInterval = null;
        this.lastMessageCount = 0;
        this.hasMarkedSeen = false;
        
        // DOM elements
        this.modal = document.getElementById('cqChatModal');
        this.modalTitle = document.getElementById('cqChatModalTitle');
        this.messagesContainer = document.getElementById('cqChatMessages');
        this.messageForm = document.getElementById('cqChatForm');
        this.messageInput = document.getElementById('cqChatMessageInput');
        this.sendBtn = document.getElementById('cqChatSendBtn');
        
        // Dropdown elements
        this.dropdownList = document.getElementById('cqChatDropdownList');
        this.newChatBtn = document.getElementById('cqChatNewChatBtn');
        
        // New chat modal elements
        this.newChatModal = document.getElementById('cqChatNewChatModal');
        this.newChatContactSelect = document.getElementById('cqChatNewChatContact');
        this.newChatConfirmBtn = document.getElementById('cqChatNewChatConfirmBtn');
        
        this.init();
    }
    
    init() {
        if (!this.modal) return;
        
        // Load chats for dropdown
        this.loadChatsForDropdown();
        
        // Event listeners
        this.messageForm?.addEventListener('submit', (e) => this.handleSendMessage(e));
        this.newChatBtn?.addEventListener('click', () => this.showNewChatModal());
        this.newChatConfirmBtn?.addEventListener('click', () => this.createNewChat());
        
        // Modal events
        this.modal.addEventListener('shown.bs.modal', () => {
            this.messageInput?.focus();
            this.startPolling();
        });
        
        this.modal.addEventListener('hidden.bs.modal', () => {
            this.stopPolling();
        });
        
        // Start polling for dropdown updates
        this.startDropdownPolling();
    }
    
    /**
     * Load chats for the dropdown list (combined with unseen count)
     */
    async loadChatsForDropdown() {
        try {
            // Use combined endpoint that returns both chats and unseen count
            const response = await fetch('/api/cq-chat/dropdown');
            if (!response.ok) {
                throw new Error('Failed to load chats');
            }
            
            const data = await response.json();
            this.chats = data.chats || [];
            
            // Update unseen count badge
            this.updateUnseenBadge(data.unseenCount || 0);
            
            this.renderDropdownList(this.chats);
        } catch (error) {
            console.error('Error loading chats:', error);
            this.dropdownList.innerHTML = `
                <div class="text-center p-3 text-danger">
                    <small>${error.message}</small>
                </div>
            `;
        }
    }
    
    /**
     * Update unseen count badge
     */
    updateUnseenBadge(count) {
        const badge = document.getElementById('cqChatUnseenCountBadge');
        if (badge) {
            badge.textContent = count > 0 ? count : '';
        }
    }
    
    /**
     * Render the dropdown list
     */
    renderDropdownList(chats) {
        if (!this.dropdownList) return;
        
        if (chats.length === 0) {
            this.dropdownList.innerHTML = `
                <div class="text-center p-3 text-muted">
                    <small>${window.translations?.['cq_chat.no_chats'] || 'No chats yet'}</small>
                </div>
            `;
            return;
        }
        
        this.dropdownList.innerHTML = '';
        
        chats.forEach(chat => {
            const item = document.createElement('a');
            item.href = '#';
            item.className = 'list-group-item list-group-item-action bg-transparent text-light border-0 py-2';
            item.dataset.chatId = chat.id;
            
            const contactName = chat.contact?.cqContactUsername || chat.contact?.name || chat.contact?.citadelAddress || 'Unknown';
            const lastMessage = chat.lastMessage?.content || '';
            const hasUnread = chat.unreadCount > 0;
            
            item.innerHTML = `
                <div class="d-flex justify-content-between align-items-start">
                    <div class="flex-grow-1">
                        <div class="d-flex align-items-center">
                            <strong class="${hasUnread ? 'text-cyber' : ''}">${contactName}</strong><span class="opacity-50 small">@${chat.contact?.cqContactDomain || ''}</span>
                            ${hasUnread ? `<span class="badge bg-cyber text-dark ms-2">${chat.unreadCount}</span>` : ''}
                        </div>
                        <small class="text-muted text-truncate d-block" style="max-width: 250px;">${lastMessage}</small>
                    </div>
                </div>
            `;
            
            item.addEventListener('click', (e) => {
                e.preventDefault();
                this.openChat(chat.id);
                // Close dropdown
                const dropdown = bootstrap.Dropdown.getInstance(document.getElementById('cqChatDropdown'));
                dropdown?.hide();
            });
            
            this.dropdownList.appendChild(item);
        });
    }
    
    /**
     * Open a chat in the modal
     */
    async openChat(chatId) {
        this.currentChatId = chatId;
        this.lastMessageCount = 0;
        this.hasMarkedSeen = false;
        
        // Show modal
        const modalInstance = new bootstrap.Modal(this.modal);
        modalInstance.show();
        
        // Load chat data
        await this.loadChat(chatId);
    }
    
    /**
     * Load chat data
     */
    async loadChat(chatId) {
        try {
            const chat = await this.apiService.getChat(chatId);
            this.currentChat = chat;
            
            // Update modal title with username and domain
            const contactName = chat.contact?.cqContactUsername || chat.contact?.name || 'Unknown';
            const contactDomain = chat.contact?.cqContactDomain || '';
            this.modalTitle.innerHTML = contactDomain ? `<span class="fw-bold">${contactName}</span><span class="opacity-50">@${contactDomain}</span>` : contactName;
            
            // Load messages
            await this.loadMessages(chatId);
        } catch (error) {
            console.error('Error loading chat:', error);
            window.toast?.error(error.message);
        }
    }
    
    /**
     * Load messages for current chat
     */
    async loadMessages(chatId) {
        try {
            const response = await this.apiService.getMessages(chatId);
            const messages = response.messages || [];
            
            // Check if there are new messages
            const hasNewMessages = messages.length > this.lastMessageCount;
            this.lastMessageCount = messages.length;
            
            this.renderMessages(messages);
            
            // Mark messages as seen only if:
            // 1. We haven't marked them yet (first load), OR
            // 2. There are new messages
            if (!this.hasMarkedSeen || hasNewMessages) {
                await this.markMessagesAsSeen(chatId);
                this.hasMarkedSeen = true;
            }
        } catch (error) {
            console.error('Error loading messages:', error);
            this.messagesContainer.innerHTML = `
                <div class="alert alert-danger">
                    ${error.message}
                </div>
            `;
        }
    }
    
    /**
     * Mark messages as seen
     */
    async markMessagesAsSeen(chatId) {
        try {
            await fetch(`/api/cq-chat/${chatId}/mark-seen`, {
                method: 'POST'
            });
        } catch (error) {
            console.error('Error marking messages as seen:', error);
        }
    }
    
    /**
     * Render messages
     */
    renderMessages(messages) {
        this.messagesContainer.innerHTML = '';
        
        if (messages.length === 0) {
            this.messagesContainer.innerHTML = `
                <div class="text-center text-muted p-4">
                    <i class="mdi mdi-message-outline fs-1"></i>
                    <p>${window.translations?.['cq_chat.no_messages'] || 'No messages yet'}</p>
                </div>
            `;
            return;
        }
        
        // Sort messages by creation time (oldest first)
        messages.sort((a, b) => new Date(a.createdAt || a.created_at) - new Date(b.createdAt || b.created_at));
        
        messages.forEach(message => {
            const messageEl = this.createMessageElement(message);
            this.messagesContainer.appendChild(messageEl);
        });
        
        // Scroll to bottom
        this.messagesContainer.scrollTop = this.messagesContainer.scrollHeight;
    }
    
    /**
     * Create message element (matching original CQ Chat styling)
     */
    createMessageElement(message) {
        const div = document.createElement('div');
        
        // Determine if outgoing (sent by current user)
        const isOutgoing = !message.cqContactId && !message.cq_contact_id;
        const messageClass = isOutgoing ? 'chat-message-user' : 'chat-message-assistant';
        
        // Get status icon
        const status = message.status || 'SENT';
        let statusIcon = '';
        if (isOutgoing) {
            if (status === 'SEEN') {
                statusIcon = '<i class="mdi mdi-eye text-cyber ms-1" title="Seen"></i>';
            } else if (status === 'DELIVERED') {
                statusIcon = '<i class="mdi mdi-check-all text-cyber ms-1" title="Delivered"></i>';
            } else if (status === 'SENT') {
                statusIcon = '<i class="mdi mdi-check text-muted ms-1" title="Sent"></i>';
            } else if (status === 'FAILED') {
                statusIcon = '<i class="mdi mdi-alert-circle text-danger ms-1" title="Failed"></i>';
            }
        } else {
            if (status === 'SEEN') {
                statusIcon = '<i class="mdi mdi-eye ms-1" title="Seen"></i>';
            } else if (status === 'RECEIVED') {
                statusIcon = '<i class="mdi mdi-circle-small text-cyber ms-1" title="Unread"></i>';
            }
        }
        
        // Format time
        const createdAt = message.createdAt || message.created_at;
        const messageTime = new Date(createdAt).toLocaleTimeString('sk-SK', { 
            hour: '2-digit', 
            minute: '2-digit', 
            timeZone: 'Europe/Prague' 
        });
        
        // Get contact name from current chat
        const contactName = this.currentChat?.contact?.cqContactUsername || 'Contact';
        const userName = document.querySelector('.js-user')?.dataset?.username || 'You';
        
        const nameDisplay = isOutgoing 
            ? `<div class="text-end"><small class="text-cyber">${userName}</small></div>` 
            : `<div><small class="text-cyber">${contactName}</small></div>`;
        
        div.className = `chat-message ${messageClass}`;
        div.dataset.messageId = message.id;
        div.innerHTML = `
            <div class="chat-bubble">
                ${nameDisplay}
                <div class="chat-content">${this.escapeHtml(message.content)}</div>
                <div class="chat-timestamp">${messageTime}${statusIcon}</div>
            </div>
        `;
        
        return div;
    }
    
    /**
     * Handle send message
     */
    async handleSendMessage(e) {
        e.preventDefault();
        
        const content = this.messageInput.value.trim();
        if (!content || !this.currentChatId) return;
        
        try {
            this.sendBtn.disabled = true;
            
            await this.apiService.sendMessage(this.currentChatId, content);
            
            this.messageInput.value = '';
            await this.loadMessages(this.currentChatId);
            
        } catch (error) {
            console.error('Error sending message:', error);
            window.toast?.error(error.message);
        } finally {
            this.sendBtn.disabled = false;
            this.messageInput.focus();
        }
    }
    
    /**
     * Show new chat modal
     */
    async showNewChatModal() {
        try {
            // Load contacts
            const contacts = await this.apiService.getContacts();
            
            this.newChatContactSelect.innerHTML = '<option value="">Select a contact...</option>';
            contacts.forEach(contact => {
                const option = document.createElement('option');
                option.value = contact.id;
                console.log('contact', contact);
                const contactName = contact.cqContactUsername || contact.name || 'Unknown';
                const contactDomain = contact.cqContactDomain || '';
                option.textContent = contactName + (contactDomain ? ` (${contactDomain})` : '');
                this.newChatContactSelect.appendChild(option);
            });
            
            // Show modal
            const modalInstance = new bootstrap.Modal(this.newChatModal);
            modalInstance.show();
            
        } catch (error) {
            console.error('Error loading contacts:', error);
            window.toast?.error(error.message);
        }
    }
    
    /**
     * Create new chat
     */
    async createNewChat() {
        const contactId = this.newChatContactSelect.value;
        if (!contactId) return;
        
        try {
            // Close new chat modal
            const modalInstance = bootstrap.Modal.getInstance(this.newChatModal);
            modalInstance?.hide();
            
            // Reload chats
            await this.loadChatsForDropdown();
            
            // Find and open the new chat
            const chat = this.chats.find(c => c.contact?.id === contactId);
            if (chat) {
                this.openChat(chat.id);
            }
            
        } catch (error) {
            console.error('Error creating chat:', error);
            window.toast?.error(error.message);
        }
    }
    
    /**
     * Start polling for new messages
     */
    startPolling() {
        if (this.pollingInterval) return;
        
        this.pollingInterval = setInterval(() => {
            if (this.currentChatId) {
                this.loadMessages(this.currentChatId);
            }
        }, 3000);
    }
    
    /**
     * Stop polling
     */
    stopPolling() {
        if (this.pollingInterval) {
            clearInterval(this.pollingInterval);
            this.pollingInterval = null;
        }
    }
    
    /**
     * Start dropdown polling
     */
    startDropdownPolling() {
        setInterval(() => {
            this.loadChatsForDropdown();
        }, 10000); // Every 10 seconds
    }
    
    /**
     * Utility: Escape HTML
     */
    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    
    /**
     * Utility: Format date
     */
    formatDate(dateString) {
        const date = new Date(dateString);
        return date.toLocaleString();
    }
}
