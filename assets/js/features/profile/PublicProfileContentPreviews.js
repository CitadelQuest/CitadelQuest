import { MemoryGraphView } from '../cq-memory/MemoryGraphView';

/**
 * PublicProfileContentPreviews
 * Initializes content previews on the public profile page:
 * - 3D graph visualization for Memory Pack shares
 * - Auto-rotate for visual interest
 */
export class PublicProfileContentPreviews {
    constructor() {
        this.graphViews = [];
        this.init();
    }

    init() {
        this.initMemoryPackGraphs();
    }

    /**
     * Initialize 3D graph previews for all Memory Pack share items
     */
    initMemoryPackGraphs() {
        const containers = document.querySelectorAll('.share-graph-preview');
        containers.forEach(container => {
            const graphUrl = container.dataset.graphUrl;
            if (!graphUrl) return;

            this.initSingleGraph(container, graphUrl);
        });
    }

    async initSingleGraph(container, graphUrl) {
        const canvas = container.querySelector('canvas');
        if (!canvas) return;

        // Set canvas dimensions
        const rect = container.getBoundingClientRect();
        if (rect.width === 0 || rect.height === 0) return;
        canvas.width = rect.width;
        canvas.height = rect.height;

        // Initialize compact graph view
        const graphView = new MemoryGraphView(container, {
            backgroundColor: 0x0a0a0f,
            compact: true
        });

        // Read-only preview — disable node selection
        graphView.setOnNodeSelect(null);

        this.graphViews.push(graphView);

        // Load graph data
        try {
            const response = await fetch(graphUrl);
            if (!response.ok) throw new Error(`HTTP ${response.status}`);

            const data = await response.json();
            const graphData = {
                nodes: data.nodes || [],
                edges: data.edges || [],
                stats: data.stats || {},
                packs: {}
            };

            // Update stats display
            const statsEl = container.parentElement?.querySelector('.share-graph-stats');
            if (statsEl) {
                const nodesEl = statsEl.querySelector('.stat-nodes');
                const edgesEl = statsEl.querySelector('.stat-edges');
                if (nodesEl) nodesEl.textContent = graphData.nodes.length;
                if (edgesEl) edgesEl.textContent = graphData.edges.length;
            }

            // Load graph and start animation
            graphView.loadGraph(graphData);
            graphView.resetView();

            // Hide loading spinner
            const loadingEl = container.querySelector('.graph-loading');
            if (loadingEl) loadingEl.classList.add('d-none');

            // Auto-rotate
            if (graphView.controls) {
                graphView.controls.autoRotate = true;
                graphView.controls.autoRotateSpeed = 0.5;
            }
        } catch (error) {
            console.warn('Failed to load share graph:', error);
            const loadingEl = container.querySelector('.graph-loading');
            if (loadingEl) {
                loadingEl.innerHTML = `
                    <div class="text-secondary small">
                        <i class="mdi mdi-alert-circle-outline"></i>
                        <p class="mt-1 mb-0">Could not load graph</p>
                    </div>
                `;
            }
        }
    }

    destroy() {
        this.graphViews.forEach(gv => gv.destroy());
        this.graphViews = [];
    }
}
