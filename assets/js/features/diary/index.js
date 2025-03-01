import { DURATION, wait } from '../../shared/animation';

export class DiaryManager {
    constructor() {
        this.activeEntryId = null;
        this.entriesContainer = document.querySelector('.diary-entries');
        this.loadingTemplate = `
            <div class="loading-indicator w-100 text-center">
                <div class="spinner-border text-cyber" role="status">
                    <span class="visually-hidden">Loading...</span>
                </div>
            </div>
        `;
    }

    async initialize() {
        console.log('Initializing diary manager');
        // Bind click handlers to entry cards
        this.entriesContainer.addEventListener('click', async (e) => {
            const entryCard = e.target.closest('.diary-entry-card');
            if (!entryCard) return;

            // Handle favorite toggle
            if (e.target.closest('#toggleFavorite')) {
                e.preventDefault();
                e.stopPropagation();
                const entryId = entryCard.dataset.entryId;
                try {
                    const isFavorite = await this.toggleFavorite(entryId);
                    const starIcon = e.target.closest('#toggleFavorite').querySelector('i');
                    starIcon.classList.toggle('text-warning', isFavorite);
                    starIcon.classList.toggle('text-muted', !isFavorite);
                    
                    // Update the static icon state too
                    const staticIcon = entryCard.querySelector('.favorite-static-icon');
                    staticIcon.classList.toggle('text-warning', isFavorite);
                    staticIcon.classList.toggle('text-muted', !isFavorite);
                    staticIcon.title = isFavorite ? 'Favorite' : 'Not favorite';
                } catch (error) {
                    console.error('Failed to toggle favorite:', error);
                    window.toast.error('Failed to toggle favorite');
                }
                return;
            }

            // Handle delete
            if (e.target.closest('[data-action="delete"]')) {
                e.preventDefault();
                e.stopPropagation();
                const entryId = e.target.closest('[data-action="delete"]').dataset.entryId;
                
                if (confirm('Are you sure you want to delete this entry?')) {
                    try {
                        await this.deleteEntry(entryId);
                        // Remove the entry card from DOM
                        entryCard.remove();
                        history.back(); // Go back to list view
                    } catch (error) {
                        console.error('Failed to delete entry:', error);
                        // Show error toast
                        window.toast.error('Failed to delete entry');
                    }
                }
                return;
            }

            // Handle card expansion
            e.preventDefault();
            const entryId = entryCard.dataset.entryId;
            await this.expandEntry(entryId, entryCard);
        });

        // Handle browser back/forward
        window.addEventListener('popstate', (e) => {
            console.log('Handling popstate event', e);
            if (e.state && e.state.entryId) {
                console.log('~ Expanding entry', e.state.entryId);
                this.expandEntry(e.state.entryId);
            } else {
                console.log('Collapsing all entries');
                this.collapseAllEntries();
            }
        });
    }

    async expandEntry(entryId, entryCard) {
        //console.log(`Expanding entry ${entryId}`, entryCard);

        if (this.activeEntryId === entryId) {
            return;
        }

        // returning from - history.back()
        if (typeof entryCard === 'undefined') {
            entryCard = this.entriesContainer.querySelector(`[data-entry-id="${entryId}"]`);
            if (!entryCard) {
                console.error(`Could not find entry card with ID ${entryId}`);
                return;
            }
        } else {
            // Update URL without reload
            const url = `/diary/${entryId}`;
            history.pushState({ entryId }, '', url);
        }
        
        // Update UI state
        entryCard.classList.add('expanded');
        entryCard.classList.add('cyber-glow');
        entryCard.children[0].classList.add('bg-cyber-g-light');
        this.activeEntryId = entryId;

        entryCard.style.transform = '';
        entryCard.style.opacity = '';

        // Show expanded content div
        const contentContainer = entryCard.querySelector('.entry-content-expanded');
        
        // Scale down other entries
        this.scaleAndCollapseOtherEntries(entryCard);

        // Update GUI - hide Favorite icon + show toggleFavorite
        const favoriteStatic = entryCard.querySelector('.favorite-static-icon');
        const toggleFavorite = entryCard.querySelector('#toggleFavorite');
        
        // Animate the transition
        favoriteStatic.classList.add('d-none');
        toggleFavorite.classList.remove('d-none');
        
        // Check if content is already loaded
        if (contentContainer.innerHTML !== '') {
            // already loaded
            console.log('Entry content already loaded');
        } else {
            // Show loading state in expanded content with fade
            contentContainer.innerHTML = this.loadingTemplate;
            const loadingIndicator = contentContainer.querySelector('.loading-indicator');
            loadingIndicator.classList.add('active');

            // load entry content
            try {
                // Fetch full entry content
                const response = await fetch(`/api/diary/${entryId}`);
                if (!response.ok) throw new Error('Failed to load entry');
                
                const data = await response.json();
                
                // Fade out loading indicator
                loadingIndicator.classList.remove('active');
                await wait(DURATION.QUICK); // Wait for fade out
                
                // Update content with animation
                contentContainer.innerHTML = this.renderEntryDetail(data.entry);
                
                // Initialize any dropdowns in the loaded content
                const dropdownButtons = contentContainer.querySelectorAll('[data-bs-toggle="dropdown"]');
                dropdownButtons.forEach(button => {
                    button.addEventListener('click', (e) => {
                        e.stopPropagation();
                        const dropdownMenu = button.nextElementSibling;
                        dropdownMenu.classList.toggle('show');
                        
                        // Close dropdown when clicking outside
                        const closeDropdown = (event) => {
                            if (!dropdownMenu.contains(event.target) && !button.contains(event.target)) {
                                dropdownMenu.classList.remove('show');
                                document.removeEventListener('click', closeDropdown);
                            }
                        };
                        
                        document.addEventListener('click', closeDropdown);
                    });
                });
            } catch (error) {
                console.error('Error loading entry:', error);
                window.toast.error('Failed to load entry content');
                
                contentContainer.innerHTML = `
                    <div class="alert alert-danger">
                        Failed to load entry content. Please try again later.
                    </div>
                `;
            }
        }
    }

    collapseEntry(entryCard) {
        //console.log('Collapsing entry', entryCard);
        // Update UI state
        entryCard.classList.remove('expanded');
        entryCard.classList.remove('cyber-glow');
        entryCard.children[0].classList.remove('bg-cyber-g-light');

        if (this.activeEntryId === entryCard.dataset.entryId) {
            this.activeEntryId = null;
        }
        
        // Update GUI - show Favorite icon + hide toggleFavorite
        entryCard.querySelector('.favorite-static-icon').classList.remove('d-none');
        entryCard.querySelector('#toggleFavorite').classList.add('d-none');
    }

    collapseAllEntries() {
        //console.log('Collapsing all entries');
        const entries = this.entriesContainer.querySelectorAll('.diary-entry-card');
        entries.forEach(entry => {
            this.collapseEntry(entry);

            entry.style.transform = '';
            entry.style.opacity = '';
        });
        this.activeEntryId = null;
    }

    scaleAndCollapseOtherEntries(activeCard) {
        //console.log('Scaling other entries', activeCard);
        const entries = this.entriesContainer.querySelectorAll('.diary-entry-card');
        entries.forEach(entry => {
            if (entry !== activeCard) {
                this.collapseEntry(entry);

                entry.style.transform = 'scale(0.7)';
                entry.style.opacity = '0.7';
            }
        });
    }

    async toggleFavorite(entryId) {
        const entry = document.querySelector(`[data-entry-id="${entryId}"]`);
        const favoriteIcon = entry.querySelector('.favorite-static-icon');
        const toggleIcon = entry.querySelector('#toggleFavorite');
        
        // Start animation before the request
        favoriteIcon.style.transform = 'scale(0.8)';
        favoriteIcon.style.opacity = '0';
        toggleIcon.style.transform = 'scale(0.8)';
        toggleIcon.style.opacity = '0';
        
        try {
            const response = await fetch(`/api/diary/${entryId}/favorite`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                }
            });

            if (!response.ok) throw new Error('Failed to toggle favorite');
            const data = await response.json();
            
            // Toggle the star and animate back
            setTimeout(() => {
                favoriteIcon.classList.toggle('text-warning', data.entry.isFavorite);
                favoriteIcon.classList.toggle('text-muted', !data.entry.isFavorite);
                toggleIcon.classList.toggle('text-warning', data.entry.isFavorite);
                toggleIcon.classList.toggle('text-muted', !data.entry.isFavorite);
                
                favoriteIcon.style.transform = '';
                favoriteIcon.style.opacity = '';
                toggleIcon.style.transform = '';
                toggleIcon.style.opacity = '';
            }, 150); // Micro-interaction timing - matches $duration-instant
            
            console.log('Favorite status updated');
            
            return data.entry.isFavorite;
        } catch (error) {
            console.error('Error toggling favorite:', error);
            window.toast.error('Failed to update favorite status');
            throw error;
        }
    }

    async deleteEntry(entryId) {
        const entry = document.querySelector(`[data-entry-id="${entryId}"]`);
        
        // Close dropdown first with animation
        const dropdownMenu = entry.querySelector('.dropdown-menu');
        if (dropdownMenu) {
            dropdownMenu.classList.remove('show');
            await wait(DURATION.EMPHASIS); // Important action timing
        }
        
        try {
            const response = await fetch(`/api/diary/${entryId}`, {
                method: 'DELETE',
            });

            if (!response.ok) throw new Error('Failed to delete entry');
            
            // Animate removal
            entry.classList.add('removing');
            await wait(DURATION.EMPHASIS); // Match transition duration
            entry.remove();
            
            window.toast.success('Entry deleted successfully');
            
            return true;
        } catch (error) {
            console.error('Error deleting entry:', error);
            window.toast.error('Failed to delete entry');
            throw error;
        }
    }

    renderEntryDetail(entry) {
        return `
            <div class="entry-detail">
                <div class="entry-full-content">
                    ${entry.contentFormatted || entry.content}
                </div>
                ${entry.tags ? `
                    <div class="entry-tags mt-3">
                        ${entry.tags.map(tag => `
                            <span class="badge bg-cyber bg-opacity-50 me-1">${tag}</span>
                        `).join('')}
                    </div>
                ` : ''}
                <div class="entry-actions mt-4">
                    <button class="btn btn-sm btn-light me-2" onclick="history.back()">
                        <i class="mdi mdi-keyboard-return"></i> Back
                    </button>
                    <button class="btn btn-sm btn-cyber float-end" onclick="window.location.href='/diary/${entry.id}/edit'">
                        <i class="mdi mdi-pencil"></i> Edit
                    </button>
                    
                    <div class="dropdown dropup d-inline float-end position-relative_ me-2">
                        <button class="btn btn-sm btn-link text-cyber p-0" type="button" data-bs-toggle="dropdown">
                            <i class="mdi mdi-dots-vertical"></i>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end bg-transparent border-0 text-center">
                            <button class="btn btn-sm btn-danger" type="button" data-entry-id="${entry.id}" data-action="delete">
                                <i class="mdi mdi-trash-can-outline"></i> Delete
                            </button>
                        </ul>
                    </div>
                </div>
            </div>
        `;
    }
}
