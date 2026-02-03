/**
 * AI Model Selector Manager
 * Reusable component for selecting AI models with visual comparison
 */
import * as bootstrap from 'bootstrap';
import { marked } from 'marked';

export class AiModelSelectorManager {
    constructor() {
        this.models = [];
        this.filteredModels = [];
        this.selectedModel = null;
        this.onSelectCallback = null;
        this.filterType = 'primary'; // 'primary' (exclude image-only) or 'image' (image models only)
        
        // Sorting state
        this.sortBy = 'avgPrice'; // 'modelName', 'avgPrice', 'contextWindow'
        this.sortDirection = 'asc'; // 'asc' or 'desc'
        
        // Provider colors
        this.providerColors = {
            'anthropic': '#cc785c',
            'google': '#4285f4',
            'x-ai': '#000000',
            'meta': '#0668e1',
            'mistral': '#ff7000',
            'cohere': '#39594d',
            'other': '#cad0d4ff'
        };
        
        // Modality icons
        this.modalityIcons = {
            'text': 'mdi-text',
            'image': 'mdi-image',
            'audio': 'mdi-microphone',
            'video': 'mdi-video'
        };
        
        this.init();
    }

    init() {
        // Set up event listeners
        this.setupEventListeners();
    }

    setupEventListeners() {
        // Search input
        const searchInput = document.getElementById('aiModelSearchInput');
        if (searchInput) {
            searchInput.addEventListener('input', () => this.filterModels());
        }

        // Provider filters
        document.querySelectorAll('input[name="providerFilter"]').forEach(radio => {
            radio.addEventListener('change', () => this.filterModels());
        });

        // Modal events
        const modal = document.getElementById('aiModelSelectorModal');
        if (modal) {
            // Modal fully shown - trigger scroll to selected model
            modal.addEventListener('shown.bs.modal', () => {
                this.scrollToSelectedModel();
                this.setupSortableHeaders();
            });
            
            // Modal close cleanup
            modal.addEventListener('hidden.bs.modal', () => {
                this.resetFilters();
            });
        }
    }

    setupSortableHeaders() {
        // Add click handlers to sortable headers
        document.querySelectorAll('th[data-sort]').forEach(header => {
            header.style.cursor = 'pointer';
            header.addEventListener('click', () => {
                const sortField = header.dataset.sort;
                if (this.sortBy === sortField) {
                    // Toggle direction
                    this.sortDirection = this.sortDirection === 'asc' ? 'desc' : 'asc';
                } else {
                    // New sort field, default to ascending
                    this.sortBy = sortField;
                    this.sortDirection = 'asc';
                }
                this.sortModels();
                this.renderModels();
                this.updateSortIndicators();
            });
        });
        this.updateSortIndicators();
    }

    updateSortIndicators() {
        // Update all sort indicators
        document.querySelectorAll('th[data-sort]').forEach(header => {
            const sortField = header.dataset.sort;
            const indicator = header.querySelector('.sort-indicator');
            
            if (sortField === this.sortBy) {
                if (!indicator) {
                    const icon = document.createElement('i');
                    icon.className = 'mdi sort-indicator ms-1';
                    header.appendChild(icon);
                }
                const icon = header.querySelector('.sort-indicator');
                icon.className = `mdi sort-indicator ms-1 ${this.sortDirection === 'asc' ? 'mdi-arrow-up' : 'mdi-arrow-down'}`;
            } else if (indicator) {
                indicator.remove();
            }
        });
    }

    sortModels() {
        this.filteredModels.sort((a, b) => {
            let aVal, bVal;
            
            switch (this.sortBy) {
                case 'modelName':
                    aVal = a.modelName.toLowerCase();
                    bVal = b.modelName.toLowerCase();
                    break;
                case 'avgPrice':
                    aVal = a.avgPrice;
                    bVal = b.avgPrice;
                    break;
                case 'contextWindow':
                    aVal = a.contextWindow;
                    bVal = b.contextWindow;
                    break;
                default:
                    return 0;
            }
            
            if (aVal < bVal) return this.sortDirection === 'asc' ? -1 : 1;
            if (aVal > bVal) return this.sortDirection === 'asc' ? 1 : -1;
            return 0;
        });
    }

    /**
     * Open the model selector
     * @param {string} filterType - 'all' or 'image'
     * @param {function} onSelect - Callback when model is selected
     * @param {string|null} currentModelId - Currently selected model ID
     */
    open(filterType = 'all', onSelect = null, currentModelId = null) {
        this.filterType = filterType;
        this.onSelectCallback = onSelect;
        this.selectedModel = currentModelId;
        
        // Show modal
        const modal = new bootstrap.Modal(document.getElementById('aiModelSelectorModal'));
        modal.show();
        
        // Load models
        this.loadModels();
    }

    async loadModels() {
        const loadingEl = document.getElementById('aiModelSelectorLoading');
        const filtersEl = document.getElementById('aiModelSelectorFilters');
        const tableEl = document.getElementById('aiModelSelectorTable');
        const errorEl = document.getElementById('aiModelSelectorError');

        // Show loading
        loadingEl.classList.remove('d-none');
        filtersEl.classList.add('d-none');
        tableEl.classList.add('d-none');
        errorEl.classList.add('d-none');

        try {
            const endpoint = `/api/ai/model/selector?type=${this.filterType}`;
            
            const response = await fetch(endpoint);
            const data = await response.json();

            if (!data.success) {
                throw new Error(data.message || 'Failed to load models');
            }

            this.models = data.models;
            this.filteredModels = [...this.models];

            // Hide loading, show content
            loadingEl.classList.add('d-none');
            //filtersEl.classList.remove('d-none');
            tableEl.classList.remove('d-none');

            // Apply default sorting
            this.sortModels();
            
            // Render models
            this.renderModels();
        } catch (error) {
            console.error('Error loading AI models:', error);
            loadingEl.classList.add('d-none');
            errorEl.classList.remove('d-none');
            document.getElementById('aiModelSelectorErrorMessage').textContent = 
                error.message || 'Failed to load AI models. Please try again.';
        }
    }

    filterModels() {
        const searchQuery = document.getElementById('aiModelSearchInput').value.toLowerCase();
        const selectedProvider = document.querySelector('input[name="providerFilter"]:checked').value;

        this.filteredModels = this.models.filter(model => {
            // Search filter
            const matchesSearch = !searchQuery || 
                model.modelName.toLowerCase().includes(searchQuery) ||
                (model.description && model.description.toLowerCase().includes(searchQuery));

            // Provider filter
            const matchesProvider = selectedProvider === 'all' || model.provider === selectedProvider;

            return matchesSearch && matchesProvider;
        });

        this.renderModels();
    }

    renderModels() {
        const tbody = document.getElementById('aiModelSelectorTableBody');
        const noResultsEl = document.getElementById('aiModelSelectorNoResults');
        const tableEl = document.getElementById('aiModelSelectorTable');

        if (this.filteredModels.length === 0) {
            tableEl.classList.add('d-none');
            noResultsEl.classList.remove('d-none');
            return;
        }

        tableEl.classList.remove('d-none');
        noResultsEl.classList.add('d-none');

        tbody.innerHTML = this.filteredModels.map(model => this.renderModelRow(model)).join('');

        // Add click handlers
        tbody.querySelectorAll('.model-row').forEach(row => {
            row.addEventListener('click', (e) => {
                if (!e.target.closest('.btn-select-model')) {
                    const modelId = row.dataset.modelId;
                    this.showModelDetails(modelId);
                }
            });
        });

        // Select button handlers
        tbody.querySelectorAll('.btn-select-model').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.stopPropagation();
                const modelId = btn.dataset.modelId;
                this.selectModel(modelId);
            });
        });
    }

    renderModelRow(model) {
        const providerColor = this.providerColors[model.provider] || this.providerColors['other'];
        const isSelected = model.id === this.selectedModel;

        return `
            <tr class="model-row ${isSelected ? 'table-active' : ''}" data-model-id="${model.id}" style="cursor: pointer;">
                <td>
                    <div class="d-flex align-items-start">
                        <div class="me-2" style="min-width: 4px; height: 100%; background: ${providerColor}; border-radius: 2px;"></div>
                        <div>
                            <div class="fw-semibold" style="color: ${providerColor};">
                                ${this.escapeHtml(model.modelName)}
                                ${isSelected ? '<i class="mdi mdi-check-circle text-cyber ms-1"></i>' : ''}
                            </div>                            
                        </div>
                    </div>
                </td>
                <td class="text-center">
                    <div class="mb-1">
                        <small class="text-cyber">${model.avgPrice.toFixed(2)}</small>
                        <small class="text-secondary"> Credits/M</small>
                    </div>
                    <div class="progress" style="height: 6px;">
                        <div class="progress-bar bg-cyber" role="progressbar" 
                            style="width: ${model.pricePercentage}%;" 
                            aria-valuenow="${model.pricePercentage}" 
                            aria-valuemin="0" aria-valuemax="100"></div>
                    </div>
                    <small class="text-secondary">${model.ppmInput.toFixed(2)} / ${model.ppmOutput.toFixed(2)}</small>
                </td>
                <td class="text-center">
                    <div class="mb-1">
                        <small class="text-secondary">Context:</small>
                        <small class="text-info">${model.contextWindow.toLocaleString()}</small>
                    </div>
                    <div class="progress mb-2_" style="height: 6px;">
                        <div class="progress-bar bg-info" role="progressbar" 
                            style="width: ${model.contextPercentage}%;" 
                            aria-valuenow="${model.contextPercentage}" 
                            aria-valuemin="0" aria-valuemax="100"></div>
                    </div>
                    <div class="mb-1_">
                        <small class="text-secondary">Output:</small>
                        <small class="text-light">${model.maxOutput ? model.maxOutput.toLocaleString() : 'N/A'}</small>
                    </div>
                    <div class="progress d-none" style="height: 6px;">
                        <div class="progress-bar bg-warning" role="progressbar" 
                            style="width: ${model.maxOutputPercentage || 0}%;" 
                            aria-valuenow="${model.maxOutputPercentage || 0}" 
                            aria-valuemin="0" aria-valuemax="100"></div>
                    </div>
                </td>
                <td class="text-center">
                    <div class="d-flex justify-content-center gap-1">
                        ${this.renderModalities(model.inputModalities, 'input')}
                        <i class="mdi mdi-arrow-right text-secondary"></i>
                        ${this.renderModalities(model.outputModalities, 'output')}
                    </div>
                </td>
                <td class="text-center">
                    <button class="btn btn-sm btn-cyber btn-select-model" data-model-id="${model.id}">
                        <i class="mdi mdi-check"></i>
                    </button>
                </td>
            </tr>
        `;
    }

    renderModalities(modalities, type) {
        if (!modalities || modalities.length === 0) return '<span class="text-secondary">-</span>';
        
        return modalities.map(modality => {
            const icon = this.modalityIcons[modality] || 'mdi-file';
            const color = type === 'input' ? 'text-info' : 'text-cyber';
            return `<i class="mdi ${icon} ${color}" title="${modality}"></i>`;
        }).join(' ');
    }

    showModelDetails(modelId) {
        const model = this.models.find(m => m.id === modelId);
        if (!model) return;

        const detailsBody = document.getElementById('aiModelDetailsBody');
        const providerColor = this.providerColors[model.provider] || this.providerColors['other'];

        detailsBody.innerHTML = `
            <div class="mb-3">
                <div class="fw-bold" style="color: ${providerColor};">
                    ${this.escapeHtml(model.modelName)}
                </div>
            </div>

            <div class="row g-3">
                <div class="col-12">
                    <div class="glass-panel p-3">
                        <h6 class="text-cyber mb-2">
                            <i class="mdi mdi-information-outline me-2"></i>Description
                        </h6>
                        <div class="text-secondary mb-0 model-description">${marked.parse(model.description)}</div>
                    </div>
                </div>

                <div class="col-md-6">
                    <div class="glass-panel p-3">
                        <h6 class="text-cyber mb-2"><i class="mdi mdi-cash me-2"></i>Pricing</h6>
                        <div class="d-flex justify-content-between mb-1">
                            <small class="text-secondary">Input:</small>
                            <small class="text-light">${model.ppmInput.toFixed(2)} Credits/M tokens</small>
                        </div>
                        <div class="d-flex justify-content-between mb-1">
                            <small class="text-secondary">Output:</small>
                            <small class="text-light">${model.ppmOutput.toFixed(2)} Credits/M tokens</small>
                        </div>
                        <div class="d-flex justify-content-between">
                            <small class="text-secondary">Average:</small>
                            <small class="text-cyber fw-bold">${model.avgPrice.toFixed(2)} Credits/M tokens</small>
                        </div>
                    </div>
                </div>

                <div class="col-md-6">
                    <div class="glass-panel p-3">
                        <h6 class="text-info mb-2"><i class="mdi mdi-memory me-2"></i>Capabilities</h6>
                        <div class="d-flex justify-content-between mb-1">
                            <small class="text-secondary">Context Window:</small>
                            <small class="text-light">${this.formatNumber(model.contextWindow)} tokens</small>
                        </div>
                        <div class="d-flex justify-content-between">
                            <small class="text-secondary">Max Output:</small>
                            <small class="text-light">${model.maxOutput ? this.formatNumber(model.maxOutput) : 'N/A'} tokens</small>
                        </div>
                    </div>
                </div>

                <div class="col-12">
                    <div class="glass-panel p-3">
                        <h6 class="mb-2"><i class="mdi mdi-swap-horizontal me-2"></i>Modalities</h6>
                        <div class="row">
                            <div class="col-6">
                                <small class="text-secondary d-block mb-1">Input:</small>
                                <div class="d-flex gap-2">
                                    ${model.inputModalities.map(m => `<span class="badge bg-info text-dark">${m}</span>`).join(' ')}
                                </div>
                            </div>
                            <div class="col-6">
                                <small class="text-secondary d-block mb-1">Output:</small>
                                <div class="d-flex gap-2">
                                    ${model.outputModalities.map(m => `<span class="badge bg-cyber">${m}</span>`).join(' ')}
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        `;

        // Set up select button
        const selectBtn = document.getElementById('aiModelDetailsSelectBtn');
        selectBtn.onclick = () => {
            this.selectModel(modelId);
            bootstrap.Modal.getInstance(document.getElementById('aiModelDetailsModal')).hide();
        };

        // Show details modal
        const detailsModal = new bootstrap.Modal(document.getElementById('aiModelDetailsModal'));
        detailsModal.show();
    }

    selectModel(modelId) {
        this.selectedModel = modelId;
        const model = this.models.find(m => m.id === modelId);
        
        if (model && this.onSelectCallback) {
            this.onSelectCallback(model);
        }
        
        // Update button text
        if (this.triggerButton) {
            this.triggerButton.textContent = model ? model.modelName : this.triggerButton.dataset.originalText;
        }
        
        // Close modal
        const modal = bootstrap.Modal.getInstance(document.getElementById('aiModelSelectorModal'));
        if (modal) {
            modal.hide();
        }
        
        // Scroll selected model into view
        this.scrollToSelectedModel();
    }

    scrollToSelectedModel() {
        if (!this.selectedModel) return;
        
        const selectedRow = document.querySelector(`tr.model-row[data-model-id="${this.selectedModel}"]`);
        if (selectedRow) {
            // Get the table container
            const tableContainer = document.querySelector('#aiModelSelectorModal .modal-body');
            if (tableContainer) {
                // Calculate scroll position to center the row
                const containerRect = tableContainer.getBoundingClientRect();
                const rowRect = selectedRow.getBoundingClientRect();
                const scrollTop = tableContainer.scrollTop + (rowRect.top - containerRect.top) - (containerRect.height / 2) + (rowRect.height / 2);
                
                // Smooth scroll to position
                tableContainer.scrollTo({
                    top: scrollTop,
                    behavior: 'smooth'
                });
            }
        }
    }

    resetFilters() {
        document.getElementById('aiModelSearchInput').value = '';
        document.querySelector('input[name="providerFilter"][value="all"]').checked = true;
    }

    formatNumber(num) {
        if (!num) return '0';
        return new Intl.NumberFormat('en-US').format(num);
    }

    escapeHtml(text) {
        const map = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        };
        return text.replace(/[&<>"']/g, m => map[m]);
    }
}

// Initialize global instance
if (typeof window !== 'undefined') {
    window.aiModelSelector = new AiModelSelectorManager();
}
