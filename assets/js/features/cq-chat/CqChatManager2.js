import { CqChatApiService } from './CqChatApiService';
import * as bootstrap from 'bootstrap';

/**
 * CQ Chat Manager 2 - Clean, working implementation
 */
export class CqChatManager2 {
    constructor() {
        this.apiService = new CqChatApiService();
        this.contacts = [];
        this.currentChatId = null;
        this.currentContact = null;
        this.chats = [];
        this.pollingInterval = null;
        this.lastMessageTimestamp = null;
        
        // DOM Elements
        this.modal = null;
        this.chatsList = null;
        this.chatMessages = null;
        this.messageInput = null;
        this.chatForm = null;
        this.chatSearch = null;
        this.currentChatTitle = null;
        
        this.init();
    }
    
    async init() {
        this.setupDOM();
        this.setupEventListeners();
        await this.loadContacts();
        await this.loadChats();
    }
    
    setupDOM() {
        this.modal = document.getElementById('cqChatModal2');
        this.chatsList = document.getElementById('chatsList2');
        this.chatMessages = document.getElementById('chatMessages2');
        this.messageInput = document.getElementById('messageInput2');
        this.chatForm = document.getElementById('chatForm2');
        this.chatSearch = document.getElementById('chatSearch2');
        this.currentChatTitle = document.getElementById('currentChatTitle2');
        
        if (!this.modal) {
            console.error('CQ Chat Modal 2 not found');
            return;
        }
        
        // Initialize Bootstrap modal
        this.modalInstance = new bootstrap.Modal(this.modal);
    }
    
    setupEventListeners() {
        // Modal events
        if (this.modal) {
            this.modal.addEventListener('shown.bs.modal', () => {
                this.startPolling();
            });
            
            this.modal.addEventListener('hidden.bs.modal', () => {
                this.stopPolling();
            });
        }
        
        // Chat form submission
        if (this.chatForm) {
            this.chatForm.addEventListener('submit', (e) => {
                e.preventDefault();
                this.sendMessage();
            });
        }

        // Send on `Ctrl + Enter`
        if (this.messageInput) {
            this.messageInput.addEventListener('keydown', (e) => {
                if (e.key === 'Enter' && e.ctrlKey) {
                    e.preventDefault();
                    this.sendMessage();
                }
            });
        }
        
        // Search functionality
        if (this.chatSearch) {
            this.chatSearch.addEventListener('input', () => {
                this.filterChats();
            });
        }
    }
    
    async loadContacts() {
        try {
            const contacts = await this.apiService.getContacts();
            this.contacts = contacts;
        } catch (error) {
            console.error('Error loading contacts:', error);
        }
    }
    
    async loadChats() {
        if (!this.chatsList) return;
        
        try {
            this.chatsList.innerHTML = '<div class="text-center p-3"><div class="spinner-border text-cyber"></div></div>';
            
            const chats = await this.apiService.getChats();
            this.chats = chats;
            
            this.renderChats();
        } catch (error) {
            console.error('Error loading chats:', error);
            this.chatsList.innerHTML = '<div class="alert alert-danger">Failed to load chats</div>';
        }
    }
    
    renderChats() {
        if (!this.chatsList) return;
        
        if (this.chats.length === 0) {
            this.chatsList.innerHTML = '<div class="text-center p-3 text-muted">No chats found</div>';
            return;
        }
        
        let html = '';
        this.chats.forEach(chat => {
            const isActive = chat.id === this.currentChatId ? 'active' : '';
            html += `
                <div class="chat2-item ${isActive}" data-chat-id="${chat.id}">
                    <div class="chat2-item-content">
                        <h6 class="chat2-title">${chat.title}</h6>
                        <p class="chat2-summary">${chat.summary || ''}</p>
                        <small class="chat2-time">${new Date(chat.updatedAt).toLocaleString()}</small>
                    </div>
                </div>
            `;
        });
        
        this.chatsList.innerHTML = html;
        
        // Add click events
        this.chatsList.querySelectorAll('.chat2-item').forEach(item => {
            item.addEventListener('click', () => {
                const chatId = item.dataset.chatId;
                this.loadChat(chatId);
            });
        });
    }
    
    async loadChat(chatId) {
        if (!this.chatMessages) return;

        this.currentChatId = chatId;
        
        // Update active chat in list
        this.chatsList.querySelectorAll('.chat2-item').forEach(item => {
            item.classList.toggle('active', item.dataset.chatId === chatId);
        });
        
        // Update chat title
        const chat = this.chats.find(c => c.id === chatId);
        if (chat && this.currentChatTitle) {
            this.currentChatTitle.textContent = chat.title;
        }

        this.currentContact = this.contacts.find(c => c.id === chat.cqContactId);
        
        // Show loading
        this.chatMessages.innerHTML = '<div class="text-center p-3"><div class="spinner-border text-cyber"></div></div>';
        
        try {
            const response = await this.apiService.getMessages(chatId);
            this.renderMessages(response.messages || []);
            this.startPolling();
        } catch (error) {
            console.error('Error loading messages:', error);
            this.chatMessages.innerHTML = '<div class="alert alert-danger">Failed to load messages</div>';
        }
    }
    
    renderMessages(messages) {
        if (!this.chatMessages) return;
        
        if (messages.length === 0) {
            this.chatMessages.innerHTML = '<div class="text-center p-3 text-muted">No messages yet</div>';
            return;
        }
        
        // Sort messages by creation time
        messages.sort((a, b) => new Date(a.createdAt) - new Date(b.createdAt));
        
        let html = '';
        messages.forEach(message => {
            //const isOutgoing = message.cqContactId === null;
            const isOutgoing = message.status === 'DELIVERED';
            let statusIcon = '';
            if (isOutgoing) {
                if (message.status === 'DELIVERED') {
                    statusIcon = '<i class="mdi mdi-check-all text-cyber ms-1" title="{{ "cq_chat.delivered"|trans }}"></i>';
                } else if (message.status === 'SENT') {
                    statusIcon = '<i class="mdi mdi-check text-muted ms-1" title="{{ "cq_chat.sent"|trans }}"></i>';
                } else if (message.status === 'FAILED') {
                    statusIcon = '<i class="mdi mdi-alert-circle text-danger ms-1" title="{{ "cq_chat.failed"|trans }}"></i>';
                }
            }
                
            const messageClass = isOutgoing ? 'message-user' : 'message-contact';
            const time = new Date(message.createdAt).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
            
            // Get sender name
            const senderName = isOutgoing ? window.appUsername : (this.currentContact ? this.currentContact.cqContactUsername : 'Contact');
            
            html += `
                <div class="message ${messageClass}">
                    <div class="message-header">
                        <span class="message-sender">${senderName}</span>
                    </div>
                    <div class="message-bubble">
                        <div class="message-content">${message.content}</div>
                        <div class="message-time">${time}${statusIcon}</div>
                    </div>
                </div>
            `;
        });
        
        this.chatMessages.innerHTML = html;
        this.scrollToBottom();
    }
    
    scrollToBottom() {
        if (this.chatMessages) {
            this.chatMessages.scrollTop = this.chatMessages.scrollHeight;
        }
    }
    
    async sendMessage() {
        if (!this.currentChatId || !this.messageInput) return;
        
        const content = this.messageInput.value.trim();
        if (!content) return;
        
        try {
            // Clear input immediately
            this.messageInput.value = '';
            
            // Add message to UI optimistically
            const tempMessage = {
                content: content,
                createdAt: new Date().toISOString(),
                cqContactId: null
            };
            
            // Get current messages and add the new one
            const currentMessages = this.getCurrentMessages();
            currentMessages.push(tempMessage);
            this.renderMessages(currentMessages);
            
            // Send to API
            await this.apiService.sendMessage(this.currentChatId, content);
            
            // Reload messages to get the actual response
            setTimeout(() => this.reloadCurrentChat(), 1000);
            
        } catch (error) {
            console.error('Error sending message:', error);
            if (window.toast) {
                window.toast.error('Failed to send message');
            }
        }
    }
    
    getCurrentMessages() {
        const messages = [];
        this.chatMessages.querySelectorAll('.message').forEach(msgEl => {
            const content = msgEl.querySelector('.message-content').textContent;
            const time = msgEl.querySelector('.message-time').textContent;
            const isUser = msgEl.classList.contains('message-user');
            
            messages.push({
                content: content,
                createdAt: new Date().toISOString(), // Approximate
                cqContactId: isUser ? null : 'contact'
            });
        });
        return messages;
    }
    
    async reloadCurrentChat() {
        if (this.currentChatId) {
            const response = await this.apiService.getMessages(this.currentChatId);
            this.renderMessages(response.messages || []);
        }
    }
    
    filterChats() {
        if (!this.chatSearch || !this.chatsList) return;
        
        const searchTerm = this.chatSearch.value.toLowerCase();
        const chatItems = this.chatsList.querySelectorAll('.chat2-item');
        
        chatItems.forEach(item => {
            const title = item.querySelector('.chat2-title').textContent.toLowerCase();
            const summary = item.querySelector('.chat2-summary').textContent.toLowerCase();
            
            if (title.includes(searchTerm) || summary.includes(searchTerm)) {
                item.style.display = 'block';
            } else {
                item.style.display = 'none';
            }
        });
    }
    
    startPolling() {
        this.stopPolling();
        
        if (this.currentChatId) {
            this.pollingInterval = setInterval(() => {
                this.reloadCurrentChat();
            }, 3000);
        }
    }
    
    stopPolling() {
        if (this.pollingInterval) {
            clearInterval(this.pollingInterval);
            this.pollingInterval = null;
        }
    }
    
    show() {
        if (this.modalInstance) {
            this.modalInstance.show();
        }
    }
    
    hide() {
        if (this.modalInstance) {
            this.modalInstance.hide();
        }
    }
}
