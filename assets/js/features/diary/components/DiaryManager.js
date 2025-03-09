import { DiaryApiService } from './DiaryApiService';
import { DiaryEntryNew } from './DiaryEntryNew';
import { DiaryEntryEdit } from './DiaryEntryEdit';
import { DiaryEntryDisplay } from './DiaryEntryDisplay';
import { DURATION, wait, slideUp, slideDown, scrollIntoViewWithOffset } from '../../../shared/animation';
import * as bootstrap from 'bootstrap';

/**
 * DiaryManager - Main class for orchestrating diary functionality
 */
export class DiaryManager {
    /**
     * @param {Object} options - Configuration options
     * @param {Object} options.translations - Translation strings
     */
    constructor(options) {
        this.translations = options.translations;
        this.entriesContainer = document.querySelector('.diary-entries');
        
        // Initialize components
        this.apiService = new DiaryApiService(this.translations);
        this.entryDisplay = new DiaryEntryDisplay({
            translations: this.translations,
            getConsciousnessLevelClass: this.getConsciousnessLevelClass.bind(this)
        });
        this.entryNew = new DiaryEntryNew({
            translations: this.translations,
            entriesContainer: this.entriesContainer,
            getConsciousnessLevelClass: this.getConsciousnessLevelClass.bind(this),
            loadAndRenderEntries: this.loadAndRenderEntries.bind(this),
            apiService: this.apiService
        });
        this.entryEdit = new DiaryEntryEdit({
            translations: this.translations,
            getConsciousnessLevelClass: this.getConsciousnessLevelClass.bind(this),
            apiService: this.apiService,
            renderEntryDetail: this.entryDisplay.renderEntryDetail.bind(this.entryDisplay)
        });
        
        // Initialize event listeners
        this.initEventListeners();
        
        // Initial load of entries and handle direct URL access
        this.initFromUrl();
    }

    /**
     * Initialize the diary manager based on the current URL
     */
    async initFromUrl() {
        // Get the current path
        const path = window.location.pathname;
        
        // Check if we're on a specific entry page
        if (path.includes('/diary/')) {
            // Extract the entry ID from the path
            const pathParts = path.split('/');
            const entryId = pathParts[pathParts.length - 1];
            
            // If it's the new entry form
            if (entryId === 'new') {
                // Load entries first (in background)
                await this.loadAndRenderEntries();
                // Then show the new entry form
                await wait(DURATION.QUICK);
                this.entryNew.showNewEntryForm();
            } else {
                // Load entries first
                await this.loadAndRenderEntries();
                // Then try to view the specific entry
                await wait(DURATION.QUICK);
                await this.viewEntry(entryId);
            }
        } else {
            // Just load all entries
            this.loadAndRenderEntries();
        }
    }
    
    /**
     * Initialize event listeners for diary functionality
     */
    initEventListeners() {
        // Handle browser back/forward
        window.addEventListener('popstate', async (e) => {
            if (e.state && e.state.entryId) {
                await this.viewEntry(e.state.entryId);
            } else if (e.state && e.state.action === 'new-entry') {
                this.entryNew.showNewEntryForm();
            } else {
                await this.collapseAllEntries();
            }
        });
        
        // Global event delegation for diary actions
        document.addEventListener('click', async (e) => {
            // Find the closest action button
            const actionButton = e.target.closest('[data-action]');
            if (!actionButton) return;
            
            const action = actionButton.dataset.action;
            
            // Handle different actions
            switch (action) {
                case 'new-entry':
                    await this.entryNew.showNewEntryForm();
                    break;
                    
                case 'save-new':
                    const newEntryForm = actionButton.closest('form');
                    if (newEntryForm) {
                        await this.entryNew.saveNewEntry(newEntryForm);
                    }
                    break;
                    
                case 'cancel-new':
                    await this.entryNew.cancelNewEntry();
                    break;
                    
                case 'view':
                    const entryId = actionButton.dataset.entryId || 
                                    actionButton.closest('[data-entry-id]')?.dataset.entryId;
                    if (entryId) {
                        await this.viewEntry(entryId);
                    }
                    break;
                    
                case 'edit':
                    const editEntryId = actionButton.dataset.entryId;
                    const entryCardToEdit = actionButton.closest('.diary-entry-card');
                    if (editEntryId && entryCardToEdit) {
                        await this.entryEdit.showEditForm(editEntryId, entryCardToEdit);
                    }
                    break;
                    
                case 'save-edit':
                    const editForm = actionButton.closest('form');
                    const entryIdToSave = editForm?.dataset.entryId;
                    const entryCardToSave = editForm?.closest('.diary-entry-card');
                    if (entryIdToSave && entryCardToSave) {
                        await this.entryEdit.saveEditForm(entryIdToSave, editForm, entryCardToSave);
                    }
                    break;
                    
                case 'cancel-edit':
                    const cancelEntryId = actionButton.dataset.entryId;
                    const entryCardToCancel = actionButton.closest('.diary-entry-card');
                    if (cancelEntryId && entryCardToCancel) {
                        await this.entryEdit.cancelEdit(cancelEntryId, entryCardToCancel);
                    }
                    break;
                    
                case 'delete':
                    const deleteEntryId = actionButton.dataset.entryId;
                    if (deleteEntryId) {
                        await this.deleteEntry(deleteEntryId);
                    }
                    break;
                    
                case 'favorite':
                    const favoriteEntryId = actionButton.dataset.entryId;
                    if (favoriteEntryId) {
                        await this.toggleFavorite(favoriteEntryId);
                    }
                    break;
                    
                case 'back':
                    // Find the closest entry card
                    const entryCard = actionButton.closest('.diary-entry-card');
                    if (entryCard) {
                        // Collapse all entries with animation
                        await this.collapseAllEntries();
                        
                        // Update URL without reload
                        history.pushState({ action: 'list' }, '', '/diary');
                    } else {
                        // Fallback to history.back()
                        history.back();
                    }
                    break;
            }
        });
        
        // Entry card click event for viewing entries
        this.entriesContainer.addEventListener('click', async (e) => {
            // If clicking on a button or link inside the card, don't trigger view
            if (e.target.closest('button') || e.target.closest('a') || e.target.closest('[data-action]')) {
                return;
            }
            
            // Find the closest entry card
            const entryCard = e.target.closest('.diary-entry-card');
            if (!entryCard) return;
            
            // Get entry ID and view it
            const entryId = entryCard.dataset.entryId;
            if (entryId) {
                await this.viewEntry(entryId, entryCard);
            }
        });
    }

    /**
     * Load and render all diary entries
     * @param {boolean} prependNewEntry - Whether to prepend a new entry
     */
    async loadAndRenderEntries(prependNewEntry = false) {
        await this.entryDisplay.loadAndRenderEntries(
            this.entriesContainer, 
            this.apiService.fetchEntries.bind(this.apiService), 
            prependNewEntry
        );
    }

    /**
     * View a single diary entry
     * @param {string} entryId - The ID of the entry to view
     * @param {Element} entryCard - The entry card element (optional)
     */
    async viewEntry(entryId, entryCard = null) {
        // If already viewing this entry, do nothing
        if (entryCard && entryCard.classList.contains('expanded') && 
            !entryCard.querySelector('.entry-content-expanded').classList.contains('d-none')) {
            return;
        }
        
        // If no entry card provided, find it by entry ID
        if (!entryCard) {
            entryCard = document.querySelector(`.diary-entry-card[data-entry-id="${entryId}"]`);
            if (!entryCard) {
                // Entry not found - show error and redirect to diary list
                history.pushState(null, '', '/diary');
                window.toast?.error?.(this.translations.entry_not_found);
                return;
            }
        } else {
            // Update URL without reload
            const url = `/diary/${entryId}`;
            history.pushState({ action: 'view', entryId }, '', url);
        }
        
        // Scale down other entries
        await this.scaleAndCollapseOtherEntries(entryCard);
        await wait(DURATION.NORMAL); // Wait for animations to complete
        
        // Update UI state
        entryCard.classList.add('expanded');
        entryCard.classList.add('cyber-glow');
        entryCard.children[0].classList.add('bg-cyber-g-light');
        
        // Reset transform and opacity for the active card
        entryCard.style.transform = '';
        entryCard.style.opacity = '';
        
        // Get content containers
        const originalContent = entryCard.querySelector('.entry-content-original');
        const expandedContent = entryCard.querySelector('.entry-content-expanded');
        
        // Hide original content and show expanded content
        originalContent.classList.add('d-none');
        expandedContent.classList.remove('d-none');
        
        // Update GUI - hide Favorite icon + show toggleFavorite
        const favoriteStatic = entryCard.querySelector('.favorite-static-icon');
        const favoriteToggle = entryCard.querySelector('#toggleFavorite');
        if (favoriteStatic && favoriteToggle) {
            favoriteStatic.classList.add('d-none');
            favoriteToggle.classList.remove('d-none');
            favoriteToggle.dataset.entryId = entryId;
            favoriteToggle.dataset.action = 'favorite';
        }
        
        // Scroll entryCard to top under navigation
        scrollIntoViewWithOffset(entryCard);
        
        // Check if content is already loaded
        if (expandedContent.innerHTML !== '' && expandedContent.querySelector('.entry-detail')) {
            // Content already loaded, just slide it down
            const detailContent = expandedContent.querySelector('.entry-detail');
            await slideDown(detailContent, DURATION.NORMAL);
        } else {
            // Show loading state
            expandedContent.innerHTML = `
                <div class="loading-indicator w-100 text-center">
                    <div class="spinner-border text-cyber" role="status">
                        <span class="visually-hidden">${this.translations.loading}</span>
                    </div>
                </div>
            `;
            const loadingIndicator = expandedContent.querySelector('.loading-indicator');
            loadingIndicator.classList.add('active');
            await wait(DURATION.QUICK);
            
            try {
                // Fetch full entry content
                const data = await this.apiService.fetchEntry(entryId);
                
                // Fade out loading indicator
                loadingIndicator.classList.remove('active');
                await wait(DURATION.QUICK); // Wait for fade out
                
                // Render entry detail view
                expandedContent.innerHTML = this.entryDisplay.renderEntryDetail(data.entry);
                
                // Get the content container and apply slide down animation
                const detailContent = expandedContent.querySelector('.entry-detail');
                await slideDown(detailContent, DURATION.NORMAL);
                
                // Initialize Bootstrap dropdowns
                const dropdownButtons = expandedContent.querySelectorAll('[data-bs-toggle="dropdown"]');
                dropdownButtons.forEach(button => {
                    new bootstrap.Dropdown(button);
                });
            } catch (error) {
                window.toast?.error?.(this.translations.failed_load);
                
                expandedContent.innerHTML = `
                    <div class="alert alert-danger">
                        ${this.translations.failed_load_content}
                    </div>
                `;
            }
        }
        
        // Scroll entryCard to top under navigation after content loaded
        scrollIntoViewWithOffset(entryCard);
    }

    /**
     * Collapse a single entry
     * @param {Element} entryCard - The entry card to collapse
     */
    async collapseEntry(entryCard) {
        // Get content containers
        const originalContent = entryCard.querySelector('.entry-content-original');
        const expandedContent = entryCard.querySelector('.entry-content-expanded');
        const detailContent = expandedContent.querySelector('.entry-detail');
        
        // If expanded content is visible, collapse it with animation
        if (!expandedContent.classList.contains('d-none')) {
            // Slide up the detail content first
            if (detailContent) {
                await slideUp(detailContent, DURATION.NORMAL);
            }
            
            // Hide expanded content and show original content
            expandedContent.classList.add('d-none');
            originalContent.classList.remove('d-none');
            
            // Update GUI - show Favorite icon + hide toggleFavorite
            const favoriteStatic = entryCard.querySelector('.favorite-static-icon');
            const favoriteToggle = entryCard.querySelector('#toggleFavorite');
            if (favoriteStatic && favoriteToggle) {
                favoriteStatic.classList.remove('d-none');
                favoriteToggle.classList.add('d-none');
            }
            
            // Remove expanded styling
            entryCard.classList.remove('expanded');
            entryCard.classList.remove('cyber-glow');
            entryCard.children[0].classList.remove('bg-cyber-g-light');
        }
    }
    
    /**
     * Collapse all entries
     */
    async collapseAllEntries() {
        const entries = this.entriesContainer.querySelectorAll('.diary-entry-card');
        for (const entry of entries) {
            await this.collapseEntry(entry);
            
            // Reset any scaling/opacity changes
            entry.style.transform = '';
            entry.style.opacity = '';
        }
    }
    
    /**
     * Scale down and collapse other entries when one is expanded
     * @param {Element} activeCard - The active entry card that should remain expanded
     */
    async scaleAndCollapseOtherEntries(activeCard) {
        const entries = this.entriesContainer.querySelectorAll('.diary-entry-card');
        for (const entry of entries) {
            if (entry !== activeCard) {
                await this.collapseEntry(entry);
                
                // Scale down and reduce opacity of other entries
                entry.style.transform = 'scale(0.7)';
                entry.style.opacity = '0.7';
            }
        }
    }
    
    /**
     * Delete a diary entry
     * @param {string} entryId - The ID of the entry to delete
     */
    async deleteEntry(entryId) {
        // Ask for confirmation
        if (!confirm(this.translations.confirm_delete)) {
            return;
        }
        
        try {
            // Send delete request
            await this.apiService.deleteEntry(entryId);
            
            // Show success message
            window.toast.success(this.translations.entry_deleted);
            
            // Find the entry card and remove it with animation
            const entryCard = document.querySelector(`.diary-entry-card[data-entry-id="${entryId}"]`);
            if (entryCard) {
                await slideUp(entryCard, DURATION.NORMAL);
                entryCard.remove();
            }
            
            // If we're on the entry detail view, go back to the list
            if (window.location.pathname.includes(`/diary/${entryId}`)) {
                history.pushState({ action: 'list' }, '', '/diary');
            }
            
            // If no entries left, show the no entries message
            if (this.entriesContainer.children.length === 0) {
                this.entriesContainer.innerHTML = `
                    <div class="alert alert-info">
                        ${this.translations.no_entries}
                    </div>
                `;
            }
        } catch (error) {
            window.toast.error(this.translations.failed_delete);
        }
    }

    /**
     * Toggle the favorite status of an entry
     * @param {string} entryId - The ID of the entry to toggle
     */
    async toggleFavorite(entryId) {
        try {
            // Send toggle request
            const data = await this.apiService.toggleFavorite(entryId);
            
            // Update UI
            const favoriteToggle = document.querySelector(`#toggleFavorite[data-entry-id="${entryId}"]`);
            const favoriteIcon = favoriteToggle?.querySelector('i');
            if (favoriteIcon) {
                if (data.entry.isFavorite) {
                    favoriteIcon.classList.add('text-warning');
                    favoriteIcon.classList.remove('text-muted');
                    favoriteToggle.title = this.translations.favorite.remove_favorite;
                } else {
                    favoriteIcon.classList.remove('text-warning');
                    favoriteIcon.classList.add('text-muted');
                    favoriteToggle.title = this.translations.favorite.add_favorite;
                }
            }
            
            // Update static icon as well
            const favoriteStatic = document.querySelector(`.diary-entry-card[data-entry-id="${entryId}"] .favorite-static-icon`);
            if (favoriteStatic) {
                if (data.entry.isFavorite) {
                    favoriteStatic.classList.add('text-warning');
                    favoriteStatic.classList.remove('text-muted');
                    favoriteStatic.title = this.translations.favorite.title;
                } else {
                    favoriteStatic.classList.remove('text-warning');
                    favoriteStatic.classList.add('text-muted');
                    favoriteStatic.title = this.translations.favorite.not_favorite;
                }
            }
        } catch (error) {
            window.toast.error(this.translations.failed_favorite);
        }
    }

    /**
     * Get the CSS class for a consciousness level
     * @param {number} level - The consciousness level
     * @returns {string} - The CSS class
     */
    getConsciousnessLevelClass(level) {
        if (level === null || level === undefined) return 'bg-secondary';
        
        if (level < 200) return 'bg-danger';
        if (level < 350) return 'bg-warning';
        if (level < 500) return 'bg-info';
        if (level < 600) return 'bg-primary';
        if (level < 700) return 'bg-success';
        if (level < 850) return 'bg-cyber';
        return 'bg-light text-dark';
    }
}
