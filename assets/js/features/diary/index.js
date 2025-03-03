import { DURATION, wait, slideUp, slideDown, scrollIntoViewWithOffset } from '../../shared/animation';
import * as bootstrap from 'bootstrap';

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
        // Handle new entry button
        const newEntryButton = document.querySelector('.btn-cyber[data-action="new-entry"]');
        if (newEntryButton) {
            newEntryButton.addEventListener('click', async (e) => {
                e.preventDefault();
                await this.showNewEntryForm();
            });
        }
        
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
            
            // Handle edit button click
            if (e.target.closest('[data-action="edit"]')) {
                e.preventDefault();
                e.stopPropagation();
                const entryId = e.target.closest('[data-action="edit"]').dataset.entryId;
                await this.showEditForm(entryId, entryCard);
                return;
            }
            
            // Handle save edit form
            if (e.target.closest('[data-action="save-edit"]')) {
                e.preventDefault();
                e.stopPropagation();
                const editForm = e.target.closest('form');
                const entryId = editForm.dataset.entryId;
                await this.saveEditForm(entryId, editForm, entryCard);
                return;
            }
            
            // Handle cancel edit
            if (e.target.closest('[data-action="cancel-edit"]')) {
                e.preventDefault();
                e.stopPropagation();
                const entryId = e.target.closest('[data-action="cancel-edit"]').dataset.entryId;
                await this.cancelEdit(entryId, entryCard);
                return;
            }
            
            // Handle save new entry form
            if (e.target.closest('[data-action="save-new"]')) {
                e.preventDefault();
                e.stopPropagation();
                const newForm = e.target.closest('form');
                await this.saveNewEntry(newForm);
                return;
            }
            
            // Handle cancel new entry
            if (e.target.closest('[data-action="cancel-new"]')) {
                e.preventDefault();
                e.stopPropagation();
                await this.cancelNewEntry();
                return;
            }

            // Handle card expansion
            e.preventDefault();
            const entryId = entryCard.dataset.entryId;
            await this.expandEntry(entryId, entryCard);
        });

        // Handle browser back/forward
        window.addEventListener('popstate', async (e) => {
            if (e.state && e.state.entryId) {
                await this.expandEntry(e.state.entryId);
            } else if (e.state && e.state.action === 'new-entry') {
                this.showNewEntryForm();
            } else {
                await this.collapseAllEntries();
            }
        });

        // extract last part of from url `/diary/{new|uuid}`
        let uuid = window.location.pathname.split('/').pop();
        if (uuid === 'new') {
            this.showNewEntryForm();
        } else if (uuid) {
            await this.expandEntry(uuid);
        }
    }

    async expandEntry(entryId, entryCard) {
        if (this.activeEntryId === entryId) {
            return;
        }

        // returning from - history.back() or direc detail URL accees
        if (typeof entryCard === 'undefined') {
            entryCard = this.entriesContainer.querySelector(`[data-entry-id="${entryId}"]`);
            if (!entryCard) {
                console.error(`Could not find entry card with ID ${entryId}`);
                history.pushState(null, '', '/diary/');
                window.toast.error('Entry not found');
                return;
            }
        } else {
            // Update URL without reload
            const url = `/diary/${entryId}`;
            history.pushState({ entryId }, '', url);
        }

        // Scale down other entries
        await this.scaleAndCollapseOtherEntries(entryCard);
        await wait(DURATION.NORMAL); // Wait for fade out
        
        // Update UI state
        entryCard.classList.add('expanded');
        entryCard.classList.add('cyber-glow');
        entryCard.children[0].classList.add('bg-cyber-g-light');
        this.activeEntryId = entryId;

        entryCard.style.transform = '';
        entryCard.style.opacity = '';

        // Show expanded content div
        const contentContainer = entryCard.querySelector('.entry-content-expanded');
        contentContainer.classList.remove('d-none');
        entryCard.querySelector('.entry-content-original')?.classList.add('d-none');
        
        // Update GUI - hide Favorite icon + show toggleFavorite
        const favoriteStatic = entryCard.querySelector('.favorite-static-icon');
        const toggleFavorite = entryCard.querySelector('#toggleFavorite');
        
        // Animate the transition
        favoriteStatic.classList.add('d-none');
        toggleFavorite.classList.remove('d-none');
        
        // Scroll entryCard to top under navigation
        scrollIntoViewWithOffset(entryCard);        
        
        // Check if content is already loaded
        if (contentContainer.innerHTML !== '') {
            // already loaded
            console.log('Entry content already loaded');
            await slideDown(contentContainer, DURATION.NORMAL);
        } else {
            // Show loading state in expanded content with fade
            contentContainer.innerHTML = this.loadingTemplate;
            const loadingIndicator = contentContainer.querySelector('.loading-indicator');
            loadingIndicator.classList.add('active');
            await wait(DURATION.QUICK);

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
                await slideDown(contentContainer, DURATION.NORMAL);
                
                // Initialize Bootstrap dropdowns
                const dropdownButtons = contentContainer.querySelectorAll('[data-bs-toggle="dropdown"]');
                dropdownButtons.forEach(button => {
                    new bootstrap.Dropdown(button);
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

        // Scroll entryCard to top under navigation, after content loaded
        scrollIntoViewWithOffset(entryCard);
    }

    async collapseEntry(entryCard) {
        // Hide expanded content div
        entryCard.querySelector('.entry-content-expanded')?.classList.add('d-none');
        
        // Show original content div
        entryCard.querySelector('.entry-content-original')?.classList.remove('d-none');

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

    async collapseAllEntries() {
        const entries = this.entriesContainer.querySelectorAll('.diary-entry-card');
        entries.forEach(async entry => {
            await this.collapseEntry(entry);

            entry.style.transform = '';
            entry.style.opacity = '';
        });
        this.activeEntryId = null;
    }

    async scaleAndCollapseOtherEntries(activeCard) {
        const entries = this.entriesContainer.querySelectorAll('.diary-entry-card');
        entries.forEach(async entry => {
            if (entry !== activeCard) {
                await this.collapseEntry(entry);

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
                    <button class="btn btn-sm btn-cyber float-end" data-action="edit" data-entry-id="${entry.id}">
                        <i class="mdi mdi-pencil"></i> Edit
                    </button>
                    
                    <div class="dropdown dropup d-inline float-end me-2">
                        <button class="btn btn-sm btn-link text-cyber p-0" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="mdi mdi-dots-vertical"></i>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li>
                                <button class="dropdown-item text-danger" type="button" data-entry-id="${entry.id}" data-action="delete">
                                    <i class="mdi mdi-delete-forever-outline"></i> Delete
                                </button>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
        `;
    }
    
    async showEditForm(entryId, entryCard) {
        const contentContainer = entryCard.querySelector('.entry-content-expanded');
        const detailContent = contentContainer.querySelector('.entry-detail');

        // save current content state before editing to `.entry-content-expanded-before-edit`
        const entryContentExpandedBeforeEdit = entryCard.querySelector('.entry-content-expanded-before-edit');
        if (entryContentExpandedBeforeEdit) {
            console.log('saving content before edit', contentContainer.innerHTML);
            entryContentExpandedBeforeEdit.innerHTML = contentContainer.innerHTML;
        }
        
        // If there's existing content, slide it up first
        if (detailContent) {
            await slideUp(detailContent, DURATION.NORMAL);
        }
        
        // Show loading state
        contentContainer.innerHTML = this.loadingTemplate;
        const loadingIndicator = contentContainer.querySelector('.loading-indicator');
        loadingIndicator.classList.add('active');
        await wait(DURATION.QUICK);
        
        try {
            // Fetch entry data
            const response = await fetch(`/api/diary/${entryId}`);
            if (!response.ok) throw new Error('Failed to load entry');
            
            const data = await response.json();
            const entry = data.entry;
            
            // Fade out loading indicator
            loadingIndicator.classList.remove('active');
            await wait(DURATION.QUICK);
            
            // Render edit form (initially hidden)
            contentContainer.innerHTML = this.renderEditForm(entry);
            
            // Get the form and apply slide down animation
            const editForm = contentContainer.querySelector('form');
            await slideDown(editForm, DURATION.NORMAL);
            
            // Initialize rich text editor
            const editor = contentContainer.querySelector('#editor');
            const toolbar = contentContainer.querySelector('.toolbar');
            
            // Add event listeners to toolbar buttons
            toolbar.querySelectorAll('[data-command]').forEach(button => {
                button.addEventListener('click', (e) => {
                    e.preventDefault();
                    const command = button.getAttribute('data-command');
                    try {
                        document.execCommand(command, false, null);
                    } catch (error) {
                        console.error('Error executing command:', error);
                    }
                    editor.focus();
                });
            });
        } catch (error) {
            console.error('Error loading entry for editing:', error);
            window.toast.error('Failed to load entry for editing');
            
            contentContainer.innerHTML = `
                <div class="alert alert-danger">
                    Failed to load entry for editing. Please try again later.
                </div>
            `;
        }
    }
    
    async saveEditForm(entryId, form, entryCard) {
        const contentContainer = entryCard.querySelector('.entry-content-expanded');
        const editor = form.querySelector('#editor');
        
        // Prepare form data
        const formData = {
            title: form.querySelector('#title').value,
            content: editor.innerText,
            contentFormatted: editor.innerHTML,
            mood: form.querySelector('#mood').value,
            tags: form.querySelector('#tags').value.split(',').map(tag => tag.trim()).filter(tag => tag)
        };
        
        try {
            // Show loading state
            const saveButton = form.querySelector('[data-action="save-edit"]');
            const originalButtonText = saveButton.innerHTML;
            saveButton.innerHTML = '<i class="mdi mdi-loading mdi-spin"></i> Saving...';
            saveButton.disabled = true;
            
            // Send update request
            const response = await fetch(`/api/diary/${entryId}`, {
                method: 'PUT',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(formData)
            });
            
            if (!response.ok) throw new Error('Failed to update entry');
            
            const data = await response.json();
            
            // Show success message
            window.toast.success('Entry updated successfully');
            
            // Apply slide-up animation to the form
            await slideUp(form, DURATION.NORMAL);
            
            // Show loading state for content refresh
            contentContainer.innerHTML = this.loadingTemplate;
            const loadingIndicator = contentContainer.querySelector('.loading-indicator');
            loadingIndicator.classList.add('active');
            await wait(DURATION.QUICK);
            
            // Reload the entry detail view
            try {
                // Fetch full entry content
                const entryResponse = await fetch(`/api/diary/${entryId}`);
                if (!entryResponse.ok) throw new Error('Failed to load updated entry');
                
                const entryData = await entryResponse.json();
                
                // Fade out loading indicator
                loadingIndicator.classList.remove('active');
                await wait(DURATION.QUICK); // Wait for fade out
                
                // Render entry detail view (initially hidden)
                contentContainer.innerHTML = this.renderEntryDetail(entryData.entry);
                // Update entry title
                entryCard.querySelector('.entry-title').textContent = entryData.entry.title;
                // Update entry mood
                entryCard.querySelector('.entry-mood').textContent = entryData.entry.mood;
                
                // Get the content container and apply slide down animation
                const detailContent = contentContainer.querySelector('.entry-detail');
                await slideDown(detailContent, DURATION.NORMAL);
                
                // Initialize Bootstrap dropdowns
                const dropdownButtons = contentContainer.querySelectorAll('[data-bs-toggle="dropdown"]');
                dropdownButtons.forEach(button => {
                    new bootstrap.Dropdown(button);
                });
            } catch (error) {
                console.error('Error loading updated entry:', error);
                window.toast.error('Entry was updated but failed to reload content');
                
                contentContainer.innerHTML = `
                    <div class="alert alert-warning">
                        Entry was updated successfully but failed to reload content. Please refresh the page.
                    </div>
                `;
            }
        } catch (error) {
            console.error('Error updating entry:', error);
            window.toast.error('Failed to update entry');
            
            // Restore button state
            const saveButton = form.querySelector('[data-action="save-edit"]');
            saveButton.innerHTML = originalButtonText;
            saveButton.disabled = false;
        }
    }
    
    async cancelEdit(entryId, entryCard) {
        const contentContainer = entryCard.querySelector('.entry-content-expanded');
        const editForm = contentContainer.querySelector('form');
        
        // Apply slide-up animation to the form
        await slideUp(editForm, DURATION.NORMAL);
        
        // restore content before edit
        const entryContentExpandedBeforeEdit = entryCard.querySelector('.entry-content-expanded-before-edit');
        if (entryContentExpandedBeforeEdit) {
            console.log('restoring content before edit', entryContentExpandedBeforeEdit.innerHTML);
            contentContainer.innerHTML = entryContentExpandedBeforeEdit.innerHTML;

            await slideDown(contentContainer, DURATION.NORMAL);
        }
    }
    
    renderEditForm(entry) {
        return `
            <form class="edit-entry-form" data-entry-id="${entry.id}">
                <div class="mb-3">
                    <label for="title" class="form-label">Title</label>
                    <input type="text" class="form-control" id="title" name="title" value="${entry.title}" required>
                </div>
                
                <div class="mb-3">
                    <div class="toolbar btn-group mb-3 float-end">
                        <button type="button" class="btn btn-sm btn-outline-secondary rounded-bottom-0 border-bottom-0" data-command="bold">
                            <i class="mdi mdi-format-bold"></i>
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-secondary border-bottom-0" data-command="italic">
                            <i class="mdi mdi-format-italic"></i>
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-secondary border-bottom-0" data-command="underline">
                            <i class="mdi mdi-format-underline"></i>
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-secondary border-bottom-0" data-command="insertUnorderedList">
                            <i class="mdi mdi-format-list-bulleted"></i>
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-secondary rounded-bottom-0 border-bottom-0" data-command="insertOrderedList">
                            <i class="mdi mdi-format-list-numbered"></i>
                        </button>
                    </div>

                    <label for="editor" class="form-label">Content</label>
                    <div id="editor" class="form-control rounded-top-0 rounded-start-1" style="min-height: 200px;" contenteditable="true">
                        ${entry.contentFormatted || entry.content}
                    </div>
                </div>
                
                <div class="mb-3">
                    <label for="mood" class="form-label">Mood</label>
                    <select class="form-select" id="mood" name="mood">
                        <option value="">Select mood...</option>
                        <option value="Happy" ${entry.mood === 'Happy' ? 'selected' : ''}>Happy</option>
                        <option value="Calm" ${entry.mood === 'Calm' ? 'selected' : ''}>Calm</option>
                        <option value="Thoughtful" ${entry.mood === 'Thoughtful' ? 'selected' : ''}>Thoughtful</option>
                        <option value="Excited" ${entry.mood === 'Excited' ? 'selected' : ''}>Excited</option>
                        <option value="Sad" ${entry.mood === 'Sad' ? 'selected' : ''}>Sad</option>
                        <option value="Anxious" ${entry.mood === 'Anxious' ? 'selected' : ''}>Anxious</option>
                    </select>
                </div>
                
                <div class="mb-3">
                    <label for="tags" class="form-label">Tags</label>
                    <input type="text" class="form-control" id="tags" name="tags" value="${entry.tags ? entry.tags.join(', ') : ''}">
                    <small class="form-text text-muted">Separate tags with commas</small>
                </div>
                
                <div class="d-flex justify-content-between mt-4">
                    <button type="button" class="btn btn-secondary" data-action="cancel-edit" data-entry-id="${entry.id}">
                        <i class="mdi mdi-cancel"></i> Cancel
                    </button>
                    <button type="button" class="btn btn-cyber" data-action="save-edit">
                        <i class="mdi mdi-content-save"></i> Save Changes
                    </button>
                </div>
            </form>
        `;
    }
    
    async showNewEntryForm() {
        // Update URL without reload
        const url = `/diary/new`;
        history.pushState({ action: 'new-entry' }, '', url);
        
        // Collapse all entries
        this.collapseAllEntries();
        
        // Hide the New Entry button
        const newEntryButton = document.querySelector('.btn-cyber[data-action="new-entry"]');
        if (newEntryButton) {
            newEntryButton.style.display = 'none';
        }
        
        // Create a temporary container for the new entry form
        const newEntryContainer = document.createElement('div');
        newEntryContainer.className = 'diary-new-entry-container glass-panel glass-panel-glow mb-4';
        newEntryContainer.style.opacity = '0';
        newEntryContainer.style.height = '0';
        newEntryContainer.style.overflow = 'hidden';
        newEntryContainer.innerHTML = this.renderNewEntryForm();
        
        // Insert at the top of the entries container
        if (this.entriesContainer.firstChild) {
            this.entriesContainer.insertBefore(newEntryContainer, this.entriesContainer.firstChild);
        } else {
            this.entriesContainer.appendChild(newEntryContainer);
        }
        
        // Apply slide down animation
        await slideDown(newEntryContainer, DURATION.NORMAL);
        
        // Initialize rich text editor
        const editor = newEntryContainer.querySelector('#editor');
        const toolbar = newEntryContainer.querySelector('.toolbar');
        
        // Add event listeners to toolbar buttons
        toolbar.querySelectorAll('[data-command]').forEach(button => {
            button.addEventListener('click', (e) => {
                e.preventDefault();
                const command = button.getAttribute('data-command');
                try {
                    document.execCommand(command, false, null);
                } catch (error) {
                    console.error('Error executing command:', error);
                }
                editor.focus();
            });
        });
        
        // Add event listeners for save and cancel buttons
        const form = newEntryContainer.querySelector('form');
        const saveButton = form.querySelector('[data-action="save-new"]');
        const cancelButton = form.querySelector('[data-action="cancel-new"]');
        
        saveButton.addEventListener('click', async (e) => {
            e.preventDefault();
            await this.saveNewEntry(form);
        });
        
        cancelButton.addEventListener('click', async (e) => {
            e.preventDefault();
            await this.cancelNewEntry();
        });
    }
    
    async saveNewEntry(form) {
        const editor = form.querySelector('#editor');
        const newEntryContainer = document.querySelector('.diary-new-entry-container');
        
        // Validate form
        const title = form.querySelector('#title').value.trim();
        if (!title) {
            window.toast.error('Title is required');
            form.querySelector('#title').focus();
            return;
        }
        
        // Prepare form data
        const formData = {
            title: title,
            content: editor.innerText,
            contentFormatted: editor.innerHTML,
            mood: form.querySelector('#mood').value,
            tags: form.querySelector('#tags').value.split(',').map(tag => tag.trim()).filter(tag => tag),
            isEncrypted: form.querySelector('#isEncrypted')?.checked || false
        };
        
        try {
            // Show loading state
            const saveButton = form.querySelector('[data-action="save-new"]');
            const originalButtonText = saveButton.innerHTML;
            saveButton.innerHTML = '<i class="mdi mdi-loading mdi-spin"></i> Saving...';
            saveButton.disabled = true;
            
            // Send create request
            const response = await fetch('/api/diary', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(formData)
            });
            
            if (!response.ok) throw new Error('Failed to create entry');
            
            const data = await response.json();
            
            // Show success message
            window.toast.success('Entry created successfully');
            
            // Slide up and remove the form
            await slideUp(newEntryContainer, DURATION.NORMAL);
            newEntryContainer.remove();
            
            // Show the New Entry button again
            const newEntryButton = document.querySelector('.btn-cyber[data-action="new-entry"]');
            if (newEntryButton) {
                newEntryButton.style.display = '';
            }
            
            // Update URL without reload
            history.pushState({ action: 'list' }, '', '/diary');
            
            // Fetch and render the new entry at the top
            await this.loadAndRenderEntries(true);
        } catch (error) {
            console.error('Error creating entry:', error);
            window.toast.error('Failed to create entry');
            
            // Restore button state
            const saveButton = form.querySelector('[data-action="save-new"]');
            saveButton.innerHTML = originalButtonText;
            saveButton.disabled = false;
        }
    }
    
    async cancelNewEntry() {
        // Get the new entry container
        const newEntryContainer = document.querySelector('.diary-new-entry-container');
        if (newEntryContainer) {
            // Slide up animation
            await slideUp(newEntryContainer, DURATION.NORMAL);
            newEntryContainer.remove();
        }
        
        // Show the New Entry button again
        const newEntryButton = document.querySelector('.btn-cyber[data-action="new-entry"]');
        if (newEntryButton) {
            newEntryButton.style.display = '';
        }
        
        // Update URL without reload
        history.back();
    }
    
    renderNewEntryForm() {
        return `
            <div class="card-body body-color rounded p-4 bg-cyber-g-light">
                <h3 class="mb-4">New Diary Entry</h3>
                <form class="new-entry-form">
                    <div class="mb-3">
                        <label for="title" class="form-label">Title</label>
                        <input type="text" class="form-control" id="title" name="title" required>
                    </div>
                    
                    <div class="mb-3">
                        <div class="toolbar btn-group mb-3 float-end">
                            <button type="button" class="btn btn-sm btn-outline-secondary rounded-bottom-0 border-bottom-0" data-command="bold">
                                <i class="mdi mdi-format-bold"></i>
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-secondary border-bottom-0" data-command="italic">
                                <i class="mdi mdi-format-italic"></i>
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-secondary border-bottom-0" data-command="underline">
                                <i class="mdi mdi-format-underline"></i>
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-secondary border-bottom-0" data-command="insertUnorderedList">
                                <i class="mdi mdi-format-list-bulleted"></i>
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-secondary rounded-bottom-0 border-bottom-0" data-command="insertOrderedList">
                                <i class="mdi mdi-format-list-numbered"></i>
                            </button>
                        </div>

                        <label for="editor" class="form-label">Content</label>
                        <div id="editor" class="form-control rounded-top-0 rounded-start-1" style="min-height: 200px;" contenteditable="true"></div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="mood" class="form-label">Mood</label>
                        <select class="form-select" id="mood" name="mood">
                            <option value="">Select mood...</option>
                            <option value="Happy">Happy</option>
                            <option value="Calm">Calm</option>
                            <option value="Thoughtful">Thoughtful</option>
                            <option value="Excited">Excited</option>
                            <option value="Sad">Sad</option>
                            <option value="Anxious">Anxious</option>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label for="tags" class="form-label">Tags</label>
                        <input type="text" class="form-control" id="tags" name="tags">
                        <small class="form-text text-muted">Separate tags with commas</small>
                    </div>
                    
                    <div class="mb-3 form-check">
                        <input type="checkbox" class="form-check-input" id="isEncrypted" name="isEncrypted">
                        <label class="form-check-label" for="isEncrypted">Encrypt this entry</label>
                    </div>
                    
                    <div class="d-flex justify-content-between mt-4">
                        <button type="button" class="btn btn-secondary" data-action="cancel-new">
                            <i class="mdi mdi-cancel"></i> Cancel
                        </button>
                        <button type="button" class="btn btn-cyber" data-action="save-new">
                            <i class="mdi mdi-content-save"></i> Save Entry
                        </button>
                    </div>
                </form>
            </div>
        `;
    }
    
    renderEntryCard(entry) {
        // Format date
        const date = new Date(entry.createdAt);
        const formattedDate = date.toLocaleDateString('en-US', {
            year: 'numeric',
            month: 'long',
            day: 'numeric',
            hour: 'numeric',
            minute: 'numeric',
            second: 'numeric',
            hour12: true
        });
        
        // Format tags
        let tagsHtml = '';
        if (entry.tags && entry.tags.length > 0) {
            tagsHtml = `
                <div class="entry-tags">
                    ${entry.tags.map(tag => `<span class="badge bg-light text-cyber bg-opacity-10 me-1">${tag}</span>`).join('')}
                </div>
            `;
        }
        
        // Format mood
        const moodHtml = entry.mood ? `• ${entry.mood}` : '';
        
        // Format preview content (first 150 chars)
        const contentPreview = entry.content.replace(/<[^>]*>/g, '').substring(0, 150) + '...';
        
        return `
            <div class="card-body body-color rounded p-4">
                <div class="entry-header d-flex justify-content-between">
                    <h4 class="entry-title">
                        ${entry.title}
                    </h4>
                    <div class="entry-actions">
                        <i class="mdi mdi-star ${entry.isFavorite ? 'text-warning' : 'text-muted'} favorite-static-icon" title="${entry.isFavorite ? 'Favorite' : 'Not favorite'}"></i>
                        <span id="toggleFavorite" class="cursor-pointer p-0 me-2 d-none" title="Toggle favorite">
                            <i class="mdi mdi-star ${entry.isFavorite ? 'text-warning' : 'text-muted'}"></i>
                        </span>
                    </div>
                </div>
                <div class="entry-metadata mb-2">
                    <small class="text-muted">
                        ${formattedDate}
                        ${moodHtml}
                    </small>
                </div>
                <div class="entry-content-original">
                    <p class="entry-preview">${contentPreview}</p>
                    ${tagsHtml}
                </div>
                <div class="entry-content-expanded d-none"></div>
                <div class="entry-content-expanded-before-edit d-none"></div>
            </div>
        `;
    }
    
    async loadAndRenderEntries(prependNewEntry = false) {
        try {
            // If not prepending a new entry, show loading indicator
            if (!prependNewEntry) {
                this.entriesContainer.innerHTML = this.loadingTemplate;
                const loadingIndicator = this.entriesContainer.querySelector('.loading-indicator');
                loadingIndicator.classList.add('active');
                await wait(DURATION.QUICK);
            }
            
            // Fetch entries
            const response = await fetch('/api/diary');
            if (!response.ok) throw new Error('Failed to load entries');
            
            const data = await response.json();
            const entries = data.entries;
            
            // If not prepending, fade out loading indicator
            if (!prependNewEntry) {
                const loadingIndicator = this.entriesContainer.querySelector('.loading-indicator');
                if (loadingIndicator) {
                    loadingIndicator.classList.remove('active');
                    await wait(DURATION.QUICK);
                }
            }
            
            // Render entries
            if (entries.length === 0) {
                this.entriesContainer.innerHTML = `
                    <div class="alert alert-info">
                        No diary entries yet. Create your first one by clicking the "New Entry" button.
                    </div>
                `;
            } else {
                // If prepending new entry, keep existing entries
                if (prependNewEntry) {
                    // Get the newest entry (first in the array)
                    const newestEntry = entries[0];
                    
                    // Create element for the new entry
                    const entryElement = document.createElement('div');
                    entryElement.className = 'diary-entry-card glass-panel mb-4';
                    entryElement.dataset.entryId = newestEntry.id;
                    entryElement.style.opacity = '0';
                    entryElement.style.height = '0';
                    entryElement.style.overflow = 'hidden';
                    entryElement.innerHTML = this.renderEntryCard(newestEntry);
                    
                    // Insert at the top
                    if (this.entriesContainer.firstChild) {
                        this.entriesContainer.insertBefore(entryElement, this.entriesContainer.firstChild);
                    } else {
                        this.entriesContainer.appendChild(entryElement);
                    }
                    
                    // Animate the new entry
                    await slideDown(entryElement, DURATION.NORMAL);
                } else {
                    // Replace all entries
                    this.entriesContainer.innerHTML = '';
                    entries.forEach(entry => {
                        const entryElement = document.createElement('div');
                        entryElement.className = 'diary-entry-card glass-panel mb-4';
                        entryElement.dataset.entryId = entry.id;
                        entryElement.innerHTML = this.renderEntryCard(entry);
                        this.entriesContainer.appendChild(entryElement);
                    });
                }
            }
        } catch (error) {
            console.error('Error loading entries:', error);
            if (!prependNewEntry) {
                this.entriesContainer.innerHTML = `
                    <div class="alert alert-danger">
                        Failed to load entries. Please try again later.
                    </div>
                `;
            } else {
                window.toast.error('Failed to refresh entries');
            }
        }
    }
}
