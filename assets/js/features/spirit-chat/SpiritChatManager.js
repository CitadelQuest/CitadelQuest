import { SpiritChatApiService } from './SpiritChatApiService';
import { updatesService } from '../../services/UpdatesService';
import * as bootstrap from 'bootstrap';
//import { marked } from 'marked';
import MarkdownIt from 'markdown-it';
import * as animation from '../../shared/animation';
import { ImageShowcase } from '../../shared/image-showcase';

/**
 * Spirit Chat Manager
 * Manages the Spirit chat UI and interactions
 */
export class SpiritChatManager {
    constructor() {
        this.apiService = new SpiritChatApiService();
        this.currentSpiritId = null;
        this.currentSpirit = null; // Store current spirit data to avoid re-fetching
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
        this.lastMessageUsage = null;
        
        // Pagination state
        this.messageLimit = 10; // Messages per page
        this.currentOffset = 0;
        this.hasMoreMessages = false;
        this.totalMessages = 0;
        
        // DOM Elements
        this.spiritIcon = document.getElementById('spiritIcon');
        this.spiritChatModal = document.getElementById('spiritChatModal');
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
        this.deleteConversationModalSubmit = document.getElementById('deleteConversationModalSubmit');
        this.deleteConversationModalCancel = document.getElementById('deleteConversationModalCancel');
        this.conversationTitle = document.getElementById('conversationTitle');
        this.spiritChatModalTitle = document.getElementById('spiritChatModalTitle');
        this.responseMaxOutputSlider = document.getElementById('responseMaxOutputSlider');
        this.responseMaxOutputValue = document.getElementById('responseMaxOutputValue');
        this.responseTemperatureSlider = document.getElementById('responseTemperatureSlider');
        this.responseTemperatureValue = document.getElementById('responseTemperatureValue');
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
        this.chatInfoSecondaryAiModel = document.getElementById('chatInfoSecondaryAiModel');
        this.chatInfoIcon = document.getElementById('chatInfoIcon');
        this.chatInfo = document.getElementById('chatInfo');
        this.contentShowcaseModal = document.getElementById('contentShowcaseModal');
        this.contextWindowUsageBar = document.getElementById('contextWindowUsageBar');
        this.contextWindowUsage = document.getElementById('contextWindowUsage');
        
        // Context window tracking
        this.primaryModelContextWindow = null;
        this.currentContextUsage = 0;
        
        // Image showcase for fullscreen viewing (uses existing modal)
        this.imageShowcase = new ImageShowcase('contentShowcaseModal');
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
            // Restore selected spirit if saved
            const savedSpiritId = localStorage.getItem('selectedSpiritId');
            if (savedSpiritId && window.spiritDropdownManager) {
                window.spiritDropdownManager.selectSpirit(savedSpiritId);
            }
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
            <i class="d-none ms-1 mdi mdi-gauge${creditBalance < 0 ? '-empty text-danger' : creditBalance < 30 ? '-low text-danger' : creditBalance < 60 ? ' text-warning' : creditBalance > 500 ? '-full text-success' : ' text-cyber'}" 
                title="${creditBalanceText} Credits"></i>
            <span class="text-muted" style="font-size: 0.6rem !important;" title="${creditBalanceText} Credits">
                <i class="mdi mdi-circle-multiple-outline text-cyber opacity-75 me-1 ms-1"></i>${creditBalanceText}
            </span>
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
                }, { once: true });
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

        // Response temperature slider
        if (this.responseTemperatureSlider) {
            this.responseTemperatureSlider.addEventListener('input', (event) => {
                this.responseTemperatureValue.textContent = event.target.value;
                localStorage.setItem('config.chat.settings.responseTemperature.value', event.target.value);
            });
        }

        // Response temperature value click
        if (this.responseTemperatureValue) {
            this.responseTemperatureValue.addEventListener('click', (event) => {
                let newTemperature = prompt('Enter response temperature', event.target.textContent); 
                if (newTemperature) { 
                    this.responseTemperatureSlider.value = newTemperature;
                    localStorage.setItem('config.chat.settings.responseTemperature.value', newTemperature);
                    this.responseTemperatureSlider.dispatchEvent(new Event('input')); 
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
     * Fetch the user's primary spirit or selected spirit
     */
    async fetchPrimarySpirit() {
        // Check if we're in onboarding mode
        const isOnboarding = window.location.pathname.includes('/welcome') && localStorage.getItem('currentStep') !== null;
        if (isOnboarding) {
            return;
        }

        // Get selected spirit ID from dropdown manager or default to primary
        let spiritId = null;
        if (window.spiritDropdownManager) {
            spiritId = window.spiritDropdownManager.getSelectedSpiritId();
        }

        try {
            let response;
            if (spiritId) {
                // Fetch specific spirit
                response = await fetch(`/api/spirit/${spiritId}/settings`);
                if (response.ok) {
                    const settings = await response.json();
                    // Get spirit data from list
                    const listResponse = await fetch('/api/spirit/list');
                    const listData = await listResponse.json();
                    const spirit = listData.spirits?.find(s => s.id === spiritId);
                    if (spirit) {
                        spirit.settings = settings;
                        this.currentSpiritId = spirit.id;
                        this.updateSpiritUI(spirit);
                        return;
                    }
                }
            }

            // Fallback to primary spirit
            response = await fetch('/api/spirit');
            if (!response.ok) {
                throw new Error('Failed to fetch primary spirit');
            }

            const spirit = await response.json();
            this.currentSpiritId = spirit.id;
            this.updateSpiritUI(spirit);

        } catch (error) {
            console.error('Error fetching spirit:', error);
        }
    }

    async updateSpiritUI(spirit) {
        // Store spirit data for reuse
        this.currentSpirit = spirit;
        
        // Update Spirit color immediately (before any async operations)
        let spiritAvatar = document.querySelectorAll('.spiritChatButtonIcon');
        if (spiritAvatar) {
            let visualState = spirit.settings?.visualState || '{"color":"#95ec86"}';
            let color = null;
            try {
                color = JSON.parse(visualState)?.color || null;
            } catch (e) {
                // visualState might be just a string, use default
                color = '#95ec86';
            }
            if (color) {
                spiritAvatar.forEach(icon => icon.style.color = color);
            }
        }
        
        // Update UI with spirit info
        if (this.spiritNames && this.spiritNames.length > 0) {
            this.spiritNames.forEach(name => name.textContent = spirit.name);
        }

        if (this.spiritLevel) {
            const levelText = window.translations && window.translations['spirit.level'] ? window.translations['spirit.level'] : 'Level';
            const level = spirit.settings?.level || '1';
            this.spiritLevel.textContent = `${levelText}: ${level}`;
        }

        // set response max output and load AI model info
        if (this.responseMaxOutputSlider) {
            let maxOutput = '4096';
            try {
                // Check if spirit has a specific AI model configured
                let modelId = spirit.settings?.aiModel;
                let apiUrl = modelId ? `/api/ai/gateway/model/${modelId}` : '/api/ai/gateway/primary-model';
                
                let response = await fetch(apiUrl, {
                    method: 'GET'
                });
                let data = await response.json();
                if (data.maxOutput) {
                    maxOutput = data.maxOutput;
                    this.updatePrimaryModelInfo(data);
                    // Store context window for usage calculation
                    this.primaryModelContextWindow = data.contextWindow || null;
                }
            } catch (error) {
                console.error('Error fetching AI model:', error);
            }

            this.responseMaxOutputSlider.max = maxOutput;
            localStorage.setItem('config.chat.settings.responseMaxOutput.value', maxOutput);
            this.responseMaxOutputSlider.value = localStorage.getItem('config.chat.settings.responseMaxOutput.value') || maxOutput;

            this.responseMaxOutputValue.textContent = this.responseMaxOutputSlider.value;
        }

        // Load secondary AI model info
        await this.loadSecondaryModelInfo();

        // set response temperature
        if (this.responseTemperatureSlider) {
            this.responseTemperatureSlider.value = localStorage.getItem('config.chat.settings.responseTemperature.value') || '0.7';
            localStorage.setItem('config.chat.settings.responseTemperature.value', this.responseTemperatureSlider.value);

            this.responseTemperatureValue.textContent = this.responseTemperatureSlider.value;
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
    }

    /**
     * Switch to a different spirit
     */
    async switchSpirit(spiritId) {
        if (this.currentSpiritId === spiritId) {
            return;
        }

        this.currentSpiritId = spiritId;

        // Clear current conversation
        this.currentConversationId = null;
        this.conversations = [];

        // Clear chat messages
        if (this.chatMessages) {
            this.chatMessages.innerHTML = '';
        }

        // Fetch and update spirit UI
        try {
            const response = await fetch(`/api/spirit/${spiritId}/settings`);
            if (response.ok) {
                const settings = await response.json();
                // Get spirit data from list
                const listResponse = await fetch('/api/spirit/list');
                const listData = await listResponse.json();
                const spirit = listData.spirits?.find(s => s.id === spiritId);
                if (spirit) {
                    spirit.settings = settings;
                    this.updateSpiritUI(spirit);
                }
            }
        } catch (error) {
            console.error('Error switching spirit:', error);
        }

        // Reload conversations for new spirit
        await this.loadConversations(true);
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
                            <span class="" title="Context window size"><i class="mdi mdi-chart-donut text-cyber opacity-75 me-1 ms-2"></i>${conversation.lastMsgUsage?.totalTokensFormatted || '0'}</span>
                            <span class="" title="Conversation data size"><i class="mdi mdi-database text-cyber opacity-75 me-1 ms-2"></i>${conversation.formattedSize || '0 B'}</span> <span class="text-cyber d-none">/</span>
                            <span class="" title="Messages count"><i class="mdi mdi-message-outline text-cyber opacity-75 me-1 ms-2"></i>${conversation.messagesCount || '0'}</span> <span class="text-cyber d-none">/</span>
                            <span class="" title="User images count"><i class="mdi mdi-image-outline text-cyber opacity-75 me-1 ms-2"></i>${conversation.imagesCount || '0'}</span>
                            <span class="" title="Tool use count"><i class="mdi mdi-tools text-cyber opacity-75 me-1 ms-2"></i>${conversation.toolsCount || '0'}</span>
                            <span class="" title="Tokens"><i class="mdi mdi-tally-mark-5 text-cyber opacity-75 me-1 ms-2"></i>${conversation.tokens?.total_tokens_formatted || '0'}</span>
                            <span class="" title="Credits - img gen not included"><i class="mdi mdi-circle-multiple-outline text-cyber opacity-75 me-1 ms-2"></i>${conversation.price?.total_price.toFixed(0) || '0'}</span>
                            <span class="" title="Conversation start"><i class="mdi mdi-clock-outline text-cyber opacity-75 me-1 ms-2"></i>${formattedDate} <span class="text-cyber">/</span> ${formattedTime}</span>
                        </small>
                    </div>
                `;
                
                item.addEventListener('click', (e) => {
                    e.preventDefault();
                    
                    // Update context window usage from conversation's last message
                    this.lastMessageUsage = conversation.lastMsgUsage || null;
                    this.updateContextWindowUsage(conversation.lastMsgUsage?.totalTokens || 0);
                    
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
                    // Only load last conversation if it belongs to current spirit
                    let lastConversationId = localStorage.getItem('config.chat.last_conversation_id');
                    let lastConversationSpiritId = localStorage.getItem('config.chat.last_conversation_spirit_id');
                    
                    // Check if the last conversation belongs to the current spirit
                    if (lastConversationId && lastConversationSpiritId === this.currentSpiritId) {
                        const lastConv = this.conversations.find(c => c.id === lastConversationId);
                        if (lastConv) {
                            this.currentConversationId = lastConversationId;
                            await this.loadConversation(lastConversationId);
                        }
                    } else {
                        this.spiritChatModalTitle.textContent = '';
                        // toggle only if hidden
                        let open = localStorage.getItem('config.chat.toolsAndConversations.open') === 'true';
                        if (!open) {
                            this.toggleToolsAndConversationsPanel();
                        }
                    }
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
        
        // Reset pagination state for new conversation
        this.currentOffset = 0;
        this.hasMoreMessages = false;
        this.totalMessages = 0;
        
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
            
            // Fetch conversation with pagination (last N messages)
            const conversation = await this.apiService.getConversation(conversationId, this.messageLimit, this.currentOffset);
            this.currentConversationId = conversationId;
            localStorage.setItem('config.chat.last_conversation_id', conversationId);
            localStorage.setItem('config.chat.last_conversation_spirit_id', this.currentSpiritId);
            
            // Store pagination info
            if (conversation.pagination) {
                this.totalMessages = conversation.pagination.total;
                this.hasMoreMessages = conversation.pagination.hasMore;
            }
            
            // Update modal title
            this.spiritChatModalTitle.innerHTML = conversation.title /* + '<i class="mdi mdi-forum text-dark ms-2 fs-6 opacity-50"></i>' */;
            
            // Render messages (with "load more" button if needed)
            this.renderMessages(conversation.messages, true);

            // update conversation tokens and price
            let lastMsgUsage = null;
            conversation.messages.forEach(msg => {
                if (msg.usage && msg.usage.totalTokens) {
                    lastMsgUsage = msg.usage;
                }
            });
            this.updateContextWindowUsage(parseInt(lastMsgUsage?.totalTokens || 0));
            
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
     * Load older messages (pagination)
     */
    async loadOlderMessages() {
        if (this.isLoadingMessages || !this.hasMoreMessages || !this.currentConversationId) return;
        this.isLoadingMessages = true;
        
        // Increase offset to get older messages
        this.currentOffset += this.messageLimit;
        
        try {
            // Show loading indicator at top
            const loadMoreBtn = this.chatMessages.querySelector('#loadMoreMessagesBtn');
            if (loadMoreBtn) {
                loadMoreBtn.innerHTML = `
                    <div class="spinner-border spinner-border-sm text-cyber me-2" role="status"></div>
                    ${window.translations?.['spirit.chat.loading'] || 'Loading...'}
                `;
                loadMoreBtn.disabled = true;
            }
            
            // Remember the first currently visible message to scroll back to it
            const firstVisibleMessage = this.chatMessages.querySelector('.chat-message');
            
            // Fetch older messages
            const conversation = await this.apiService.getConversation(
                this.currentConversationId, 
                this.messageLimit, 
                this.currentOffset
            );
            
            // Update pagination info
            if (conversation.pagination) {
                this.hasMoreMessages = conversation.pagination.hasMore;
            }
            
            // Prepend older messages to chat
            this.prependMessages(conversation.messages);
            
            // Scroll to the message that was first visible before loading (after DOM update)
            if (firstVisibleMessage) {
                // Use requestAnimationFrame to wait for DOM to be fully rendered
                requestAnimationFrame(() => {
                    requestAnimationFrame(() => {
                        firstVisibleMessage.scrollIntoView({ behavior: 'instant', block: 'end' });
                        
                    });
                });
            }
            
        } catch (error) {
            console.error('Error loading older messages:', error);
            // Revert offset on error
            this.currentOffset -= this.messageLimit;
        } finally {
            this.isLoadingMessages = false;
        }
    }
    
    /**
     * Prepend older messages to the chat (at the top)
     * @param {Array} messages - Array of message objects to prepend
     */
    prependMessages(messages) {
        if (!this.chatMessages || messages.length === 0) return;
        
        // Remove existing "load more" button
        const existingBtn = this.chatMessages.querySelector('#loadMoreMessagesBtn');
        if (existingBtn) {
            existingBtn.parentElement.remove();
        }
        
        // Create a fragment to hold the new messages
        const fragment = document.createDocumentFragment();
        
        // Add "load more" button if there are more messages
        if (this.hasMoreMessages) {
            const loadMoreContainer = document.createElement('div');
            loadMoreContainer.className = 'text-center p-2 mb-2';
            loadMoreContainer.innerHTML = `
                <button type="button" id="loadMoreMessagesBtn" class="btn btn-outline-cyber btn-sm">
                    <i class="mdi mdi-history me-1"></i>
                    ${window.translations?.['spirit.chat.load_older'] || 'Load older messages'}
                </button>
            `;
            fragment.appendChild(loadMoreContainer);
            
            // Add click event listener only (no observer here - scroll positions user below button)
            loadMoreContainer.querySelector('#loadMoreMessagesBtn').addEventListener('click', () => {
                this.loadOlderMessages();
            });
        }
        
        // Render each message and add to fragment
        messages.forEach(message => {
            const messageEl = this.createMessageElement(message);
            if (messageEl) {
                fragment.appendChild(messageEl);
            }
        });
        
        // Insert at the beginning of chat messages
        this.chatMessages.insertBefore(fragment, this.chatMessages.firstChild);
        // Note: Observer is set up in loadOlderMessages() after scroll completes
    }
    
    /**
     * Setup scroll listener for auto-loading older messages when user scrolls to top
     */
    setupScrollAutoLoad() {
        // The scroll happens on modal-body, not chatMessages (due to modal-dialog-scrollable)
        const scrollContainer = this.spiritChatModal?.querySelector('.modal-body');
        if (!scrollContainer) return;
        
        // Remove existing listener if any
        if (this._scrollHandler) {
            scrollContainer.removeEventListener('scroll', this._scrollHandler);
        }
        
        // Store reference for cleanup
        this._scrollContainer = scrollContainer;
        
        // Create scroll handler
        this._scrollHandler = () => {
            // Only trigger if: scrolled near top, not loading, has more messages
            if (scrollContainer.scrollTop < 100 && !this.isLoadingMessages && this.hasMoreMessages) {
                this.loadOlderMessages();
            }
        };
        
        // Add scroll listener
        scrollContainer.addEventListener('scroll', this._scrollHandler);
    }
    
    /**
     * Render messages in the chat container
     * @param {Array} messages - Array of message objects
     * @param {boolean} isInitialLoad - Whether this is the initial load (shows "load more" button)
     */
    renderMessages(messages, isInitialLoad = false) {
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
        
        // Add "load more" button at the top if there are more messages
        if (isInitialLoad && this.hasMoreMessages) {
            const loadMoreContainer = document.createElement('div');
            loadMoreContainer.className = 'text-center p-2 mb-2';
            loadMoreContainer.innerHTML = `
                <button type="button" id="loadMoreMessagesBtn" class="btn btn-outline-cyber btn-sm">
                    <i class="mdi mdi-history me-1"></i>
                    ${window.translations?.['spirit.chat.load_older'] || 'Load older messages'}
                </button>
            `;
            this.chatMessages.appendChild(loadMoreContainer);
            
            // Add click event listener (manual fallback)
            loadMoreContainer.querySelector('#loadMoreMessagesBtn').addEventListener('click', () => {
                this.loadOlderMessages();
            });
        }
        
        // Render each message
        messages.forEach(message => {
            const messageEl = this.createMessageElement(message);
            if (messageEl) {
                this.chatMessages.appendChild(messageEl);
            }
        });
        
        // Scroll to bottom
        this.chatMessages.scrollIntoView({ behavior: 'instant', block: 'end' });
        
        // Setup scroll listener for auto-loading (after scroll to bottom)
        if (this.hasMoreMessages) {
            setTimeout(() => this.setupScrollAutoLoad(), 100);
        }
        
        // Focus input
        /* if (this.messageInput) {
            this.messageInput.focus();
        } */
    }
    
    /**
     * Create a message element from message data
     * @param {Object} message - Message object
     * @returns {HTMLElement|null} - The message element or null
     */
    createMessageElement(message) {
        // Handle tool result messages - display frontendData if present
        if (message.role === 'tool' || message.type === 'tool_result') {
            // Check if message content has frontendData
            let content = message.content;
            const fragment = document.createDocumentFragment();
            
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
                        
                        this.showContentShowcase(frontendDataEl);
                        fragment.appendChild(frontendDataEl);
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
                
                this.showContentShowcase(frontendDataEl);
                fragment.appendChild(frontendDataEl);
            }
            
            return fragment.childNodes.length > 0 ? fragment : null;
        }
        
        const messageEl = document.createElement('div');
        messageEl.className = `chat-message ${message.role === 'user' ? 'chat-message-user' : 'chat-message-assistant'}`;
        
        // Format timestamp
        let timestampHtml = '';
        if (message.timestamp) {
            const date = new Date(message.timestamp);
            const formattedDate = date.toLocaleDateString('sk-SK', { month: '2-digit', day: '2-digit', timeZone: 'Europe/Prague'});
            const formattedTime = date.toLocaleTimeString('sk-SK', { hour: '2-digit', minute: '2-digit', timeZone: 'Europe/Prague'});
            timestampHtml = `<div class="chat-timestamp">${formattedDate} <i class="mdi mdi-circle-small opacity-75 me-1"></i> ${formattedTime}</div>`;
        }
        
        // Format usage info (tokens and price) for assistant messages
        let usageHtml = '<div></div>';
        if (message.role === 'assistant' && message.usage) {
            const tokens = message.usage.totalTokensFormatted;
            const price = message.usage.totalPriceFormatted;
            if (tokens > 0 || price > 0) {
                usageHtml = `
                    <div class="chat-usage small text-muted opacity-50">
                        <span title="Tokens"><i class="mdi mdi-tally-mark-5 me-1 text-cyber opacity-50"></i>${tokens}</span>
                        <i class="mdi mdi-circle-small opacity-75 me-1"></i>
                        <span title="Credits"><i class="mdi mdi-circle-multiple-outline me-1 text-cyber opacity-50"></i>${price}</span>
                    </div>`;
            }
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
                
                // Format usage info for tool execution
                let toolUsageHtml = '';
                if (message.usage) {
                    const tokens = message.usage.totalTokensFormatted;
                    const price = message.usage.totalPriceFormatted;
                    if (tokens > 0 || price > 0) {
                        toolUsageHtml = `
                            <span class="small text-muted opacity-50 ms-2">
                                <i class="mdi mdi-tally-mark-5 me-1 text-cyber opacity-50" title="Tokens"></i>${tokens}
                                <i class="mdi mdi-circle-small opacity-75 me-1"></i>
                                <i class="mdi mdi-circle-multiple-outline me-1 text-cyber opacity-50" title="Credits"></i>${price}
                            </span>`;
                    }
                }
                
                toolExecutionHtml = `
                    <div class="d-flex align-items-center gap-2 p-2 bg-success bg-opacity-10 rounded border border-success border-opacity-25">
                        <span class="text-muted small"><i class="mdi mdi-tools text-cyber opacity-75 me-2"></i>Executed: <strong>${toolNames}</strong></span>
                        ${toolUsageHtml}
                    </div>
                `;
            }
        } else if (Array.isArray(message.content)) {
            formattedContent = message.content.map(item => {
                if (item.type === 'text') {
                    let formattedText = this.formatMessageContent(item.text);
                    return (formattedText != '' ? `<div style="clear: both;"></div>` : '') + formattedText;
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
        
        if (hasToolExecution) {
            // prevent duplicate usage info message when tool execution is present
            usageHtml = '<div></div>';
        }

        messageEl.innerHTML = (formattedContent != '') ? `
            <div class="chat-bubble">
                <div class="chat-content">${formattedContent}</div>
                <div class="chat-meta d-flex flex-wrap align-items-center justify-content-between">${usageHtml}${timestampHtml}</div>
            </div>
        ` : '';

        // add content showcase icon event listener
        this.showContentShowcase(messageEl);
        
        // If there's tool execution, wrap in a fragment with both elements
        if (hasToolExecution) {
            const fragment = document.createDocumentFragment();
            fragment.appendChild(messageEl);
            
            const toolEl = document.createElement('div');
            toolEl.className = 'chat-message chat-message-tool';
            toolEl.innerHTML = toolExecutionHtml;
            fragment.appendChild(toolEl);
            
            return fragment;
        }
        
        return messageEl;
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
        let originalCaption = this.deleteConversationModalSubmit.innerHTML;
        try {
            if (!this.deleteConversationId) {
                // this should never happen, but just in case
                console.error('No conversation ID found');
                alert('No conversation ID found');
                return;
            }

            this.deleteConversationModalSubmit.disabled = true;
            this.deleteConversationModalSubmit.classList.add('disabled');
            this.deleteConversationModalSubmit.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Deleting...';
            this.deleteConversationModalCancel.disabled = true;
            this.deleteConversationModalCancel.classList.add('disabled');

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
        } finally {
            this.deleteConversationModalSubmit.disabled = false;
            this.deleteConversationModalSubmit.classList.remove('disabled');
            this.deleteConversationModalSubmit.innerHTML = originalCaption;
            this.deleteConversationModalCancel.disabled = false;
            this.deleteConversationModalCancel.classList.remove('disabled');
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

        if (content === '<empty-content />' || content === '<empty-content></empty-content>') {
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
            this.imgPreviewData.push({
                'filename': file.name,
                'url': optimizedDataUrl
            });
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
            
            // Store URL with filename - will be used in image_url structure
            this.imgPreviewData.push({
                'filename': fileName,
                'url': url
            });
            
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
                            'url': imageData.url,
                            'filename': imageData.filename
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
        const temperature = this.getResponseTemperature();
        
        this.messageInput.value = '';
        // reset message input height
        this.messageInput.dispatchEvent(new Event('input'));
        
        // Pause updates polling during AI response processing
        updatesService.pause();
        
        try {
            // Add user message to UI immediately
            this.addUserMessageToUI(messageContent);
            
            // Add loading indicator for assistant response
            const loadingEl = this.addLoadingIndicator();
            
            // Send message to API (async - returns immediately)
            const response = await this.apiService.sendMessageAsync(this.currentConversationId, messageContent, maxOutput, temperature);
            
            // Remove loading indicator
            if (this.chatMessages && loadingEl) {
                this.chatMessages.removeChild(loadingEl);
            }
            
            if (response.error) {
                throw new Error(response.error);
            }
            
            // Add AI's response to UI
            this.addAssistantMessageToUI(response.message);
            
            // Update context window usage from response
            if (response.message?.usage?.totalTokens) {
                this.lastMessageUsage = response.message.usage;
                this.updateContextWindowUsage(response.message.usage.totalTokens);
            }
            
            // If AI wants to use tools, execute them
            if (response.requiresToolExecution && response.toolCalls) {
                await this.executeToolChain(response.message.id, response.toolCalls, maxOutput, 0.5/* temperature */);
            }
            
            // Update conversation list to reflect changes
            this.loadConversations();
            
            // Trigger async database vacuum (user is now reading the response)
            // disabled - it's blocking async tool use
            /* if (window.databaseVacuum) {
                window.databaseVacuum.vacuum().catch(err => {
                    console.error('Background vacuum failed:', err);
                });
            } */
            
        } catch (error) {
            console.error('Error sending message:', error);
            window.toast.error(error.message || 'Failed to send message');

            this.sendMessageBtn.disabled = false;
        } finally {
            // Update credit indicator once at the end (after all tool executions)
            this.updateCreditIndicator();
            
            // Resume updates polling after AI response is complete
            updatesService.resume();
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
            
            // Reset context window usage for new conversation
            this.lastMessageUsage = null;
            this.updateContextWindowUsage(0);

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
     * Update primary AI model info display
     */
    updatePrimaryModelInfo(data) {
        if (!this.chatInfoPrimaryAiModel) return;
        this.chatInfoPrimaryAiModel.innerHTML = 
            '<span class="me-1 fw-bold">' + data.modelName + '</span> ' +
            '[<span class="d-md-inline_ d-none">context window: </span><span class="fw-bold_">' + Number(data.contextWindow).toLocaleString('sk-SK') + '</span>]';
        // Store context window for usage calculation
        this.primaryModelContextWindow = data.contextWindow || null;
    }
    
    /**
     * Update context window usage bar
     * @param {number} usedTokens - Number of tokens used in current conversation
     */
    updateContextWindowUsage(usedTokens) {
        if (!this.contextWindowUsageBar || !this.contextWindowUsage) return;
        
        this.currentContextUsage = usedTokens || 0;
        
        // Get translation for tooltip
        const contextWindowLabel = window.translations?.['spirit.chat.context_window_usage'] || 'Context window usage';
        
        if (!this.primaryModelContextWindow || this.primaryModelContextWindow <= 0) {
            this.contextWindowUsageBar.style.width = '0%';
            this.contextWindowUsage.title = `${contextWindowLabel}: ?`;
            return;
        }
        
        const percentage = Math.min(100, (this.currentContextUsage / this.primaryModelContextWindow) * 100);
        this.contextWindowUsageBar.style.width = percentage.toFixed(1) + '%';
        
        // Update color based on usage (green -> yellow -> red)
        if (percentage < 50) {
            this.contextWindowUsageBar.className = 'rounded opacity-75 bg-cyber';
        } else if (percentage < 80) {
            this.contextWindowUsageBar.className = 'rounded opacity-75 bg-warning';
        } else {
            this.contextWindowUsageBar.className = 'rounded opacity-75 bg-danger';
        }
        
        // Update tooltip with translation
        const usedFormatted = Number(this.currentContextUsage).toLocaleString('sk-SK');
        const totalFormatted = Number(this.primaryModelContextWindow).toLocaleString('sk-SK');
        this.contextWindowUsage.title = `${contextWindowLabel}: ${usedFormatted} / ${totalFormatted} (${percentage.toFixed(1)}%)`;
    }
    
    /**
     * Load and display secondary AI model info
     */
    async loadSecondaryModelInfo() {
        if (!this.chatInfoSecondaryAiModel) return;
        
        try {
            const response = await fetch('/api/settings/ai.secondary_ai_service_model_id');
            if (!response.ok) {
                this.chatInfoSecondaryAiModel.innerHTML = '<span class="text-muted">-</span>';
                return;
            }
            
            const settingsData = await response.json();
            const modelId = settingsData.value;
            
            if (!modelId) {
                this.chatInfoSecondaryAiModel.innerHTML = '<span class="text-muted">-</span>';
                return;
            }
            
            // Fetch model details
            const modelResponse = await fetch(`/api/ai/model/${modelId}`);
            if (!modelResponse.ok) {
                this.chatInfoSecondaryAiModel.innerHTML = '<span class="text-muted">-</span>';
                return;
            }
            
            const modelData = await modelResponse.json();
            if (modelData.model) {
                this.chatInfoSecondaryAiModel.innerHTML = 
                    '<span class="me-1 fw-bold">' + modelData.model.modelName + '</span> ' +
                    '[<span class="d-md-inline_ d-none">context window: </span><span class="fw-bold_">' + Number(modelData.model.contextWindow).toLocaleString('sk-SK') + '</span>]';
            }
        } catch (error) {
            console.error('Error fetching secondary model:', error);
            this.chatInfoSecondaryAiModel.innerHTML = '<span class="text-muted">-</span>';
        }
    }
    
    /**
     * Get max output from slider
     */
    getMaxOutput() {
        return this.responseMaxOutputSlider ? this.responseMaxOutputSlider.value : 500;
    }

    getResponseTemperature() {
        return this.responseTemperatureSlider ? this.responseTemperatureSlider.value : 0.7;
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
        
        // Format usage info (tokens and price)
        let usageHtml = '<div></div>';
        if (message.usage) {
            const tokens = message.usage.totalTokensFormatted;
            const price = message.usage.totalPriceFormatted;
            if (tokens > 0 || price > 0) {
                usageHtml = `
                    <div class="chat-usage small text-muted opacity-50">
                        <span title="Tokens"><i class="mdi mdi-tally-mark-5 me-1 text-cyber opacity-50"></i>${tokens}</span>
                        <i class="mdi mdi-circle-small opacity-75 me-1"></i>
                        <span title="Credits"><i class="mdi mdi-circle-multiple-outline me-1 text-cyber opacity-50"></i>${price}</span>
                    </div>`;
            }
        }
        
        messageEl.innerHTML = formattedContent != '' ? `
            <div class="chat-bubble">
                <div class="chat-content">${formattedContent}</div>
                <div class="chat-meta d-flex flex-wrap align-items-center justify-content-between pt-1">
                    ${usageHtml}
                    <div class="chat-timestamp">${formattedDate} <i class="mdi mdi-circle-small opacity-75"></i> ${formattedTime}</div>
                </div>
            </div>
        ` : '';
        
        this.chatMessages.appendChild(messageEl);
        this.chatMessages.scrollIntoView({ behavior: 'instant', block: 'end' });
    }

    /**
     * Execute tool chain (loop until no more tools needed)
     */
    async executeToolChain(messageId, toolCalls, maxOutput, temperature) {
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
                    toolCalls,
                    maxOutput,
                    temperature
                );
                
                if (response.error) {
                    throw new Error(response.error);
                }
                
                // Replace loading spinner with success indicator
                if (toolIndicator) {
                    // Format usage info for tool execution
                    let toolUsageHtml = '';
                    if (response.message?.usage) {
                        const tokens = response.message.usage.totalTokensFormatted;
                        const price = response.message.usage.totalPriceFormatted;
                        if (tokens > 0 || price > 0) {
                            toolUsageHtml = `
                                <span class="small text-muted opacity-50 ms-2">
                                    <i class="mdi mdi-tally-mark-5 me-1 text-cyber opacity-50" title="Tokens"></i>${tokens} <i class="mdi mdi-circle-small opacity-75 me-1"></i>
                                    <i class="mdi mdi-circle-multiple-outline me-1 text-cyber opacity-50" title="Credits"></i>${price}
                                </span>`;
                        }
                    }
                    
                    toolIndicator.innerHTML = `
                        <div class="d-flex align-items-center gap-2 p-2 bg-success bg-opacity-10 rounded border border-success border-opacity-25">
                            <span class="text-muted small"><i class="mdi mdi-tools text-cyber opacity-75 me-2"></i>Executed: <strong>${toolNames}</strong></span>
                            ${toolUsageHtml}
                        </div>
                    `;
                }
                
                // Display tool results (frontendData)
                if (response.toolResults) {
                    this.addToolResultsToUI(response.toolResults);
                }
                
                // Add AI's next response
                this.addAssistantMessageToUI(response.message);
                
                // Update context window usage from tool response
                if (response.message?.usage?.totalTokens) {
                    this.lastMessageUsage = response.message.usage;
                    this.updateContextWindowUsage(response.message.usage.totalTokens);
                }
                
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
        this.imageShowcase.init(element);
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
                <span class="text-cyber small"><i class="mdi mdi-tools text-cyber opacity-75 me-2"></i>Executing: <strong>${toolNames}</strong></span>
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
