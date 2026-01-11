import { DURATION, wait, slideDown } from '../../../shared/animation';
import * as bootstrap from 'bootstrap';

/**
 * DiaryEntryDisplay - Handles rendering and display of diary entries
 */
export class DiaryEntryDisplay {
    /**
     * @param {Object} options - Configuration options
     * @param {Object} options.translations - Translation strings
     */
    constructor(options) {
        this.translations = options.translations;
        this.loadingTemplate = `
            <div class="loading-indicator w-100 text-center">
                <div class="spinner-border text-cyber" role="status">
                    <span class="visually-hidden">${this.translations.loading}</span>
                </div>
            </div>
        `;
    }

    /**
     * Render the detail view of an entry
     * @param {Object} entry - The entry to render
     * @returns {string} - HTML for the entry detail
     */
    renderEntryDetail(entry) {
        return `
            <div class="entry-detail">
                <div class="entry-full-content">
                    ${entry.contentFormatted || entry.content}
                </div>
                
                <div class="entry-metadata mt-3">
                    
                    ${entry.tags ? `
                        <div class="entry-tags">
                            ${entry.tags.map(tag => `
                                <span class="badge bg-cyber bg-opacity-50 me-1">${tag}</span>
                            `).join('')}
                        </div>
                    ` : ''}
                </div>
                <div class="entry-actions mt-4">
                    <button class="btn btn-sm btn-light me-2" data-action="back">
                        <i class="mdi mdi-keyboard-return"></i> ${this.translations.back}
                    </button>
                    <button class="btn btn-sm btn-cyber float-end" data-action="edit" data-entry-id="${entry.id}">
                        <i class="mdi mdi-pencil"></i> ${this.translations.edit}
                    </button>
                    
                    <div class="dropdown dropup d-inline float-end me-2">
                        <button class="btn btn-sm btn-link text-cyber p-0" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="mdi mdi-dots-vertical"></i>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li>
                                <button class="dropdown-item text-danger" type="button" data-entry-id="${entry.id}" data-action="delete">
                                    <i class="mdi mdi-delete-forever-outline"></i> ${this.translations.delete}
                                </button>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
        `;
    }

    /**
     * Render a card for an entry in the list view
     * @param {Object} entry - The entry to render
     * @returns {string} - HTML for the entry card
     */
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
                <div class="entry-header d-flex justify-content-between align-items-center">
                    <h4 class="entry-title mb-0">
                        ${entry.title}
                    </h4>
                    <div class="entry-actions">
                        <i class="mdi mdi-star ${entry.isFavorite ? 'text-warning' : 'text-muted'} favorite-static-icon" title="${entry.isFavorite ? this.translations.favorite.title : this.translations.favorite.not_favorite}"></i>
                        <span id="toggleFavorite" class="cursor-pointer p-0 me-2 d-none" title="${this.translations.favorite.toggle}">
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

    /**
     * Load and render all diary entries
     * @param {Element} entriesContainer - The container for entries
     * @param {Function} fetchEntries - Function to fetch entries
     * @param {boolean} prependNewEntry - Whether to prepend a new entry
     */
    async loadAndRenderEntries(entriesContainer, fetchEntries, prependNewEntry = false) {
        try {
            // If not prepending a new entry, show loading indicator
            if (!prependNewEntry) {
                entriesContainer.innerHTML = this.loadingTemplate;
                const loadingIndicator = entriesContainer.querySelector('.loading-indicator');
                loadingIndicator.classList.add('active');
                await wait(DURATION.QUICK);
            }
            
            // Fetch entries
            const data = await fetchEntries();
            const entries = data.entries;
            
            // If not prepending, fade out loading indicator
            if (!prependNewEntry) {
                const loadingIndicator = entriesContainer.querySelector('.loading-indicator');
                if (loadingIndicator) {
                    loadingIndicator.classList.remove('active');
                    await wait(DURATION.QUICK);
                }
            }
            
            // Render entries
            if (entries.length === 0) {
                entriesContainer.innerHTML = `
                    <div class="alert alert-info">
                        ${this.translations.no_entries}
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
                    if (entriesContainer.firstChild) {
                        entriesContainer.insertBefore(entryElement, entriesContainer.firstChild);
                    } else {
                        entriesContainer.appendChild(entryElement);
                    }
                    
                    // Animate the new entry
                    await slideDown(entryElement, DURATION.NORMAL);
                } else {
                    // Replace all entries
                    entriesContainer.innerHTML = '';
                    entries.forEach(entry => {
                        const entryElement = document.createElement('div');
                        entryElement.className = 'diary-entry-card glass-panel mb-4';
                        entryElement.dataset.entryId = entry.id;
                        entryElement.innerHTML = this.renderEntryCard(entry);
                        entriesContainer.appendChild(entryElement);
                    });
                }
            }
        } catch (error) {
            if (!prependNewEntry) {
                entriesContainer.innerHTML = `
                    <div class="alert alert-danger">
                        ${this.translations.failed_load_list_content}
                    </div>
                `;
            } else {
                window.toast.error(this.translations.failed_refresh);
            }
        }
    }
}
