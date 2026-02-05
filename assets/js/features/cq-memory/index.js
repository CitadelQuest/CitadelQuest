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
        this.currentPackPath = this.spiritId ? '__legacy__' : null; // Legacy mode only with Spirit context
        this.currentPackMetadata = null;
        this.availablePacks = [];
        this.availableLibraries = [];
        this.memoryPath = null;
        
        // Filter state
        this.hoverDebounceTimer = null;
        this.filters = {
            categories: new Set(['conversation', 'knowledge', 'preference', 'thought', 'fact']),
            relationships: new Set(['RELATES_TO', 'DERIVED_FROM', 'EVOLVED_INTO', 'PART_OF', 'CONTRADICTS', 'REINFORCES', 'SUPERSEDES'])
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
        this.graphView.setOnNodeSelect((node) => this.showNodeDetails(node));
        this.graphView.setOnNodeHover((node) => this.onNodeHover(node));
        this.graphView.setOnNodeDeselect(() => this.hideNodeDetails());

        // Initialize extract panel
        this.extractPanel = new MemoryExtractPanel(this.spiritId);
        
        // Set callbacks for extraction
        this.extractPanel.onExtractionStart = () => this.startManualPolling();
        this.extractPanel.onExtractionComplete = () => this.updateStats();
        this.extractPanel.onGraphDelta = (delta) => this.applyGraphDelta(delta);

        // Register listener with global updates service (polling already started in app)
        if (this.spiritId) {
            this.setupPolling();
        }

        // Setup UI event listeners
        this.setupEventListeners();

        // Setup pack selector
        this.setupPackSelector();

        // Initialize memory structure and load packs
        await this.initializeSpiritMemory();
        await this.loadPacks();

        // Load graph data (from selected pack or legacy, skip if no context)
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
                // Filter jobs for this spirit (legacy jobs only - pack jobs handled separately)
                const spiritJobs = updates.memoryJobs.active.filter(job => job.spiritId === this.spiritId);
                
                if (spiritJobs.length > 0) {
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
        console.log('🔄 Starting manual polling for memory extraction...');
        
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
            updatesService.resume();
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
            
            // Check if loading from a pack or legacy mode
            if (this.currentPackPath && this.currentPackPath !== '__legacy__') {
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
            } else if (this.spiritId) {
                // Legacy mode - load from user database (requires Spirit context)
                response = await fetch(`/memory/graph/${this.spiritId}`);
            } else {
                // No pack selected and no Spirit context - nothing to load
                if (loadingEl) loadingEl.classList.add('d-none');
                return;
            }
            
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            const data = await response.json();
            
            // Normalize response format (pack API returns different structure)
            if (this.currentPackPath && this.currentPackPath !== '__legacy__') {
                this.graphData = {
                    nodes: data.nodes || [],
                    edges: data.edges || [],
                    stats: data.stats || {}
                };
                this.currentPackMetadata = data.metadata || {};
            } else {
                this.graphData = data;
            }

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
        if (!packSelector || !this.currentPackPath || this.currentPackPath === '__legacy__') {
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
                console.log('✅ Updated pack selector:', displayName, nodeCount);
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
            fact: '#4affff'
        };
        return colors[category] || '#95ec86';
    }

    getRelationshipColor(type) {
        const colors = {
            RELATES_TO: '#ffffff',
            DERIVED_FROM: '#ffff00',
            EVOLVED_INTO: '#00ffff',
            PART_OF: '#00ff00',
            CONTRADICTS: '#ff0000',
            REINFORCES: '#0088ff',
            SUPERSEDES: '#ff8800'
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
            
            const showSourceBtn = sourceRef ? `
                <button class="btn btn-sm btn-outline-secondary show-source-btn py-0 px-1 ms-2" 
                        data-source-ref="${this.escapeHtml(sourceRef)}" 
                        data-source-range="${sourceRange || ''}"
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
                const memoryTitle = btn.dataset.memoryTitle;
                await this.showSourceContent(sourceRef, sourceRange, memoryTitle);
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
    async showSourceContent(sourceRef, sourceRange, memoryTitle) {
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
            const response = await fetch(`/memory/source`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ source_ref: sourceRef, source_range: sourceRange })
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
            const memoryTitle = group.node.summary || '(no summary)';
            const showSourceBtn = sourceRef ? `
                <button class="btn btn-sm btn-outline-secondary show-source-btn py-0 px-1 ms-2" 
                        data-source-ref="${this.escapeHtml(sourceRef)}" 
                        data-source-range="${sourceRange || ''}"
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
    // Pack Management
    // ========================================

    /**
     * Setup pack selector event listeners
     */
    setupPackSelector() {
        const packSelector = document.getElementById('pack-selector');
        const createPackBtn = document.getElementById('btn-create-pack');
        const confirmCreateBtn = document.getElementById('btn-confirm-create-pack');

        if (packSelector) {
            packSelector.addEventListener('change', (e) => this.onPackSelected(e.target.value));
        }

        if (createPackBtn) {
            createPackBtn.addEventListener('click', () => this.showCreatePackModal());
        }

        if (confirmCreateBtn) {
            confirmCreateBtn.addEventListener('click', () => this.createPack());
        }
    }

    /**
     * Load available packs for this Spirit
     */
    async loadPacks() {
        const packSelector = document.getElementById('pack-selector');
        if (!packSelector) return;

        const trans = window.memoryExplorerTranslations?.pack_selector || {};

        try {
            // Get memory path - Spirit-specific or skip
            if (this.spiritId) {
                const pathResponse = await fetch(`/spirit/${this.spiritId}/memory/path`);
                if (!pathResponse.ok) {
                    throw new Error(`HTTP ${pathResponse.status}`);
                }
                const pathData = await pathResponse.json();
                this.projectId = pathData.projectId || 'general';
                this.memoryPath = pathData.memoryPath;
                this.packsPath = pathData.packsPath;
                this.rootLibraryName = pathData.rootLibraryName;
                this.spiritNameSlug = pathData.spiritNameSlug;
            } else {
                // No Spirit context - scan entire general project for all packs
                this.projectId = 'general';
                this.memoryPath = '/';
                this.packsPath = '/';
            }

            // List packs from memory path
            const response = await fetch('/api/memory/pack/list', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ projectId: this.projectId, path: this.memoryPath })
            });
            
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            const data = await response.json();
            this.availablePacks = data.packs || [];
            this.availableLibraries = data.libraries || [];

            // Build selector options
            let html = `<option value="">${trans.select_pack || 'Select a pack'}</option>`;
            
            // Add legacy mode option only when Spirit context is available
            if (this.spiritId) {
                html += `<option value="__legacy__">${trans.legacy_mode || 'Legacy (user database)'}</option>`;
            }
            
            // Add available packs
            if (this.availablePacks.length > 0) {
                html += '<optgroup label="Memory Packs">';
                this.availablePacks.forEach(pack => {
                    const nodeCount = pack.totalNodes || 0;
                    const label = `${pack.displayName || pack.name} (${nodeCount} ${trans.nodes || 'nodes'})`;
                    // Encode path and name as JSON for the value
                    const packValue = JSON.stringify({ path: pack.path, name: pack.name });
                    html += `<option value='${this.escapeHtml(packValue)}'>${this.escapeHtml(label)}</option>`;
                });
                html += '</optgroup>';
            }

            // Add libraries if any
            if (this.availableLibraries.length > 0) {
                html += '<optgroup label="Libraries">';
                this.availableLibraries.forEach(lib => {
                    const label = `📚 ${lib.name} (${lib.packCount} packs)`;
                    html += `<option value="lib:${this.escapeHtml(lib.path)}">${this.escapeHtml(label)}</option>`;
                });
                html += '</optgroup>';
            }

            packSelector.innerHTML = html;

            // Restore last selected pack from localStorage
            const storageKey = this.spiritId ? `cqMemoryPack_${this.spiritId}` : 'cqMemoryPack_global';
            const lastPack = localStorage.getItem(storageKey);
            if (lastPack === '__legacy__' && this.spiritId) {
                packSelector.value = lastPack;
                this.currentPackPath = lastPack;
                this.extractPanel.setTargetPack(null, this.projectId);
            } else if (lastPack && lastPack !== '__legacy__') {
                // Try to find matching pack option
                try {
                    const lastPackData = JSON.parse(lastPack);
                    const matchingPack = this.availablePacks.find(p => p.path === lastPackData.path && p.name === lastPackData.name);
                    if (matchingPack) {
                        packSelector.value = lastPack;
                        this.currentPackPath = lastPack;
                        // Set target pack for extraction
                        this.extractPanel.setTargetPack({
                            projectId: this.projectId,
                            path: lastPackData.path,
                            name: lastPackData.name
                        }, this.projectId);
                    } else {
                        packSelector.value = '__legacy__';
                        this.currentPackPath = '__legacy__';
                        this.extractPanel.setTargetPack(null, this.projectId);
                    }
                } catch {
                    packSelector.value = '__legacy__';
                    this.currentPackPath = '__legacy__';
                    this.extractPanel.setTargetPack(null, this.projectId);
                }
            } else if (this.spiritId) {
                // Default to legacy mode if no pack selected and Spirit context available
                packSelector.value = '__legacy__';
                this.currentPackPath = '__legacy__';
                this.extractPanel.setTargetPack(null, this.projectId);
            } else {
                // No Spirit context - no default selection
                packSelector.value = '';
                this.currentPackPath = null;
                this.extractPanel.setTargetPack(null, this.projectId);
            }

        } catch (error) {
            console.error('Failed to load packs:', error);
            if (this.spiritId) {
                packSelector.innerHTML = `<option value="__legacy__">${trans.legacy_mode || 'Legacy (user database)'}</option>`;
                this.currentPackPath = '__legacy__';
            } else {
                packSelector.innerHTML = `<option value="">${trans.select_pack || 'Select a pack'}</option>`;
                this.currentPackPath = null;
            }
        }
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
        if (packPath === '__legacy__') {
            // Legacy mode - disable pack extraction
            this.extractPanel.setTargetPack(null, this.projectId);
        } else if (!packPath.startsWith('lib:')) {
            // Pack selected - parse JSON and set target
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
        } else {
            // Library selected - no direct extraction to libraries
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

        if (!this.packsPath) {
            window.toast?.error('Memory path not initialized');
            return;
        }

        try {
            const response = await fetch('/api/memory/pack/create', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ 
                    projectId: this.projectId,
                    path: this.packsPath,
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

            // Show success message
            window.toast?.success(trans.create_success || 'Pack created successfully');

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

    /**
     * Initialize Spirit's memory structure if needed
     * Now just ensures the path exists via /spirit/{id}/memory/path
     */
    async initializeSpiritMemory() {
        // Path initialization is now handled in loadPacks() via /spirit/{id}/memory/path
        // This method is kept for compatibility but does nothing
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
