import { MemoryGraphView } from '../../spirit-memory/MemoryGraphView';

/**
 * ProfileMemoryGraph - Compact memory visualization for Spirit profile page
 * Reuses the full MemoryGraphView but with simplified interactions
 */
export class ProfileMemoryGraph {
    constructor(spiritId) {
        this.spiritId = spiritId;
        this.graphView = null;
        this.graphData = null;
        this.container = document.querySelector('.memory-graph-preview');
        
        if (!this.container) {
            console.warn('Profile memory graph container not found');
            return;
        }

        this.init();
    }

    async init() {
        const canvas = document.getElementById('profile-memory-canvas');
        if (!canvas) {
            console.error('Profile memory canvas not found');
            return;
        }

        // Get container dimensions
        const containerRect = this.container.getBoundingClientRect();
        if (containerRect.width === 0 || containerRect.height === 0) {
            console.error('Container has no dimensions:', containerRect);
            return;
        }

        // Set canvas dimensions explicitly for WebGL
        canvas.width = containerRect.width;
        canvas.height = containerRect.height;

        // Initialize compact graph view
        this.graphView = new MemoryGraphView(this.container, {
            backgroundColor: 0x0a0a0f,
            compact: true
        });

        // Disable node selection (profile is read-only preview)
        this.graphView.setOnNodeSelect(null);
        
        // Load graph data
        await this.loadGraphData();

        // Auto-rotate for visual interest
        this.startAutoRotate();
    }

    async loadGraphData() {
        const loadingEl = document.getElementById('profile-memory-loading');
        
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

            // Hide loading
            if (loadingEl) {
                loadingEl.classList.add('d-none');
            }

            // Start gentle rotation
            this.graphView.resetView();

        } catch (error) {
            console.error('Failed to load profile memory graph:', error);
            if (loadingEl) {
                loadingEl.innerHTML = `
                    <div class="text-secondary small">
                        <i class="mdi mdi-alert-circle-outline"></i>
                        <p class="mt-1 mb-0">No memory data yet</p>
                    </div>
                `;
            }
        }
    }

    updateStats() {
        const nodesEl = document.getElementById('profile-stats-nodes');
        const edgesEl = document.getElementById('profile-stats-edges');

        if (nodesEl && this.graphData) {
            nodesEl.textContent = this.graphData.nodes?.length || 0;
        }
        if (edgesEl && this.graphData) {
            edgesEl.textContent = this.graphData.edges?.length || 0;
        }
    }

    startAutoRotate() {
        if (this.graphView?.controls) {
            this.graphView.controls.autoRotate = true;
            this.graphView.controls.autoRotateSpeed = 0.5;
        }
    }

    destroy() {
        if (this.graphView) {
            this.graphView.destroy();
            this.graphView = null;
        }
    }
}
