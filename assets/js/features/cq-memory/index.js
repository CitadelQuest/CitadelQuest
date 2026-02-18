import { MemoryGraphView } from './MemoryGraphView';
import { MemoryExtractPanel } from './MemoryExtractPanel';
import { updatesService } from '../../services/UpdatesService';
import MarkdownIt from 'markdown-it';
import * as bootstrap from 'bootstrap';

/**
 * CQ Memory Explorer - Main entry point
 * Orchestrates the graph visualization and UI interactions
 */
class CQMemoryExplorer {
    constructor(spiritId) {
        this.spiritId = spiritId;
        this.graphView = null;
        this.graphData = null;
        this.extractPanel = null;
        this.selectedNode = null;
        this.manualPollingActive = false;
        this.manualPollingTimeout = null;
        
        this.md = new MarkdownIt({ html: false, breaks: true, linkify: true });
        
        // Pack management state
        this.currentPackPath = null;
        this.currentPackMetadata = null;
        this.availablePacks = [];
        this.availableLibraries = [];
        this.memoryPath = null;
        
        // Library selector state
        this.selectedLibrary = null; // { path, name } of selected library
        this.libraryPacks = []; // Packs belonging to selected library
        
        // Search state
        this.searchDebounceTimer = null;
        this.searchAbortController = null;
        this.lastSearchQuery = '';
        this.searchHighlightedNodeIds = new Set();
        this.lastSearchResults = [];
        
        // Filter state
        this.hoverDebounceTimer = null;
        this.filters = {
            categories: new Set(['conversation', 'knowledge', 'preference', 'thought', 'fact', 'internet']),
            relationships: new Set(['PART_OF', 'RELATES_TO', 'CONTRADICTS', 'REINFORCES'])
        };

        this.loadFilterState();
        this.init();
    }

    async init() {
        // Initialize graph view
        const container = document.getElementById('memory-graph-container');
        if (!container) {
            console.error('Graph container not found');
            return;
        }

        this.graphView = new MemoryGraphView(container);
        
        // Set callbacks
        this.graphView.setOnNodeSelect((node) => { this.selectedNode = node; this.showNodeDetails(node); });
        this.graphView.setOnNodeHover((node) => this.onNodeHover(node));
        this.graphView.setOnNodeDeselect(() => { this.selectedNode = null; this.hideNodeDetails(); });

        // Initialize extract panel
        this.extractPanel = new MemoryExtractPanel(this.spiritId);
        
        // Set callbacks for extraction
        this.extractPanel.onExtractionStart = () => {
            // No manual polling — processStepsSequentially() handles all steps.
            // Manual polling would race with step processing, causing missing nodes in 3D.
        };
        this.extractPanel.onExtractionComplete = () => { this.loadGraphData(); };
        this.extractPanel.onGraphDelta = (delta) => this.applyGraphDelta(delta);

        // Register listener with global updates service (polling already started in app)
        if (this.spiritId) {
            this.setupPolling();
        }

        // Setup UI event listeners
        this.setupEventListeners();

        // Setup library and pack selectors
        this.setupLibrarySelector();
        this.setupPackSelector();

        // Initialize memory structure, load libraries, then load packs
        await this.loadLibraries();
        await this.loadPacks();

        // Load graph data (from selected pack, skip if no context)
        if (this.currentPackPath) {
            await this.loadGraphData();
        } else {
            // No Spirit context and no pack selected - show empty state
            const loadingEl = document.getElementById('memory-loading');
            if (loadingEl) {
                loadingEl.innerHTML = `
                    <div class="text-secondary">
                        <i class="mdi mdi-package-variant-plus" style="font-size: 3rem;"></i>
                        <p class="mt-2">Select or create a memory pack to begin</p>
                    </div>
                `;
            }
        }
    }

    setupPolling() {
        // Register listener for memory job updates with global singleton
        // Polling is already started globally in cq-chat-modal.js entry point
        updatesService.addListener('memoryExplorer', async (updates) => {
            if (updates.memoryJobs?.active?.length > 0) {
                // Filter jobs for this spirit
                const spiritJobs = updates.memoryJobs.active.filter(job => job.spiritId === this.spiritId);
                
                if (spiritJobs.length > 0) {
                    // Switch to manual polling to prevent request stacking
                    // (handles Spirit-initiated jobs detected on page load / global poll)
                    if (!this.manualPollingActive) {
                        updatesService.pause('memoryExplorer');
                        this.startManualPolling();
                    }
                    
                    // Update extract panel progress
                    this.extractPanel.updateJobProgress(spiritJobs);
                    
                    // Apply graph delta if included in updates
                    if (updates.memoryJobs.graphDeltas && updates.memoryJobs.graphDeltas[this.spiritId]) {
                        this.applyGraphDelta(updates.memoryJobs.graphDeltas[this.spiritId]);
                    }
                }
            }

            // Handle completed jobs
            if (updates.memoryJobs?.completed?.length > 0) {
                updates.memoryJobs.completed.forEach(job => {
                    if (job.spiritId === this.spiritId) {
                        this.extractPanel.removeCompletedJob(job.id);
                        
                        // Show notification
                        if (job.status === 'completed') {
                            window.toast?.success('Memory extraction completed!');
                        } else if (job.status === 'failed') {
                            window.toast?.error('Memory extraction failed: ' + (job.error || 'Unknown error'));
                        }
                        
                        // Reload stats
                        this.updateStats();
                        
                        // Check if all jobs for this spirit are done
                        this.checkAndResumePolling(updates.memoryJobs.active);
                    }
                });
            }
            
            // Also check on active job updates in case last job completes without separate completed event
            if (this.manualPollingActive && updates.memoryJobs?.active) {
                this.checkAndResumePolling(updates.memoryJobs.active);
            }
        });
    }

    /**
     * Start manual polling loop during memory extraction
     * Prevents request stacking by controlling fetch timing
     */
    startManualPolling() {
        if (this.manualPollingActive) return;
        
        this.manualPollingActive = true;
        this.pollManually();
    }
    
    /**
     * Manual polling function - fetches updates and schedules next poll after response
     */
    async pollManually() {
        if (!this.manualPollingActive) return;
        
        try {
            // Fetch updates manually
            const updates = await updatesService.getUpdates();
            
            // Process updates through normal listener mechanism
            updatesService.notifyListeners(updates);
            
            // Schedule next poll after 2 seconds (faster during extraction for better UX)
            this.manualPollingTimeout = setTimeout(() => this.pollManually(), 2000);
            
        } catch (error) {
            console.error('Manual polling error:', error);
            // Retry after 3 seconds on error
            this.manualPollingTimeout = setTimeout(() => this.pollManually(), 3000);
        }
    }
    
    /**
     * Check if all jobs for this spirit are complete and resume global polling
     */
    checkAndResumePolling(activeJobs) {
        const spiritHasActiveJobs = activeJobs?.some(job => job.spiritId === this.spiritId);
        
        if (!spiritHasActiveJobs && this.manualPollingActive) {
            // All jobs done - stop manual polling and resume global
            this.manualPollingActive = false;
            
            if (this.manualPollingTimeout) {
                clearTimeout(this.manualPollingTimeout);
                this.manualPollingTimeout = null;
            }
            
            console.log('✅ Memory extraction complete - resuming global polling');
            updatesService.resume('memoryExplorer');
        }
    }

    /**
     * Apply graph delta updates (nodes and edges) to the 3D visualization
     * Delta is now included in /api/updates response, no separate fetch needed
     */
    applyGraphDelta(delta) {
        try {
            // Add new nodes with animations (no camera movement for better UX)
            if (delta.nodes && delta.nodes.length > 0) {
                delta.nodes.forEach(node => this.graphView.addNode(node));
            }

            // Add new edges
            if (delta.edges && delta.edges.length > 0) {
                delta.edges.forEach(edge => {
                    this.graphView.addEdge(edge);
                });
            }

        } catch (error) {
            console.error('Error applying graph delta:', error);
        }
    }

    setupEventListeners() {
        // Reset view button (default 3D perspective)
        const resetBtn = document.getElementById('btn-reset-view');
        if (resetBtn) {
            resetBtn.addEventListener('click', () => {
                this.graphView.resetView();
            });
        }

        // Top view button (bird's eye view)
        const topViewBtn = document.getElementById('btn-top-view');
        if (topViewBtn) {
            topViewBtn.addEventListener('click', () => {
                this.graphView.setTopView();
            });
        }

        // Category filter toggles
        document.querySelectorAll('.filter-category').forEach(checkbox => {
            checkbox.addEventListener('change', (e) => {
                const category = e.target.dataset.category;
                if (e.target.checked) {
                    this.filters.categories.add(category);
                } else {
                    this.filters.categories.delete(category);
                }
                this.saveFilterState();
                this.applyFilters();
            });
        });

        // Relationship filter toggles
        document.querySelectorAll('.filter-relationship').forEach(checkbox => {
            checkbox.addEventListener('change', (e) => {
                const relationship = e.target.dataset.relationship;
                if (e.target.checked) {
                    this.filters.relationships.add(relationship);
                } else {
                    this.filters.relationships.delete(relationship);
                }
                this.saveFilterState();
                this.applyFilters();
            });
        });

        // Collapsible section toggles - save state
        document.querySelectorAll('.filter-section-header').forEach(header => {
            header.addEventListener('click', () => {
                setTimeout(() => this.saveCollapsibleState(), 100);
            });
        });

        // Load collapsible state
        this.loadCollapsibleState();

        // Search tab
        this.setupSearchListeners();

        // Delete key listener for selected node (skip when typing in inputs)
        document.addEventListener('keydown', (e) => {
            const tag = document.activeElement?.tagName;
            if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') return;
            if (e.key === 'Delete' && this.selectedNode && !this.isModalOpen()) {
                e.preventDefault();
                this.showDeleteNodeModal();
            }
        });

        // Confirm delete node button
        const confirmDeleteNodeBtn = document.getElementById('btn-confirm-delete-node');
        if (confirmDeleteNodeBtn) {
            confirmDeleteNodeBtn.addEventListener('click', () => this.deleteSelectedNode());
        }
    }

    async loadGraphData() {
        const loadingEl = document.getElementById('memory-loading');
        
        // Show loading state
        if (loadingEl) {
            loadingEl.classList.remove('d-none');
            loadingEl.innerHTML = `
                <div class="spinner-border text-cyber" role="status">
                    <span class="visually-hidden">Loading...</span>
                </div>
                <p class="mt-2 text-secondary">Loading memory graph...</p>
            `;
        }
        
        try {
            let response;
            
            // Check if loading from a pack
            if (this.currentPackPath) {
                // Check if it's a library or pack
                if (this.currentPackPath.startsWith('lib:')) {
                    // Load from library
                    const libraryPath = this.currentPackPath.substring(4);
                    response = await fetch('/api/memory/library/graph', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ libraryPath })
                    });
                } else {
                    // Load from pack file - parse JSON encoded path+name
                    const packData = JSON.parse(this.currentPackPath);
                    response = await fetch('/api/memory/pack/open', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ 
                            projectId: this.projectId,
                            path: packData.path,
                            name: packData.name
                        })
                    });
                }
            } else {
                // No pack selected and no Spirit context - nothing to load
                if (loadingEl) loadingEl.classList.add('d-none');
                return;
            }
            
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            const data = await response.json();
            
            // Normalize response format
            this.graphData = {
                nodes: data.nodes || [],
                edges: data.edges || [],
                stats: data.stats || {}
            };
            this.currentPackMetadata = data.metadata || {};

            // Update stats
            this.updateStats();

            // Load into graph view
            this.graphView.loadGraph(this.graphData);

            // Apply saved filters to 3D scene
            this.graphView.setFilters(this.filters);

            // Hide loading
            if (loadingEl) {
                loadingEl.classList.add('d-none');
            }

        } catch (error) {
            console.error('Failed to load graph data:', error);
            if (loadingEl) {
                loadingEl.innerHTML = `
                    <div class="text-danger">
                        <i class="mdi mdi-alert-circle" style="font-size: 3rem;"></i>
                        <p class="mt-2">Failed to load memory graph</p>
                        <small>${error.message}</small>
                    </div>
                `;
            }
        }
    }

    updateStats() {
        const nodesEl = document.getElementById('stats-nodes');
        const edgesEl = document.getElementById('stats-edges');

        if (nodesEl && this.graphData) {
            nodesEl.textContent = this.graphData.nodes?.length || 0;
        }
        if (edgesEl && this.graphData) {
            edgesEl.textContent = this.graphData.edges?.length || 0;
        }
        
        // Update pack selector node count for current pack
        this.updatePackSelectorNodeCount();
    }
    
    updatePackSelectorNodeCount() {
        const packSelector = document.getElementById('pack-selector');
        if (!packSelector || !this.currentPackPath) {
            return;
        }
        
        const trans = window.memoryExplorerTranslations?.pack_selector || {};
        const nodeCount = this.graphData?.nodes?.length || 0;
        
        try {
            const packData = JSON.parse(this.currentPackPath);
            
            // Find the pack in availablePacks and update its totalNodes
            const pack = this.availablePacks?.find(p => p.path === packData.path && p.name === packData.name);
            if (pack) {
                pack.totalNodes = nodeCount;
            }
            
            // Update the selected option text directly
            const selectedOption = packSelector.options[packSelector.selectedIndex];
            if (selectedOption && selectedOption.value === this.currentPackPath) {
                const displayName = pack?.displayName || packData.name.replace('.cqmpack', '');
                selectedOption.textContent = `${displayName} (${nodeCount} ${trans.nodes})`;
            }
        } catch (e) {
            console.error('Failed to update pack selector node count:', e);
        }
    }

    onNodeHover(node) {
        // Clear any pending debounce timer
        if (this.hoverDebounceTimer) {
            clearTimeout(this.hoverDebounceTimer);
            this.hoverDebounceTimer = null;
        }

        // If no node hovered, remove highlight immediately
        if (!node) {
            document.querySelectorAll('.compilation-block.hover-highlight').forEach(block => {
                block.classList.remove('hover-highlight');
            });
            return;
        }

        // Debounce the highlight/scroll to avoid rapid switching when moving mouse across nodes
        this.hoverDebounceTimer = setTimeout(() => {
            // Remove highlight from all compilation blocks
            document.querySelectorAll('.compilation-block.hover-highlight').forEach(block => {
                block.classList.remove('hover-highlight');
            });

            // Highlight corresponding text block in left panel
            const block = document.querySelector(`.compilation-block[data-node-id="${node.id}"]`);
            if (block) {
                block.classList.add('hover-highlight');
                // Scroll block into view if not visible
                block.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            }
        }, 200);
    }

    /**
     * Hide node details when deselected
     */
    hideNodeDetails() {
        const detailsEl = document.getElementById('node-details');
        const placeholderEl = document.getElementById('node-placeholder');
        const compilationPlaceholderEl = document.getElementById('compilation-placeholder');
        const compilationContentEl = document.getElementById('compilation-content');

        if (detailsEl) {
            detailsEl.classList.add('d-none');
        }
        if (placeholderEl) {
            placeholderEl.classList.remove('d-none');
        }
        if (compilationPlaceholderEl) {
            compilationPlaceholderEl.classList.remove('d-none');
        }
        if (compilationContentEl) {
            compilationContentEl.classList.add('d-none');
        }
    }

    showNodeDetails(node) {
        if (!node) return;

        const detailsEl = document.getElementById('node-details');
        const placeholderEl = document.getElementById('node-placeholder');

        if (detailsEl) {
            detailsEl.classList.remove('d-none');
        }
        if (placeholderEl) {
            placeholderEl.classList.add('d-none');
        }

        // Category
        const categoryEl = document.getElementById('detail-category');
        if (categoryEl) {
            categoryEl.textContent = node.category;
            categoryEl.className = 'badge';
            categoryEl.style.backgroundColor = this.getCategoryColor(node.category);
        }

        // Importance
        const importanceBar = document.getElementById('detail-importance-bar');
        const importanceValue = document.getElementById('detail-importance-value');
        const importance = Math.round((node.importance || 0.5) * 100);
        if (importanceBar) {
            importanceBar.style.width = `${importance}%`;
        }
        if (importanceValue) {
            importanceValue.textContent = `${importance}%`;
        }

        // Summary
        const summaryEl = document.getElementById('detail-summary');
        if (summaryEl) {
            summaryEl.textContent = node.summary || '(no summary)';
        }

        // Tags
        const tagsEl = document.getElementById('detail-tags');
        if (tagsEl) {
            if (node.tags && node.tags.length > 0) {
                tagsEl.innerHTML = node.tags
                    .map(tag => `<span class="badge bg-secondary me-1 mb-1">${tag}</span>`)
                    .join('');
            } else {
                tagsEl.innerHTML = '<span class="text-secondary">(no tags)</span>';
            }
        }

        // Created at
        const createdEl = document.getElementById('detail-created');
        if (createdEl) {
            createdEl.textContent = node.createdAt || 'Unknown';
        }

        // Access count
        const accessEl = document.getElementById('detail-access');
        if (accessEl) {
            accessEl.textContent = node.accessCount || 0;
        }

        // Update compilation panel
        this.updateCompilation(node);
    }

    getCategoryColor(category) {
        const colors = {
            conversation: '#4a9eff',
            knowledge: '#4aff9e',
            preference: '#9e4aff',
            thought: '#ff9e4a',
            fact: '#4affff',
            internet: '#ff4a9e'
        };
        return colors[category] || '#95ec86';
    }

    getRelationshipColor(type) {
        const colors = {
            PART_OF: '#ffffff',
            RELATES_TO: '#0088ff',
            CONTRADICTS: '#ff0000',
            REINFORCES: '#00ff00'
        };
        return colors[type] || '#666666';
    }

    /**
     * Update the Content Compilation panel with selected node and related memories
     */
    updateCompilation(node) {
        const placeholderEl = document.getElementById('compilation-placeholder');
        const contentEl = document.getElementById('compilation-content');
        const selectedContentEl = document.getElementById('compilation-selected-content');
        const relatedEl = document.getElementById('compilation-related');

        if (!node) {
            if (placeholderEl) placeholderEl.classList.remove('d-none');
            if (contentEl) contentEl.classList.add('d-none');
            return;
        }

        // Show content, hide placeholder
        if (placeholderEl) placeholderEl.classList.add('d-none');
        if (contentEl) contentEl.classList.remove('d-none');

        // Selected memory content (with markdown rendering)
        if (selectedContentEl) {
            const content = node.content || '(no content)';
            const charCount = content.length;
            const sourceRef = node.sourceRef;
            const sourceRange = node.sourceRange;
            const memoryTitle = node.summary || '(no summary)';
            
            const sourceType = node.sourceType;
            const showSourceBtn = sourceRef ? `
                <button class="btn btn-sm btn-outline-secondary show-source-btn py-0 px-1 ms-2" 
                        data-source-ref="${this.escapeHtml(sourceRef)}" 
                        data-source-range="${sourceRange || ''}"
                        data-source-type="${sourceType || ''}"
                        data-memory-title="${this.escapeHtml(memoryTitle)}"
                        title="Show source">
                    <i class="mdi mdi-file-find-outline"></i>
                </button>
            ` : '';
            
            selectedContentEl.setAttribute('data-node-id', node.id);
            selectedContentEl.classList.add('compilation-block', 'expanded');
            selectedContentEl.innerHTML = `
                <div class="fw-bold mb-1 d-flex align-items-center" style="color: ${this.getCategoryColor(node.category)};">
                    <span class="flex-grow-1">${node.summary || '(no summary)'}</span>
                    ${showSourceBtn}
                </div>
                <div class="compilation-content small">${this.md.render(content)}</div>
                <div class="char-count text-secondary text-end mt-1 me-1">${charCount.toLocaleString()} chars</div>
            `;
        }

        // Find related memories and their relationships
        if (relatedEl && this.graphData) {
            const { html, totalChars, contextChars } = this.getRelatedMemoriesHtml(node.id);
            relatedEl.innerHTML = html;
            
            // Add hover listeners for compilation blocks
            this.setupCompilationBlockHovers();

            // Update total compiled content count in header
            const mainChars = (node.content || '').length;
            const totalCompiled = mainChars + totalChars + contextChars;
            this.updateCompiledCount(totalCompiled);
        }
    }

    /**
     * Update the total compiled content character count in header
     */
    updateCompiledCount(count) {
        let countEl = document.getElementById('compilation-total-count');
        if (!countEl) {
            // Create the count element in the header
            const header = document.querySelector('.compilation-panel h5');
            if (header) {
                countEl = document.createElement('span');
                countEl.id = 'compilation-total-count';
                countEl.className = 'badge bg-dark bg-opacity-25 text-cyber ms-2 float-end';
                header.appendChild(countEl);
            }
        }
        if (countEl) {
            countEl.innerHTML = `<i class="mdi mdi-sigma"></i> ${count.toLocaleString()} chars`;
        }
    }

    /**
     * Setup hover and click events for compilation blocks
     */
    setupCompilationBlockHovers() {
        const blocks = document.querySelectorAll('.compilation-block[data-node-id]');
        blocks.forEach(block => {
            const nodeId = block.dataset.nodeId;
            const fullContent = block.dataset.fullContent;
            const contentEl = block.querySelector('.compilation-content');
            const expandIndicator = block.querySelector('.expand-indicator');
            
            // Hover effect - glow node in 3D (only for related blocks, not main selected)
            if (block.id !== 'compilation-selected-content') {
                block.addEventListener('mouseenter', () => {
                    if (this.graphView) {
                        this.graphView.glowNodeById(nodeId, true);
                    }
                });
                
                block.addEventListener('mouseleave', () => {
                    if (this.graphView) {
                        this.graphView.glowNodeById(nodeId, false);
                    }
                });
            }
            
            // Click to toggle expand/collapse
            if (fullContent && contentEl) {
                block.addEventListener('click', (e) => {
                    // Don't expand if clicking on the show source button
                    if (e.target.closest('.show-source-btn')) return;
                    
                    e.stopPropagation();
                    const isExpanded = block.classList.toggle('expanded');
                    
                    if (isExpanded) {
                        contentEl.innerHTML = this.md.render(fullContent);
                        if (expandIndicator) {
                            expandIndicator.innerHTML = '<i class="mdi mdi-chevron-up"></i>';
                        }
                    } else {
                        contentEl.textContent = this.truncateText(fullContent, 150);
                        if (expandIndicator) {
                            expandIndicator.innerHTML = '<i class="mdi mdi-chevron-down"></i>';
                        }
                    }
                });
            }
        });

        // Setup show source button handlers
        this.setupShowSourceButtons();
    }

    /**
     * Setup click handlers for Show Source buttons
     */
    setupShowSourceButtons() {
        const buttons = document.querySelectorAll('.show-source-btn');
        buttons.forEach(btn => {
            // Remove existing listener to avoid duplicates
            btn.replaceWith(btn.cloneNode(true));
        });
        // Re-query after cloning
        document.querySelectorAll('.show-source-btn').forEach(btn => {
            btn.addEventListener('click', async (e) => {
                e.stopPropagation();
                e.preventDefault();
                const sourceRef = btn.dataset.sourceRef;
                const sourceRange = btn.dataset.sourceRange;
                const sourceType = btn.dataset.sourceType;
                const memoryTitle = btn.dataset.memoryTitle;
                await this.showSourceContent(sourceRef, sourceRange, memoryTitle, sourceType);
            });
        });
        
        // Setup copy button
        this.setupCopySourceButton();
    }
    
    /**
     * Setup copy button for source viewer modal
     */
    setupCopySourceButton() {
        const copyBtn = document.getElementById('copySourceContentBtn');
        if (!copyBtn) return;
        
        // Remove existing listener
        const newBtn = copyBtn.cloneNode(true);
        copyBtn.replaceWith(newBtn);
        
        newBtn.addEventListener('click', () => {
            const contentEl = document.getElementById('source-viewer-content');
            if (!contentEl) return;
            
            const content = contentEl.textContent;
            navigator.clipboard.writeText(content).then(() => {
                const originalHtml = newBtn.innerHTML;
                newBtn.innerHTML = '<i class="mdi mdi-check me-1"></i>Copied!';
                newBtn.classList.remove('btn-outline-cyber');
                newBtn.classList.add('btn-success');
                
                setTimeout(() => {
                    newBtn.innerHTML = originalHtml;
                    newBtn.classList.remove('btn-success');
                    newBtn.classList.add('btn-outline-cyber');
                }, 2000);
            }).catch(err => {
                console.error('Failed to copy:', err);
                if (window.toast) {
                    window.toast.error('Failed to copy content');
                }
            });
        });
    }

    /**
     * Fetch and display source content in modal
     */
    async showSourceContent(sourceRef, sourceRange, memoryTitle, sourceType) {
        const modal = document.getElementById('sourceViewerModal');
        const modalTitle = document.getElementById('sourceViewerModalLabel');
        const fileEl = document.getElementById('source-viewer-file');
        const rangeEl = document.getElementById('source-viewer-range');
        const contentEl = document.getElementById('source-viewer-content');

        if (!modal || !contentEl) return;

        // Set modal title to memory title
        if (modalTitle && memoryTitle) {
            modalTitle.innerHTML = `<i class="mdi mdi-file-document-outline text-cyber me-2"></i>${memoryTitle}`;
        }

        // Show loading state
        contentEl.textContent = 'Loading...';
        fileEl.textContent = '';
        rangeEl.textContent = sourceRange ? `(lines ${sourceRange.replace(':', '-')})` : '';

        // Show modal
        const bsModal = bootstrap.Modal.getOrCreateInstance(modal);
        bsModal.show();

        try {
            // Build request body with pack context for source lookup
            const requestBody = { 
                source_ref: sourceRef, 
                source_range: sourceRange,
                source_type: sourceType || null
            };

            // Add pack context if we have a current pack selected
            if (this.currentPackPath && !this.currentPackPath.startsWith('lib:')) {
                try {
                    const packData = JSON.parse(this.currentPackPath);
                    requestBody.pack_project_id = this.projectId;
                    requestBody.pack_path = packData.path;
                    requestBody.pack_name = packData.name;
                } catch (e) {
                    // Ignore parse errors
                }
            }

            const response = await fetch(`/memory/source`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(requestBody)
            });

            if (!response.ok) {
                const error = await response.json();
                throw new Error(error.error || 'Failed to load source');
            }

            const data = await response.json();
            contentEl.textContent = data.content || '(empty)';
            fileEl.textContent = data.filename || sourceRef;

        } catch (error) {
            console.error('Failed to load source:', error);
            contentEl.textContent = `Error: ${error.message}`;
        }
    }

    /**
     * Get HTML for related memories with relationship context
     * Returns { html, totalChars, contextChars }
     */
    getRelatedMemoriesHtml(nodeId) {
        if (!this.graphData?.edges || !this.graphData?.nodes) {
            return { html: '', totalChars: 0, contextChars: 0 };
        }

        const nodeMap = new Map(this.graphData.nodes.map(n => [n.id, n]));
        const relatedItems = [];

        // Find all edges connected to this node
        this.graphData.edges.forEach(edge => {
            // Filter by relationship type
            if (!this.filters.relationships.has(edge.type)) return;

            let relatedNodeId = null;
            let direction = '';

            if (edge.source === nodeId) {
                relatedNodeId = edge.target;
                direction = '→';
            } else if (edge.target === nodeId) {
                relatedNodeId = edge.source;
                direction = '←';
            }

            if (relatedNodeId) {
                const relatedNode = nodeMap.get(relatedNodeId);
                // Filter by category
                if (relatedNode && this.filters.categories.has(relatedNode.category)) {
                    relatedItems.push({
                        node: relatedNode,
                        type: edge.type,
                        context: edge.context || '',
                        direction
                    });
                }
            }
        });

        if (relatedItems.length === 0) {
            return { 
                html: '<div class="text-secondary small text-center py-3">No related memories (check filters)</div>',
                totalChars: 0,
                contextChars: 0
            };
        }

        // Group related items by node ID to avoid duplicates
        const groupedItems = new Map();
        relatedItems.forEach(item => {
            const nodeId = item.node.id;
            if (groupedItems.has(nodeId)) {
                // Add relationship to existing group
                groupedItems.get(nodeId).relationships.push({
                    type: item.type,
                    context: item.context,
                    direction: item.direction
                });
            } else {
                // Create new group
                groupedItems.set(nodeId, {
                    node: item.node,
                    relationships: [{
                        type: item.type,
                        context: item.context,
                        direction: item.direction
                    }]
                });
            }
        });

        let totalChars = 0;
        let contextChars = 0;

        const html = Array.from(groupedItems.values()).map(group => {
            const fullContent = group.node.content || '';
            const charCount = fullContent.length;
            const needsTruncate = charCount > 150;

            // Count chars only once per unique node
            totalChars += charCount;
            // Count context chars for all relationships
            group.relationships.forEach(rel => {
                contextChars += rel.context.length;
            });

            // Build relationship labels HTML
            const relationshipsHtml = group.relationships.map(rel => {
                const color = this.getRelationshipColor(rel.type);
                const borderColor = this.hexToRgba(color, 0.6);
                const relTypeClass = rel.type.toLowerCase().replace(/_/g, '-');
                return `
                    <div class="small text-secondary mb-1">
                        <span class="relationship-type ${relTypeClass}">―</span>
                        ${this.translateRelationType(rel.type)} <i class="mdi mdi-arrow-${rel.direction === '→' ? 'right' : 'left'}-bold" style="opacity: 0.8;"></i>
                    </div>
                    ${rel.context ? `
                        <div class="small relationship-context mb-1 px-2" style="color: ${borderColor};">
                            "${rel.context}"
                        </div>
                    ` : ''}
                `;
            }).join('');

            // Show source button if source_ref exists
            const sourceRef = group.node.sourceRef;
            const sourceRange = group.node.sourceRange;
            const sourceType = group.node.sourceType;
            const memoryTitle = group.node.summary || '(no summary)';
            const showSourceBtn = sourceRef ? `
                <button class="btn btn-sm btn-outline-secondary show-source-btn py-0 px-1 ms-2" 
                        data-source-ref="${this.escapeHtml(sourceRef)}" 
                        data-source-range="${sourceRange || ''}"
                        data-source-type="${sourceType || ''}"
                        data-memory-title="${this.escapeHtml(memoryTitle)}"
                        title="Show source">
                    <i class="mdi mdi-file-find-outline"></i>
                </button>
            ` : '';

            return `
                <div class="mb-3">
                    ${relationshipsHtml}
                    <div class="compilation-block small p-2 rounded bg-secondary bg-opacity-25" data-node-id="${group.node.id}" data-full-content="${this.escapeHtml(fullContent)}">
                        <div class="fw-bold mb-1 d-flex align-items-center" style="color: ${this.getCategoryColor(group.node.category)};">
                            <span class="flex-grow-1">${group.node.summary || '(no summary)'}</span>
                            ${showSourceBtn}
                        </div>
                        <div class="compilation-content small">${needsTruncate ? this.truncateText(fullContent, 150) : this.md.render(fullContent || '(no content)')}</div>
                        ${needsTruncate ? '<div class="expand-indicator text-cyber mt-1 d-inline-block float-start"><i class="mdi mdi-chevron-down"></i></div>' : ''}
                        <div class="char-count text-secondary d-inline-block float-end mt-1 me-1">${charCount.toLocaleString()} chars</div>
                        <div style="clear: both;"></div>
                    </div>
                </div>
            `;
        }).join('');

        return { html, totalChars, contextChars };
    }

    /**
     * Convert hex color to rgba
     */
    hexToRgba(hex, alpha) {
        const r = parseInt(hex.slice(1, 3), 16);
        const g = parseInt(hex.slice(3, 5), 16);
        const b = parseInt(hex.slice(5, 7), 16);
        return `rgba(${r}, ${g}, ${b}, ${alpha})`;
    }

    /**
     * Truncate text to max length
     */
    truncateText(text, maxLength) {
        if (!text) return '(no content)';
        if (text.length <= maxLength) return text;
        return text.substring(0, maxLength) + '...';
    }

    /**
     * Escape HTML for safe attribute values
     */
    escapeHtml(text) {
        if (!text) return '';
        return text
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    /**
     * Translate relationship type
     */
    translateRelationType(type) {
        const translations = window.memoryExplorerTranslations?.relationship_types || {};
        return translations[type] || type;
    }

    /**
     * Load filter state from localStorage
     */
    loadFilterState() {
        const stored = localStorage.getItem('cqMemoryFilters');
        if (stored) {
            try {
                const state = JSON.parse(stored);
                this.filters.categories = new Set(state.categories || []);
                this.filters.relationships = new Set(state.relationships || []);
            } catch (e) {
                console.warn('Failed to parse filter state:', e);
            }
        }
        
        // Sync checkbox state with loaded filters
        this.syncCheckboxState();
    }

    /**
     * Save filter state to localStorage
     */
    saveFilterState() {
        const state = {
            categories: Array.from(this.filters.categories),
            relationships: Array.from(this.filters.relationships)
        };
        localStorage.setItem('cqMemoryFilters', JSON.stringify(state));
    }

    /**
     * Sync checkbox UI with filter state
     */
    syncCheckboxState() {
        document.querySelectorAll('.filter-category').forEach(checkbox => {
            const category = checkbox.dataset.category;
            checkbox.checked = this.filters.categories.has(category);
        });

        document.querySelectorAll('.filter-relationship').forEach(checkbox => {
            const relationship = checkbox.dataset.relationship;
            checkbox.checked = this.filters.relationships.has(relationship);
        });
    }

    /**
     * Save collapsible section state to localStorage
     */
    saveCollapsibleState() {
        const state = {};
        document.querySelectorAll('.filter-section-header').forEach(header => {
            const targetId = header.getAttribute('data-bs-target');
            const target = document.querySelector(targetId);
            if (target) {
                state[targetId] = target.classList.contains('show');
            }
        });
        localStorage.setItem('cqMemoryCollapsible', JSON.stringify(state));
    }

    /**
     * Load collapsible section state from localStorage
     */
    loadCollapsibleState() {
        const stored = localStorage.getItem('cqMemoryCollapsible');
        if (stored) {
            try {
                const state = JSON.parse(stored);
                Object.entries(state).forEach(([targetId, isOpen]) => {
                    const target = document.querySelector(targetId);
                    const header = document.querySelector(`[data-bs-target="${targetId}"]`);
                    if (target && header) {
                        if (isOpen) {
                            target.classList.add('show');
                            header.setAttribute('aria-expanded', 'true');
                        } else {
                            target.classList.remove('show');
                            header.setAttribute('aria-expanded', 'false');
                        }
                    }
                });
            } catch (e) {
                console.warn('Failed to parse collapsible state:', e);
            }
        }
    }

    /**
     * Apply filters to 3D scene and related memories
     */
    applyFilters() {
        if (!this.graphView || !this.graphData) return;

        // Apply to 3D graph
        this.graphView.setFilters(this.filters);

        // Re-render related memories if a node is selected
        if (this.graphView.selectedNode) {
            // selectedNode is a mesh, userData contains the node data
            this.updateCompilation(this.graphView.selectedNode.userData);
        }
    }

    // ========================================
    // Memory Search (Phase 5)
    // ========================================

    setupSearchListeners() {
        const searchInput = document.getElementById('memory-search-input');
        const clearBtn = document.getElementById('memory-search-clear');
        const categorySelect = document.getElementById('memory-search-category');

        if (searchInput) {
            searchInput.addEventListener('input', () => {
                // Show/hide clear button
                if (clearBtn) {
                    clearBtn.classList.toggle('d-none', !searchInput.value);
                }
                // Debounced search
                if (this.searchDebounceTimer) clearTimeout(this.searchDebounceTimer);
                this.searchDebounceTimer = setTimeout(() => this.performSearch(), 300);
            });

            searchInput.addEventListener('keydown', (e) => {
                if (e.key === 'Escape') {
                    searchInput.value = '';
                    if (clearBtn) clearBtn.classList.add('d-none');
                    this.clearSearchResults();
                }
            });
        }

        if (clearBtn) {
            clearBtn.addEventListener('click', () => {
                if (searchInput) searchInput.value = '';
                clearBtn.classList.add('d-none');
                this.clearSearchResults();
            });
        }

        if (categorySelect) {
            categorySelect.addEventListener('change', () => {
                if (searchInput?.value?.trim()) {
                    this.performSearch();
                }
            });
        }

        // Hover on status text → highlight all search result nodes in 3D
        const statusEl = document.getElementById('memory-search-status');
        if (statusEl) {
            statusEl.style.cursor = 'default';
            statusEl.addEventListener('mouseenter', () => {
                if (this.lastSearchResults.length && this.graphView) {
                    this.lastSearchResults.forEach(r => this.graphView.glowNodeById(r.id, true));
                }
            });
            statusEl.addEventListener('mouseleave', () => {
                if (this.lastSearchResults.length && this.graphView) {
                    this.lastSearchResults.forEach(r => this.graphView.glowNodeById(r.id, false));
                }
            });
        }
    }

    async performSearch() {
        const searchInput = document.getElementById('memory-search-input');
        const query = searchInput?.value?.trim() || '';

        if (query.length < 2) {
            this.clearSearchResults();
            return;
        }

        // Abort previous request
        if (this.searchAbortController) {
            this.searchAbortController.abort();
        }
        this.searchAbortController = new AbortController();

        // Show loading
        const placeholder = document.getElementById('memory-search-placeholder');
        const loading = document.getElementById('memory-search-loading');
        const listEl = document.getElementById('memory-search-list');
        const statusEl = document.getElementById('memory-search-status');

        if (placeholder) placeholder.classList.add('d-none');
        if (loading) loading.classList.remove('d-none');
        if (listEl) listEl.innerHTML = '';

        try {
            if (!this.currentPackPath) {
                if (statusEl) statusEl.textContent = 'No pack selected';
                if (loading) loading.classList.add('d-none');
                return;
            }

            const packData = JSON.parse(this.currentPackPath);
            const category = document.getElementById('memory-search-category')?.value || null;

            const response = await fetch('/api/memory/pack/search', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    projectId: this.projectId,
                    path: packData.path,
                    name: packData.name,
                    query: query,
                    category: category || null,
                    limit: 500
                }),
                signal: this.searchAbortController.signal
            });

            if (!response.ok) {
                const errData = await response.json();
                throw new Error(errData.error || `HTTP ${response.status}`);
            }

            const data = await response.json();

            if (loading) loading.classList.add('d-none');

            this.lastSearchResults = data.results || [];
            this.renderSearchResults(this.lastSearchResults, query);
            this.highlightSearchResultsIn3D(this.lastSearchResults);

            if (statusEl) {
                const engine = data.hasFTS5 ? 'FTS5' : 'LIKE';
                statusEl.textContent = `${data.count} result${data.count !== 1 ? 's' : ''} (${engine})`;
            }

            this.lastSearchQuery = query;

        } catch (error) {
            if (error.name === 'AbortError') return; // Cancelled by newer search
            console.error('Search failed:', error);
            if (loading) loading.classList.add('d-none');
            if (statusEl) statusEl.textContent = 'Search failed';
            if (listEl) {
                listEl.innerHTML = `<div class="text-danger small p-2"><i class="mdi mdi-alert-circle me-1"></i>${this.escapeHtml(error.message)}</div>`;
            }
        }
    }

    renderSearchResults(results, query) {
        const listEl = document.getElementById('memory-search-list');
        const placeholder = document.getElementById('memory-search-placeholder');
        if (!listEl) return;

        if (results.length === 0) {
            listEl.innerHTML = `
                <div class="text-center text-secondary py-4">
                    <i class="mdi mdi-magnify-close" style="font-size: 2rem; opacity: 0.4;"></i>
                    <p class="mt-2 small">No memories found for "${this.escapeHtml(query)}"</p>
                </div>
            `;
            return;
        }

        if (placeholder) placeholder.classList.add('d-none');

        const html = results.map((result, index) => {
            const scorePercent = Math.round(result.score * 100);
            const importancePercent = Math.round(result.importance * 100);
            const summary = result.summary || '(no summary)';
            const content = result.content || '';
            const needsTruncate = content.length > 200;
            const displayContent = needsTruncate ? content.substring(0, 200) + '...' : content;
            const tagsHtml = result.tags?.length
                ? `<div class="search-result-tags mt-1">${result.tags.map(t => `<span class="badge bg-secondary bg-opacity-50 opacity-75">${this.escapeHtml(t)}</span>`).join('')}</div>`
                : '';

            return `
                <div class="search-result-item" data-node-id="${this.escapeHtml(result.id)}" data-result-index="${index}">
                    <div class="d-flex align-items-start gap-2">
                        <div class="search-score-badge" title="Relevance score">
                            ${scorePercent}%
                        </div>
                        <div class="flex-grow-1" style="min-width: 0;">
                            <div class="search-result-summary" style="color: ${this.getCategoryColor(result.category)};">
                                ${this.escapeHtml(summary)}
                            </div>
                            <div class="search-result-content small text-secondary">
                                ${this.highlightQuery(this.escapeHtml(displayContent), query)}
                            </div>
                            <div class="d-flex align-items-center gap-2 mt-1">
                                <span class="badge text-dark opacity-75" style="background-color: ${this.getCategoryColor(result.category)}; font-size: 0.65rem;">${result.category}</span>
                                <span class="small" title="Importance">
                                    <i class="mdi mdi-star" style="opacity: ${0.3 + result.importance * 0.7}"></i>${importancePercent}%
                                </span>
                            </div>
                            ${tagsHtml}
                        </div>
                    </div>
                </div>
            `;
        }).join('');

        listEl.innerHTML = html;

        // Attach click handlers
        listEl.querySelectorAll('.search-result-item').forEach(item => {
            const nodeId = item.dataset.nodeId;

            item.addEventListener('click', () => {
                this.onSearchResultClick(nodeId);
                // Highlight active
                listEl.querySelectorAll('.search-result-item').forEach(i => i.classList.remove('active'));
                item.classList.add('active');
            });

            item.addEventListener('mouseenter', () => {
                if (this.graphView) this.graphView.glowNodeById(nodeId, true);
            });

            item.addEventListener('mouseleave', () => {
                if (this.graphView) this.graphView.glowNodeById(nodeId, false);
            });
        });
    }

    highlightQuery(text, query) {
        if (!query || query.length < 2) return text;
        // Split query into words and highlight each
        const words = query.split(/\s+/).filter(w => w.length >= 2);
        let result = text;
        words.forEach(word => {
            const escaped = word.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
            const regex = new RegExp(`(${escaped})`, 'gi');
            result = result.replace(regex, '<mark>$1</mark>');
        });
        return result;
    }

    onSearchResultClick(nodeId) {
        // Focus the node in the 3D graph
        if (this.graphView) {
            const mesh = this.graphView.nodeMeshes.get(nodeId);
            if (mesh) {
                // Select the node (triggers showNodeDetails + compilation panel)
                this.graphView.selectNodeMesh(mesh);
                // Switch to compilation tab to show details
                const compilationTab = document.getElementById('tab-compilation');
                if (compilationTab) {
                    const tabInstance = bootstrap.Tab.getOrCreateInstance(compilationTab);
                    tabInstance.show();
                }
            }
        }
    }

    highlightSearchResultsIn3D(results) {
        // Clear previous search highlights
        this.clearSearchHighlights();

        if (!this.graphView || !results.length) return;

        // Glow all result nodes
        results.forEach(result => {
            this.searchHighlightedNodeIds.add(result.id);
            this.graphView.glowNodeById(result.id, true);
        });

        // Auto-clear highlights after 3 seconds
        setTimeout(() => this.clearSearchHighlights(), 3000);
    }

    clearSearchHighlights() {
        if (!this.graphView) return;
        this.searchHighlightedNodeIds.forEach(id => {
            this.graphView.glowNodeById(id, false);
        });
        this.searchHighlightedNodeIds.clear();
    }

    clearSearchResults() {
        const listEl = document.getElementById('memory-search-list');
        const placeholder = document.getElementById('memory-search-placeholder');
        const loading = document.getElementById('memory-search-loading');
        const statusEl = document.getElementById('memory-search-status');

        if (listEl) listEl.innerHTML = '';
        if (placeholder) placeholder.classList.remove('d-none');
        if (loading) loading.classList.add('d-none');
        if (statusEl) statusEl.textContent = '';

        this.clearSearchHighlights();
        this.lastSearchQuery = '';
    }

    // ========================================
    // Library & Pack Management
    // ========================================

    /**
     * Setup library selector event listeners
     */
    setupLibrarySelector() {
        const librarySelector = document.getElementById('library-selector');
        const createLibBtn = document.getElementById('btn-create-library');
        const confirmCreateLibBtn = document.getElementById('btn-confirm-create-library');
        const detailsLibBtn = document.getElementById('btn-library-details');
        const deleteLibBtn = document.getElementById('btn-delete-library');
        const confirmDeleteLibBtn = document.getElementById('btn-confirm-delete-library');

        if (librarySelector) {
            librarySelector.addEventListener('change', (e) => this.onLibrarySelected(e.target.value));
        }

        if (createLibBtn) {
            createLibBtn.addEventListener('click', (e) => { e.preventDefault(); this.showCreateLibraryModal(); });
        }

        if (confirmCreateLibBtn) {
            confirmCreateLibBtn.addEventListener('click', () => this.createLibrary());
        }

        if (detailsLibBtn) {
            detailsLibBtn.addEventListener('click', (e) => { e.preventDefault(); this.showLibraryDetails(); });
        }

        if (deleteLibBtn) {
            deleteLibBtn.addEventListener('click', (e) => { e.preventDefault(); this.showDeleteLibraryModal(); });
        }

        if (confirmDeleteLibBtn) {
            confirmDeleteLibBtn.addEventListener('click', () => this.deleteLibrary());
        }
    }

    /**
     * Setup pack selector event listeners
     */
    setupPackSelector() {
        const packSelector = document.getElementById('pack-selector');
        const createPackBtn = document.getElementById('btn-create-pack');
        const confirmCreateBtn = document.getElementById('btn-confirm-create-pack');
        const detailsPackBtn = document.getElementById('btn-pack-details');
        const addPackToLibBtn = document.getElementById('btn-add-pack-to-lib');
        const confirmAddPackToLibBtn = document.getElementById('btn-confirm-add-pack-to-lib');
        const removePackFromLibBtn = document.getElementById('btn-remove-pack-from-lib');
        const deletePackBtn = document.getElementById('btn-delete-pack');
        const confirmRemovePackFromLibBtn = document.getElementById('btn-confirm-remove-pack-from-lib');
        const confirmDeletePackBtn = document.getElementById('btn-confirm-delete-pack');

        if (packSelector) {
            packSelector.addEventListener('change', (e) => this.onPackSelected(e.target.value));
        }

        if (createPackBtn) {
            createPackBtn.addEventListener('click', (e) => { e.preventDefault(); this.showCreatePackModal(); });
        }

        if (confirmCreateBtn) {
            confirmCreateBtn.addEventListener('click', () => this.createPack());
        }

        if (detailsPackBtn) {
            detailsPackBtn.addEventListener('click', (e) => { e.preventDefault(); this.showPackDetails(); });
        }

        if (addPackToLibBtn) {
            addPackToLibBtn.addEventListener('click', (e) => { e.preventDefault(); this.showAddPackToLibModal(); });
        }

        if (confirmAddPackToLibBtn) {
            confirmAddPackToLibBtn.addEventListener('click', () => this.addPackToLibrary());
        }

        if (removePackFromLibBtn) {
            removePackFromLibBtn.addEventListener('click', (e) => { e.preventDefault(); this.showRemovePackFromLibModal(); });
        }

        if (deletePackBtn) {
            deletePackBtn.addEventListener('click', (e) => { e.preventDefault(); this.showDeletePackModal(); });
        }

        if (confirmRemovePackFromLibBtn) {
            confirmRemovePackFromLibBtn.addEventListener('click', () => this.removePackFromLibrary());
        }

        if (confirmDeletePackBtn) {
            confirmDeletePackBtn.addEventListener('click', () => this.deletePack());
        }
    }

    /**
     * Load available libraries for the project
     */
    async loadLibraries() {
        const librarySelector = document.getElementById('library-selector');
        if (!librarySelector) return;

        try {
            // Get memory path - Spirit-specific or project root
            if (this.spiritId) {
                const pathResponse = await fetch(`/spirit/${this.spiritId}/memory/init`);
                if (!pathResponse.ok) {
                    throw new Error(`HTTP ${pathResponse.status}`);
                }
                const pathData = await pathResponse.json();
                this.projectId = pathData.projectId || 'general';
                this.memoryPath = pathData.memoryPath;
                this.packsPath = pathData.packsPath;
                this.rootLibraryName = pathData.rootLibraryName;
                this.rootPackName = pathData.rootPackName;
                this.spiritNameSlug = pathData.spiritNameSlug;
            } else {
                // No Spirit context - organized under /memory/
                this.projectId = 'general';
                this.memoryPath = '/memory';
                this.libsPath = '/memory/libs';
                this.packsPath = '/memory/packs';
            }

            // List all libraries from project root (always recursive from /)
            const response = await fetch('/api/memory/pack/list', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ projectId: this.projectId, path: '/' })
            });
            
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            const data = await response.json();
            this.availableLibraries = data.libraries || [];
            this.availablePacks = data.packs || [];

            // When viewing a Spirit's memory, only show that Spirit's libraries
            if (this.spiritId && this.memoryPath) {
                this.availableLibraries = this.availableLibraries.filter(
                    lib => lib.path === this.memoryPath
                );
            }

            // Sort libraries alphabetically by display name
            this.availableLibraries.sort((a, b) => {
                const nameA = (a.displayName || a.name).toLowerCase();
                const nameB = (b.displayName || b.name).toLowerCase();
                return nameA.localeCompare(nameB);
            });

            // Build library selector options
            const trans = window.memoryExplorerTranslations?.library_selector || {};
            let html = this.spiritId ? '' : `<option value="">${trans.all_packs || 'All Packs'}</option>`;
            
            if (this.availableLibraries.length > 0) {
                this.availableLibraries.forEach(lib => {
                    const libValue = JSON.stringify({ path: lib.path, name: lib.name });
                    const label = `${lib.displayName || lib.name} (${lib.packCount} packs)`;
                    html += `<option value='${this.escapeHtml(libValue)}'>${this.escapeHtml(label)}</option>`;
                });
            }

            librarySelector.innerHTML = html;

            // Check for ?lib= URL param for pre-selection
            const libPathInput = document.getElementById('lib-path');
            const libPathParam = libPathInput?.value || null;
            
            if (libPathParam) {
                // Try to match the lib path param to an available library
                // Format: /spirit/{slug}/memory/{slug}.cqmlib or path:name
                const matchingLib = this.findLibraryByPath(libPathParam);
                if (matchingLib) {
                    const libValue = JSON.stringify({ path: matchingLib.path, name: matchingLib.name });
                    librarySelector.value = libValue;
                    this.selectedLibrary = matchingLib;
                    // Pre-load library packs so loadPacks() can filter
                    await this.loadLibraryPacks(matchingLib.path, matchingLib.name);
                }
            } else {
                // Restore from localStorage
                const storageKey = this.spiritId ? `cqMemoryLib_${this.spiritId}` : 'cqMemoryLib_global';
                const lastLib = localStorage.getItem(storageKey);
                if (lastLib) {
                    try {
                        const lastLibData = JSON.parse(lastLib);
                        const matchingLib = this.availableLibraries.find(
                            l => l.path === lastLibData.path && l.name === lastLibData.name
                        );
                        if (matchingLib) {
                            librarySelector.value = lastLib;
                            this.selectedLibrary = matchingLib;
                            // Pre-load library packs so loadPacks() can filter
                            await this.loadLibraryPacks(matchingLib.path, matchingLib.name);
                        }
                    } catch { /* ignore */ }
                }
            }

            // Spirit view: if no library was selected yet, auto-select the first (and usually only) library
            if (!this.selectedLibrary && this.spiritId && this.availableLibraries.length > 0) {
                const firstLib = this.availableLibraries[0];
                const libValue = JSON.stringify({ path: firstLib.path, name: firstLib.name });
                librarySelector.value = libValue;
                this.selectedLibrary = firstLib;
                await this.loadLibraryPacks(firstLib.path, firstLib.name);
            }

        } catch (error) {
            console.error('Failed to load libraries:', error);
        }
    }

    /**
     * Find a library by its file path (for ?lib= URL param matching)
     * Supports formats: "/spirit/slug/memory/slug.cqmlib" or "path:name"
     */
    findLibraryByPath(libPath) {
        // Try exact path:name match first
        if (libPath.includes(':')) {
            const [path, name] = libPath.split(':');
            return this.availableLibraries.find(l => l.path === path && l.name === name);
        }
        // Try matching as full file path (e.g. /spirit/slug/memory/slug.cqmlib)
        const dirPath = libPath.substring(0, libPath.lastIndexOf('/')) || '/';
        const fileName = libPath.substring(libPath.lastIndexOf('/') + 1);
        return this.availableLibraries.find(l => l.path === dirPath && l.name === fileName)
            || this.availableLibraries.find(l => l.name === fileName);
    }

    /**
     * Handle library selection change
     */
    async onLibrarySelected(value) {
        const storageKey = this.spiritId ? `cqMemoryLib_${this.spiritId}` : 'cqMemoryLib_global';

        if (!value) {
            // "All Packs" selected - clear library filter
            this.selectedLibrary = null;
            this.libraryPacks = [];
            localStorage.removeItem(storageKey);
        } else {
            try {
                const libData = JSON.parse(value);
                this.selectedLibrary = this.availableLibraries.find(
                    l => l.path === libData.path && l.name === libData.name
                ) || libData;
                localStorage.setItem(storageKey, value);
                
                // Fetch library contents to get its pack list
                await this.loadLibraryPacks(libData.path, libData.name);
            } catch (e) {
                console.error('Failed to parse library value:', e);
                this.selectedLibrary = null;
                this.libraryPacks = [];
            }
        }

        // Reload pack selector with filtered packs
        await this.loadPacks();

        // Auto-select first pack or clear
        const packSelector = document.getElementById('pack-selector');
        if (packSelector && packSelector.options.length > 1) {
            // Select first real option (skip placeholder)
            packSelector.selectedIndex = 1;
            await this.onPackSelected(packSelector.value);
        } else if (!this.currentPackPath) {
            // Show empty state
            await this.loadGraphData();
        }
    }

    /**
     * Load packs belonging to a specific library
     */
    async loadLibraryPacks(libPath, libName) {
        try {
            const response = await fetch('/api/memory/library/open', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    projectId: this.projectId,
                    path: libPath,
                    name: libName
                })
            });

            if (!response.ok) throw new Error(`HTTP ${response.status}`);

            const data = await response.json();
            // Library packs have relative paths - resolve them relative to library dir
            this.libraryPacks = (data.library?.packs || []).map(p => ({
                ...p,
                _libRelative: true,
                _libPath: libPath
            }));
        } catch (error) {
            console.error('Failed to load library packs:', error);
            this.libraryPacks = [];
        }
    }

    /**
     * Show create library modal
     */
    showCreateLibraryModal() {
        const modal = document.getElementById('createLibraryModal');
        if (!modal) return;

        // Clear form
        const nameInput = document.getElementById('library-name');
        const descInput = document.getElementById('library-description');
        if (nameInput) nameInput.value = '';
        if (descInput) descInput.value = '';

        const bsModal = bootstrap.Modal.getOrCreateInstance(modal);
        bsModal.show();
    }

    /**
     * Create a new memory library
     */
    async createLibrary() {
        const nameInput = document.getElementById('library-name');
        const descInput = document.getElementById('library-description');
        const trans = window.memoryExplorerTranslations?.library_selector || {};

        const name = nameInput?.value?.trim();
        const description = descInput?.value?.trim() || '';

        if (!name) {
            window.toast?.error(trans.name_required || 'Library name is required');
            return;
        }

        // Use libsPath for non-Spirit, memoryPath for Spirit context
        const libPath = this.libsPath || this.memoryPath || '/memory/libs';

        try {
            const response = await fetch('/api/memory/library/create', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    projectId: this.projectId,
                    path: libPath,
                    name,
                    description
                })
            });

            const data = await response.json();

            if (!response.ok || !data.success) {
                throw new Error(data.error || 'Failed to create library');
            }

            // Close modal
            const modal = document.getElementById('createLibraryModal');
            if (modal) {
                bootstrap.Modal.getInstance(modal)?.hide();
            }

            window.toast?.success(trans.create_success || 'Library created successfully');

            // Reload libraries list
            await this.loadLibraries();

            // Select the newly created library
            const librarySelector = document.getElementById('library-selector');
            if (librarySelector && data.path && data.name) {
                const libValue = JSON.stringify({ path: data.path, name: data.name });
                librarySelector.value = libValue;
                await this.onLibrarySelected(libValue);
            }

        } catch (error) {
            console.error('Failed to create library:', error);
            window.toast?.error((trans.create_error || 'Failed to create library') + ': ' + error.message);
        }
    }

    /**
     * Show library details modal
     */
    async showLibraryDetails() {
        if (!this.selectedLibrary) {
            window.toast?.warning('Select a library first');
            return;
        }

        const contentEl = document.getElementById('libraryDetailsContent');
        if (!contentEl) return;

        contentEl.innerHTML = '<div class="text-center py-3"><i class="mdi mdi-loading mdi-spin text-cyber fs-3"></i></div>';

        const modal = document.getElementById('libraryDetailsModal');
        const bsModal = bootstrap.Modal.getOrCreateInstance(modal);
        bsModal.show();

        try {
            const response = await fetch('/api/memory/library/open', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    projectId: this.projectId,
                    path: this.selectedLibrary.path,
                    name: this.selectedLibrary.name
                })
            });

            if (!response.ok) throw new Error(`HTTP ${response.status}`);
            const data = await response.json();
            const lib = data.library || {};
            const packs = lib.packs || [];

            contentEl.innerHTML = `
                <div class="mb-3">
                    <p><strong class="d-inline-block w-100"><i class="mdi mdi-label-outline me-2 text-cyber"></i>Name:</strong>
                    <span class="ms-3">${this.escapeHtml(lib.name || this.selectedLibrary.displayName || this.selectedLibrary.name)}</span></p>
                    <p><strong class="d-inline-block w-100"><i class="mdi mdi-text me-2 text-cyber"></i>Description:</strong>
                    <span class="ms-3">${this.escapeHtml(lib.description || '(none)')}</span></p>
                    <p><strong class="d-inline-block w-100"><i class="mdi mdi-folder-outline me-2 text-cyber"></i>Path:</strong>
                    <span class="ms-3 text-secondary small">${this.escapeHtml(this.selectedLibrary.path + '/' + this.selectedLibrary.name)}</span></p>
                    <p><strong class="d-inline-block w-100"><i class="mdi mdi-package-variant me-2 text-cyber"></i>Packs:</strong>
                    <span class="ms-3">${packs.length}</span></p>
                </div>
                ${packs.length > 0 ? `
                <h6 class="mb-2"><i class="mdi mdi-package-variant-closed me-1 text-cyber"></i>Packs in this library:</h6>
                <ul class="list-unstyled ms-2">
                    ${packs.map(p => `<li class="mb-1 small">
                        <i class="mdi mdi-package-variant text-secondary me-1"></i>
                        ${this.escapeHtml(p.displayName || p.name || p.path)}
                        ${(p.enabled ?? true) ? '' : '<span class="badge bg-secondary ms-1">disabled</span>'}
                    </li>`).join('')}
                </ul>` : ''}
            `;
        } catch (error) {
            console.error('Failed to load library details:', error);
            contentEl.innerHTML = `<div class="alert alert-danger"><i class="mdi mdi-alert me-2"></i>Failed to load library details: ${this.escapeHtml(error.message)}</div>`;
        }
    }

    /**
     * Show delete library confirmation modal
     */
    showDeleteLibraryModal() {
        if (!this.selectedLibrary) {
            window.toast?.warning('Select a library first');
            return;
        }

        const modal = document.getElementById('deleteLibraryModal');
        if (!modal) return;
        const bsModal = bootstrap.Modal.getOrCreateInstance(modal);
        bsModal.show();
    }

    /**
     * Delete the selected library
     */
    async deleteLibrary() {
        if (!this.selectedLibrary) return;

        try {
            const response = await fetch('/api/memory/library/delete', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    projectId: this.projectId,
                    path: this.selectedLibrary.path,
                    name: this.selectedLibrary.name
                })
            });

            const data = await response.json();
            if (!response.ok || !data.success) {
                throw new Error(data.error || 'Failed to delete library');
            }

            // Close modal
            const modal = document.getElementById('deleteLibraryModal');
            if (modal) bootstrap.Modal.getInstance(modal)?.hide();

            window.toast?.success('Library deleted successfully');

            // Clear selection and reload
            this.selectedLibrary = null;
            this.libraryPacks = [];
            const storageKey = this.spiritId ? `cqMemoryLib_${this.spiritId}` : 'cqMemoryLib_global';
            localStorage.removeItem(storageKey);

            await this.loadLibraries();
            const librarySelector = document.getElementById('library-selector');
            if (librarySelector) librarySelector.value = '';
            await this.loadPacks();

        } catch (error) {
            console.error('Failed to delete library:', error);
            window.toast?.error('Failed to delete library: ' + error.message);
        }
    }

    /**
     * Show pack details modal
     */
    async showPackDetails() {
        if (!this.currentPackPath) {
            window.toast?.warning('Select a pack first');
            return;
        }

        const contentEl = document.getElementById('packDetailsContent');
        if (!contentEl) return;

        contentEl.innerHTML = '<div class="text-center py-3"><i class="mdi mdi-loading mdi-spin text-cyber fs-3"></i></div>';

        const modal = document.getElementById('packDetailsModal');
        const bsModal = bootstrap.Modal.getOrCreateInstance(modal);
        bsModal.show();

        try {
            const packData = JSON.parse(this.currentPackPath);
            const response = await fetch('/api/memory/pack/metadata', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    projectId: this.projectId,
                    path: packData.path,
                    name: packData.name
                })
            });

            if (!response.ok) throw new Error(`HTTP ${response.status}`);
            const data = await response.json();
            const meta = data.metadata || {};
            const stats = data.stats || {};
            const aiUsage = data.aiUsage || {};

            // Build AI usage section
            let aiUsageHtml = '';
            if (aiUsage.total_calls > 0) {
                const totalCredits = aiUsage.total_cost_credits ? parseFloat(aiUsage.total_cost_credits).toFixed(4) : '0';
                const totalTokens = aiUsage.total_tokens ? parseInt(aiUsage.total_tokens).toLocaleString() : '0';
                
                let purposeRows = '';
                if (aiUsage.by_purpose && aiUsage.by_purpose.length > 0) {
                    purposeRows = aiUsage.by_purpose.map(p => `
                        <tr>
                            <td class="small">${this.escapeHtml(p.purpose)}</td>
                            <td class="text-center small">${p.calls}</td>
                            <td class="text-center small">${p.tokens ? parseInt(p.tokens).toLocaleString() : '0'}</td>
                            <td class="text-end small text-warning">${p.cost_credits ? parseFloat(p.cost_credits).toFixed(4) : '0'}</td>
                        </tr>
                    `).join('');
                }

                aiUsageHtml = `
                    <h6 class="mb-2 mt-3"><i class="mdi mdi-circle-multiple-outline me-1 text-warning"></i>AI Usage:</h6>
                    <div class="row text-center mb-2">
                        <div class="col-4">
                            <div class="glass-panel p-2">
                                <div class="fs-4 text-warning">${aiUsage.total_calls}</div>
                                <small class="text-secondary">AI Calls</small>
                            </div>
                        </div>
                        <div class="col-4">
                            <div class="glass-panel p-2">
                                <div class="fs-4 text-warning">${totalTokens}</div>
                                <small class="text-secondary">Tokens</small>
                            </div>
                        </div>
                        <div class="col-4">
                            <div class="glass-panel p-2">
                                <div class="fs-4 text-warning">${totalCredits}</div>
                                <small class="text-secondary">Credits</small>
                            </div>
                        </div>
                    </div>
                    ${purposeRows ? `
                    <table class="table table-sm table-dark table-borderless mb-0 mt-1">
                        <thead><tr>
                            <th class="small text-secondary">Sub-Agent</th>
                            <th class="text-center small text-secondary">Calls</th>
                            <th class="text-center small text-secondary">Tokens</th>
                            <th class="text-end small text-secondary">Credits</th>
                        </tr></thead>
                        <tbody>${purposeRows}</tbody>
                    </table>` : ''}
                `;
            }

            contentEl.innerHTML = `
                <div class="mb-3">
                    <p><strong class="d-inline-block w-100"><i class="mdi mdi-label-outline me-2 text-cyber"></i>Name:</strong>
                    <span class="ms-3">${this.escapeHtml(meta.name || packData.name)}</span></p>
                    <p><strong class="d-inline-block w-100"><i class="mdi mdi-text me-2 text-cyber"></i>Description:</strong>
                    <span class="ms-3">${this.escapeHtml(meta.description || '(none)')}</span></p>
                    <p><strong class="d-inline-block w-100"><i class="mdi mdi-folder-outline me-2 text-cyber"></i>Path:</strong>
                    <span class="ms-3 text-secondary small">${this.escapeHtml(packData.path + '/' + packData.name)}</span></p>
                </div>
                <h6 class="mb-2"><i class="mdi mdi-chart-bar me-1 text-cyber"></i>Statistics:</h6>
                <div class="row text-center mb-2">
                    <div class="col-4">
                        <div class="glass-panel p-2">
                            <div class="fs-4 text-cyber">${stats.totalNodes || 0}</div>
                            <small class="text-secondary">Nodes</small>
                        </div>
                    </div>
                    <div class="col-4">
                        <div class="glass-panel p-2">
                            <div class="fs-4 text-cyber">${stats.totalRelationships || 0}</div>
                            <small class="text-secondary">Relationships</small>
                        </div>
                    </div>
                    <div class="col-4">
                        <div class="glass-panel p-2">
                            <div class="fs-4 text-cyber">${stats.tagsCount || 0}</div>
                            <small class="text-secondary">Tags</small>
                        </div>
                    </div>
                </div>
                ${aiUsageHtml}
                ${meta.created_at ? `<p class="small text-secondary mt-2"><i class="mdi mdi-clock-outline me-1"></i>Created: ${this.escapeHtml(meta.created_at)}</p>` : ''}
            `;
        } catch (error) {
            console.error('Failed to load pack details:', error);
            contentEl.innerHTML = `<div class="alert alert-danger"><i class="mdi mdi-alert me-2"></i>Failed to load pack details: ${this.escapeHtml(error.message)}</div>`;
        }
    }

    /**
     * Show add pack to library modal with library selector
     */
    showAddPackToLibModal() {
        if (!this.currentPackPath) {
            window.toast?.warning('Select a pack first');
            return;
        }

        // Populate library select with available libraries
        const libSelect = document.getElementById('add-pack-lib-select');
        if (!libSelect) return;

        let html = '<option value="">-- Select a library --</option>';
        const sortedLibs = [...this.availableLibraries].sort((a, b) => {
            const nameA = (a.displayName || a.name).toLowerCase();
            const nameB = (b.displayName || b.name).toLowerCase();
            return nameA.localeCompare(nameB);
        });
        sortedLibs.forEach(lib => {
            const libValue = JSON.stringify({ path: lib.path, name: lib.name });
            const label = `${lib.displayName || lib.name} (${lib.packCount} packs)`;
            html += `<option value='${this.escapeHtml(libValue)}'>${this.escapeHtml(label)}</option>`;
        });
        libSelect.innerHTML = html;

        const modal = document.getElementById('addPackToLibModal');
        if (!modal) return;
        const bsModal = bootstrap.Modal.getOrCreateInstance(modal);
        bsModal.show();
    }

    /**
     * Add the current pack to the selected library
     */
    async addPackToLibrary() {
        if (!this.currentPackPath) return;

        const libSelect = document.getElementById('add-pack-lib-select');
        if (!libSelect || !libSelect.value) {
            window.toast?.warning('Select a library first');
            return;
        }

        try {
            const packData = JSON.parse(this.currentPackPath);
            const libData = JSON.parse(libSelect.value);

            const response = await fetch('/api/memory/library/add-pack', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    projectId: this.projectId,
                    libraryPath: libData.path,
                    libraryName: libData.name,
                    packPath: packData.path,
                    packName: packData.name
                })
            });

            const data = await response.json();
            if (!response.ok || !data.success) {
                throw new Error(data.error || 'Failed to add pack to library');
            }

            // Close modal
            const modal = document.getElementById('addPackToLibModal');
            if (modal) bootstrap.Modal.getInstance(modal)?.hide();

            const trans = window.memoryExplorerTranslations?.pack_selector || {};
            window.toast?.success(trans.add_to_lib_success || 'Pack added to library');

            // Save current pack reference before reloading
            const savedPackPath = this.currentPackPath;

            // Reload libraries to update pack counts
            await this.loadLibraries();

            // Select the target library (without auto-selecting first pack)
            const librarySelector = document.getElementById('library-selector');
            if (librarySelector) {
                const libValue = libSelect.value;
                librarySelector.value = libValue;
                const storageKey = this.spiritId ? `cqMemoryLib_${this.spiritId}` : 'cqMemoryLib_global';
                this.selectedLibrary = this.availableLibraries.find(
                    l => l.path === libData.path && l.name === libData.name
                ) || libData;
                localStorage.setItem(storageKey, libValue);
                await this.loadLibraryPacks(libData.path, libData.name);
                await this.loadPacks();
            }

            // Re-select the pack we just added
            const packSelector = document.getElementById('pack-selector');
            if (packSelector && savedPackPath) {
                packSelector.value = savedPackPath;
                await this.onPackSelected(savedPackPath);
            }

        } catch (error) {
            console.error('Failed to add pack to library:', error);
            const trans = window.memoryExplorerTranslations?.pack_selector || {};
            window.toast?.error((trans.add_to_lib_error || 'Failed to add pack to library') + ': ' + error.message);
        }
    }

    /**
     * Show remove pack from library confirmation modal
     */
    showRemovePackFromLibModal() {
        if (!this.currentPackPath) {
            window.toast?.warning('Select a pack first');
            return;
        }
        if (!this.selectedLibrary) {
            window.toast?.warning('No library selected — this pack is not in a library');
            return;
        }

        const modal = document.getElementById('removePackFromLibModal');
        if (!modal) return;
        const bsModal = bootstrap.Modal.getOrCreateInstance(modal);
        bsModal.show();
    }

    /**
     * Remove the selected pack from the current library
     */
    async removePackFromLibrary() {
        if (!this.currentPackPath || !this.selectedLibrary) return;

        try {
            const packData = JSON.parse(this.currentPackPath);

            const response = await fetch('/api/memory/library/remove-pack', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    projectId: this.projectId,
                    libraryPath: this.selectedLibrary.path,
                    libraryName: this.selectedLibrary.name,
                    packPath: packData.path + '/' + packData.name
                })
            });

            const data = await response.json();
            if (!response.ok || !data.success) {
                throw new Error(data.error || 'Failed to remove pack from library');
            }

            // Close modal
            const modal = document.getElementById('removePackFromLibModal');
            if (modal) bootstrap.Modal.getInstance(modal)?.hide();

            window.toast?.success('Pack removed from library');

            // Clear current selection and graph
            this.currentPackPath = null;
            this.selectedNode = null;
            this.hideNodeDetails();
            if (this.graphView) this.graphView.clearGraph();

            // Reload library packs and refresh pack list
            await this.loadLibraryPacks(this.selectedLibrary.path, this.selectedLibrary.name);
            await this.loadLibraries();
            await this.loadPacks();

        } catch (error) {
            console.error('Failed to remove pack from library:', error);
            window.toast?.error('Failed to remove pack: ' + error.message);
        }
    }

    /**
     * Show delete pack confirmation modal
     */
    showDeletePackModal() {
        if (!this.currentPackPath) {
            window.toast?.warning('Select a pack first');
            return;
        }

        const modal = document.getElementById('deletePackModal');
        if (!modal) return;
        const bsModal = bootstrap.Modal.getOrCreateInstance(modal);
        bsModal.show();
    }

    /**
     * Delete the selected pack
     */
    async deletePack() {
        if (!this.currentPackPath) return;

        try {
            const packData = JSON.parse(this.currentPackPath);

            const response = await fetch('/api/memory/pack/delete', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    projectId: this.projectId,
                    path: packData.path,
                    name: packData.name
                })
            });

            const data = await response.json();
            if (!response.ok || !data.success) {
                throw new Error(data.error || 'Failed to delete pack');
            }

            // Close modal
            const modal = document.getElementById('deletePackModal');
            if (modal) bootstrap.Modal.getInstance(modal)?.hide();

            window.toast?.success('Pack deleted successfully');

            // Clear selection and graph
            this.currentPackPath = null;
            this.selectedNode = null;
            this.hideNodeDetails();
            if (this.graphView) this.graphView.clearGraph();
            const storageKey = this.spiritId ? `cqMemoryPack_${this.spiritId}` : 'cqMemoryPack_global';
            localStorage.removeItem(storageKey);

            // Reload everything
            await this.loadLibraries();
            await this.loadPacks();

        } catch (error) {
            console.error('Failed to delete pack:', error);
            window.toast?.error('Failed to delete pack: ' + error.message);
        }
    }

    /**
     * Check if any Bootstrap modal is currently open
     */
    isModalOpen() {
        return document.querySelector('.modal.show') !== null;
    }

    /**
     * Check if a node has PART_OF children in the current graph data
     */
    hasPartOfChildren(nodeId) {
        if (!this.graphData?.edges) return false;
        return this.graphData.edges.some(e => e.target === nodeId && e.type === 'PART_OF');
    }

    /**
     * Show delete node confirmation modal for the selected node
     */
    showDeleteNodeModal() {
        if (!this.selectedNode) {
            window.toast?.warning('Select a node first');
            return;
        }

        // Update modal with node info
        const summaryEl = document.getElementById('deleteNodeSummary');
        if (summaryEl) {
            summaryEl.textContent = this.selectedNode.summary || this.selectedNode.id;
        }

        // Show/hide children warning
        const childrenWarning = document.getElementById('deleteNodeChildrenWarning');
        if (childrenWarning) {
            if (this.hasPartOfChildren(this.selectedNode.id)) {
                childrenWarning.classList.remove('d-none');
            } else {
                childrenWarning.classList.add('d-none');
            }
        }

        const modal = document.getElementById('deleteNodeModal');
        if (!modal) return;
        const bsModal = bootstrap.Modal.getOrCreateInstance(modal);
        bsModal.show();
    }

    /**
     * Delete the selected node and its PART_OF children via API
     */
    async deleteSelectedNode() {
        if (!this.selectedNode || !this.currentPackPath) return;

        const nodeId = this.selectedNode.id;
        const nodeSummary = this.selectedNode.summary || nodeId;

        try {
            const packData = JSON.parse(this.currentPackPath);

            const response = await fetch('/api/memory/pack/node/delete', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    projectId: this.projectId,
                    path: packData.path,
                    name: packData.name,
                    nodeId: nodeId
                })
            });

            const data = await response.json();
            if (!response.ok || !data.success) {
                throw new Error(data.error || 'Failed to delete node');
            }

            // Close modal
            const modal = document.getElementById('deleteNodeModal');
            if (modal) bootstrap.Modal.getInstance(modal)?.hide();

            // Remove deleted nodes from 3D scene
            if (data.deletedNodeIds && this.graphView) {
                this.graphView.removeNodes(data.deletedNodeIds);
            }

            // Clear selection
            this.selectedNode = null;
            this.hideNodeDetails();

            // Update stats
            this.updateStats();

            window.toast?.success(`Deleted ${data.deletedCount} node(s): ${nodeSummary}`);

        } catch (error) {
            console.error('Failed to delete node:', error);
            window.toast?.error('Failed to delete node: ' + error.message);
        }
    }

    /**
     * Load available packs (filtered by selected library if any)
     */
    async loadPacks() {
        const packSelector = document.getElementById('pack-selector');
        if (!packSelector) return;

        const trans = window.memoryExplorerTranslations?.pack_selector || {};

        try {
            let packsToShow;

            if (this.selectedLibrary) {
                // Library selected - show only packs from that library
                // Match library pack paths against available project packs
                packsToShow = this.resolveLibraryPacks();
            } else {
                // No library filter - show all project packs
                packsToShow = this.availablePacks;
            }

            // Sort packs alphabetically by display name
            packsToShow.sort((a, b) => {
                const nameA = (a.displayName || a.name).toLowerCase();
                const nameB = (b.displayName || b.name).toLowerCase();
                return nameA.localeCompare(nameB);
            });

            // Build selector options
            let html = `<option value="">${trans.select_pack || 'Select a pack'}</option>`;
            
            // Add available packs
            if (packsToShow.length > 0) {
                packsToShow.forEach(pack => {
                    const nodeCount = pack.totalNodes || 0;
                    const label = `${pack.displayName || pack.name} (${nodeCount} ${trans.nodes || 'nodes'})`;
                    const packValue = JSON.stringify({ path: pack.path, name: pack.name });
                    html += `<option value='${this.escapeHtml(packValue)}'>${this.escapeHtml(label)}</option>`;
                });
            }

            packSelector.innerHTML = html;

            // Restore last selected pack from localStorage
            const storageKey = this.spiritId ? `cqMemoryPack_${this.spiritId}` : 'cqMemoryPack_global';
            const lastPack = localStorage.getItem(storageKey);
            let selectedPack = false;

            if (lastPack) {
                // Try to find matching pack option
                try {
                    const lastPackData = JSON.parse(lastPack);
                    const matchingPack = packsToShow.find(p => p.path === lastPackData.path && p.name === lastPackData.name);
                    if (matchingPack) {
                        packSelector.value = lastPack;
                        this.currentPackPath = lastPack;
                        this.extractPanel.setTargetPack({
                            projectId: this.projectId,
                            path: lastPackData.path,
                            name: lastPackData.name
                        }, this.projectId);
                        selectedPack = true;
                    }
                } catch { /* ignore */ }
            }

            // Auto-select Spirit's root pack if no pack was restored
            if (!selectedPack && this.spiritId && this.packsPath && this.rootPackName && !this.selectedLibrary) {
                const rootPack = packsToShow.find(p => p.path === this.packsPath && p.name === this.rootPackName);
                if (rootPack) {
                    const rootPackValue = JSON.stringify({ path: rootPack.path, name: rootPack.name });
                    packSelector.value = rootPackValue;
                    this.currentPackPath = rootPackValue;
                    this.extractPanel.setTargetPack({
                        projectId: this.projectId,
                        path: rootPack.path,
                        name: rootPack.name
                    }, this.projectId);
                    localStorage.setItem(storageKey, rootPackValue);
                    selectedPack = true;
                }
            }

            if (!selectedPack) {
                packSelector.value = '';
                this.currentPackPath = null;
                this.extractPanel.setTargetPack(null, this.projectId);
            }

        } catch (error) {
            console.error('Failed to load packs:', error);
            packSelector.innerHTML = `<option value="">${trans.select_pack || 'Select a pack'}</option>`;
            this.currentPackPath = null;
        }
    }

    /**
     * Resolve library pack references to actual project packs
     * Library stores relative paths like "packs/session.cqmpack"
     * We need to match them against availablePacks which have absolute project paths
     */
    resolveLibraryPacks() {
        if (!this.libraryPacks.length) return [];
        
        const resolved = [];
        const libPath = this.selectedLibrary?.path || '/';
        
        for (const libPack of this.libraryPacks) {
            if (!(libPack.enabled ?? true)) continue;
            
            const packRefPath = libPack.path;
            let fullPackDir, packName;
            
            if (packRefPath.startsWith('/')) {
                // Absolute path (e.g. /memory/packs/PackPack.cqmpack)
                fullPackDir = packRefPath.substring(0, packRefPath.lastIndexOf('/')) || '/';
                packName = packRefPath.substring(packRefPath.lastIndexOf('/') + 1);
            } else {
                // Relative path (e.g. packs/session.cqmpack) — resolve relative to library dir
                const packDir = packRefPath.includes('/') ? packRefPath.substring(0, packRefPath.lastIndexOf('/')) : '.';
                packName = packRefPath.includes('/') ? packRefPath.substring(packRefPath.lastIndexOf('/') + 1) : packRefPath;
                
                fullPackDir = libPath;
                if (packDir !== '.') {
                    fullPackDir = libPath.replace(/\/$/, '') + '/' + packDir.replace(/^\//, '');
                }
            }
            
            // Find matching project pack
            const match = this.availablePacks.find(p => p.path === fullPackDir && p.name === packName);
            if (match) {
                resolved.push(match);
            }
        }
        
        return resolved;
    }

    /**
     * Handle pack selection change
     */
    async onPackSelected(packPath) {
        if (!packPath) return;

        this.currentPackPath = packPath;
        const storageKey = this.spiritId ? `cqMemoryPack_${this.spiritId}` : 'cqMemoryPack_global';
        localStorage.setItem(storageKey, packPath);

        // Clear current selection and panels
        this.selectedNode = null;
        this.hideNodeDetails();
        
        // Update extract panel's target pack
        try {
            const packData = JSON.parse(packPath);
            this.extractPanel.setTargetPack({
                projectId: this.projectId,
                path: packData.path,
                name: packData.name
            }, this.projectId);
        } catch (e) {
            console.error('Failed to parse pack path:', e);
            this.extractPanel.setTargetPack(null, this.projectId);
        }

        // Reload graph data from selected pack
        await this.loadGraphData();
    }

    /**
     * Show create pack modal
     */
    showCreatePackModal() {
        const modal = document.getElementById('createPackModal');
        if (!modal) return;

        // Clear form
        const nameInput = document.getElementById('pack-name');
        const descInput = document.getElementById('pack-description');
        if (nameInput) nameInput.value = '';
        if (descInput) descInput.value = '';

        // Show modal
        const bsModal = bootstrap.Modal.getOrCreateInstance(modal);
        bsModal.show();
    }

    /**
     * Create a new memory pack
     */
    async createPack() {
        const nameInput = document.getElementById('pack-name');
        const descInput = document.getElementById('pack-description');
        const trans = window.memoryExplorerTranslations?.pack_selector || {};

        const name = nameInput?.value?.trim();
        const description = descInput?.value?.trim() || '';

        if (!name) {
            window.toast?.error('Pack name is required');
            return;
        }

        // Determine pack storage path:
        // If a library is selected, store in the library's packs subdirectory
        // (e.g., library at /spirit/Lori/memory → packs at /spirit/Lori/memory/packs)
        const packPath = this.selectedLibrary
            ? this.selectedLibrary.path + '/packs'
            : this.packsPath;

        if (!packPath) {
            window.toast?.error('Memory path not initialized');
            return;
        }

        try {
            const response = await fetch('/api/memory/pack/create', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ 
                    projectId: this.projectId,
                    path: packPath,
                    name, 
                    description 
                })
            });

            const data = await response.json();

            if (!response.ok || !data.success) {
                throw new Error(data.error || 'Failed to create pack');
            }

            // Close modal
            const modal = document.getElementById('createPackModal');
            if (modal) {
                bootstrap.Modal.getInstance(modal)?.hide();
            }

            // If a library is selected, auto-add the new pack to it
            if (this.selectedLibrary && data.path && data.name) {
                try {
                    const addResponse = await fetch('/api/memory/library/add-pack', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            projectId: this.projectId,
                            libraryPath: this.selectedLibrary.path,
                            libraryName: this.selectedLibrary.name,
                            packPath: data.path,
                            packName: data.name
                        })
                    });
                    const addData = await addResponse.json();
                    if (addData.success) {
                        // Reload library packs so the filter is up to date
                        await this.loadLibraryPacks(this.selectedLibrary.path, this.selectedLibrary.name);
                    }
                } catch (e) {
                    console.warn('Failed to auto-add pack to library:', e);
                }
            }

            // Show success message
            window.toast?.success(trans.create_success || 'Pack created successfully');

            // Reload libraries to update pack counts in dropdown
            await this.loadLibraries();

            // Reload packs list
            await this.loadPacks();

            // Select the newly created pack
            const packSelector = document.getElementById('pack-selector');
            if (packSelector && data.path && data.name) {
                // Create the pack value JSON (same format as in loadPacks)
                const packValue = JSON.stringify({ path: data.path, name: data.name });
                packSelector.value = packValue;
                
                // Trigger pack selection to reload all UI panels
                await this.onPackSelected(packValue);
            }

        } catch (error) {
            console.error('Failed to create pack:', error);
            window.toast?.error((trans.create_error || 'Failed to create pack') + ': ' + error.message);
        }
    }
}

// Initialize on DOM ready
document.addEventListener('DOMContentLoaded', () => {
    const spiritIdInput = document.getElementById('spirit-id');
    const spiritId = spiritIdInput?.value || null;
    
    new CQMemoryExplorer(spiritId);
    
    // Hide page loading indicator
    const loadingIndicator = document.getElementById('page-loading-indicator');
    if (loadingIndicator) {
        loadingIndicator.classList.add('d-none');
    }
});
