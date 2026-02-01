import { MemoryGraphView } from './MemoryGraphView';
import MarkdownIt from 'markdown-it';

/**
 * Spirit Memory Explorer - Main entry point
 * Orchestrates the graph visualization and UI interactions
 */
class SpiritMemoryExplorer {
    constructor() {
        this.spiritId = document.getElementById('spirit-id')?.value;
        this.graphView = null;
        this.graphData = null;
        this.md = new MarkdownIt({ html: false, breaks: true, linkify: true });
        
        // Filter state
        this.hoverDebounceTimer = null;
        this.filters = {
            categories: new Set(['conversation', 'knowledge', 'preference', 'thought', 'fact']),
            relationships: new Set(['RELATES_TO', 'DERIVED_FROM', 'EVOLVED_INTO', 'PART_OF', 'CONTRADICTS', 'REINFORCES', 'SUPERSEDES'])
        };

        if (!this.spiritId) {
            console.error('Spirit ID not found');
            return;
        }

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

        // Setup UI event listeners
        this.setupEventListeners();

        // Load graph data
        await this.loadGraphData();
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
        
        try {
            const response = await fetch(`/spirit/${this.spiritId}/memory/graph`);
            
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            this.graphData = await response.json();

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
            selectedContentEl.setAttribute('data-node-id', node.id);
            selectedContentEl.classList.add('compilation-block', 'expanded');
            selectedContentEl.innerHTML = `
                <div class="fw-bold mb-1" style="color: ${this.getCategoryColor(node.category)};">${node.summary || '(no summary)'}</div>
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

            return `
                <div class="mb-3">
                    ${relationshipsHtml}
                    <div class="compilation-block small p-2 rounded bg-secondary bg-opacity-25" data-node-id="${group.node.id}" data-full-content="${this.escapeHtml(fullContent)}">
                        <div class="fw-bold mb-1" style="color: ${this.getCategoryColor(group.node.category)};">${group.node.summary || '(no summary)'}</div>
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
        const stored = localStorage.getItem('spiritMemoryFilters');
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
        localStorage.setItem('spiritMemoryFilters', JSON.stringify(state));
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
        localStorage.setItem('spiritMemoryCollapsible', JSON.stringify(state));
    }

    /**
     * Load collapsible section state from localStorage
     */
    loadCollapsibleState() {
        const stored = localStorage.getItem('spiritMemoryCollapsible');
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
}

// Initialize on DOM ready
document.addEventListener('DOMContentLoaded', () => {
    new SpiritMemoryExplorer();
});
