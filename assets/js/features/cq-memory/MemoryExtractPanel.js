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
        this.isPanelVisible = false;
        
        // Pack target for extraction (set by parent)
        this.targetPack = null;
        this.projectId = 'general';
        
        // Synchronous step processing
        this.isProcessingSteps = false;
        this.currentJobId = null;
        
        this.init();
    }

    init() {
        this.setupEventListeners();
        this.loadPanelState();
        this.loadProjectFiles();
    }

    setupEventListeners() {
        // Toggle panel visibility
        const toggleBtn = document.getElementById('btn-toggle-extract-panel');
        if (toggleBtn) {
            toggleBtn.addEventListener('click', () => {
                this.togglePanel();
            });
        }

        // File browser file selection
        const fileSelector = document.getElementById('extract-file-selector');
        if (fileSelector) {
            fileSelector.addEventListener('change', (e) => {
                this.onFileSelected(e.target.value);
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
    }

    togglePanel() {
        const panel = document.getElementById('memory-extract-panel');
        const toggleBtn = document.getElementById('btn-toggle-extract-panel');
        
        if (!panel || !toggleBtn) return;

        this.isPanelVisible = !this.isPanelVisible;
        
        const t = window.memoryExplorerTranslations?.extract_panel || {};
        
        if (this.isPanelVisible) {
            panel.classList.remove('d-none');
            toggleBtn.innerHTML = `<i class="mdi mdi-chevron-up"></i> ${t.hide || 'Hide Memory Extraction Panel'}`;
        } else {
            panel.classList.add('d-none');
            toggleBtn.innerHTML = `<i class="mdi mdi-chevron-down"></i> ${t.show || 'Show Memory Extraction Panel'}`;
        }

        this.savePanelState();
    }

    savePanelState() {
        const key = this.spiritId ? `cqMemory-${this.spiritId}-extract-panel-visible` : 'cqMemory-global-extract-panel-visible';
        localStorage.setItem(key, this.isPanelVisible);
    }

    loadPanelState() {
        const key = this.spiritId ? `cqMemory-${this.spiritId}-extract-panel-visible` : 'cqMemory-global-extract-panel-visible';
        const savedState = localStorage.getItem(key);
        if (savedState === 'true') {
            this.isPanelVisible = false;
            this.togglePanel();
        }
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

        // Enable start button
        const startBtn = document.getElementById('btn-start-extraction');
        if (startBtn) {
            startBtn.disabled = false;
        }
    }

    /**
     * Set the target pack for extraction
     * Called by parent component when pack selection changes
     */
    setTargetPack(targetPack, projectId = 'general') {
        this.targetPack = targetPack;
        this.projectId = projectId;
    }

    async startExtraction() {
        const fileSelector = document.getElementById('extract-file-selector');
        const depthSlider = document.getElementById('extract-max-depth');
        const startBtn = document.getElementById('btn-start-extraction');
        const t = window.memoryExplorerTranslations?.extract_panel || {};
        
        if (!fileSelector || !fileSelector.value) {
            this.showError(t.select_file_first || 'Please select a file first');
            return;
        }

        // Validate pack target
        if (!this.targetPack) {
            this.showError(t.select_pack_first || 'Please select a Memory Pack first');
            return;
        }

        const sourceRef = fileSelector.value;
        const maxDepth = depthSlider ? parseInt(depthSlider.value) : 3;

        // Pause global polling BEFORE fetch to prevent pile-up
        updatesService.pause();

        // Disable button during request
        if (startBtn) {
            startBtn.disabled = true;
            startBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Starting...';
        }

        try {
            // Use new pack-based extraction endpoint
            const response = await fetch('/api/memory/pack/extract', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    targetPack: this.targetPack,
                    sourceType: 'document',
                    sourceRef: sourceRef,
                    maxDepth: maxDepth
                })
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
                
                // Add root node to graph immediately
                if (result.rootNode && this.onGraphDelta) {
                    console.log('📊 Adding root node to graph:', result.rootNode.id);
                    this.onGraphDelta({
                        nodes: [result.rootNode],
                        edges: []
                    });
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
                updatesService.resume();
                
                // Build graph delta from extraction result and push to 3D scene
                if (this.onGraphDelta && result.memories) {
                    const nodes = [];
                    const edges = [];
                    
                    // Add document summary node
                    if (result.documentSummary) {
                        nodes.push({
                            id: result.documentSummary.id,
                            content: result.documentSummary.content,
                            summary: 'Document Summary',
                            category: 'knowledge',
                            importance: 0.8,
                            tags: ['document', 'summary']
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
                                type: 'part_of',
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
            updatesService.resume();
        } finally {
            if (startBtn) {
                startBtn.disabled = false;
                startBtn.innerHTML = '<i class="mdi mdi-brain"></i> Start Extraction';
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
        console.log('🔄 Starting synchronous step-by-step extraction...');
        
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
                    throw new Error('Step processing failed');
                }
                
                const data = await response.json();
                
                if (!data.success) {
                    throw new Error(data.error || 'Step processing failed');
                }
                
                // Update job status
                if (data.job) {
                    this.activeJobs.set(data.job.id, data.job);
                    this.renderJobsList();
                }
                
                // Apply graph delta immediately
                if (data.delta && this.onGraphDelta) {
                    console.log('📊 Step delta:', {
                        nodes: data.delta.nodes?.length || 0,
                        edges: data.delta.edges?.length || 0
                    });
                    this.onGraphDelta(data.delta);
                }
                
                // Check if more steps remain
                if (!data.hasMoreSteps) {
                    console.log('✅ All extraction steps complete');
                    this.isProcessingSteps = false;
                    
                    // Resume global polling
                    updatesService.resume();
                    
                    // Clear jobs after delay
                    setTimeout(() => {
                        this.activeJobs.clear();
                        this.renderJobsList();
                        if (this.onExtractionComplete) {
                            this.onExtractionComplete();
                        }
                    }, 2000);
                    
                    break;
                }
                
                // Small delay between steps for UI responsiveness
                await new Promise(resolve => setTimeout(resolve, 100));
            }
            
        } catch (error) {
            console.error('Step processing error:', error);
            this.isProcessingSteps = false;
            this.showError('Extraction failed: ' + error.message);
            updatesService.resume();
        }
    }
    
    /**
     * Stop step processing
     */
    stopStepProcessing() {
        this.isProcessingSteps = false;
    }

    addJobToList(jobId, jobData) {
        this.activeJobs.set(jobId, jobData);
        this.renderJobsList();
    }

    updateJobProgress(jobs) {
        // Update active jobs with new progress data (legacy spirit jobs only)
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

        const html = Array.from(this.activeJobs.values()).map(job => {
            const percentage = job.totalSteps > 0 ? Math.round((job.progress / job.totalSteps) * 100) : 0;
            // Job is complete when status is 'completed' OR progress reaches totalSteps
            const isComplete = job.status === 'completed' || (job.progress === job.totalSteps && job.totalSteps > 0);
            const statusClass = job.status === 'processing' ? 'text-cyber' : 'text-secondary';
            
            // Icon: checkmark when complete, spinning cog when processing, clock when pending
            let icon = 'clock-outline';
            if (isComplete) {
                icon = 'check-circle';
            } else if (job.status === 'processing') {
                icon = 'cog mdi-spin';
            }
            
            return `
                <div class="extraction-job-item mb-3" data-job-id="${job.id}">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <small class="${isComplete ? 'text-success' : statusClass}">
                            <i class="mdi mdi-${icon}"></i>
                            ${this.getJobTypeLabel(job.type)}
                        </small>
                        <small class="text-secondary">${job.progress}/${job.totalSteps} ${t.steps || 'steps'}</small>
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
