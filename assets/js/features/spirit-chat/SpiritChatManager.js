import { SpiritChatApiService } from './SpiritChatApiService';
import * as bootstrap from 'bootstrap';
//import { marked } from 'marked';
import MarkdownIt from 'markdown-it';
import * as animation from '../../shared/animation';

/**
 * Spirit Chat Manager
 * Manages the Spirit chat UI and interactions
 */
export class SpiritChatManager {
    constructor() {
        this.apiService = new SpiritChatApiService();
        this.currentSpiritId = null;
        this.currentConversationId = null;
        this.deleteConversationId = null;
        this.isLoadingMessages = false;
        this.isLoadingConversations = false;
        this.conversations = [];
        this.imgPreviewData = [];
        this.pdfPreviewData = [];
        this.maxImageSize = 1024; // Max width/height for optimal AI processing
        this.maxFileSize = 5 * 1024 * 1024; // 5MB max file size
        
        // Async conversation state
        this.executionStopped = false;
        this.isExecutingTools = false;
        
        // DOM Elements
        this.spiritIcon = document.getElementById('spiritIcon');
        this.spiritChatModal = document.getElementById('spiritChatModal');
        this.spiritChatButton = document.getElementById('spiritChatButton');
        this.spiritNames = document.querySelectorAll('.spiritName');
        this.spiritLevel = document.getElementById('spiritLevel');
        this.spiritChatAvatar = document.getElementById('spiritChatAvatar');
        this.conversationsList = document.getElementById('conversationsList');
        this.chatContainer = document.getElementById('chatContainer');
        this.chatMessages = document.getElementById('chatMessages');
        this.chatForm = document.getElementById('chatForm');
        this.messageInput = document.getElementById('messageInput');
        this.conversationSearch = document.getElementById('conversationSearch');
        this.newConversationBtn = document.getElementById('newConversationBtn');
        this.newConversationModal = document.getElementById('newConversationModal');
        this.newConversationForm = document.getElementById('newConversationForm');
        this.deleteConversationModal = document.getElementById('deleteConversationModal');
        this.deleteConversationForm = document.getElementById('deleteConversationForm');
        this.conversationTitle = document.getElementById('conversationTitle');
        this.spiritChatModalTitle = document.getElementById('spiritChatModalTitle');
        this.responseMaxOutputSlider = document.getElementById('responseMaxOutputSlider');
        this.responseMaxOutputValue = document.getElementById('responseMaxOutputValue');
        this.chatSettingsIcon = document.getElementById('chatSettingsIcon');
        this.chatSettings = document.getElementById('chatSettings');
        this.spiritChatToolsAndConversationsToggle = document.getElementById('spiritChatToolsAndConversationsToggle');
        this.spiritChatToolsAndConversations = document.getElementById('spiritChatToolsAndConversations');
        this.creditIndicator = document.getElementById('creditIndicator');
        this.sendMessageBtn = document.getElementById('sendMessageBtn');
        this.stopExecutionBtn = document.getElementById('stopExecutionBtn');
        this.imageUpload = document.getElementById('imageUpload');
        this.imageUploadPreview = document.getElementById('imageUploadPreview');
        this.chatInfoPrimaryAiModel = document.getElementById('chatInfoPrimaryAiModel');
        this.chatInfoIcon = document.getElementById('chatInfoIcon');
        this.chatInfo = document.getElementById('chatInfo');
        this.contentShowcaseModal = document.getElementById('contentShowcaseModal');
    }
    
    /**
     * Initialize the Spirit chat functionality
     */
    async init() {
        if (!this.spiritIcon) return;
        
        // Fetch the user's primary spirit
        await this.fetchPrimarySpirit();
        
        // Initialize event listeners
        await this.initEventListeners();

        // Show the modal if it was open before and we are on same url as before (page refresh/reload)
        if (localStorage.getItem('spiritChatModal') === 'true' && window.location.pathname === localStorage.getItem('spiritChatModalUrl')) {
            this.spiritChatButton.dispatchEvent(new Event('click'));
        }

        // show tools and conversations based on local storage
        if (localStorage.getItem('config.chat.toolsAndConversations.open') === 'true') {
            this.spiritChatToolsAndConversations.classList.remove('d-none');
            this.spiritChatToolsAndConversations.classList.add('d-flex');
            // slide down
            animation.slideDown(this.spiritChatToolsAndConversations);
            
            let icon = this.spiritChatToolsAndConversationsToggle.querySelector('i');
            icon.classList.remove('mdi-menu-open');
            icon.classList.add('mdi-menu');
        }

        // update credit indicator based current real data from CQ AI Gateway
        await this.updateCreditIndicator();
    }

    /**
     * Update and show credit indicator based current real data from CQ AI Gateway
     */
    async updateCreditIndicator() {
        let creditBalanceResponse = await this.apiService.getCreditBalance();
        if (creditBalanceResponse.success) {
            let creditBalance = creditBalanceResponse.creditBalance || creditBalanceResponse.credits;
            this.showCreditIndicator(creditBalance);

            if (creditBalance <= 0) {
                this.sendMessageBtn.classList.add('disabled');
                this.sendMessageBtn.disabled = true;
                this.sendMessageBtn.setAttribute('title', 'You have no credits');
                
                let icon = this.sendMessageBtn.querySelector('i');
                icon.classList.add('text-danger');
                icon.classList.remove('mdi-message-check');
                icon.classList.add('mdi-message-alert');
            } else {
                this.sendMessageBtn.classList.remove('disabled');
                this.sendMessageBtn.disabled = false;
                this.sendMessageBtn.setAttribute('title', 'Send message');
                
                let icon = this.sendMessageBtn.querySelector('i');
                icon.classList.remove('text-danger');
                icon.classList.remove('mdi-message-alert');
                icon.classList.add('mdi-message-check');
            }
        }
    }
    
    /**
     * Show credit indicator based current real data from CQ AI Gateway
     */
    showCreditIndicator(creditBalance) {
        let creditBalanceText = creditBalance.toLocaleString('sk-SK');
        this.creditIndicator.innerHTML = `
            <i class="ms-1 mdi mdi-gauge${creditBalance < 0 ? '-empty text-danger' : creditBalance < 30 ? '-low text-danger' : creditBalance < 60 ? ' text-warning' : creditBalance > 500 ? '-full text-success' : ' text-cyber'}" 
            title="${creditBalanceText} Credits"></i>
            <span class="text-muted" style="font-size: 0.6rem !important;" title="${creditBalanceText} Credits">${creditBalanceText}<br>Credits</span>
        `;
    }
    
    /**
     * Initialize event listeners
     */
    async initEventListeners() {
        // Spirit chat modal events
        if (this.spiritChatModal) {
            this.spiritChatModal.addEventListener('shown.bs.modal', async () => {
                if (this.conversations.length === 0) {
                    await this.loadConversations(true);
                }
                localStorage.setItem('spiritChatModal', 'true');
                localStorage.setItem('spiritChatModalUrl', window.location.pathname);
            });
            
            this.spiritChatModal.addEventListener('hidden.bs.modal', () => {
                localStorage.removeItem('spiritChatModal');
                localStorage.removeItem('spiritChatModalUrl');
            });
        }

        // Chat form submission
        if (this.chatForm) {
            this.chatForm.addEventListener('submit', (e) => {
                e.preventDefault();
                this.sendMessage();
            });
        }
        
        // Stop execution button
        if (this.stopExecutionBtn) {
            this.stopExecutionBtn.addEventListener('click', () => {
                this.stopExecution();
            });
        }

        // Tools and conversations toggle
        if (this.spiritChatToolsAndConversationsToggle) {
            this.spiritChatToolsAndConversationsToggle.addEventListener('click', async () => {
                await this.toggleToolsAndConversationsPanel();
            });
        }

        // Search/filter conversations
        if (this.conversationSearch) {
            this.conversationSearch.addEventListener('input', () => {
                this.filterConversations();
            });
        }
        
        // New conversation button
        if (this.newConversationBtn) {
            this.newConversationBtn.addEventListener('click', () => {
                const newConversationModal = new bootstrap.Modal(this.newConversationModal);
                this.newConversationModal.addEventListener('shown.bs.modal', () => {
                    this.conversationTitle.focus();
                });
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

        // Delete conversation form
        if (this.deleteConversationForm) {
            this.deleteConversationForm.addEventListener('submit', (e) => {
                e.preventDefault();
                this.deleteConversation();
            });
        }

        // Message image upload
        if (this.imageUpload) {
            this.imageUpload.addEventListener('change', async (e) => {
                await this.handleUploadedFiles(e.target.files);
            });
        }
        
        // Clipboard paste for images
        if (this.messageInput) {
            this.messageInput.addEventListener('paste', (e) => {
                this.handleClipboardPaste(e);
            });
        }
        
        // Image upload button click handler
        if (this.imageUpload) {
            const uploadButton = document.getElementById('imageUploadButton');
            if (uploadButton) {
                uploadButton.addEventListener('click', () => {
                    this.imageUpload.click();
                });
            }
        }

        // Message input textarea - dynamic rows + send on `Ctrl + Enter`
        if (this.messageInput) {
            this.messageInput.addEventListener('input', (e) => {
                // Dynamic rows
                this.messageInput.rows = 3;
                let rowCount = this.messageInput.value.split('\n').length;
                let contentLength = this.messageInput.value.length / 140;
                this.messageInput.rows = Math.min( Math.max(rowCount + contentLength, 2), 9 );
            });

            this.messageInput.addEventListener('keydown', (e) => {
                if (e.key === 'Enter' && e.ctrlKey) {
                    e.preventDefault();
                    this.sendMessage();
                }
            });
        }

        // Response max output slider
        if (this.responseMaxOutputSlider) {
            this.responseMaxOutputSlider.addEventListener('input', (event) => {
                this.responseMaxOutputValue.textContent = event.target.value;
                localStorage.setItem('config.chat.settings.responseMaxOutput.value', event.target.value);
            });
        }

        // Response max output value click
        if (this.responseMaxOutputValue) {
            this.responseMaxOutputValue.addEventListener('click', (event) => {
                let newLength = prompt('Enter Max response output tokens', event.target.textContent); 
                if (newLength) { 
                    this.responseMaxOutputSlider.value = newLength;
                    localStorage.setItem('config.chat.settings.responseMaxOutput.value', newLength);
                    this.responseMaxOutputSlider.dispatchEvent(new Event('input')); 
                }
            });
        }

        // Chat settings icon
        if (this.chatSettingsIcon) {
            this.chatSettingsIcon.addEventListener('click', () => {
                let open = localStorage.getItem('config.chat.settings.open') === 'true';
                localStorage.setItem('config.chat.settings.open', !open);
                if (open) {
                    this.chatSettings.classList.add('d-none');
                    this.chatSettings.classList.remove('d-flex');
                } else {
                    this.chatSettings.classList.remove('d-none');
                    this.chatSettings.classList.add('d-flex');
                }
            });
        }

        // Chat info icon
        if (this.chatInfoIcon) {
            this.chatInfoIcon.addEventListener('click', () => {
                let open = localStorage.getItem('config.chat.info.open') === 'true';
                localStorage.setItem('config.chat.info.open', !open);
                if (open) {
                    this.chatInfo.classList.add('d-none');
                    this.chatInfo.classList.remove('d-flex');
                } else {
                    this.chatInfo.classList.remove('d-none');
                    this.chatInfo.classList.add('d-flex');
                }
            });
        }

        // Content showcase modal - clear content on close
        if (this.contentShowcaseModal) {
            this.contentShowcaseModal.addEventListener('hidden.bs.modal', () => {
                this.contentShowcaseModal.querySelector('.contentShowcaseModal-content').innerHTML = '';
                this.contentShowcaseModal.querySelector('.modal-header')?.classList.add('d-none');
                this.contentShowcaseModal.querySelector('.modal-footer')?.classList.add('d-none');
            });
        }
    }

    /**
     * Toggle tools and conversations panel
     */
    async toggleToolsAndConversationsPanel() {
        let open = localStorage.getItem('config.chat.toolsAndConversations.open') === 'true';
        localStorage.setItem('config.chat.toolsAndConversations.open', !open);
        if (open) {
            await animation.slideUp(this.spiritChatToolsAndConversations);
            this.spiritChatToolsAndConversations.classList.add('d-none');
            this.spiritChatToolsAndConversations.classList.remove('d-flex');

            let icon = this.spiritChatToolsAndConversationsToggle.querySelector('i');
            icon.classList.remove('mdi-menu');
            icon.classList.add('mdi-menu-open');
        } else {
            this.spiritChatToolsAndConversations.classList.remove('d-none');
            this.spiritChatToolsAndConversations.classList.add('d-flex');
            await animation.slideDown(this.spiritChatToolsAndConversations);

            let icon = this.spiritChatToolsAndConversationsToggle.querySelector('i');
            icon.classList.remove('mdi-menu-open');
            icon.classList.add('mdi-menu');
        }
    }
    
    /**
     * Fetch the user's primary spirit
     */
    async fetchPrimarySpirit() {
        // Check if we're in onboarding mode
        const isOnboarding = window.location.pathname.includes('/welcome') && localStorage.getItem('currentStep') !== null;
        if (isOnboarding) {
            return;
        }
        
        try {
            const response = await fetch('/api/spirit');
            if (!response.ok) {
                throw new Error('Failed to fetch primary spirit');
            }
            
            const spirit = await response.json();
            this.currentSpiritId = spirit.id;
            
            // Update UI with spirit info
            if (this.spiritNames && this.spiritNames.length > 0) {
                this.spiritNames.forEach(name => name.textContent = spirit.name);
            }
            
            if (this.spiritLevel) {
                const levelText = window.translations && window.translations['spirit.level'] ? window.translations['spirit.level'] : 'Level';
                this.spiritLevel.textContent = `${levelText}: ${spirit.level}`;
            }

            // set response max output
            if (this.responseMaxOutputSlider) {
                let maxOutput = '4096';//localStorage.getItem('config.chat.settings.responseMaxOutput.max') || '4096';
                try {
                    let response = await fetch('/api/ai/gateway/primary-model', {
                        method: 'GET'
                    });
                    let data = await response.json();
                    if (data.maxOutput) {
                        maxOutput = data.maxOutput;
                    
                        //localStorage.setItem('config.chat.settings.responseMaxOutput.max', maxOutput);

                        this.chatInfoPrimaryAiModel.innerHTML = 
                            '<span class="me-1 fw-bold">' + data.modelName + '</span> '+
                            '[<span class="d-none d-md-inline">context window: </span><span class="fw-bold">' + Number(data.contextWindow).toLocaleString('sk-SK') + '</span> tokens]';
                    }
                } catch (error) {
                    console.error('Error fetching primary model:', error);
                }

                this.responseMaxOutputSlider.max = maxOutput;
                this.responseMaxOutputSlider.value = localStorage.getItem('config.chat.settings.responseMaxOutput.value') || '500';

                this.responseMaxOutputValue.textContent = this.responseMaxOutputSlider.value;
            }

            // set chat settings open
            if (this.chatSettings) {
                let open = localStorage.getItem('config.chat.settings.open') === 'true';
                localStorage.setItem('config.chat.settings.open', open);
                if (open) {
                    this.chatSettings.classList.remove('d-none');
                    this.chatSettings.classList.add('d-flex');
                } else {
                    this.chatSettings.classList.add('d-none');
                    this.chatSettings.classList.remove('d-flex');
                }
            }
            
            // set chat info open
            if (this.chatInfo) {
                let open = localStorage.getItem('config.chat.info.open') === 'true';
                localStorage.setItem('config.chat.info.open', open);
                if (open) {
                    this.chatInfo.classList.remove('d-none');
                    this.chatInfo.classList.add('d-flex');
                } else {
                    this.chatInfo.classList.add('d-none');
                    this.chatInfo.classList.remove('d-flex');
                }
            }
            
            let spiritAvatar = document.querySelectorAll('#spiritChatButtonIcon, .spiritChatButtonIcon');
            if (spiritAvatar) {
                let color = JSON.parse(spirit.visualState)?.color??null;
                if (color) {
                    spiritAvatar.forEach(icon => icon.style.color = color);
                }
            }
            
        } catch (error) {
            console.error('Error fetching primary spirit:', error);
        }
    }
    
    /**
     * Load conversations for the current spirit
     */
    async loadConversations(loadLastConversation = false) {
        if (!this.currentSpiritId || !this.conversationsList) return;

        this.isLoadingConversations = true;
        
        try {
            // Show loading indicator
            this.conversationsList.innerHTML = `
                <div class="text-center p-3">
                    <div class="spinner-border text-cyber" role="status">
                        <span class="visually-hidden">${window.translations && window.translations['auth.key_generation.loading'] ? window.translations['loading'] : 'Loading...'}</span>
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
            conversations.forEach(async conversation => {
                const item = document.createElement('a');
                item.href = '#';
                item.className = 'list-group-item list-group-item-action d-flex justify-content-between align-items-center';
                item.dataset.id = conversation.id;
                
                // Format date and time
                const date = new Date(conversation.lastInteraction);
                const formattedDate = date.toLocaleDateString('sk-SK', { year: 'numeric', month: '2-digit', day: '2-digit', timeZone: 'Europe/Prague'});
                const formattedTime = date.toLocaleTimeString('sk-SK', { hour: '2-digit', minute: '2-digit', timeZone: 'Europe/Prague'});
                
                item.innerHTML = `
                    <div class="cursor-pointer w-100">
                        <span class="text-light">
                            ${conversation.title}
                        </span>
                        
                        <button type="button" class="btn btn-outline-danger btn-sm float-end ms-2 mb-1" style="padding: 0px 0.5rem !important;" 
                            data-id="${conversation.id}" data-title="${conversation.title}" data-action="delete">
                            <i class="mdi mdi-delete"></i>
                        </button>

                        <small class="text-muted float-end d-none_d-md-inline-block pt-1 me-2">
                            <span class="" title="Conversation size">${conversation.formattedSize || '0 B'}</span> <span class="text-cyber d-none">/</span>
                            <i class="mdi mdi-message-outline text-cyber opacity-75 me-1 ms-2"></i><span class="" title="Messages count">${conversation.messagesCount || '0'}</span> <span class="text-cyber d-none">/</span>
                            <i class="mdi mdi-image-outline text-cyber opacity-75 me-1 ms-2"></i><span class="" title="Images count">${conversation.imagesCount || '0'}</span>
                            <i class="mdi mdi-clock-outline text-cyber opacity-75 me-1 ms-2"></i>${formattedDate} <span class="text-cyber">/</span> ${formattedTime}
                        </small>
                    </div>
                `;
                
                item.addEventListener('click', (e) => {
                    e.preventDefault();
                    
                    this.loadConversation(conversation.id);

                    this.toggleToolsAndConversationsPanel();
                });

                item.querySelector('[data-action="delete"]').addEventListener('click', (e) => {
                    e.preventDefault();
                    e.stopPropagation();

                    this.deleteConversationId = e.currentTarget.dataset.id;
                    this.deleteConversationModal.querySelector('#deleteConversationModalLabel').textContent = e.currentTarget.dataset.title;

                    const deleteConversationModal = new bootstrap.Modal(this.deleteConversationModal);
                    deleteConversationModal.show();
                });
                
                this.conversationsList.appendChild(item);

                this.conversations.push(conversation);

                if (loadLastConversation) {
                    let lastConversationId = localStorage.getItem('config.chat.last_conversation_id') || conversation.id;
                    this.currentConversationId = lastConversationId;
                    await this.loadConversation(lastConversationId);
                    loadLastConversation = false;
                }
            });
            
        } catch (error) {
            console.error('Error loading conversations:', error);
            this.conversationsList.innerHTML = `
                <div class="alert alert-danger">
                    ${error.message || 'Failed to load conversations'}
                </div>
            `;
        } finally {
            this.isLoadingConversations = false;
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
            // remove active class from all items
            const items = this.conversationsList.querySelectorAll('.list-group-item');
            items.forEach(item => {
                item.classList.remove('active', 'bg-cyber-g');
            });            
            // add active class to the selected item
            while (this.isLoadingConversations) {
                await new Promise(resolve => setTimeout(resolve, 222));
            }
            const activeItem = this.conversationsList.querySelector(`[data-id="${conversationId}"]`);
            if (activeItem) {
                activeItem.classList.add('active', 'bg-cyber-g');
                activeItem.scrollIntoView({ behavior: 'smooth' });
                let infoBlock = activeItem.querySelector('.text-muted');
                if (infoBlock) {
                    infoBlock.classList.remove('text-muted');
                    infoBlock.classList.add('text-light');
                }
            }
            
            // Fetch conversation
            const conversation = await this.apiService.getConversation(conversationId);
            this.currentConversationId = conversationId;
            localStorage.setItem('config.chat.last_conversation_id', conversationId);
            
            // Update modal title
            this.spiritChatModalTitle.innerHTML = conversation.title /* + '<i class="mdi mdi-forum text-dark ms-2 fs-6 opacity-50"></i>' */;
            
            // Render messages
            this.renderMessages(conversation.messages);

            // update conversation tokens
            this.apiService.getConversationTokens(conversationId).then(tokens => {
                this.conversationTokens = tokens;
                //console.log('Conversation tokens:', this.conversationTokens);
            });
            
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
                <div class="text-center p-3" id="welcomeMessage">
                    <p>${window.translations && window.translations['spirit.chat.no_messages'] ? window.translations['spirit.chat.no_messages'] : 'Say `Hi` to your Spirit :)'}</p>
                </div>
            `;
            return;
        }
        
        // Render each message
        messages.forEach(message => {
            // Handle tool result messages - display frontendData if present
            if (message.role === 'tool' || message.type === 'tool_result') {
                // Check if message content has frontendData
                let content = message.content;
                if (Array.isArray(content)) {
                    content.forEach(item => {
                        if (item.frontendData) {
                            const frontendDataEl = document.createElement('div');
                            frontendDataEl.className = 'chat-message chat-message-system';
                            
                            frontendDataEl.innerHTML = `
                                <div data-src='injected system data' data-type='tool_calls_frontend_data' data-ai-generated='false' class='bg-dark p-2 mb-2 rounded d-block w-100'>
                                    ${item.frontendData}
                                </div>
                            `;
                            
                            this.chatMessages.appendChild(frontendDataEl);
                            this.showContentShowcase(frontendDataEl);
                        }
                    });
                } else if (content && typeof content === 'object' && content.frontendData) {
                    const frontendDataEl = document.createElement('div');
                    frontendDataEl.className = 'chat-message chat-message-system';
                    
                    frontendDataEl.innerHTML = `
                        <div data-src='injected system data' data-type='tool_calls_frontend_data' data-ai-generated='false' class='bg-dark p-2 mb-2 rounded d-block w-100'>
                            ${content.frontendData}
                        </div>
                    `;
                    
                    this.chatMessages.appendChild(frontendDataEl);
                    this.showContentShowcase(frontendDataEl);
                }
                return;
            }
            
            const messageEl = document.createElement('div');
            messageEl.className = `chat-message ${message.role === 'user' ? 'chat-message-user' : 'chat-message-assistant'}`;
            
            // Format timestamp
            let timestampHtml = '';
            if (message.timestamp) {
                const date = new Date(message.timestamp);
                const formattedDate = date.toLocaleDateString('sk-SK', { month: '2-digit', day: '2-digit', timeZone: 'Europe/Prague'});
                const formattedTime = date.toLocaleTimeString('sk-SK', { hour: '2-digit', minute: '2-digit', timeZone: 'Europe/Prague'});
                timestampHtml = `<div class="chat-timestamp">${formattedDate} <span class="text-cyber opacity-75">/</span> ${formattedTime}</div>`;
            }

            // format message content
            let formattedContent = '';
            
            // Handle assistant messages with tool_use (content is full message object)
            let hasToolExecution = false;
            let toolExecutionHtml = '';
            
            if (message.role === 'assistant' && message.type === 'tool_use' && message.content && typeof message.content === 'object') {
                // Extract text content from the full message object
                const textContent = message.content.content;
                // Handle null, empty string, or actual content (array vs string demon!)
                if (textContent && textContent !== '') {
                    if (typeof textContent === 'string') {
                        formattedContent = this.formatMessageContent(textContent);
                    } else if (Array.isArray(textContent)) {
                        formattedContent = textContent.map(item => {
                            if (item.type === 'text') {
                                return this.formatMessageContent(item.text);
                            }
                            return '';
                        }).join('');
                    }
                }
                // Prepare tool execution indicator (will be added as separate element)
                if (message.content.tool_calls && Array.isArray(message.content.tool_calls)) {
                    hasToolExecution = true;
                    const toolNames = message.content.tool_calls.map(tc => tc.function?.name || 'unknown').join(', ');
                    toolExecutionHtml = `
                        <div class="d-flex align-items-center gap-2 p-2 bg-success bg-opacity-10 rounded border border-success border-opacity-25">
                            <i class="mdi mdi-check-circle text-success"></i>
                            <span class="text-muted small">🔧 Executed: ${toolNames}</span>
                        </div>
                    `;
                }
            } else if (Array.isArray(message.content)) {
                formattedContent = message.content.map(item => {
                    if (item.type === 'text') {
                        return `<div style="clear: both;"></div>` + this.formatMessageContent(item.text);
                    } else if (item.type === 'image_url') {
                        return `<div class="content-showcase position-relative d-inline-block float-end" data-title="" data-type="image">
                                    <img src="${item.image_url.url}" alt="" class="chat-image-preview mb-2 ms-2">
                                    <div class="content-showcase-icon position-absolute top-0 end-0 p-1 _py-2 badge bg-dark bg-opacity-75 text-cyber cursor-pointer">
                                        <i class="mdi mdi-fullscreen"></i>
                                    </div>
                                </div>`;
                    } else if (item.type === 'file') {
                        return `<div style="clear: both;"></div>
                                <div class="chat-file-preview rounded text-cyber bg-dark bg-opacity-25 cursor-pointer mb-2 content-showcase position-relative"
                                        data-title="${item.file.filename}" data-type="file"
                                        onclick="this.querySelector('.embed-container').classList.toggle('d-none');">
                                    <div class="d-flex align-items-center px-1 chat-file-preview-title">
                                        <i class="mdi mdi-file-pdf-box me-1" style="font-size: 1.6rem; padding: 0 0.3rem !important;"></i>
                                        <span class="text-cyber">${item.file.filename}</span>
                                    </div>
                                    <div class="p-2 pt-0 d-none embed-container">
                                        <embed src="${item.file.file_data}"
                                            width="100%" height="420"
                                            class="rounded"
                                            type="application/pdf"
                                            title="${item.file.filename}" />
                                    </div>
                                    <div class="content-showcase-icon position-absolute top-0 end-0 p-1 _py-2 badge bg-dark bg-opacity-25 text-cyber cursor-pointer">
                                        <i class="mdi mdi-fullscreen"></i>
                                    </div>
                                </div>`;
                    }
                }).join('');
                if (formattedContent.trim() != '') {
                    formattedContent += '<div style="clear: both;"></div>';
                }
            } else {
                formattedContent = this.formatMessageContent(message.content);
            }
            
            messageEl.innerHTML = formattedContent != '' ? `
                <div class="chat-bubble">
                    <div class="chat-content">${formattedContent}</div>
                    <div class="chat-timestamp">${timestampHtml}</div>
                </div>
            ` : '';

            // add content showcase icon event listener
            this.showContentShowcase(messageEl);
            
            this.chatMessages.appendChild(messageEl);
            
            // Add tool execution block as separate element (outside bubble)
            if (hasToolExecution) {
                const toolEl = document.createElement('div');
                toolEl.className = 'chat-message chat-message-tool';
                toolEl.innerHTML = toolExecutionHtml;
                this.chatMessages.appendChild(toolEl);
            }
        });
        
        // Scroll to bottom
        this.chatMessages.scrollIntoView({ behavior: 'smooth', block: 'end' });
        
        // Focus input
        if (this.messageInput) {
            this.messageInput.focus();
        }
    }

    /**
     * Filter conversations based on search query
     */
    filterConversations() {
        const searchQuery = this.conversationSearch.value.toLowerCase();
        const conversations = this.conversationsList.querySelectorAll('.list-group-item');
        conversations.forEach(conversation => {
            const title = conversation.textContent.toLowerCase();
            if (title.includes(searchQuery)) {
                conversation.classList.remove('d-none');
            } else {
                conversation.classList.add('d-none');
            }
        });
    }

    /**
     * Delete a conversation
     */
    async deleteConversation() {
        try {
            if (!this.deleteConversationId) {
                // this should never happen, but just in case
                console.error('No conversation ID found');
                alert('No conversation ID found');
                return;
            }
            // delete conversation
            await this.apiService.deleteConversation(this.deleteConversationId);
            
            // Close modal
            const modal = bootstrap.Modal.getInstance(this.deleteConversationModal);
            if (modal) {
                this.deleteConversationModal.querySelector('#deleteConversationModalLabel').textContent = '';
                modal.hide();
            }
            
            // Clear deleteConversationId
            this.deleteConversationId = null;            
            
            // Refresh conversations list
            this.loadConversations();

            // Clear message conversation
            this.chatMessages.innerHTML = '';
            // Update modal title
            this.spiritChatModalTitle.innerHTML = '';
            
            // Show success message
            window.toast.success(window.translations && window.translations['spirit.chat.conversation_deleted'] ? window.translations['spirit.chat.conversation_deleted'] : 'Conversation deleted');
            
        } catch (error) {
            console.error('Error deleting conversation:', error);
            window.toast.error(error.message || 'Failed to delete conversation');
        }
    }
    
    /**
     * Format message content with markdown support
     */
    formatMessageContent(content) {
        if (!content) return '';
        
        // Ensure content is a string (array vs string demon!)
        if (typeof content !== 'string') {
            console.warn('formatMessageContent received non-string:', typeof content, content);
            return '';
        }

        let md = new MarkdownIt({
            html: true,  // ← This enables HTML parsing
            linkify: true, // Optional: converts URLs to links
            typographer: true // Optional: improves typography (e.g., quotes, dashes)
          });
        let html = md.render(content);
        
        return html;
    }

    /**
     * Handle multiple uploaded files from upload
     */
    async handleUploadedFiles(files) {
        if (!files || files.length === 0) return;
        
        try {
            for (let file of files) {
                if (file.size > this.maxFileSize) {
                    window.toast?.warning(`Image "${file.name}" is too large (max 5MB)`);
                    continue;
                }
            
                if (file.type.startsWith('image/')) {
                    await this.processAndAddImage(file);
                } else if (file.type.startsWith('application/pdf')) {
                    await this.addPdfToPreview(file);
                } else {
                    window.toast?.warning(`File "${file.name}" is not an image or PDF`);
                    continue;
                }
            }
            
            // Clear the input
            if (this.imageUpload) {
                this.imageUpload.value = '';
            }
        } catch (error) {
            console.error('Error handling image files:', error);
            window.toast?.error('Failed to handle image files');
        }
    }

    /**
     * Process and optimize image before adding to preview
     */
    async processAndAddImage(file) {
        try {
            const optimizedDataUrl = await this.resizeImage(file, this.maxImageSize);
            this.addImageToPreview(optimizedDataUrl, file.name);
            this.imgPreviewData.push(optimizedDataUrl);
        } catch (error) {
            console.error('Error processing image:', error);
            window.toast?.error(`Failed to process image "${file.name}"`);
        }
    }

    /**
     * Resize image to optimal dimensions for AI processing
     */
    resizeImage(file, maxSize) {
        return new Promise((resolve, reject) => {
            const reader = new FileReader();
            reader.onload = (e) => {
                const img = new Image();
                img.onload = () => {
                    const canvas = document.createElement('canvas');
                    const ctx = canvas.getContext('2d');
                    
                    // Calculate new dimensions
                    let { width, height } = this.calculateOptimalDimensions(img.width, img.height, maxSize);
                    
                    canvas.width = width;
                    canvas.height = height;
                    
                    // Draw resized image
                    ctx.drawImage(img, 0, 0, width, height);
                    
                    // Convert to data URL with quality optimization
                    const dataUrl = canvas.toDataURL('image/jpeg', 0.85);
                    resolve(dataUrl);
                };
                img.onerror = reject;
                img.src = e.target.result;
            };
            reader.onerror = reject;
            reader.readAsDataURL(file);
        });
    }

    /**
     * Calculate optimal dimensions maintaining aspect ratio
     */
    calculateOptimalDimensions(originalWidth, originalHeight, maxSize) {
        if (originalWidth <= maxSize && originalHeight <= maxSize) {
            return { width: originalWidth, height: originalHeight };
        }
        
        const aspectRatio = originalWidth / originalHeight;
        
        if (originalWidth > originalHeight) {
            return {
                width: maxSize,
                height: Math.round(maxSize / aspectRatio)
            };
        } else {
            return {
                width: Math.round(maxSize * aspectRatio),
                height: maxSize
            };
        }
    }

    /**
     * Add image to preview panel with remove functionality
     */
    async addImageToPreview(dataUrl, fileName = 'image') {
        const previewContainer = document.createElement('div');
        previewContainer.className = 'image-preview-item position-relative d-inline-block m-2';
        
        const img = document.createElement('img');
        img.src = dataUrl;
        img.dataset.fileName = fileName;
        img.className = 'preview-image shadow-sm';
        img.title = fileName;
        
        const removeBtn = document.createElement('span');
        removeBtn.className = 'badge bg-danger position-absolute top-0 end-0 rounded';
        removeBtn.innerHTML = '<i class="mdi mdi-close"></i>';
        removeBtn.title = 'Remove image';
        
        removeBtn.addEventListener('click', async () => {
            const index = Array.from(this.imageUploadPreview.children).indexOf(previewContainer);
            if (index !== -1) {
                this.imgPreviewData.splice(index, 1);

                await animation.fade(previewContainer, 'out', animation.DURATION.QUICK);
                previewContainer.remove();
                
                if (this.imgPreviewData.length === 0 && this.pdfPreviewData.length === 0) {
                    await animation.slideDown(this.imageUploadPreview, animation.DURATION.NORMAL);
                    this.imageUploadPreview.classList.add('d-none');
                }
            }
        });
        
        previewContainer.appendChild(img);
        previewContainer.appendChild(removeBtn);
        this.imageUploadPreview.appendChild(previewContainer);

        this.imageUploadPreview.classList.remove('d-none');     

        await animation.fade(img, 'in', animation.DURATION.EMPHASIS);        
    }

    /**
     * Check if a string is a valid image URL
     */
    isImageUrl(text) {
        try {
            const url = new URL(text);
            // Check if it's HTTP/HTTPS
            if (!['http:', 'https:'].includes(url.protocol)) {
                return false;
            }
            
            // Check if it has an image extension
            const imageExtensions = /\.(jpg|jpeg|png|gif|webp)(\?.*)?$/i;   
                     
            return imageExtensions.test(url.pathname);
        } catch {
            return false;
        }
    }

    /**
     * Handle image URL from clipboard
     */
    async handleImageUrl(url) {
        try {
            // Add to preview with URL as filename
            const fileName = this.extractFileNameFromUrl(url);
            await this.addImageToPreview(url, fileName);
            
            // Store URL directly - it will be used in image_url.url field just like base64 data
            this.imgPreviewData.push(url);
            
            window.toast?.success(`Image URL added: ${fileName}`);
        } catch (error) {
            console.error('Error handling image URL:', error);
            window.toast?.error('Failed to add image URL');
        }
    }

    async fileToBase64(file) {
        return new Promise((resolve, reject) => {
            const reader = new FileReader();
            reader.readAsDataURL(file);
            reader.onload = () => resolve(reader.result);
            reader.onerror = reject;
        });
    }

    formatFileSize(bytes, rounded = false) {
        if (bytes === 0) return '0 Bytes';
        
        const k = 1024;
        const sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        
        return parseFloat((bytes / Math.pow(k, i)).toFixed(rounded ? 0 : 2)) + ' ' + sizes[i];
    }

    async addPdfToPreview(file) {
        try {
            let fileName = '';
            let fileSize = '';
            if (file.name) {
                fileName = file.name || 'file';
                fileSize = this.formatFileSize(file.size, true);
            
                const pdfBase64data = await this.fileToBase64(file);
                this.pdfPreviewData.push({
                    'filename': fileName,
                    'file_data': pdfBase64data
                });
            } else {
                fileName = this.extractFileNameFromUrl(file);
                fileSize = '<em>online</em>';
                this.pdfPreviewData.push({
                    'filename': fileName,
                    'file_data': file
                });
            }
            const previewContainer = document.createElement('div');
            previewContainer.className = 'image-preview-item position-relative d-inline-block m-2';
            
            const pdfDiv = document.createElement('div');
            pdfDiv.className = 'preview-pdf shadow-sm flex-column gap-0 pt-1';
            pdfDiv.title = fileName;
            pdfDiv.dataset.fileName = fileName;
            let displayName = fileName.replace(/\.[^/.]+$/, "");
            if (displayName.length > 6) {
                displayName = displayName.substring(0, 6) + '...';
            }
            pdfDiv.innerHTML = `<i class="mdi mdi-file-pdf-box text-cyber" style="font-size: 1.4rem !important;line-height: 1rem !important;"></i>
                                <span style="font-size: 0.8rem !important;">${displayName}</span>
                                <span style="font-size: 0.5rem !important;">${fileSize}</span>`;
            
            const removeBtn = document.createElement('span');
            removeBtn.className = 'badge bg-danger position-absolute top-0 end-0 rounded';
            removeBtn.innerHTML = '<i class="mdi mdi-close"></i>';
            removeBtn.title = 'Remove PDF';
            
            removeBtn.addEventListener('click', async () => {
                const index = Array.from(this.imageUploadPreview.children).indexOf(previewContainer);
                if (index !== -1) {
                    this.pdfPreviewData.splice(index, 1);

                    await animation.fade(previewContainer, 'out', animation.DURATION.QUICK);
                    previewContainer.remove();
                    
                    if (this.pdfPreviewData.length === 0 && this.imgPreviewData.length === 0) {
                        await animation.slideDown(this.imageUploadPreview, animation.DURATION.NORMAL);
                        this.imageUploadPreview.classList.add('d-none');
                    }
                }
            });
            
            pdfDiv.appendChild(removeBtn);
            previewContainer.appendChild(pdfDiv);
            this.imageUploadPreview.appendChild(previewContainer);

            this.imageUploadPreview.classList.remove('d-none');     

            await animation.fade(pdfDiv, 'in', animation.DURATION.EMPHASIS);
            pdfDiv.style.display = 'flex';
        } catch (error) {
            console.error('Error adding PDF file to preview:', error);
            window.toast?.error('Failed to add PDF file to preview');
        }
    }
    
    /**
     * Check if a string is a valid PDF URL
    */
    isPdfUrl(url) {
        try {
            const urlObj = new URL(url);
            // Check if it's HTTP/HTTPS
            if (!['http:', 'https:'].includes(urlObj.protocol)) {
                return false;
            }
            
            const pathname = urlObj.pathname;
            const filename = pathname.split('/').pop() || 'file';
            return filename.endsWith('.pdf');
        } catch {
            return false;
        }
    }

    /**
     * Handle PDF URL from clipboard
    */
    async handlePdfUrl(url) {
        try {
            // Add to preview with URL as filename
            const fileName = this.extractFileNameFromUrl(url);
            await this.addPdfToPreview(url, fileName);
            
            window.toast?.success(`PDF URL added: ${fileName}`);
        } catch (error) {
            console.error('Error handling PDF URL:', error);
            window.toast?.error('Failed to add PDF URL');
        }
    }

    /**
     * Extract filename from URL for display
     */
    extractFileNameFromUrl(url) {
        try {
            const urlObj = new URL(url);
            const pathname = urlObj.pathname;
            const filename = pathname.split('/').pop() || 'file';
            return filename.length > 30 ? filename.substring(0, 27) + '...' : filename;
        } catch {
            return 'image-url';
        }
    }

    /**
     * Handle clipboard paste for images and image URLs
     */
    handleClipboardPaste(e) {
        const items = e.clipboardData?.items;
        if (!items) return;
        
        // First check for image files
        for (let item of items) {
            if (item.type.startsWith('image/')) {
                e.preventDefault();
                const file = item.getAsFile();
                if (file) {
                    this.handleUploadedFiles([file]);
                }
                return;
            }
        }

        // PDF files
        for (let item of items) {
            if (item.type === 'application/pdf') {
                e.preventDefault();
                const file = item.getAsFile();
                if (file) {
                    this.handleUploadedFiles([file]);
                }
                return;
            }
        }

        // Then check for text that might be an image or PDF URL
        for (let item of items) {
            if (item.type === 'text/plain') {
                item.getAsString((text) => {
                    if (this.isImageUrl(text)) {
                        e.preventDefault();
                        this.handleImageUrl(text);
                    }/*  else if (this.isPdfUrl(text)) {
                        e.preventDefault();
                        this.handlePdfUrl(text);
                    } */
                    return;
                });
            }
        }
    }

    
    /**
     * Send a message in the current conversation (ASYNC MODE)
     */
    async sendMessage() {
        if (!this.currentConversationId || !this.messageInput || !this.messageInput.value.trim()) return;

        this.sendMessageBtn.disabled = true;
        this.executionStopped = false;
        
        const message = this.messageInput.value.trim();
        let messageContent = message;
        // if image, PDF preview data is available, add it to the message content
        if (this.imgPreviewData.length > 0 || this.pdfPreviewData.length > 0) {
            messageContent = [
                {
                    'type': 'text',
                    'text': message
                },
                ...this.imgPreviewData.map((imageData) => {
                    return {
                        'type': 'image_url',
                        'image_url': {
                            'url': imageData
                        }
                    };
                }),
                ...this.pdfPreviewData.map((pdfData) => {
                    return {
                        'type': 'file',
                        'file': {
                            'filename': pdfData.filename,
                            'file_data': pdfData.file_data
                        }
                    };
                })
            ];
        }
        // clear image preview data + elements
        this.imgPreviewData = [];
        this.pdfPreviewData = [];
        this.imageUploadPreview.innerHTML = '';
        this.imageUploadPreview.classList.add('d-none');
        this.imageUpload.value = '';

        const maxOutput = this.getMaxOutput();
        
        this.messageInput.value = '';
        // reset message input height
        this.messageInput.dispatchEvent(new Event('input'));
        
        try {
            // Add user message to UI immediately
            this.addUserMessageToUI(messageContent);
            
            // Add loading indicator for assistant response
            const loadingEl = this.addLoadingIndicator();
            
            // Send message to API (async - returns immediately)
            const response = await this.apiService.sendMessageAsync(this.currentConversationId, messageContent, maxOutput);
            
            // Remove loading indicator
            if (this.chatMessages && loadingEl) {
                this.chatMessages.removeChild(loadingEl);
            }
            
            if (response.error) {
                throw new Error(response.error);
            }
            
            // Add AI's response to UI
            this.addAssistantMessageToUI(response.message);
            
            // If AI wants to use tools, execute them
            if (response.requiresToolExecution && response.toolCalls) {
                await this.executeToolChain(response.message.id, response.toolCalls);
            }
            
            // Update conversation list to reflect changes
            this.loadConversations();

            // update credit indicator
            this.updateCreditIndicator();
            
            // Trigger async database vacuum (user is now reading the response)
            if (window.databaseVacuum) {
                window.databaseVacuum.vacuum().catch(err => {
                    console.error('Background vacuum failed:', err);
                });
            }
            
        } catch (error) {
            console.error('Error sending message:', error);
            window.toast.error(error.message || 'Failed to send message');

            this.sendMessageBtn.disabled = false;
        }
        this.sendMessageBtn.disabled = false;
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

            // toggle tools and conversations panel
            this.toggleToolsAndConversationsPanel();
            
            // Show success message
            window.toast.success(window.translations && window.translations['spirit.chat.conversation_created'] ? window.translations['spirit.chat.conversation_created'] : 'Conversation created');
            
        } catch (error) {
            console.error('Error creating conversation:', error);
            window.toast.error(error.message || 'Failed to create conversation');
        }
    }

    // ========================================================================
    // ASYNC CONVERSATION HELPER METHODS
    // ========================================================================

    /**
     * Get max output from slider
     */
    getMaxOutput() {
        return this.responseMaxOutputSlider ? this.responseMaxOutputSlider.value : 500;
    }

    /**
     * Add user message to UI
     */
    addUserMessageToUI(messageContent) {
        if (!this.chatMessages) return;
        
        // Clear welcome message if present (first message)
        const welcomeMessage = this.chatMessages.querySelector('#welcomeMessage');
        if (welcomeMessage) {
            welcomeMessage.remove();
        }

        const userMessage = {
            role: 'user',
            content: messageContent,
            timestamp: new Date().toISOString()
        };
        
        const messageEl = document.createElement('div');
        messageEl.className = 'chat-message chat-message-user';
        
        const date = new Date(userMessage.timestamp);
        const formattedDate = date.toLocaleDateString('sk-SK', { month: '2-digit', day: '2-digit', timeZone: 'Europe/Prague'});
        const formattedTime = date.toLocaleTimeString('sk-SK', { hour: '2-digit', minute: '2-digit', timeZone: 'Europe/Prague'});

        // format message content
        let formattedContent = '';
        if (Array.isArray(userMessage.content)) {
            formattedContent = userMessage.content.map(item => {
                if (item.type === 'text') {
                    return this.formatMessageContent(item.text);
                } else if (item.type === 'image_url') {
                    return `<img src="${item.image_url.url}" alt="" class="chat-image-preview">`;
                } else if (item.type === 'file') {
                    return `<div class="chat-file-preview rounded text-cyber bg-dark bg-opacity-25 cursor-pointer mb-2"
                                onclick="this.querySelector('.embed-container').classList.toggle('d-none');">
                            <div class="d-flex align-items-center px-1">
                                <i class="mdi mdi-file-pdf-box me-1" style="font-size: 1.6rem; padding: 0 0.3rem !important;"></i>
                                <span class="text-cyber">${item.file.filename}</span>
                            </div>
                            <div class="p-2 pt-0 d-none embed-container">
                                <embed src="${item.file.file_data}"
                                    width="100%" height="420"
                                    class="rounded"
                                    type="application/pdf"
                                    title="${item.file.filename}" />
                            </div>
                        </div>`;
                }
            }).join('');
        } else {
            formattedContent = this.formatMessageContent(userMessage.content);
        }
        
        messageEl.innerHTML = formattedContent != '' ? `
            <div class="chat-bubble">
                <div class="chat-content">${formattedContent}</div>
                <div class="chat-timestamp">${formattedDate} <span class="text-cyber opacity-75">/</span> ${formattedTime}</div>
            </div>
        ` : '';
        
        this.chatMessages.appendChild(messageEl);
        this.chatMessages.scrollIntoView({ behavior: 'smooth', block: 'end' });
    }

    /**
     * Add loading indicator
     */
    addLoadingIndicator() {
        if (!this.chatMessages) return null;

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
        
        this.chatMessages.appendChild(loadingEl);
        this.chatMessages.scrollIntoView({ behavior: 'smooth', block: 'end' });
        
        return loadingEl;
    }

    /**
     * Add assistant message to UI
     */
    addAssistantMessageToUI(message) {
        if (!this.chatMessages) return;

        const messageEl = document.createElement('div');
        messageEl.className = 'chat-message chat-message-assistant';
        
        const date = new Date(message.timestamp || message.createdAt);
        const formattedDate = date.toLocaleDateString('sk-SK', { month: '2-digit', day: '2-digit', timeZone: 'Europe/Prague'});
        const formattedTime = date.toLocaleTimeString('sk-SK', { hour: '2-digit', minute: '2-digit', timeZone: 'Europe/Prague'});

        // Format content - handle different message types
        let formattedContent = '';
        
        // Handle assistant messages with tool_use (content is full message object)
        if (message.type === 'tool_use' && message.content && typeof message.content === 'object') {
            // Extract text content from the full message object
            const textContent = message.content.content;
            // Handle null, empty string, or actual content (array vs string demon!)
            if (textContent && textContent !== '') {
                if (typeof textContent === 'string') {
                    formattedContent = this.formatMessageContent(textContent);
                } else if (Array.isArray(textContent)) {
                    formattedContent = textContent
                        .filter(item => item.type === 'text')
                        .map(item => this.formatMessageContent(item.text))
                        .join('');
                }
            }
            // Don't show tool execution block in real-time - it will be shown separately
            // This is only for when loading from history
        } else if (Array.isArray(message.content)) {
            formattedContent = message.content
                .filter(item => item.type === 'text')  // Only show text blocks
                .map(item => this.formatMessageContent(item.text))
                .join('');
        } else if (typeof message.content === 'string') {
            formattedContent = this.formatMessageContent(message.content);
        }
        
        // Only add message if there's actual content to display
        if (!formattedContent.trim()) {
            return;
        }
        
        messageEl.innerHTML = formattedContent != '' ? `
            <div class="chat-bubble">
                <div class="chat-content">${formattedContent}</div>
                <div class="chat-timestamp">${formattedDate} <span class="text-cyber opacity-75">/</span> ${formattedTime}</div>
            </div>
        ` : '';
        
        this.chatMessages.appendChild(messageEl);
        this.chatMessages.scrollIntoView({ behavior: 'smooth', block: 'end' });
    }

    /**
     * Execute tool chain (loop until no more tools needed)
     */
    async executeToolChain(messageId, toolCalls) {
        this.isExecutingTools = true;
        
        // Show stop button
        if (this.stopExecutionBtn) {
            this.stopExecutionBtn.classList.remove('d-none');
        }
        
        while (toolCalls && !this.executionStopped) {
            // Add tool execution indicator
            const toolIndicator = this.addToolExecutionToUI(toolCalls);
            
            // Extract tool names for later use
            const toolNames = toolCalls.map(tc => tc.function?.name || tc.name || 'unknown').join(', ');
            
            try {
                // Execute tools
                const response = await this.apiService.executeTools(
                    this.currentConversationId,
                    messageId,
                    toolCalls
                );
                
                if (response.error) {
                    throw new Error(response.error);
                }
                
                // Replace loading spinner with success indicator
                if (toolIndicator) {
                    toolIndicator.innerHTML = `
                        <div class="d-flex align-items-center gap-2 p-2 bg-success bg-opacity-10 rounded border border-success border-opacity-25">
                            <i class="mdi mdi-check-circle text-success"></i>
                            <span class="text-muted small">🔧 Executed: ${toolNames}</span>
                        </div>
                    `;
                }
                
                // Display tool results (frontendData)
                if (response.toolResults) {
                    this.addToolResultsToUI(response.toolResults);
                }
                
                // Add AI's next response
                this.addAssistantMessageToUI(response.message);
                
                // Update credit indicator
                this.updateCreditIndicator();
                
                // Check if more tools needed
                if (!response.requiresToolExecution) {
                    break;
                }
                
                // Continue with next tool calls
                messageId = response.message.id;
                toolCalls = response.toolCalls;
                
            } catch (error) {
                console.error('Error executing tools:', error);
                // Remove loading indicator on error
                if (toolIndicator) {
                    toolIndicator.remove();
                }
                this.addErrorMessageToUI(error.message);
                break;
            }
        }
        
        this.isExecutingTools = false;
        this.sendMessageBtn.disabled = false;
        
        // Hide stop button
        if (this.stopExecutionBtn) {
            this.stopExecutionBtn.classList.add('d-none');
        }
    }

    // add content showcase icon event listener
    showContentShowcase(element) {
        element.querySelectorAll('.content-showcase-icon').forEach(el => {
            let showcase = el.parentElement;
            el.addEventListener('click', (e) => {
                if (this.contentShowcaseModal) {    
                    e.stopPropagation();
                    e.preventDefault();

                    // update modal content
                    this.contentShowcaseModal.querySelector('.contentShowcaseModal-content').innerHTML = showcase.innerHTML;

                    // remove icon
                    this.contentShowcaseModal.querySelector('.contentShowcaseModal-content').querySelector('.content-showcase-icon').remove();

                    // update modal embed height
                    let embedContainer = this.contentShowcaseModal.querySelector('.embed-container');
                    if (embedContainer) {
                        embedContainer.querySelector('embed')?.setAttribute('height', '100%');
                        embedContainer.classList.remove('d-none');
                        embedContainer.classList.add('h-100');
                        this.contentShowcaseModal.querySelector('.chat-file-preview-title')?.remove();
                    }

                    // update `chat-image-preview` class
                    this.contentShowcaseModal.querySelector('.chat-image-preview')?.classList.remove('ms-2');
                    
                    // show modal
                    const newContentShowcaseModal = new bootstrap.Modal(this.contentShowcaseModal);
                    newContentShowcaseModal.show();                    
                }
            });
        });
    }

    /**
     * Add tool execution indicator to UI
     * Returns the element so it can be removed later
     */
    addToolExecutionToUI(toolCalls) {
        if (!this.chatMessages || !toolCalls) return null;

        const toolEl = document.createElement('div');
        toolEl.className = 'chat-message chat-message-tool';
        
        const toolNames = toolCalls.map(tc => {
            return tc.function?.name || tc.name || 'unknown';
        }).join(', ');
        
        toolEl.innerHTML = `
            <div class="d-flex align-items-center gap-2 p-2 bg-dark bg-opacity-25 rounded">
                <div class="spinner-border spinner-border-sm text-cyber" role="status"></div>
                <span class="text-cyber small">🔧 Executing: ${toolNames}</span>
            </div>
        `;
        
        this.chatMessages.appendChild(toolEl);
        this.chatMessages.scrollIntoView({ behavior: 'smooth', block: 'end' });
        
        return toolEl;
    }

    /**
     * Add tool results to UI
     * Displays frontendData from tool execution results
     */
    addToolResultsToUI(toolResults) {
        if (!this.chatMessages || !toolResults) return;

        // Process each tool result and display frontendData if present
        toolResults.forEach(toolResult => {
            if (toolResult.frontendData) {
                const frontendDataEl = document.createElement('div');
                frontendDataEl.className = 'chat-message chat-message-system';
                
                frontendDataEl.innerHTML = `
                    <div data-src='injected system data' data-type='tool_calls_frontend_data' data-ai-generated='false' class='bg-dark p-2 mb-2 rounded d-block w-100'>
                        ${toolResult.frontendData}
                    </div>
                `;
                
                this.chatMessages.appendChild(frontendDataEl);
                this.showContentShowcase(frontendDataEl);
            }
        });
        
        this.chatMessages.scrollIntoView({ behavior: 'smooth', block: 'end' });
    }

    /**
     * Add error message to UI
     */
    addErrorMessageToUI(errorMessage) {
        if (!this.chatMessages) return;

        const errorEl = document.createElement('div');
        errorEl.className = 'chat-message chat-message-error';
        
        errorEl.innerHTML = `
            <div class="p-2 bg-danger bg-opacity-10 rounded border border-danger border-opacity-25">
                <i class="mdi mdi-alert-circle text-danger"></i>
                <span class="text-danger small">${errorMessage}</span>
            </div>
        `;
        
        this.chatMessages.appendChild(errorEl);
        this.chatMessages.scrollIntoView({ behavior: 'smooth', block: 'end' });
    }

    /**
     * Stop tool execution
     */
    async stopExecution() {
        this.executionStopped = true;
        
        try {
            await this.apiService.stopExecution(this.currentConversationId);
            this.addErrorMessageToUI('Execution stopped by user');
        } catch (error) {
            console.error('Error stopping execution:', error);
        }
        
        this.isExecutingTools = false;
        this.sendMessageBtn.disabled = false;
    }
}
