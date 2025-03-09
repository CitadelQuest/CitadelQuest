import { DURATION, wait, slideUp, slideDown, scrollIntoViewWithOffset } from '../../../shared/animation';

/**
 * DiaryEntryNew - Handles all functionality related to creating new diary entries
 */
export class DiaryEntryNew {
    /**
     * @param {Object} options - Configuration options
     * @param {Object} options.translations - Translation strings
     * @param {Element} options.entriesContainer - Container for diary entries
     * @param {Function} options.getConsciousnessLevelClass - Function to get consciousness level CSS class
     * @param {Function} options.loadAndRenderEntries - Function to load and render entries
     * @param {Object} options.apiService - The DiaryApiService instance
     */
    constructor(options) {
        this.translations = options.translations;
        this.entriesContainer = options.entriesContainer;
        this.getConsciousnessLevelClass = options.getConsciousnessLevelClass;
        this.loadAndRenderEntries = options.loadAndRenderEntries;
        this.apiService = options.apiService;
    }

    /**
     * Show the new entry form
     */
    async showNewEntryForm() {
        // Update URL without reload
        const url = `/diary/new`;
        history.pushState({ action: 'new-entry' }, '', url);
        
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
                    console.error(this.translations.error_executing_command, error);
                }
                editor.focus();
            });
        });
        
        // Initialize consciousness level slider
        const consciousnessLevelSlider = newEntryContainer.querySelector('#consciousnessLevel');
        const consciousnessLevelValue = newEntryContainer.querySelector('.consciousness-level-value');
        
        if (consciousnessLevelSlider && consciousnessLevelValue) {
            consciousnessLevelSlider.addEventListener('input', (e) => {
                const value = parseInt(e.target.value);
                consciousnessLevelValue.textContent = value;
                consciousnessLevelValue.className = `consciousness-level-value badge ${this.getConsciousnessLevelClass(value)}`;
            });
        }
    }

    /**
     * Save a new diary entry
     * @param {HTMLFormElement} form - The form element
     */
    async saveNewEntry(form) {
        const newEntryContainer = document.querySelector('.diary-new-entry-container');
        const editor = form.querySelector('#editor');
        
        // Basic validation
        const title = form.querySelector('#title').value.trim();
        if (!title) {
            window.toast.error(this.translations.title_required);
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
            consciousnessLevel: parseInt(form.querySelector('#consciousnessLevel').value) || 0,
            isEncrypted: form.querySelector('#isEncrypted')?.checked || false
        };
        
        try {
            // Show loading state
            const saveButton = form.querySelector('[data-action="save-new"]');
            const originalButtonText = saveButton.innerHTML;
            saveButton.innerHTML = `<i class="mdi mdi-loading mdi-spin"></i> ${this.translations.saving}`;
            saveButton.disabled = true;
            
            // Send create request
            await this.apiService.createEntry(formData);
            
            // Show success message
            window.toast.success(this.translations.entry_saved);
            
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
            window.toast.error(this.translations.failed_save);
            
            // Restore button state
            const saveButton = form.querySelector('[data-action="save-new"]');
            saveButton.innerHTML = originalButtonText;
            saveButton.disabled = false;
        }
    }

    /**
     * Cancel creating a new entry
     */
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
        // If we came directly to /diary/new, use pushState instead of history.back()
        if (document.referrer === '' || !document.referrer.includes('/diary')) {
            history.pushState({ action: 'list' }, '', '/diary');
        } else {
            history.back();
        }
    }

    /**
     * Render the new entry form
     * @returns {string} - HTML for the new entry form
     */
    renderNewEntryForm() {
        return `
            <div class="card-body body-color rounded p-4 bg-cyber-g-light">
                <h3 class="mb-4">${this.translations.new_entry}</h3>
                <form class="new-entry-form">
                    <div class="mb-3">
                        <label for="title" class="form-label">${this.translations.form_title}</label>
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

                        <label for="editor" class="form-label">${this.translations.form_content}</label>
                        <div id="editor" class="form-control rounded-top-0 rounded-start-1" style="min-height: 200px;" contenteditable="true" data-placeholder="${this.translations.placeholders_content}"></div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="mood" class="form-label">${this.translations.form_mood}</label>
                        <select class="form-select" id="mood" name="mood">
                            <option value="">${this.translations.form_mood_select}</option>
                            <option value="Happy">${this.translations.form_moods_happy}</option>
                            <option value="Calm">${this.translations.form_moods_calm}</option>
                            <option value="Thoughtful">${this.translations.form_moods_thoughtful}</option>
                            <option value="Excited">${this.translations.form_moods_excited}</option>
                            <option value="Sad">${this.translations.form_moods_sad}</option>
                            <option value="Anxious">${this.translations.form_moods_anxious}</option>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label for="tags" class="form-label">${this.translations.form_tags}</label>
                        <input type="text" class="form-control" id="tags" name="tags" placeholder="${this.translations.placeholders_tags}">
                        <small class="form-text text-muted">${this.translations.form_tags_help}</small>
                    </div>
                    
                    <div class="mb-3">
                        <label for="consciousnessLevel" class="form-label">${this.translations.form_consciousness_level}</label>
                        <div class="d-flex align-items-center gap-2">
                            <input type="range" class="form-range flex-grow-1" id="consciousnessLevel" name="consciousnessLevel" min="0" max="1000" step="10" value="0">
                            <span class="consciousness-level-value badge bg-secondary">0</span>
                        </div>
                        <small class="form-text text-muted">${this.translations.form_consciousness_level_help}</small>
                    </div>
                    
                    <div class="mb-3 form-check">
                        <input type="checkbox" class="form-check-input" id="isEncrypted" name="isEncrypted">
                        <label class="form-check-label" for="isEncrypted">${this.translations.encrypt_entry}</label>
                    </div>
                    
                    <div class="d-flex justify-content-between mt-4">
                        <button type="button" class="btn btn-secondary" data-action="cancel-new">
                            <i class="mdi mdi-cancel"></i> ${this.translations.cancel}
                        </button>
                        <button type="button" class="btn btn-cyber" data-action="save-new">
                            <i class="mdi mdi-content-save"></i> ${this.translations.save}
                        </button>
                    </div>
                </form>
            </div>
        `;
    }
}
