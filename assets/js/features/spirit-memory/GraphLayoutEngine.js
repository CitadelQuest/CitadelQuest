import { forceSimulation, forceLink, forceManyBody, forceCenter, forceCollide } from 'd3-force';

/**
 * GraphLayoutEngine - Uses d3-force for node positioning in 3D space
 * Calculates positions only, rendering handled by MemoryGraphView
 */
export class GraphLayoutEngine {
    constructor(options = {}) {
        this.options = {
            linkDistance: 50,
            chargeStrength: -15,   // Reduced repulsion
            centerStrength: 0.9,   // Strong pull toward center
            collisionRadius: 15,
            alphaDecay: 0.02,
            velocityDecay: 0.4,
            ...options
        };

        this.simulation = null;
        this.nodes = [];
        this.links = [];
        this.onTick = null;
        this.onEnd = null;
    }

    /**
     * Initialize the force simulation with graph data
     * @param {Array} nodes - Array of node objects with id, importance, category
     * @param {Array} edges - Array of edge objects with source, target, type
     */
    initialize(nodes, edges) {
        // Prepare nodes with initial random 3D positions
        this.nodes = nodes.map(node => ({
            ...node,
            x: (Math.random() - 0.5) * 200,
            y: (Math.random() - 0.5) * 200,
            z: (Math.random() - 0.5) * 200,
            vx: 0,
            vy: 0,
            vz: 0
        }));

        // Create node lookup for links
        const nodeById = new Map(this.nodes.map(n => [n.id, n]));

        // Prepare links with node references
        this.links = edges
            .filter(e => nodeById.has(e.source) && nodeById.has(e.target))
            .map(edge => ({
                ...edge,
                source: nodeById.get(edge.source),
                target: nodeById.get(edge.target)
            }));

        // Create 2D force simulation (we'll extend to 3D manually)
        this.simulation = forceSimulation(this.nodes)
            .force('link', forceLink(this.links)
                .id(d => d.id)
                .distance(this.options.linkDistance)
                .strength(0.5))
            .force('charge', forceManyBody()
                .strength(this.options.chargeStrength))
            .force('center', forceCenter(0, 0)
                .strength(this.options.centerStrength))
            .force('collision', forceCollide()
                .radius(d => this.getNodeRadius(d) + 5))
            .alphaDecay(this.options.alphaDecay)
            .velocityDecay(this.options.velocityDecay)
            .on('tick', () => this.tick())
            .on('end', () => this.end());

        return this;
    }

    /**
     * Get node radius based on importance
     */
    getNodeRadius(node) {
        const minRadius = 3;
        const maxRadius = 12;
        const importance = node.importance || 0.5;
        return minRadius + (maxRadius - minRadius) * importance;
    }

    /**
     * Called on each simulation tick
     */
    tick() {
        // Position nodes on Z axis based on depth tags
        this.nodes.forEach(node => {
            const depth = this.getNodeDepth(node);
            const depthSpacing = 40; // Distance between depth levels
            
            // Calculate target Z based on depth (root=0 at top, deeper nodes below)
            const targetZ = -depth * depthSpacing;
            
            // Smoothly move toward target Z
            node.z = node.z * 0.9 + targetZ * 0.1;
        });

        if (this.onTick) {
            this.onTick(this.nodes, this.links);
        }
    }

    /**
     * Get node depth from tags (document, root, depth-0, depth-1, etc.)
     */
    getNodeDepth(node) {
        const tags = node.tags || [];
        
        // Check for depth-N tags
        for (const tag of tags) {
            const match = tag.match(/^depth-(\d+)$/);
            if (match) {
                return parseInt(match[1], 10);
            }
        }
        
        // Check for special tags
        if (tags.includes('root') || tags.includes('document')) {
            return 0;
        }
        if (tags.includes('section')) {
            return 1;
        }
        if (tags.includes('subsection')) {
            return 2;
        }
        
        // Default depth based on category as fallback
        return this.getCategoryDepth(node.category);
    }

    /**
     * Fallback depth based on category
     */
    getCategoryDepth(category) {
        const depths = {
            'knowledge': 1,
            'fact': 2,
            'conversation': 3,
            'thought': 3,
            'preference': 2
        };
        return depths[category] || 2;
    }

    /**
     * Called when simulation ends
     */
    end() {
        if (this.onEnd) {
            this.onEnd(this.nodes, this.links);
        }
    }

    /**
     * Set tick callback
     */
    setOnTick(callback) {
        this.onTick = callback;
        return this;
    }

    /**
     * Set end callback
     */
    setOnEnd(callback) {
        this.onEnd = callback;
        return this;
    }

    /**
     * Reheat the simulation (after user interaction)
     */
    reheat(alpha = 0.3) {
        if (this.simulation) {
            this.simulation.alpha(alpha).restart();
        }
        return this;
    }

    /**
     * Stop the simulation
     */
    stop() {
        if (this.simulation) {
            this.simulation.stop();
        }
        return this;
    }

    /**
     * Get current node positions
     */
    getNodePositions() {
        return this.nodes.map(n => ({
            id: n.id,
            x: n.x,
            y: n.y,
            z: n.z
        }));
    }

    /**
     * Fix a node position (for dragging)
     */
    fixNode(nodeId, x, y, z) {
        const node = this.nodes.find(n => n.id === nodeId);
        if (node) {
            node.fx = x;
            node.fy = y;
            node.fz = z;
        }
        return this;
    }

    /**
     * Release a fixed node
     */
    releaseNode(nodeId) {
        const node = this.nodes.find(n => n.id === nodeId);
        if (node) {
            node.fx = null;
            node.fy = null;
            node.fz = null;
        }
        return this;
    }

    /**
     * Dispose of resources
     */
    dispose() {
        this.stop();
        this.simulation = null;
        this.nodes = [];
        this.links = [];
    }
}
