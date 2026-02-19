import { updatesService } from '../../services/UpdatesService';

/**
 * Memory Extract Panel Component
 * 
 * Provides GUI interface for manual memory extraction from files
 * with real-time progress visualization and job management
 */
export class MemoryExtractPanel {
    constructor(spiritId) {
        this.spiritId = spiritId;
        this.activeJobs = new Map();
        this.abortedJobIds = new Set();
        this.isPanelVisible = false;
        
        // Pack target for extraction (set by parent)
        this.targetPack = null;
        this.projectId = 'general';
        
        // Synchronous step processing
        this.isProcessingSteps = false;
        this.currentJobId = null;
        
        // Active source type: 'file', 'url', 'text', 'conversation'
        this.activeSourceType = 'file';
        
        // Selective root analysis state
        this.rootNodes = [];
        this.selectedRootIds = new Set();
        
        // Reference to 3D graph view for bidirectional hover sync
        this.graphView = null;
        
        // Conversation source data (cached after first load)
        this.conversationData = null;
        
        this.init();
    }

    init() {
        this.setupEventListeners();
        this.loadPanelState();
        this.loadProjectFiles();
    }

    setupEventListeners() {
        // Source type tab switching
        document.querySelectorAll('.extract-source-tabs .nav-link').forEach(tab => {
            tab.addEventListener('shown.bs.tab', (e) => {
                this.activeSourceType = e.target.id.replace('source-tab-', '');
                // Lazy-load conversations when conversation tab is first activated
                if (this.activeSourceType === 'conversation' && !this.conversationData) {
                    this.loadConversations();
                }
                this.updateStartButtonState();
            });
        });

        // File browser file selection
        const fileSelector = document.getElementById('extract-file-selector');
        if (fileSelector) {
            fileSelector.addEventListener('change', (e) => {
                this.onFileSelected(e.target.value);
            });
        }

        // URL input
        const urlInput = document.getElementById('extract-url-input');
        if (urlInput) {
            urlInput.addEventListener('input', () => this.updateStartButtonState());
        }

        // Text input + character count
        const textInput = document.getElementById('extract-text-input');
        if (textInput) {
            textInput.addEventListener('input', () => {
                const charCount = document.getElementById('extract-text-charcount');
                if (charCount) charCount.textContent = textInput.value.length;
                this.updateStartButtonState();
            });
        }

        // Spirit selector (for conversation source)
        const spiritSelector = document.getElementById('extract-spirit-selector');
        if (spiritSelector) {
            spiritSelector.addEventListener('change', (e) => {
                this.onSpiritSelected(e.target.value);
            });
        }

        // Conversation selector
        const convSelector = document.getElementById('extract-conversation-selector');
        if (convSelector) {
            convSelector.addEventListener('change', () => {
                this.updateStartButtonState();
            });
        }

        // Max depth slider
        const depthSlider = document.getElementById('extract-max-depth');
        const depthValue = document.getElementById('extract-max-depth-value');
        if (depthSlider && depthValue) {
            depthSlider.addEventListener('input', (e) => {
                depthValue.textContent = e.target.value;
            });
        }

        // Start extraction button
        const startBtn = document.getElementById('btn-start-extraction');
        if (startBtn) {
            startBtn.addEventListener('click', () => {
                this.startExtraction();
            });
        }

        // Event delegation for abort buttons in jobs list
        const jobsList = document.getElementById('extract-jobs-list');
        if (jobsList) {
            jobsList.addEventListener('click', (e) => {
                const abortBtn = e.target.closest('.btn-abort-job');
                if (abortBtn) {
                    const jobId = abortBtn.dataset.jobId;
                    if (jobId) this.abortJob(jobId);
                }
            });
        }

        // Start analysis button
        const analyzeBtn = document.getElementById('btn-start-analysis');
        if (analyzeBtn) {
            analyzeBtn.addEventListener('click', () => this.startAnalysis());
        }

        // Selective root analysis buttons
        const selectAllBtn = document.getElementById('btn-select-all-roots');
        if (selectAllBtn) {
            selectAllBtn.addEventListener('click', () => {
                this.rootNodes.forEach(n => this.selectedRootIds.add(n.id));
                this.renderRootsList();
                this.updateSelectedAnalysisButton();
            });
        }
        const deselectAllBtn = document.getElementById('btn-deselect-all-roots');
        if (deselectAllBtn) {
            deselectAllBtn.addEventListener('click', () => {
                this.selectedRootIds.clear();
                this.renderRootsList();
                this.updateSelectedAnalysisButton();
            });
        }
        const startSelectedBtn = document.getElementById('btn-start-selected-analysis');
        if (startSelectedBtn) {
            startSelectedBtn.addEventListener('click', () => this.startSelectedAnalysis());
        }

        // Event delegation for root checkboxes + hover sync
        const rootsList = document.getElementById('analyze-roots-list');
        if (rootsList) {
            rootsList.addEventListener('change', (e) => {
                const cb = e.target.closest('.root-node-checkbox');
                if (cb) {
                    if (cb.checked) {
                        this.selectedRootIds.add(cb.value);
                    } else {
                        this.selectedRootIds.delete(cb.value);
                    }
                    this.updateSelectedAnalysisButton();
                }
            });

            // Panel → 3D: hover root item glows corresponding node
            rootsList.addEventListener('mouseenter', (e) => {
                const item = e.target.closest('.root-node-item');
                if (item && this.graphView) {
                    const nodeId = item.dataset.nodeId;
                    if (nodeId) this.graphView.glowNodeById(nodeId, true);
                }
            }, true);

            rootsList.addEventListener('mouseleave', (e) => {
                const item = e.target.closest('.root-node-item');
                if (item && this.graphView) {
                    const nodeId = item.dataset.nodeId;
                    if (nodeId) this.graphView.glowNodeById(nodeId, false);
                }
            }, true);
        }

        // Auto-analyze toggle — persist state
        const autoAnalyzeToggle = document.getElementById('toggle-auto-analyze');
        if (autoAnalyzeToggle) {
            const saved = localStorage.getItem('cq_memory_auto_analyze');
            if (saved === 'false') autoAnalyzeToggle.checked = false;
            autoAnalyzeToggle.addEventListener('change', () => {
                localStorage.setItem('cq_memory_auto_analyze', autoAnalyzeToggle.checked);
            });
        }
    }

    togglePanel() {
        // Panel is now always visible in its tab - switch to extraction tab
        const tabEl = document.getElementById('tab-extraction');
        if (tabEl) {
            tabEl.click();
        }
    }

    savePanelState() {
        // No-op: panel is now a tab, always available
    }

    loadPanelState() {
        // No-op: panel is now a tab, always available
    }

    onFileSelected(sourceRef) {
        // Parse source_ref to extract file info
        if (!sourceRef) return;

        const parts = sourceRef.split(':');
        if (parts.length >= 3) {
            const fileName = parts[2];
            const fileDisplay = document.getElementById('extract-selected-file');
            if (fileDisplay) {
                fileDisplay.textContent = fileName;
            }
        }

        this.updateStartButtonState();
    }

    /**
     * Check if the current source type has valid input
     */
    hasValidSourceInput() {
        switch (this.activeSourceType) {
            case 'file':
                return !!document.getElementById('extract-file-selector')?.value;
            case 'url': {
                const url = document.getElementById('extract-url-input')?.value?.trim();
                return url && (url.startsWith('http://') || url.startsWith('https://'));
            }
            case 'text':
                return (document.getElementById('extract-text-input')?.value?.trim().length || 0) > 0;
            case 'conversation':
                return !!document.getElementById('extract-conversation-selector')?.value;
            default:
                return false;
        }
    }

    /**
     * Update start button enabled/disabled based on current source input and job state
     */
    updateStartButtonState() {
        const startBtn = document.getElementById('btn-start-extraction');
        if (!startBtn) return;
        if (this.isProcessingSteps || this.activeJobs.size > 0) {
            startBtn.disabled = true;
            return;
        }
        startBtn.disabled = !this.hasValidSourceInput();
    }

    /**
     * Set the target pack for extraction
     * Called by parent component when pack selection changes
     */
    setTargetPack(targetPack, projectId = 'general') {
        this.targetPack = targetPack;
        this.projectId = projectId;

        this.updateAnalyzeButtonState();
        
        // Load root nodes for selective analysis
        this.loadRootNodes();
        
        // Check for stalled jobs in this pack (e.g. after page refresh)
        this.checkForActiveJobs();
    }

    updateAnalyzeButtonState() {
        const btn = document.getElementById('btn-start-analysis');
        if (btn) {
            btn.disabled = !this.targetPack || this.isProcessingSteps || this.activeJobs.size > 0;
        }
        this.updateSelectedAnalysisButton();
    }

    /**
     * Check if the current pack has active (stalled) jobs and resume them
     */
    async checkForActiveJobs() {
        if (!this.targetPack || this.isProcessingSteps) return;
        
        try {
            const response = await fetch('/api/memory/pack/jobs', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    projectId: this.targetPack.projectId,
                    path: this.targetPack.path,
                    name: this.targetPack.name
                })
            });
            
            if (!response.ok) return;
            const data = await response.json();
            
            if (data.success && data.jobs && data.jobs.length > 0) {
                // Found stalled jobs — populate activeJobs and resume processing
                data.jobs.forEach(job => {
                    this.activeJobs.set(job.id, job);
                });
                this.renderJobsList();
                
                // Resume step processing for stalled jobs
                this.processStepsSequentially();
            }
        } catch (e) {
            // Non-fatal — just means we can't detect stalled jobs
            console.warn('Failed to check for active jobs:', e.message);
        }
    }

    /**
     * Build extraction request body based on active source type
     */
    getExtractionParams(maxDepth) {
        const autoAnalyzeToggle = document.getElementById('toggle-auto-analyze');
        const params = {
            targetPack: this.targetPack,
            maxDepth: maxDepth,
            skipAnalysis: autoAnalyzeToggle ? !autoAnalyzeToggle.checked : false
        };

        switch (this.activeSourceType) {
            case 'file':
                params.sourceType = 'document';
                params.sourceRef = document.getElementById('extract-file-selector')?.value;
                break;
            case 'url':
                params.sourceType = 'url';
                params.sourceRef = document.getElementById('extract-url-input')?.value?.trim();
                break;
            case 'text':
                params.sourceType = 'derived';
                params.content = document.getElementById('extract-text-input')?.value?.trim();
                params.documentTitle = 'Text Input';
                break;
            case 'conversation': {
                const convId = document.getElementById('extract-conversation-selector')?.value;
                params.sourceType = 'spirit_conversation';
                params.sourceRef = convId;
                // Get conversation title for documentTitle
                const convSelector = document.getElementById('extract-conversation-selector');
                const selectedOption = convSelector?.options[convSelector.selectedIndex];
                params.documentTitle = selectedOption?.textContent?.trim() || 'Spirit Conversation';
                break;
            }
        }

        return params;
    }

    async startExtraction() {
        const depthSlider = document.getElementById('extract-max-depth');
        const startBtn = document.getElementById('btn-start-extraction');
        const t = window.memoryExplorerTranslations?.extract_panel || {};
        
        if (!this.hasValidSourceInput()) {
            const msgs = {
                file: t.select_file_first || 'Please select a file first',
                url: t.enter_url_first || 'Please enter a valid URL',
                text: t.enter_text_first || 'Please enter some text',
                conversation: t.select_conversation_first || 'Please select a conversation first'
            };
            this.showError(msgs[this.activeSourceType] || 'Please provide input');
            return;
        }

        // Validate pack target
        if (!this.targetPack) {
            this.showError(t.select_pack_first || 'Please select a Memory Pack first');
            return;
        }

        const maxDepth = depthSlider ? parseInt(depthSlider.value) : 3;
        const extractionParams = this.getExtractionParams(maxDepth);

        // Disable button during request
        if (startBtn) {
            startBtn.disabled = true;
            startBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Starting...';
        }

        try {
            // Use pack-based extraction endpoint
            const response = await fetch('/api/memory/pack/extract', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(extractionParams)
            });

            if (!response.ok) {
                const error = await response.json();
                throw new Error(error.error || 'Extraction failed');
            }

            const result = await response.json();

            if (result.success && result.async) {
                // Job started successfully
                const msg = (t.extraction_started || 'Extraction started! Processing {steps} steps.')
                    .replace('{steps}', result.initialProgress?.totalSteps || '?');
                this.showSuccess(msg);
                
                this.currentJobId = result.jobId;
                
                // Add nodes to graph immediately
                if (this.onGraphDelta) {
                    if (result.rootNode) {
                        // Recursive extraction: single root node
                        this.onGraphDelta({ nodes: [result.rootNode], edges: [] });
                    } else if (result.memories) {
                        // Direct extraction + relationship analysis: all nodes at once
                        const nodes = [];
                        const edges = [];
                        if (result.documentSummary) {
                            nodes.push({
                                id: result.documentSummary.id,
                                content: result.documentSummary.content,
                                summary: result.documentSummary.summary || 'Document Summary',
                                category: result.documentSummary.category || 'knowledge',
                                importance: result.documentSummary.importance || 0.8,
                                tags: result.documentSummary.tags || ['document', 'summary'],
                                sourceType: result.documentSummary.sourceType,
                                sourceRef: result.documentSummary.sourceRef,
                                createdAt: result.documentSummary.createdAt
                            });
                        }
                        for (const mem of result.memories) {
                            nodes.push({
                                id: mem.id,
                                content: mem.content,
                                summary: mem.summary || '',
                                category: mem.category || 'knowledge',
                                importance: mem.importance || 0.5,
                                tags: mem.tags || []
                            });
                            if (result.documentSummary) {
                                edges.push({
                                    id: `rel-${mem.id}`,
                                    source: mem.id,
                                    target: result.documentSummary.id,
                                    type: 'PART_OF',
                                    strength: 0.9
                                });
                            }
                        }
                        this.onGraphDelta({ nodes, edges });
                    }
                }
                
                // Add job to tracking
                this.addJobToList(result.jobId, {
                    type: result.initialProgress?.type || 'extract_recursive',
                    progress: result.initialProgress?.progress || 0,
                    totalSteps: result.initialProgress?.totalSteps || 0,
                    status: 'pending',
                    packContext: this.targetPack
                });
                
                // Start synchronous step processing
                this.processStepsSequentially();
                
                // Notify parent component
                if (this.onExtractionStart) {
                    this.onExtractionStart();
                }
            } else if (result.success) {
                // Direct extraction (small file) - notify completion
                this.showSuccess(t.extraction_completed || 'Extraction completed!');
                
                // Build graph delta from extraction result and push to 3D scene
                if (this.onGraphDelta && result.memories) {
                    const nodes = [];
                    const edges = [];
                    
                    // Add document summary node
                    if (result.documentSummary) {
                        nodes.push({
                            id: result.documentSummary.id,
                            content: result.documentSummary.content,
                            summary: result.documentSummary.summary || 'Document Summary',
                            category: result.documentSummary.category || 'knowledge',
                            importance: result.documentSummary.importance || 0.8,
                            tags: result.documentSummary.tags || ['document', 'summary'],
                            sourceType: result.documentSummary.sourceType,
                            sourceRef: result.documentSummary.sourceRef,
                            createdAt: result.documentSummary.createdAt
                        });
                    }
                    
                    // Add extracted memory nodes + PART_OF edges
                    for (const mem of result.memories) {
                        nodes.push({
                            id: mem.id,
                            content: mem.content,
                            summary: mem.summary || '',
                            category: mem.category || 'knowledge',
                            importance: mem.importance || 0.5,
                            tags: mem.tags || []
                        });
                        
                        if (result.documentSummary) {
                            edges.push({
                                id: `rel-${mem.id}`,
                                source: mem.id,
                                target: result.documentSummary.id,
                                type: 'PART_OF',
                                strength: 0.9
                            });
                        }
                    }
                    
                    this.onGraphDelta({ nodes, edges });
                }
                
                // Notify parent to reload full graph (edges, stats)
                if (this.onExtractionComplete) {
                    this.onExtractionComplete(result);
                }
            } else {
                throw new Error(result.error || 'Unknown error');
            }

        } catch (error) {
            console.error('Extraction failed:', error);
            this.showError(error.message);
        } finally {
            if (startBtn) {
                // Restore button label but keep disabled if async jobs are running
                startBtn.innerHTML = '<i class="mdi mdi-excavator"></i> Start Extraction';
                if (!this.isProcessingSteps && this.activeJobs.size === 0) {
                    startBtn.disabled = false;
                }
            }
        }
    }

    /**
     * Process job steps sequentially - fetch one step, wait for response, apply to graph, repeat
     */
    async processStepsSequentially() {
        if (this.isProcessingSteps || !this.targetPack) {
            return;
        }
        
        this.isProcessingSteps = true;
        try {
            while (this.isProcessingSteps) {
                // Fetch and process ONE step
                const response = await fetch('/api/memory/pack/step', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        projectId: this.targetPack.projectId,
                        path: this.targetPack.path,
                        name: this.targetPack.name
                    })
                });
                
                if (!response.ok) {
                    // Retry on transient 5xx errors (e.g. 504 Gateway Timeout)
                    if (response.status >= 500 && response.status < 600) {
                        this.stepRetryCount = (this.stepRetryCount || 0) + 1;
                        if (this.stepRetryCount <= 3) {
                            console.warn(`Step returned ${response.status}, retry ${this.stepRetryCount}/3...`);
                            await new Promise(resolve => setTimeout(resolve, 2000 * this.stepRetryCount));
                            continue;
                        }
                    }
                    this.stepRetryCount = 0;
                    throw new Error('Step processing failed');
                }
                this.stepRetryCount = 0;
                
                const data = await response.json();
                
                if (!data.success) {
                    throw new Error(data.error || 'Step processing failed');
                }
                
                // Update job status (skip if user aborted this job)
                if (data.job && !this.abortedJobIds.has(data.job.id)) {
                    this.activeJobs.set(data.job.id, data.job);
                    this.renderJobsList();
                }
                
                // Apply graph delta immediately
                const hasNewData = (data.delta?.nodes?.length > 0) || (data.delta?.edges?.length > 0);
                if (hasNewData && this.onGraphDelta) {
                    this.onGraphDelta(data.delta);
                }
                
                // Check if more steps remain
                if (!data.hasMoreSteps) {
                    this.isProcessingSteps = false;
                    
                    // Clear jobs after delay and re-enable extraction button
                    setTimeout(() => {
                        this.activeJobs.clear();
                        this.renderJobsList();
                        this.enableStartButton();
                        if (this.onExtractionComplete) {
                            this.onExtractionComplete();
                        }
                    }, 2000);
                    
                    break;
                }
                
                // Back off when no work was done (e.g. concurrent lock held by backend)
                // Short delay for real steps, longer delay for no-ops to avoid flooding
                await new Promise(resolve => setTimeout(resolve, hasNewData ? 100 : 2000));
            }
            
        } catch (error) {
            console.error('Step processing error:', error);
            this.isProcessingSteps = false;
            this.showError('Extraction failed: ' + error.message);
            this.enableStartButton();
        }
    }
    
    /**
     * Stop step processing
     */
    stopStepProcessing() {
        this.isProcessingSteps = false;
    }

    /**
     * Start a full-pack relationship analysis job manually
     */
    async startAnalysis() {
        if (!this.targetPack) {
            this.showAnalyzeError('Please select a Memory Pack first');
            return;
        }

        const btn = document.getElementById('btn-start-analysis');
        if (btn) {
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Starting...';
        }

        try {
            const response = await fetch('/api/memory/pack/analyze', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    projectId: this.targetPack.projectId,
                    path: this.targetPack.path,
                    name: this.targetPack.name
                })
            });

            const result = await response.json();

            if (!result.success) throw new Error(result.error || 'Failed to start analysis');

            this.addJobToList(result.jobId, {
                type: result.initialProgress?.type || 'analyze_relationships',
                progress: result.initialProgress?.progress || 0,
                totalSteps: result.initialProgress?.totalSteps || 0,
                status: 'processing',
                packContext: this.targetPack
            });

            this.processStepsSequentially();

        } catch (error) {
            console.error('Analysis failed:', error);
            this.showAnalyzeError(error.message);
        } finally {
            if (btn) {
                const t = window.memoryExplorerTranslations?.extract_panel || {};
                btn.innerHTML = `<i class="mdi mdi-graph me-1"></i> ${t.start_analysis || 'Start Full Pack Analysis'}`;
                this.updateAnalyzeButtonState();
            }
        }
    }

    /**
     * Load root nodes (depth=0) from current pack's graph data for selective analysis
     */
    async loadRootNodes() {
        this.rootNodes = [];
        this.selectedRootIds.clear();

        if (!this.targetPack) {
            this.renderRootsList();
            this.updateSelectedAnalysisButton();
            return;
        }

        try {
            const response = await fetch('/api/memory/pack/open', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    projectId: this.targetPack.projectId,
                    path: this.targetPack.path,
                    name: this.targetPack.name
                })
            });

            if (!response.ok) return;
            const data = await response.json();

            if (data.success && data.nodes) {
                this.rootNodes = data.nodes.filter(n => n.depth === 0);
            }
        } catch (e) {
            console.warn('Failed to load root nodes:', e.message);
        }

        this.renderRootsList();
        this.updateSelectedAnalysisButton();
    }

    /**
     * Render the root nodes checklist in the Analyze tab
     */
    renderRootsList() {
        const container = document.getElementById('analyze-roots-list');
        if (!container) return;

        const t = window.memoryExplorerTranslations?.extract_panel || {};

        if (this.rootNodes.length === 0) {
            container.innerHTML = `<div class="text-secondary small text-center py-2">${t.no_roots || 'No documents in this pack'}</div>`;
            return;
        }

        const html = this.rootNodes.map(node => {
            const checked = this.selectedRootIds.has(node.id) ? 'checked' : '';
            const title = this.truncate(node.title || node.content || node.id, 45);
            return `
                <label class="root-node-item d-flex align-items-start gap-2 py-1 px-1" data-node-id="${node.id}">
                    <input type="checkbox" class="root-node-checkbox form-check-input mt-1" value="${node.id}" ${checked}>
                    <span class="small text-light" title="${(node.title || '').replace(/"/g, '&quot;')}">${title}</span>
                </label>
            `;
        }).join('');

        container.innerHTML = html;
    }

    /**
     * Update the "Analyze Selected" button state based on selection count
     */
    updateSelectedAnalysisButton() {
        const btn = document.getElementById('btn-start-selected-analysis');
        const badge = document.getElementById('selected-roots-count');
        if (!btn) return;

        const count = this.selectedRootIds.size;
        btn.disabled = count === 0 || !this.targetPack || this.isProcessingSteps || this.activeJobs.size > 0;

        if (badge) {
            badge.textContent = count;
            badge.classList.toggle('d-none', count === 0);
        }
    }

    /**
     * Start selective relationship analysis for chosen root nodes only
     */
    async startSelectedAnalysis() {
        if (!this.targetPack || this.selectedRootIds.size === 0) return;

        const btn = document.getElementById('btn-start-selected-analysis');
        if (btn) {
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Starting...';
        }

        try {
            const response = await fetch('/api/memory/pack/analyze-selected', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    projectId: this.targetPack.projectId,
                    path: this.targetPack.path,
                    name: this.targetPack.name,
                    rootNodeIds: Array.from(this.selectedRootIds)
                })
            });

            const result = await response.json();

            if (!result.success) throw new Error(result.error || 'Failed to start analysis');

            this.addJobToList(result.jobId, {
                type: result.initialProgress?.type || 'analyze_relationships',
                progress: result.initialProgress?.progress || 0,
                totalSteps: result.initialProgress?.totalSteps || 0,
                status: 'processing',
                packContext: this.targetPack
            });

            this.processStepsSequentially();

        } catch (error) {
            console.error('Selected analysis failed:', error);
            this.showAnalyzeError(error.message);
        } finally {
            if (btn) {
                const t = window.memoryExplorerTranslations?.extract_panel || {};
                btn.innerHTML = `<i class="mdi mdi-graph me-1"></i> ${t.analyze_selected || 'Analyze Selected'} <span id="selected-roots-count" class="badge bg-cyber text-dark ms-1 ${this.selectedRootIds.size === 0 ? 'd-none' : ''}">${this.selectedRootIds.size}</span>`;
                this.updateSelectedAnalysisButton();
            }
        }
    }

    showAnalyzeError(message) {
        const alert = document.getElementById('analyze-alert');
        if (!alert) return;
        alert.className = 'alert alert-danger alert-sm mt-2 small p-2';
        alert.textContent = message;
        setTimeout(() => alert.classList.add('d-none'), 5000);
    }

    /**
     * Abort (cancel) a specific active job
     * Marks job as cancelled on server; step loop continues and skips cancelled jobs naturally
     */
    async abortJob(jobId) {
        if (!this.targetPack) return;

        const t = window.memoryExplorerTranslations?.extract_panel || {};
        if (!confirm(t.abort_confirm || 'Abort this job? This cannot be undone.')) return;

        this.abortedJobIds.add(jobId);

        try {
            const response = await fetch('/api/memory/pack/job/abort', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    projectId: this.targetPack.projectId,
                    path: this.targetPack.path,
                    name: this.targetPack.name,
                    jobId: jobId
                })
            });

            const data = await response.json();
            if (!data.success) {
                this.abortedJobIds.delete(jobId);
                throw new Error(data.error || 'Failed to abort job');
            }

            this.activeJobs.delete(jobId);
            this.renderJobsList();

        } catch (error) {
            console.error('Failed to abort job:', error);
            this.showError('Failed to abort: ' + error.message);
        }
    }

    /**
     * Re-enable extraction start button (called when all jobs finish)
     */
    enableStartButton() {
        const startBtn = document.getElementById('btn-start-extraction');
        if (startBtn && this.hasValidSourceInput()) {
            startBtn.disabled = false;
        }
        this.updateAnalyzeButtonState();
    }

    addJobToList(jobId, jobData) {
        this.activeJobs.set(jobId, jobData);
        this.renderJobsList();
    }

    updateJobProgress(jobs) {
        // Update active jobs with new progress data
        jobs.forEach(job => {
            if (job.spiritId === this.spiritId) {
                this.activeJobs.set(job.id, job);
            }
        });

        this.renderJobsList();
    }

    renderJobsList() {
        const container = document.getElementById('extract-jobs-list');
        if (!container) return;

        const t = window.memoryExplorerTranslations?.extract_panel || {};
        
        if (this.activeJobs.size === 0) {
            container.innerHTML = `<div class="text-secondary small text-center py-3">${t.no_jobs || 'No active extraction jobs'}</div>`;
            return;
        }

        const statusOrder = { 'processing': 0, 'pending': 1, 'completed': 2, 'cancelled': 3, 'failed': 3 };
        const sortedJobs = Array.from(this.activeJobs.values()).sort((a, b) =>
            (statusOrder[a.status] ?? 4) - (statusOrder[b.status] ?? 4)
        );

        const html = sortedJobs.map(job => {
            const percentage = job.totalSteps > 0 ? Math.round((job.progress / job.totalSteps) * 100) : 0;
            // Job is complete when status is 'completed' OR progress reaches totalSteps
            const isComplete = job.status === 'completed' || (job.progress === job.totalSteps && job.totalSteps > 0);
            const statusClass = job.status === 'processing' ? 'text-cyber' : 'text-secondary';
            
            // Icon: checkmark when complete, spinning cog when processing, clock when pending
            let icon = 'clock-outline';
            if (isComplete) {
                icon = 'check-circle';
            } else /* if (job.status === 'processing') */ {
                icon = 'cog mdi-spin text-info';
            }
            
            return `
                <div class="extraction-job-item mb-3" data-job-id="${job.id}">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <small class="${isComplete ? 'text-success' : statusClass}">
                            <i class="mdi mdi-${icon}"></i>
                            ${this.getJobTypeLabel(job.type)}
                        </small>
                        <div class="d-flex align-items-center gap-2">
                            <small class="text-secondary">${job.progress}/${job.totalSteps} ${t.steps || 'steps'}</small>
                            ${!isComplete ? `<button class="btn btn-abort-job p-0 border-0 bg-transparent text-danger" data-job-id="${job.id}" title="${t.abort_job || 'Abort job'}" style="line-height:1;"><i class="mdi mdi-close-circle" style="font-size:1rem;"></i></button>` : ''}
                        </div>
                    </div>
                    <div class="progress mb-1" style="height: 6px;">
                        <div class="progress-bar ${isComplete ? 'bg-success' : 'bg-cyber'}" role="progressbar" 
                             style="width: ${percentage}%"
                             aria-valuenow="${percentage}" 
                             aria-valuemin="0" 
                             aria-valuemax="100">
                        </div>
                    </div>
                    ${job.currentBlock ? `
                        <div class="small text-secondary" style="font-size: 0.75rem; opacity: 0.8;">
                            <i class="mdi mdi-file-document-outline"></i> ${this.truncate(job.currentBlock, 50)}
                        </div>
                    ` : ''}
                </div>
            `;
        }).join('');

        container.innerHTML = html;
        this.updateAnalyzeButtonState();
    }

    removeCompletedJob(jobId) {
        this.activeJobs.delete(jobId);
        this.renderJobsList();
    }

    getJobTypeLabel(type) {
        const t = window.memoryExplorerTranslations?.extract_panel?.job_types || {};
        const labels = {
            'extract_recursive': t.extract_recursive || 'Extracting Memories',
            'analyze_relationships': t.analyze_relationships || 'Analyzing Relationships'
        };
        return labels[type] || type;
    }

    truncate(text, maxLength) {
        if (text.length <= maxLength) return text;
        return text.substring(0, maxLength) + '...';
    }

    showSuccess(message) {
        const alert = document.getElementById('extract-alert');
        if (!alert) return;

        alert.className = 'alert alert-success mb-3';
        alert.innerHTML = `<i class="mdi mdi-check-circle me-2"></i>${message}`;
        alert.classList.remove('d-none');

        setTimeout(() => {
            alert.classList.add('d-none');
        }, 5000);
    }

    showError(message) {
        const alertDiv = document.getElementById('extract-alert');
        if (!alertDiv) return;

        alertDiv.className = 'alert alert-danger alert-dismissible fade show';
        alertDiv.innerHTML = `
            <i class="mdi mdi-alert-circle me-2"></i>${message}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        `;
        alertDiv.classList.remove('d-none');
    }

    /**
     * Load spirits and their conversations for the conversation source type
     * Data is fetched once and cached for subsequent Spirit selector changes
     */
    async loadConversations() {
        const spiritSelector = document.getElementById('extract-spirit-selector');
        if (!spiritSelector) return;

        try {
            const response = await fetch('/memory/conversations');
            if (!response.ok) {
                console.error('Failed to load conversations');
                return;
            }

            const data = await response.json();
            if (!data.spirits) {
                console.error('Invalid conversations response');
                return;
            }

            this.conversationData = data.spirits;

            // Populate spirit selector
            if (this.conversationData.length === 0) {
                const t = window.memoryExplorerTranslations?.extract_panel || {};
                spiritSelector.innerHTML = `<option value="">${t.no_spirits || '-- No Spirits found --'}</option>`;
                return;
            }

            const t = window.memoryExplorerTranslations?.extract_panel || {};
            let options = `<option value="">-- ${t.select_spirit || 'Select Spirit'} --</option>`;
            for (const spirit of this.conversationData) {
                const convCount = spirit.conversations.length;
                options += `<option value="${spirit.id}">${spirit.name} (${convCount})</option>`;
            }
            spiritSelector.innerHTML = options;

        } catch (error) {
            console.error('Error loading conversations:', error);
        }
    }

    /**
     * Handle Spirit selection change - populate conversation selector
     */
    onSpiritSelected(spiritId) {
        const convSelector = document.getElementById('extract-conversation-selector');
        if (!convSelector) return;

        const t = window.memoryExplorerTranslations?.extract_panel || {};

        if (!spiritId || !this.conversationData) {
            convSelector.innerHTML = `<option value="">-- ${t.select_conversation || 'Select Conversation'} --</option>`;
            convSelector.disabled = true;
            this.updateStartButtonState();
            return;
        }

        // Find conversations for the selected spirit
        const spirit = this.conversationData.find(s => s.id === spiritId);
        if (!spirit || spirit.conversations.length === 0) {
            convSelector.innerHTML = `<option value="">${t.no_conversations || '-- No conversations --'}</option>`;
            convSelector.disabled = true;
            this.updateStartButtonState();
            return;
        }

        // Sort by created_at descending (newest first)
        const sorted = [...spirit.conversations].sort((a, b) => {
            return (b.createdAt || '').localeCompare(a.createdAt || '');
        });

        let options = `<option value="">-- ${t.select_conversation || 'Select Conversation'} --</option>`;
        for (const conv of sorted) {
            options += `<option value="${conv.id}">${conv.title} (${conv.createdAt})</option>`;
        }
        convSelector.innerHTML = options;
        convSelector.disabled = false;
        this.updateStartButtonState();
    }

    async loadProjectFiles() {
        const fileSelector = document.getElementById('extract-file-selector');
        if (!fileSelector) return;

        try {
            // Fetch project tree from 'general' project
            const response = await fetch('/api/project-file/general/tree');
            
            if (!response.ok) {
                console.error('Failed to load project files');
                return;
            }

            const data = await response.json();
            
            if (!data.success || !data.tree) {
                console.error('Invalid tree response');
                return;
            }

            // Extract all files (not directories) from tree
            const files = this.extractFilesFromTree(data.tree);
            
            // Filter for text-based files
            const textFiles = files.filter(file => this.isTextBasedFile(file.name));
            
            // Populate dropdown
            if (textFiles.length === 0) {
                fileSelector.innerHTML = '<option value="">-- No text files found --</option>';
                return;
            }

            // Build options HTML
            const options = textFiles.map(file => {
                // Split fullPath into path and filename
                // Example: /uploads/file.pdf -> path: /uploads, filename: file.pdf
                const lastSlashIndex = file.fullPath.lastIndexOf('/');
                const path = file.fullPath.substring(0, lastSlashIndex) || '/';
                const filename = file.fullPath.substring(lastSlashIndex + 1);
                
                // Format: projectId:path:filename (e.g., "general:/uploads:file.pdf")
                const sourceRef = `general:${path}:${filename}`;
                const displayPath = file.fullPath.startsWith('/') ? file.fullPath.slice(1) : file.fullPath;
                return `<option value="${sourceRef}">${displayPath}</option>`;
            }).join('');

            fileSelector.innerHTML = '<option value="">-- Select a file --</option>' + options;

        } catch (error) {
            console.error('Error loading project files:', error);
        }
    }

    extractFilesFromTree(node, parentPath = '') {
        const files = [];
        
        // Files have their actual type (txt, md, pdf, etc.), directories have type 'directory'
        const isDirectory = node.type === 'directory' || node.type === 'projectRootDirectory';
        
        if (!isDirectory) {
            // This is a file
            const fullPath = parentPath + '/' + node.name;
            files.push({
                name: node.name,
                fullPath: fullPath
            });
        }

        if (node.children && Array.isArray(node.children)) {
            const currentPath = node.type === 'projectRootDirectory' ? '' : parentPath + '/' + node.name;
            
            for (const child of node.children) {
                files.push(...this.extractFilesFromTree(child, currentPath));
            }
        }

        return files;
    }

    isTextBasedFile(filename) {
        const textExtensions = [
            'md', 'markdown', 'txt', 'text',
            'pdf',
            'json', 'xml', 'yaml', 'yml',
            'html', 'htm', 'css', 'scss', 'sass',
            'js', 'ts', 'jsx', 'tsx',
            'php', 'py', 'rb', 'java', 'c', 'cpp', 'h', 'hpp',
            'sh', 'bash', 'zsh',
            'sql', 'csv',
            'log', 'conf', 'config', 'ini',
            'rst', 'tex'
        ];

        const ext = filename.split('.').pop()?.toLowerCase();
        return ext && textExtensions.includes(ext);
    }
}
