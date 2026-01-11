import { DURATION, wait, slideUp, slideDown, scrollIntoViewWithOffset } from '../../../shared/animation';
import * as bootstrap from 'bootstrap';

/**
 * DiaryEntryEdit - Handles all functionality related to editing diary entries
 */
export class DiaryEntryEdit {
    /**
     * @param {Object} options - Configuration options
     * @param {Object} options.translations - Translation strings
     * @param {Object} options.apiService - The DiaryApiService instance
     * @param {Function} options.renderEntryDetail - Function to render entry detail
     */
    constructor(options) {
        this.translations = options.translations;
        this.apiService = options.apiService;
        this.renderEntryDetail = options.renderEntryDetail;
    }

    /**
     * Show the edit form for an entry
     * @param {string} entryId - The ID of the entry to edit
     * @param {Element} entryCard - The entry card element
     */
    async showEditForm(entryId, entryCard) {
        const contentContainer = entryCard.querySelector('.entry-content-expanded');
        const detailContent = contentContainer.querySelector('.entry-detail');

        // save current content state before editing to `.entry-content-expanded-before-edit`
        const entryContentExpandedBeforeEdit = entryCard.querySelector('.entry-content-expanded-before-edit');
        if (entryContentExpandedBeforeEdit) {
            entryContentExpandedBeforeEdit.innerHTML = contentContainer.innerHTML;
        }
        
        // If there's existing content, slide it up first
        if (detailContent) {
            await slideUp(detailContent, DURATION.NORMAL);
        }
        
        // Show loading state
        contentContainer.innerHTML = `
            <div class="loading-indicator w-100 text-center">
                <div class="spinner-border text-cyber" role="status">
                    <span class="visually-hidden">${this.translations.loading}</span>
                </div>
            </div>
        `;
        const loadingIndicator = contentContainer.querySelector('.loading-indicator');
        loadingIndicator.classList.add('active');
        await wait(DURATION.QUICK);
        
        try {
            // Fetch entry data
            const data = await this.apiService.fetchEntry(entryId);
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
                        console.error(this.translations.error_executing_command, error);
                    }
                    editor.focus();
                });
            });
        } catch (error) {
            window.toast.error(this.translations.failed_load_edit);
            
            contentContainer.innerHTML = `
                <div class="alert alert-danger">
                    ${this.translations.failed_load_edit_content}
                </div>
            `;
        }

        // Scroll entryCard to top under navigation
        scrollIntoViewWithOffset(entryCard); 
    }
    
    /**
     * Save the edit form for an entry
     * @param {string} entryId - The ID of the entry to save
     * @param {HTMLFormElement} form - The form element
     * @param {Element} entryCard - The entry card element
     */
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
            saveButton.innerHTML = `<i class="mdi mdi-loading mdi-spin"></i> ${this.translations.saving}`;
            saveButton.disabled = true;
            
            // Send update request
            await this.apiService.updateEntry(entryId, formData);
            
            // Show success message
            window.toast.success(this.translations.entry_saved);
            
            // Apply slide-up animation to the form
            await slideUp(form, DURATION.NORMAL);
            
            // Show loading state while we reload the content
            contentContainer.innerHTML = `
                <div class="loading-indicator w-100 text-center">
                    <div class="spinner-border text-cyber" role="status">
                        <span class="visually-hidden">${this.translations.loading}</span>
                    </div>
                </div>
            `;
            
            // Manually update the card header with new data immediately
            // This ensures the visible metadata is updated right away
            const entryTitle = entryCard.querySelector('.entry-title');
            if (entryTitle) {
                entryTitle.textContent = formData.title;
            }
            
            const entryMood = entryCard.querySelector('.entry-mood');
            if (entryMood) {
                entryMood.textContent = formData.mood;
            }
            
            // Update the original content preview for when it's collapsed
            const originalContentPreview = entryCard.querySelector('.entry-preview');
            if (originalContentPreview) {
                const tempDiv = document.createElement('div');
                tempDiv.innerHTML = formData.contentFormatted;
                const textContent = tempDiv.textContent || tempDiv.innerText || '';
                originalContentPreview.textContent = textContent.substring(0, 150) + '...';
            }
            
            // Update tags in the collapsed view
            const originalContent = entryCard.querySelector('.entry-content-original');
            if (originalContent && formData.tags && formData.tags.length > 0) {
                // Find or create tags container
                let tagsContainer = originalContent.querySelector('.entry-tags');
                if (!tagsContainer) {
                    tagsContainer = document.createElement('div');
                    tagsContainer.className = 'entry-tags';
                    originalContent.appendChild(tagsContainer);
                }
                
                // Generate tags HTML
                tagsContainer.innerHTML = formData.tags.map(tag => 
                    `<span class="badge bg-light text-cyber bg-opacity-10 me-1">${tag}</span>`
                ).join('');
            } else if (originalContent) {
                // Remove tags container if no tags
                const tagsContainer = originalContent.querySelector('.entry-tags');
                if (tagsContainer) {
                    tagsContainer.remove();
                }
            }
            
            // Update metadata in the collapsed view
            const metadataContainer = entryCard.querySelector('.entry-metadata');
            if (metadataContainer) {
                const moodText = formData.mood ? `• ${formData.mood}` : '';
                
                // Get the date text from existing metadata
                const existingText = metadataContainer.querySelector('small').innerHTML;
                const dateText = existingText.split('•')[0]; // Get the date part before any '•'
                
                // Update the metadata with new mood
                metadataContainer.querySelector('small').innerHTML = `${dateText}${moodText}`;
            }
            
            // Force a small delay to ensure the API has updated the entry
            await wait(DURATION.QUICK);
            
            try {
                // Fetch the updated entry data
                const data = await this.apiService.fetchEntry(entryId);
                
                // Render the updated entry detail
                contentContainer.innerHTML = this.renderEntryDetail(data.entry);
                
                // Apply slide down animation to the detail content
                const detailContent = contentContainer.querySelector('.entry-detail');
                if (detailContent) {
                    await slideDown(detailContent, DURATION.NORMAL);
                    
                    // Initialize Bootstrap dropdowns
                    const dropdownButtons = contentContainer.querySelectorAll('[data-bs-toggle="dropdown"]');
                    dropdownButtons.forEach(button => {
                        new bootstrap.Dropdown(button);
                    });
                }
            } catch (error) {
                console.error('Error reloading entry after edit:', error);
                contentContainer.innerHTML = `
                    <div class="alert alert-warning">
                        ${this.translations.update_reload_error}
                        <button class="btn btn-sm btn-cyber mt-2" data-action="view" data-entry-id="${entryId}">
                            <i class="mdi mdi-refresh me-1"></i> ${this.translations.try_again}
                        </button>
                    </div>
                `;
            }
        } catch (error) {
            window.toast.error(this.translations.failed_save);
            
            // Restore button state
            const saveButton = form.querySelector('[data-action="save-edit"]');
            saveButton.innerHTML = originalButtonText;
            saveButton.disabled = false;
        }
    }
    
    /**
     * Cancel editing an entry
     * @param {string} entryId - The ID of the entry
     * @param {Element} entryCard - The entry card element
     */
    async cancelEdit(entryId, entryCard) {
        const contentContainer = entryCard.querySelector('.entry-content-expanded');
        const editForm = contentContainer.querySelector('form');
        
        // Apply slide-up animation to the form
        await slideUp(editForm, DURATION.NORMAL);
        
        // restore content before edit
        const entryContentExpandedBeforeEdit = entryCard.querySelector('.entry-content-expanded-before-edit');
        if (entryContentExpandedBeforeEdit) {
            contentContainer.innerHTML = entryContentExpandedBeforeEdit.innerHTML;

            await slideDown(contentContainer, DURATION.NORMAL);
        }
    }
    
    /**
     * Render the edit form for an entry
     * @param {Object} entry - The entry to edit
     * @returns {string} - HTML for the edit form
     */
    renderEditForm(entry) {
        return `
            <form class="edit-entry-form" data-entry-id="${entry.id}">
                <div class="mb-3">
                    <label for="title" class="form-label">${this.translations.form_title}</label>
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

                    <label for="editor" class="form-label">${this.translations.form_content}</label>
                    <div id="editor" class="form-control rounded-top-0 rounded-start-1" style="min-height: 200px;" contenteditable="true" data-placeholder="${this.translations.placeholders_content}">
                        ${entry.contentFormatted || entry.content}
                    </div>
                </div>
                
                <div class="mb-3">
                    <label for="mood" class="form-label">${this.translations.form_mood}</label>
                    <select class="form-select" id="mood" name="mood">
                        <option value="">${this.translations.form_mood_select}</option>
                        <option value="Happy" ${entry.mood === 'Happy' ? 'selected' : ''}>${this.translations.form_moods_happy}</option>
                        <option value="Calm" ${entry.mood === 'Calm' ? 'selected' : ''}>${this.translations.form_moods_calm}</option>
                        <option value="Thoughtful" ${entry.mood === 'Thoughtful' ? 'selected' : ''}>${this.translations.form_moods_thoughtful}</option>
                        <option value="Excited" ${entry.mood === 'Excited' ? 'selected' : ''}>${this.translations.form_moods_excited}</option>
                        <option value="Sad" ${entry.mood === 'Sad' ? 'selected' : ''}>${this.translations.form_moods_sad}</option>
                        <option value="Anxious" ${entry.mood === 'Anxious' ? 'selected' : ''}>${this.translations.form_moods_anxious}</option>
                    </select>
                </div>
                
                <div class="mb-3">
                    <label for="tags" class="form-label">${this.translations.form_tags}</label>
                    <input type="text" class="form-control" id="tags" name="tags" value="${entry.tags ? entry.tags.join(', ') : ''}" placeholder="${this.translations.placeholders_tags}">
                    <small class="form-text text-muted">${this.translations.form_tags_help}</small>
                </div>
                
                <div class="d-flex justify-content-between mt-4">
                    <button type="button" class="btn btn-secondary" data-action="cancel-edit" data-entry-id="${entry.id}">
                        <i class="mdi mdi-cancel"></i> ${this.translations.cancel}
                    </button>
                    <button type="button" class="btn btn-cyber" data-action="save-edit">
                        <i class="mdi mdi-content-save"></i> ${this.translations.save}
                    </button>
                </div>
            </form>
        `;
    }
}
