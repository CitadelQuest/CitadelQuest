import { CqChatApiService } from './CqChatApiService.js';
import { updatesService } from '../../services/UpdatesService.js';
import * as bootstrap from 'bootstrap';

/**
 * CQ Chat Modal Manager
 * Manages the CQ Chat modal and dropdown functionality
 */
export class CqChatModalManager {
    constructor() {
        this.apiService = new CqChatApiService();
        this.updatesService = updatesService;
        this.currentChatId = null;
        this.currentChat = null;
        this.chats = [];
        this.lastMessageCount = 0;
        this.hasMarkedSeen = false;
        this.isInitialLoad = true;
        
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
        
        // New group chat modal elements
        this.newGroupModal = document.getElementById('cqChatNewGroupModal');
        this.groupNameInput = document.getElementById('cqChatGroupName');
        this.groupMembersList = document.getElementById('cqChatGroupMembersList');
        this.groupSelectedCount = document.getElementById('cqChatGroupSelectedCount');
        this.newGroupConfirmBtn = document.getElementById('cqChatNewGroupConfirmBtn');
        this.newGroupBtn = document.getElementById('cqChatNewGroupBtn');
        this.pageNewChatBtn = document.getElementById('cqChatPageNewChatBtn');
        this.selectedMembers = new Set();
        
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
        this.newGroupBtn?.addEventListener('click', () => this.showNewGroupModal());
        this.pageNewChatBtn?.addEventListener('click', () => this.showNewGroupModal());
        this.newGroupConfirmBtn?.addEventListener('click', () => this.createNewGroup());
        
        // Modal events
        this.modal.addEventListener('shown.bs.modal', () => {
            this.messageInput?.focus();
        });
        
        this.modal.addEventListener('hidden.bs.modal', () => {
            // Clear current chat ID so updates don't fetch detailed messages
            this.currentChatId = null;
        });

        this.messageInput.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' && e.ctrlKey) {
                e.preventDefault();
                this.messageForm.dispatchEvent(new Event('submit'));
            }
        });
        
        // Register listener for updates (polling starts in entry point)
        this.updatesService.addListener('cqChat', (updates) => {
            this.handleUpdates(updates);
        });
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
            
            const isGroup = chat.isGroupChat || false;
            const contactName = isGroup ? chat.title : (chat.contact?.cqContactUsername || chat.contact?.name || chat.contact?.citadelAddress || 'Unknown');
            const contactDomain = isGroup ? '' : `@${chat.contact?.cqContactDomain || ''}`;
            const groupIcon = isGroup ? '<i class="mdi mdi-account-multiple text-light me-1"></i>' : '';
            const lastMessage = chat.lastMessage?.content || '';
            const hasUnread = chat.unreadCount > 0;
            
            item.innerHTML = `
                <div class="d-flex justify-content-between align-items-start">
                    <div class="flex-grow-1">
                        <div class="d-flex align-items-center">
                            ${groupIcon}
                            <strong class="${hasUnread ? 'text-cyber' : ''}">${contactName}</strong><span class="opacity-50 small">${contactDomain}</span>
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
        this.isInitialLoad = true;
        
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
            
            // Update modal title - handle both direct and group chats
            const isGroup = chat.isGroupChat || false;
            if (isGroup) {
                const groupIcon = '<i class="mdi mdi-account-multiple text-light me-2"></i>';
                this.modalTitle.innerHTML = `${groupIcon}<span class="fw-bold">${chat.title}</span>`;
            } else {
                const contactName = chat.contact?.cqContactUsername || chat.contact?.name || 'Unknown';
                const contactDomain = chat.contact?.cqContactDomain || '';
                this.modalTitle.innerHTML = contactDomain ? `<span class="fw-bold">${contactName}</span><span class="opacity-50">@${contactDomain}</span>` : contactName;
            }
            
            // Load messages
            await this.loadMessages(chatId);
        } catch (error) {
            console.error('Error loading chat:', error);
            window.toast?.error(error.message);
        }
    }
    
    /**
     * Load messages for current chat (initial load only)
     */
    async loadMessages(chatId) {
        try {
            // Initial load - fetch all messages
            const response = await this.apiService.getMessages(chatId);
            const messages = response.messages || [];
            
            // Render all messages
            this.renderMessages(messages);
            
            // Mark messages as seen on initial load
            if (!this.hasMarkedSeen) {
                await this.markMessagesAsSeen(chatId);
                this.hasMarkedSeen = true;
            }
            
            this.isInitialLoad = false;
            this.lastMessageCount = this.messagesContainer.querySelectorAll('.chat-message').length;
            
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
            
            // Reset timestamp to force immediate update on next poll
            // This ensures status changes are picked up quickly
            this.updatesService.resetTimestamp();
        } catch (error) {
            console.error('Error marking messages as seen:', error);
        }
    }
    
    /**
     * Render messages (full reload)
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
     * Update message statuses in existing DOM elements
     */
    updateMessageStatuses(statusUpdates) {
        statusUpdates.forEach(update => {
            const messageEl = this.messagesContainer.querySelector(`[data-message-id="${update.id}"]`);
            if (messageEl) {
                // Find and update the status icon
                const timestampEl = messageEl.querySelector('.chat-timestamp');
                if (timestampEl) {
                    // Get the time part (before the icon)
                    const timeText = timestampEl.textContent.trim().split(' ')[0];
                    
                    // Generate new status icon
                    let statusIcon = '';
                    const status = update.status;
                    if (status === 'SEEN') {
                        statusIcon = '<i class="mdi mdi-eye text-cyber ms-1" title="Seen"></i>';
                    } else if (status === 'DELIVERED') {
                        statusIcon = '<i class="mdi mdi-check-all text-cyber ms-1" title="Delivered"></i>';
                    } else if (status === 'SENT') {
                        statusIcon = '<i class="mdi mdi-check text-muted ms-1" title="Sent"></i>';
                    } else if (status === 'FAILED') {
                        statusIcon = '<i class="mdi mdi-alert-circle text-danger ms-1" title="Failed"></i>';
                    }
                    
                    // Update the timestamp element with new icon
                    timestampEl.innerHTML = timeText + statusIcon;
                }
            }
        });
    }
    
    /**
     * Append new messages (incremental update)
     */
    appendMessages(messages) {
        if (messages.length === 0) return;
        
        // Check if user is scrolled near bottom
        const isNearBottom = this.messagesContainer.scrollHeight - this.messagesContainer.scrollTop - this.messagesContainer.clientHeight < 100;
        
        // Sort messages by creation time (oldest first)
        messages.sort((a, b) => new Date(a.createdAt || a.created_at) - new Date(b.createdAt || b.created_at));
        
        // Track if we added any new incoming messages
        let hasNewIncomingMessages = false;
        
        // Append each new message
        messages.forEach(message => {
            // Check if message already exists (by ID)
            const existingMessage = this.messagesContainer.querySelector(`[data-message-id="${message.id}"]`);
            if (!existingMessage) {
                const messageEl = this.createMessageElement(message);
                this.messagesContainer.appendChild(messageEl);
                
                // Check if this is an incoming message (from contact)
                const isIncoming = message.cqContactId || message.cq_contact_id;
                if (isIncoming) {
                    hasNewIncomingMessages = true;
                }
            }
        });
        
        // If we received new incoming messages, mark them as seen
        if (hasNewIncomingMessages && this.currentChatId) {
            this.markMessagesAsSeen(this.currentChatId);
        }
        
        // Only auto-scroll if user was near bottom
        if (isNearBottom) {
            this.messagesContainer.scrollTop = this.messagesContainer.scrollHeight;
        }
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
                statusIcon = '<i class="mdi mdi-check text-cyber ms-1" title="Sent"></i>';
            } else if (status === 'FAILED') {
                statusIcon = '<i class="mdi mdi-alert-circle text-danger ms-1" title="Failed"></i>';
            }
        } else {
            if (status === 'SEEN') {
                statusIcon = '<i class="mdi mdi-eye ms-1 text-cyber" title="Seen"></i>';
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
        
        // Get contact name - for group chats, use message's contact info
        let contactName = 'Contact';
        let contactDomain = '';
        if (message.contactUsername) {
            // Group chat - use contact info from message
            contactName = message.contactUsername;
            contactDomain = message.contactDomain;
        } else if (this.currentChat?.contact?.cqContactUsername) {
            // Direct chat - use chat's contact
            contactName = this.currentChat.contact.cqContactUsername;
            contactDomain = this.currentChat.contact.cqContactDomain??'';
        }
        
        const userName = document.querySelector('.js-user')?.dataset?.username || 'You';
        
        const nameDisplay = isOutgoing 
            ? `<div class="text-end"><small class="text-cyber">${userName}</small></div>` 
            : `<div><small class="text-cyber">${contactName}</small><small class="opacity-25 ms-1">${contactDomain}</small></div>`;
        
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
            
            // Check if this is a group chat
            const isGroup = this.currentChat?.isGroupChat || false;
            
            if (isGroup) {
                // Send group message
                const response = await fetch(`/api/cq-chat/group/${this.currentChatId}/messages`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({ content })
                });
                
                if (!response.ok) {
                    throw new Error('Failed to send message');
                }
            } else {
                // Send direct message
                await this.apiService.sendMessage(this.currentChatId, content);
            }
            
            this.messageInput.value = '';
            // Reset UpdatesService timestamp to force immediate update
            this.updatesService.resetTimestamp();
            
        } catch (error) {
            console.error('Error sending message:', error);
            window.toast?.error('Failed to send message');
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
     * Handle unified updates from UpdatesService
     */
    handleUpdates(updates) {
        // Update unread count badge (always)
        if (updates.unreadCount !== undefined) {
            this.updateUnreadBadge(updates.unreadCount);
        }
        
        // Update chat list in dropdown (only if chats provided)
        // Empty array means no updates, so don't re-render
        if (updates.chats && updates.chats.length > 0) {
            this.updateChatList(updates.chats);
        }
        
        // Update messages in open chat
        if (this.currentChatId && updates.messages && updates.messages.length > 0) {
            this.appendMessages(updates.messages);
        }
        
        // Update message statuses
        if (this.currentChatId && updates.statusUpdates && updates.statusUpdates.length > 0) {
            this.updateMessageStatuses(updates.statusUpdates);
        }
    }
    
    /**
     * Update unread messages badge
     */
    updateUnreadBadge(count) {
        const badge = document.getElementById('cqChatUnseenCountBadge');
        if (badge) {
            if (count > 0) {
                badge.textContent = count > 99 ? '99+' : count;
                badge.style.display = '';
            } else {
                badge.textContent = '';
                badge.style.display = 'none';
            }
        }
    }
    
    /**
     * Update chat list with new data
     */
    updateChatList(chats) {
        // Store chats
        this.chats = chats;
        
        // Re-render dropdown if it's visible
        if (this.dropdownList) {
            this.renderDropdownList(chats);
        }
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
    
    /**
     * Show new group chat modal
     */
    async showNewGroupModal() {
        this.selectedMembers.clear();
        this.groupNameInput.value = '';
        this.updateSelectedCount();
        
        // Load contacts for member selection
        await this.loadContactsForGroup();
        
        const modal = new bootstrap.Modal(this.newGroupModal);
        modal.show();
    }
    
    /**
     * Load contacts for group member selection
     */
    async loadContactsForGroup() {
        try {
            const response = await fetch('/api/cq-contact');
            if (!response.ok) {
                throw new Error('Failed to load contacts');
            }
            
            const contacts = await response.json();
            const activeContacts = contacts.filter(c => 
                c.isActive && c.friendRequestStatus === 'ACCEPTED'
            );
            
            if (activeContacts.length === 0) {
                this.groupMembersList.innerHTML = `
                    <div class="text-center text-muted">
                        <i class="mdi mdi-account-off"></i>
                        <p class="mt-2">No contacts available</p>
                    </div>
                `;
                return;
            }
            
            // Render contact checkboxes
            this.groupMembersList.innerHTML = activeContacts.map(contact => `
                <div class="form-check mb-2">
                    <input class="form-check-input" type="checkbox" value="${contact.id}" 
                           id="groupMember${contact.id}" data-contact-name="${contact.cqContactUsername}@${contact.cqContactDomain}">
                    <label class="form-check-label" for="groupMember${contact.id}">
                        <i class="mdi mdi-account me-1"></i>
                        ${contact.cqContactUsername}@${contact.cqContactDomain}
                    </label>
                </div>
            `).join('');
            
            // Add change listeners
            this.groupMembersList.querySelectorAll('input[type="checkbox"]').forEach(checkbox => {
                checkbox.addEventListener('change', (e) => {
                    if (e.target.checked) {
                        this.selectedMembers.add(e.target.value);
                    } else {
                        this.selectedMembers.delete(e.target.value);
                    }
                    this.updateSelectedCount();
                });
            });
            
        } catch (error) {
            console.error('Error loading contacts:', error);
            this.groupMembersList.innerHTML = `
                <div class="alert alert-danger">
                    <i class="mdi mdi-alert me-2"></i>Failed to load contacts
                </div>
            `;
        }
    }
    
    /**
     * Update selected members count
     */
    updateSelectedCount() {
        this.groupSelectedCount.textContent = this.selectedMembers.size;
    }
    
    /**
     * Create new group chat
     */
    async createNewGroup() {
        const groupName = this.groupNameInput.value.trim();
        
        if (!groupName) {
            window.toast?.error('Please enter a group name');
            return;
        }
        
        if (this.selectedMembers.size === 0) {
            window.toast?.error('Please select at least one member');
            return;
        }
        
        try {
            this.newGroupConfirmBtn.disabled = true;
            this.newGroupConfirmBtn.innerHTML = '<i class="mdi mdi-loading mdi-spin"></i> Creating...';
            
            const response = await fetch('/api/cq-chat/group', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    group_name: groupName,
                    contact_ids: Array.from(this.selectedMembers)
                })
            });
            
            if (!response.ok) {
                throw new Error('Failed to create group');
            }
            
            const data = await response.json();
            
            // Close modal
            const modal = bootstrap.Modal.getInstance(this.newGroupModal);
            modal?.hide();
            
            // Reload chats
            await this.loadChatsForDropdown();
            
            // Open the new group chat
            this.openChat(data.chat.id);
            
            window.toast?.success('Group chat created successfully!');
            
        } catch (error) {
            console.error('Error creating group:', error);
            window.toast?.error('Failed to create group chat');
        } finally {
            this.newGroupConfirmBtn.disabled = false;
            this.newGroupConfirmBtn.innerHTML = '<i class="mdi mdi-check me-2"></i>Create Group';
        }
    }
}

