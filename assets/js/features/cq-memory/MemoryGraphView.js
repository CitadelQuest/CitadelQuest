import * as THREE from 'three';
import { OrbitControls } from 'three/examples/jsm/controls/OrbitControls.js';
import { CSS2DRenderer, CSS2DObject } from 'three/examples/jsm/renderers/CSS2DRenderer.js';
import { GraphLayoutEngine } from './GraphLayoutEngine';

/**
 * MemoryGraphView - Three.js based graph visualization for Spirit Memory
 * Renders nodes as spheres and relationships as lines with cyber theme
 */
export class MemoryGraphView {
    constructor(container, options = {}) {
        this.container = container;
        this.options = {
            backgroundColor: 0x0a0a0f,
            ...options
        };

        // Category colors
        this.categoryColors = {
            conversation: 0x4a9eff,
            knowledge: 0x4aff9e,
            preference: 0x9e4aff,
            thought: 0xff9e4a,
            fact: 0x4affff,
            default: 0x95ec86
        };

        // Relationship colors
        this.relationshipColors = {
            RELATES_TO: 0xffffff,
            DERIVED_FROM: 0xffff00,
            EVOLVED_INTO: 0x00ffff,
            PART_OF: 0x00ff00,
            CONTRADICTS: 0xff0000,
            REINFORCES: 0x0088ff,
            SUPERSEDES: 0xff8800,
            default: 0x666666
        };

        // Three.js components
        this.scene = null;
        this.camera = null;
        this.renderer = null;
        this.labelRenderer = null;
        this.controls = null;

        // Graph components
        this.layoutEngine = null;
        this.nodeMeshes = new Map();
        this.edgeLines = [];
        this.nodeGroup = null;
        this.edgeGroup = null;

        // Interaction
        this.raycaster = new THREE.Raycaster();
        this.mouse = new THREE.Vector2();
        this.hoveredNode = null;
        this.selectedNode = null;
        this.highlightedNeighborIds = new Set();
        this.onNodeSelect = null;
        this.onNodeHover = null;
        this.hoverLabel = null;

        // Filters
        this.filters = {
            categories: new Set(['conversation', 'knowledge', 'preference', 'thought', 'fact']),
            relationships: new Set(['RELATES_TO', 'DERIVED_FROM', 'EVOLVED_INTO', 'PART_OF', 'CONTRADICTS', 'REINFORCES', 'SUPERSEDES'])
        };

        // Data
        this.graphData = null;

        this.init();
    }

    /**
     * Initialize Three.js scene
     */
    init() {
        const canvas = this.container.querySelector('canvas');
        const width = this.container.clientWidth;
        const height = this.container.clientHeight;

        // Scene
        this.scene = new THREE.Scene();
        this.scene.background = new THREE.Color(this.options.backgroundColor);

        // Camera
        this.camera = new THREE.PerspectiveCamera(60, width / height, 0.1, 2000);
        this.camera.position.set(0, 0, 200);

        // Renderer
        this.renderer = new THREE.WebGLRenderer({
            canvas: canvas,
            antialias: true,
            alpha: true
        });
        this.renderer.setSize(width, height);
        this.renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2));

        // Label Renderer (CSS2D for hover labels)
        this.labelRenderer = new CSS2DRenderer();
        this.labelRenderer.setSize(width, height);
        this.labelRenderer.domElement.style.position = 'absolute';
        this.labelRenderer.domElement.style.top = '0';
        this.labelRenderer.domElement.style.left = '0';
        this.labelRenderer.domElement.style.pointerEvents = 'none';
        this.container.appendChild(this.labelRenderer.domElement);

        // Controls
        this.controls = new OrbitControls(this.camera, this.renderer.domElement);
        this.controls.enableDamping = true;
        this.controls.dampingFactor = 0.05;
        this.controls.rotateSpeed = 0.5;
        this.controls.zoomSpeed = 1.2;
        this.controls.minDistance = 50;
        this.controls.maxDistance = 500;

        // Groups
        this.nodeGroup = new THREE.Group();
        this.edgeGroup = new THREE.Group();
        this.scene.add(this.edgeGroup);
        this.scene.add(this.nodeGroup);

        // Lights
        const ambientLight = new THREE.AmbientLight(0xffffff, 0.6);
        this.scene.add(ambientLight);

        const pointLight = new THREE.PointLight(0x95ec86, 1, 500);
        pointLight.position.set(100, 100, 100);
        this.scene.add(pointLight);

        // Add subtle grid for depth reference
        this.addGrid();

        // Layout engine
        this.layoutEngine = new GraphLayoutEngine();

        // Event listeners
        this.setupEventListeners();

        // Start render loop
        this.animate();
    }

    /**
     * Add a subtle grid for visual depth reference (horizontal ground plane)
     */
    addGrid() {
        const gridHelper = new THREE.GridHelper(400, 20, 0x222222, 0x111111);
        // GridHelper is horizontal by default (XZ plane), position it below the graph
        gridHelper.position.y = -150;
        this.scene.add(gridHelper);
    }

    /**
     * Setup event listeners for interaction
     */
    setupEventListeners() {
        this.container.addEventListener('mousemove', (e) => this.onMouseMove(e));
        this.container.addEventListener('mousedown', (e) => this.onMouseDown(e));
        this.container.addEventListener('mouseup', (e) => this.onMouseUp(e));
        window.addEventListener('resize', () => this.onResize());
    }

    /**
     * Track mouse down position to distinguish click from drag
     */
    onMouseDown(event) {
        this.mouseDownPos = { x: event.clientX, y: event.clientY };
    }

    /**
     * Handle mouse up - only trigger click if not dragged
     */
    onMouseUp(event) {
        if (!this.mouseDownPos) return;
        
        const dx = Math.abs(event.clientX - this.mouseDownPos.x);
        const dy = Math.abs(event.clientY - this.mouseDownPos.y);
        const dragThreshold = 5; // pixels
        
        // Only treat as click if mouse didn't move much
        if (dx < dragThreshold && dy < dragThreshold) {
            this.onClick(event);
        }
        
        this.mouseDownPos = null;
    }

    /**
     * Handle mouse move for hover effects
     */
    onMouseMove(event) {
        const rect = this.container.getBoundingClientRect();
        this.mouse.x = ((event.clientX - rect.left) / rect.width) * 2 - 1;
        this.mouse.y = -((event.clientY - rect.top) / rect.height) * 2 + 1;

        this.raycaster.setFromCamera(this.mouse, this.camera);
        const intersects = this.raycaster.intersectObjects(this.nodeGroup.children);

        // Reset previous hover - but preserve neighbor highlight scale
        if (this.hoveredNode && this.hoveredNode !== this.selectedNode) {
            const isNeighbor = this.highlightedNeighborIds.has(this.hoveredNode.userData.id);
            this.setNodeScale(this.hoveredNode, isNeighbor ? 1.2 : 1.0);
        }

        if (intersects.length > 0) {
            const mesh = intersects[0].object;
            this.hoveredNode = mesh;
            
            if (mesh !== this.selectedNode) {
                this.setNodeScale(mesh, 1.3);
            }
            
            // Show hover label
            this.showHoverLabel(mesh.userData, mesh);
            
            this.container.style.cursor = 'pointer';
            
            if (this.onNodeHover) {
                this.onNodeHover(mesh.userData);
            }
        } else {
            this.hoveredNode = null;
            this.hideHoverLabel();
            this.container.style.cursor = 'default';
            
            if (this.onNodeHover) {
                this.onNodeHover(null);
            }
        }
    }

    /**
     * Handle click for node selection
     */
    onClick(event) {
        if (this.hoveredNode) {
            // Deselect previous selection and neighbors
            this.clearHighlights();

            this.selectedNode = this.hoveredNode;
            this.setNodeEmissive(this.selectedNode, 0x95ec86, 1.5);
            this.setNodeScale(this.selectedNode, 1.5);

            // Highlight neighbors and their connections
            this.highlightNeighbors(this.selectedNode.userData.id);

            // Dim non-selected nodes
            this.dimNonSelectedNodes();

            // Focus camera on selected node, if Ctrl is held down
            if (event.ctrlKey) {
                this.focusCameraOnNode(this.selectedNode);
            }

            if (this.onNodeSelect) {
                this.onNodeSelect(this.selectedNode.userData);
            }
        } else {
            // Click on empty space - deselect
            this.deselectNode();
        }
    }

    /**
     * Smoothly animate camera focus to a node
     */
    focusCameraOnNode(mesh) {
        const targetPos = mesh.position.clone();
        const startTarget = this.controls.target.clone();
        const duration = 500; // ms
        const startTime = Date.now();

        const animateTarget = () => {
            const elapsed = Date.now() - startTime;
            const progress = Math.min(elapsed / duration, 1);
            // Ease out cubic
            const eased = 1 - Math.pow(1 - progress, 3);

            this.controls.target.lerpVectors(startTarget, targetPos, eased);
            this.controls.update();

            if (progress < 1) {
                requestAnimationFrame(animateTarget);
            }
        };

        animateTarget();
    }

    /**
     * Deselect current node
     */
    deselectNode() {
        if (this.selectedNode) {
            this.clearHighlights();
            this.restoreNodeOpacity();
            this.selectedNode = null;

            if (this.onNodeDeselect) {
                this.onNodeDeselect();
            }
        }
    }

    /**
     * Clear all highlights (selected node and neighbors)
     */
    clearHighlights() {
        // Reset all nodes
        this.nodeMeshes.forEach(mesh => {
            this.setNodeEmissive(mesh, 0x000000);
            this.setNodeScale(mesh, 1.0);
        });

        // Reset all edges opacity
        this.edgeLines.forEach(line => {
            line.material.opacity = 0.4;
        });

        // Clear neighbor tracking
        this.highlightedNeighborIds.clear();
    }

    /**
     * Highlight neighbor nodes and their connections
     */
    highlightNeighbors(nodeId) {
        const neighborIds = new Set();

        // Find all edges connected to this node
        this.edgeLines.forEach(line => {
            const { sourceId, targetId } = line.userData;
            if (sourceId === nodeId) {
                neighborIds.add(targetId);
                line.material.opacity = 1.0; // Full opacity for connected edges
            } else if (targetId === nodeId) {
                neighborIds.add(sourceId);
                line.material.opacity = 1.0;
            }
        });

        // Highlight neighbor nodes with 50% intensity
        neighborIds.forEach(id => {
            const mesh = this.nodeMeshes.get(id);
            if (mesh && mesh !== this.selectedNode) {
                this.setNodeEmissive(mesh, 0x95ec86, 0.7); // 50% intensity
                this.setNodeScale(mesh, 1.2);
                this.highlightedNeighborIds.add(id); // Track for hover reset
            }
        });
    }

    /**
     * Dim non-selected and non-neighbor nodes
     */
    dimNonSelectedNodes() {
        this.nodeMeshes.forEach((mesh, nodeId) => {
            const isSelected = mesh === this.selectedNode;
            const isNeighbor = this.highlightedNeighborIds.has(nodeId);
            
            if (!isSelected && !isNeighbor) {
                mesh.material.opacity = 0.3;
            }
        });

        // Dim non-connected edges
        this.edgeLines.forEach(line => {
            if (line.material.opacity < 1.0) {
                line.material.opacity = 0.15;
            }
        });
    }

    /**
     * Restore all node opacity to full
     */
    restoreNodeOpacity() {
        this.nodeMeshes.forEach(mesh => {
            mesh.material.opacity = 1.0;
        });

        this.edgeLines.forEach(line => {
            line.material.opacity = 0.4;
        });
    }

    /**
     * Set node scale with animation
     */
    setNodeScale(mesh, scale) {
        const baseRadius = mesh.userData.baseRadius || 5;
        mesh.scale.setScalar(scale);
    }

    /**
     * Set node emissive color (glow effect)
     * @param {THREE.Mesh} mesh - The mesh to modify
     * @param {number} color - Hex color value
     * @param {number} intensity - Optional intensity (default: 0.5 for colored, 0 for black)
     */
    setNodeEmissive(mesh, color, intensity = null) {
        if (mesh.material) {
            mesh.material.emissive.setHex(color);
            mesh.material.emissiveIntensity = intensity !== null ? intensity : (color === 0x000000 ? 0 : 0.5);
        }
    }

    /**
     * Glow a node by its ID (for external hover effects like compilation panel)
     */
    glowNodeById(nodeId, glow) {
        const mesh = this.nodeMeshes.get(nodeId);
        if (!mesh) return;
        
        if (glow) {
            // Add glow effect and scale up
            this.setNodeEmissive(mesh, 0x95ec86, 1.5);
            this.setNodeScale(mesh, 1.5);
        } else {
            // Reset - check if it's a highlighted neighbor or selected
            const isSelected = this.selectedNode && this.selectedNode.userData.id === nodeId;
            const isNeighbor = this.highlightedNeighborIds.has(nodeId);
            
            if (isSelected) {
                this.setNodeEmissive(mesh, 0x95ec86, 1.0);
                this.setNodeScale(mesh, 1.5);
            } else if (isNeighbor) {
                this.setNodeEmissive(mesh, 0x95ec86, 0.7);
                this.setNodeScale(mesh, 1.2);
            } else {
                this.setNodeEmissive(mesh, 0x000000);
                this.setNodeScale(mesh, 1.0);
            }
        }
    }

    /**
     * Handle window resize
     */
    onResize() {
        const width = this.container.clientWidth;
        const height = this.container.clientHeight;

        this.camera.aspect = width / height;
        this.camera.updateProjectionMatrix();
        this.renderer.setSize(width, height);
        this.labelRenderer.setSize(width, height);
    }

    /**
     * Load and display graph data
     */
    loadGraph(data) {
        this.graphData = data;

        // Clear existing
        this.clearGraph();

        // Always initialize layout engine (even if empty) so dynamic addNode/addEdge works
        const nodes = data.nodes || [];
        const edges = data.edges || [];
        this.layoutEngine.initialize(nodes, edges);
        this.layoutEngine.setOnTick((nodes, links) => this.updatePositions(nodes, links));

        if (nodes.length === 0) {
            return;
        }

        // Create node meshes
        data.nodes.forEach(node => {
            const mesh = this.createNodeMesh(node);
            this.nodeGroup.add(mesh);
            this.nodeMeshes.set(node.id, mesh);
        });

        // Create edge lines (will be updated on tick)
        this.createEdgeLines(data.edges);

        // Focus camera on graph
        this.focusOnGraph();
    }

    /**
     * Create a mesh for a node
     */
    createNodeMesh(node) {
        const radius = this.getNodeRadius(node);
        const color = this.categoryColors[node.category] || this.categoryColors.default;

        const geometry = new THREE.SphereGeometry(radius, 16, 16);
        const material = new THREE.MeshStandardMaterial({
            color: color,
            metalness: 0.3,
            roughness: 0.7,
            emissive: 0x000000,
            emissiveIntensity: 0
        });

        const mesh = new THREE.Mesh(geometry, material);
        mesh.userData = { ...node, baseRadius: radius };

        return mesh;
    }

    /**
     * Get node radius based on importance
     * More dramatic size difference for better visibility
     */
    getNodeRadius(node) {
        const minRadius = 0.5;
        const maxRadius = 5;
        const importance = node.importance || 0.5;
        // Use quadratic scaling for more dramatic size difference
        return minRadius + (maxRadius - minRadius) * Math.pow(importance, 0.5);
    }

    /**
     * Create edge lines
     */
    createEdgeLines(edges) {
        const nodeById = new Map(this.graphData.nodes.map(n => [n.id, n]));

        edges.forEach(edge => {
            const sourceNode = nodeById.get(edge.source);
            const targetNode = nodeById.get(edge.target);

            if (!sourceNode || !targetNode) return;

            const color = this.relationshipColors[edge.type] || this.relationshipColors.default;
            const material = new THREE.LineBasicMaterial({
                color: color,
                transparent: true,
                opacity: 0.4
            });

            const geometry = new THREE.BufferGeometry();
            const positions = new Float32Array(6); // 2 points * 3 coords
            geometry.setAttribute('position', new THREE.BufferAttribute(positions, 3));

            const line = new THREE.Line(geometry, material);
            line.userData = { sourceId: edge.source, targetId: edge.target, edge: edge };
            line.frustumCulled = false; // Prevent lines from disappearing when endpoints are off-screen

            this.edgeGroup.add(line);
            this.edgeLines.push(line);
        });
    }

    /**
     * Update positions from layout engine
     * Maps d3 coordinates to Three.js: d3(x,y,z) → Three.js(x,z,y)
     * d3 X,Y = horizontal plane → Three.js X,Z
     * d3 Z = depth (from tags) → Three.js Y (vertical)
     */
    updatePositions(nodes, links) {
        // Update node positions with axis remapping
        nodes.forEach(node => {
            const mesh = this.nodeMeshes.get(node.id);
            if (mesh) {
                // Remap: d3(x,y,z) → Three.js(x, z, y)
                mesh.position.set(node.x, node.z, node.y);
            }
        });

        // Update edge positions
        this.edgeLines.forEach(line => {
            const sourceMesh = this.nodeMeshes.get(line.userData.sourceId);
            const targetMesh = this.nodeMeshes.get(line.userData.targetId);

            if (sourceMesh && targetMesh) {
                const positions = line.geometry.attributes.position.array;
                positions[0] = sourceMesh.position.x;
                positions[1] = sourceMesh.position.y;
                positions[2] = sourceMesh.position.z;
                positions[3] = targetMesh.position.x;
                positions[4] = targetMesh.position.y;
                positions[5] = targetMesh.position.z;
                line.geometry.attributes.position.needsUpdate = true;
            }
        });
    }

    /**
     * Focus camera on the graph
     */
    focusOnGraph() {
        if (this.graphData.nodes.length === 0) return;

        // Calculate bounding box after a short delay for layout to settle
        setTimeout(() => {
            const box = new THREE.Box3();
            this.nodeGroup.children.forEach(mesh => {
                box.expandByObject(mesh);
            });

            const center = box.getCenter(new THREE.Vector3());
            const size = box.getSize(new THREE.Vector3());
            const maxDim = Math.max(size.x, size.y, size.z);

            this.controls.target.copy(center);
            this.camera.position.set(
                center.x + maxDim * 0.8,
                center.y + maxDim * 0.5,
                center.z + maxDim * 1.2
            );
            this.controls.update();
        }, 500);
    }

    /**
     * Reset camera view (default 3D perspective)
     */
    resetView() {
        this.focusOnGraph();
        this.layoutEngine.reheat(0.5);
    }

    /**
     * Set camera to top-down view (bird's eye)
     */
    setTopView() {
        if (!this.graphData || this.graphData.nodes.length === 0) return;

        const box = new THREE.Box3();
        this.nodeGroup.children.forEach(mesh => {
            box.expandByObject(mesh);
        });

        const center = box.getCenter(new THREE.Vector3());
        const size = box.getSize(new THREE.Vector3());
        const maxDim = Math.max(size.x, size.z);

        this.controls.target.copy(center);
        this.camera.position.set(
            center.x,
            center.y + maxDim * 1.5,
            center.z
        );
        this.controls.update();
    }

    /**
     * Clear the graph
     */
    clearGraph() {
        // Clear nodes
        while (this.nodeGroup.children.length > 0) {
            const mesh = this.nodeGroup.children[0];
            mesh.geometry.dispose();
            mesh.material.dispose();
            this.nodeGroup.remove(mesh);
        }
        this.nodeMeshes.clear();

        // Clear edges
        while (this.edgeGroup.children.length > 0) {
            const line = this.edgeGroup.children[0];
            line.geometry.dispose();
            line.material.dispose();
            this.edgeGroup.remove(line);
        }
        this.edgeLines = [];

        // Clear selection
        this.selectedNode = null;
        this.hoveredNode = null;
    }

    /**
     * Animation loop
     */
    animate() {
        requestAnimationFrame(() => this.animate());

        this.controls.update();
        this.renderer.render(this.scene, this.camera);
        this.labelRenderer.render(this.scene, this.camera);
    }

    /**
     * Set node select callback
     */
    setOnNodeSelect(callback) {
        this.onNodeSelect = callback;
        return this;
    }

    /**
     * Set node hover callback
     */
    setOnNodeHover(callback) {
        this.onNodeHover = callback;
        return this;
    }

    /**
     * Set node deselect callback
     */
    setOnNodeDeselect(callback) {
        this.onNodeDeselect = callback;
        return this;
    }

    /**
     * Set filters and update visibility
     */
    setFilters(filters) {
        this.filters = filters;
        this.applyFilters();
    }

    /**
     * Apply filters to nodes and edges
     */
    applyFilters() {
        if (!this.graphData) return;

        // Filter nodes by category
        this.nodeMeshes.forEach((mesh, nodeId) => {
            const node = this.graphData.nodes.find(n => n.id === nodeId);
            if (node) {
                const visible = this.filters.categories.has(node.category);
                mesh.visible = visible;
            }
        });

        // Filter edges by relationship type and connected node visibility
        this.edgeLines.forEach(line => {
            const edge = line.userData.edge;
            if (edge) {
                const typeVisible = this.filters.relationships.has(edge.type);
                const sourceMesh = this.nodeMeshes.get(edge.source);
                const targetMesh = this.nodeMeshes.get(edge.target);
                const nodesVisible = sourceMesh?.visible && targetMesh?.visible;
                line.visible = typeVisible && nodesVisible;
            }
        });
    }

    /**
     * Show hover label for a node
     */
    showHoverLabel(node, mesh) {
        this.hideHoverLabel();

        if (!node || !mesh) return;

        const labelDiv = document.createElement('div');
        labelDiv.className = 'node-label';
        labelDiv.textContent = node.summary || '(no summary)';

        this.hoverLabel = new CSS2DObject(labelDiv);
        this.hoverLabel.position.set(0, mesh.geometry.parameters.radius + 2, 0);
        mesh.add(this.hoverLabel);
    }

    /**
     * Hide hover label
     */
    hideHoverLabel() {
        if (this.hoverLabel) {
            if (this.hoverLabel.parent) {
                this.hoverLabel.parent.remove(this.hoverLabel);
            }
            this.hoverLabel = null;
        }
    }

    /**
     * Add a new node to the graph dynamically (for real-time updates)
     */
    addNode(nodeData) {
        if (!this.graphData) return;

        // Check if node already exists
        if (this.nodeMeshes.has(nodeData.id)) {
            return;
        }

        // Add to graph data
        this.graphData.nodes.push(nodeData);

        // Add to layout engine
        this.layoutEngine.addNode(nodeData);

        // Create mesh with zero scale for entrance animation
        const mesh = this.createNodeMesh(nodeData);
        mesh.scale.set(0, 0, 0);
        mesh.material.opacity = 0;
        mesh.material.transparent = true;

        this.nodeGroup.add(mesh);
        this.nodeMeshes.set(nodeData.id, mesh);

        // Animate entrance
        this.animateNodeEntrance(nodeData.id);
    }

    /**
     * Add a new edge to the graph dynamically
     */
    addEdge(edgeData) {
        if (!this.graphData) return;

        // Check if edge already exists
        const exists = this.graphData.edges.some(e => e.id === edgeData.id);
        if (exists) {
            return;
        }

        // Add to graph data
        this.graphData.edges.push(edgeData);

        // Add to layout engine
        this.layoutEngine.addLink(edgeData);

        // Edge line will be created/updated on next tick
        this.createEdgeLines(this.graphData.edges);
    }

    /**
     * Animate node entrance with fade-in and scale-up
     */
    animateNodeEntrance(nodeId) {
        const mesh = this.nodeMeshes.get(nodeId);
        if (!mesh) return;

        const duration = 500; // ms
        const startTime = performance.now();

        const animate = (currentTime) => {
            const elapsed = currentTime - startTime;
            const progress = Math.min(elapsed / duration, 1);

            // Easing function (ease-out cubic)
            const eased = 1 - Math.pow(1 - progress, 3);

            // Scale up from 0 to 1
            mesh.scale.set(eased, eased, eased);

            // Fade in opacity
            mesh.material.opacity = eased;

            if (progress < 1) {
                requestAnimationFrame(animate);
            } else {
                // Animation complete - add glow effect
                this.glowNodeById(nodeId, true);
                
                // Remove glow after 2 seconds
                setTimeout(() => {
                    this.glowNodeById(nodeId, false);
                }, 2000);
            }
        };

        requestAnimationFrame(animate);
    }

    /**
     * Focus camera on a set of nodes (for new cluster visualization)
     */
    focusOnNodes(nodeIds) {
        if (!nodeIds || nodeIds.length === 0) return;

        const nodes = [];
        nodeIds.forEach(id => {
            const mesh = this.nodeMeshes.get(id);
            if (mesh) {
                nodes.push(mesh);
            }
        });

        if (nodes.length === 0) return;

        // Calculate bounding box of nodes
        const box = new THREE.Box3();
        nodes.forEach(mesh => {
            box.expandByObject(mesh);
        });

        const center = new THREE.Vector3();
        box.getCenter(center);

        const size = new THREE.Vector3();
        box.getSize(size);

        // Calculate camera distance based on box size
        const maxDim = Math.max(size.x, size.y, size.z);
        const fov = this.camera.fov * (Math.PI / 180);
        const distance = Math.abs(maxDim / Math.sin(fov / 2)) * 1.5;

        // Animate camera movement
        this.animateCameraTo(center, distance);
    }

    /**
     * Animate camera to target position
     */
    animateCameraTo(targetPosition, distance) {
        const duration = 1000; // ms
        const startTime = performance.now();
        
        const startPosition = this.camera.position.clone();
        const startTarget = this.controls.target.clone();

        const endPosition = new THREE.Vector3(
            targetPosition.x,
            targetPosition.y,
            targetPosition.z + distance
        );

        const animate = (currentTime) => {
            const elapsed = currentTime - startTime;
            const progress = Math.min(elapsed / duration, 1);

            // Easing function (ease-in-out)
            const eased = progress < 0.5
                ? 2 * progress * progress
                : 1 - Math.pow(-2 * progress + 2, 2) / 2;

            // Interpolate camera position
            this.camera.position.lerpVectors(startPosition, endPosition, eased);
            this.controls.target.lerpVectors(startTarget, targetPosition, eased);
            this.controls.update();

            if (progress < 1) {
                requestAnimationFrame(animate);
            }
        };

        requestAnimationFrame(animate);
    }

    /**
     * Get timestamp of last graph update (for delta queries)
     */
    getLastUpdateTimestamp() {
        if (!this.graphData || !this.graphData.nodes || this.graphData.nodes.length === 0) {
            return new Date().toISOString();
        }

        // Find most recent node creation time
        const mostRecent = this.graphData.nodes.reduce((latest, node) => {
            const nodeTime = new Date(node.createdAt);
            return nodeTime > latest ? nodeTime : latest;
        }, new Date(0));

        return mostRecent.toISOString();
    }

    /**
     * Dispose of all resources
     */
    dispose() {
        this.clearGraph();
        this.hideHoverLabel();
        
        if (this.layoutEngine) {
            this.layoutEngine.dispose();
        }

        if (this.controls) {
            this.controls.dispose();
        }

        if (this.renderer) {
            this.renderer.dispose();
        }

        if (this.labelRenderer && this.labelRenderer.domElement) {
            this.labelRenderer.domElement.remove();
        }

        this.scene = null;
        this.camera = null;
        this.renderer = null;
        this.labelRenderer = null;
    }
}
