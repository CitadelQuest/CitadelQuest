/**
 * ProjectDropdownManager — the Project (workspace) dropdown in the navbar.
 *
 * Loads the user's projects, highlights the active one and switches the active
 * project (single source of truth, persisted as settings.active_project_id).
 * Switching reloads the page so the whole UI reflects the new workspace.
 *
 * See docs/features/CQ-PROJECT.md.
 */
export class ProjectDropdownManager {
    constructor() {
        this.list = document.getElementById('projects-list');
        this.label = document.getElementById('projectDropdownLabel');
        this.activeProjectId = null;

        if (!this.list) {
            return;
        }

        this.load();
    }

    async load() {
        try {
            const response = await fetch('/api/project');
            const data = await response.json();

            if (!data.success) {
                throw new Error(data.error || 'Failed to load projects');
            }

            this.activeProjectId = data.activeProjectId || 'general';
            // Expose globally so other components (e.g. the cqfile picker) can scope to it.
            window.cqActiveProjectId = this.activeProjectId;
            this.render(data.projects || []);
        } catch (error) {
            console.error('Error loading projects:', error);
            this.list.innerHTML = '<span class="dropdown-item text-muted">—</span>';
        }
    }

    render(projects) {
        if (!projects.length) {
            this.list.innerHTML = '<span class="dropdown-item text-muted">No projects</span>';
            return;
        }

        this.list.innerHTML = '';

        projects.forEach((project) => {
            const isActive = project.id === this.activeProjectId;

            if (isActive && this.label) {
                this.label.textContent = project.title;
            }

            const item = document.createElement('a');
            item.className = 'dropdown-item d-flex align-items-center justify-content-between' + (isActive ? ' active' : '');
            item.href = '#';
            item.dataset.projectId = project.id;

            const left = document.createElement('span');
            left.className = 'd-flex align-items-center';

            const icon = document.createElement('i');
            icon.className = `mdi mdi-briefcase-outline me-2 ${isActive ? 'text-cyber' : 'text-muted'}`;
            left.appendChild(icon);

            const textWrap = document.createElement('span');
            const title = document.createElement('span');
            title.className = 'fw-bold';
            title.textContent = project.title;
            textWrap.appendChild(title);

            if (project.isSystem) {
                const badge = document.createElement('span');
                badge.className = 'badge bg-secondary bg-opacity-25 text-light ms-2';
                badge.textContent = 'System';
                textWrap.appendChild(badge);
            }

            const idLine = document.createElement('div');
            idLine.className = 'small text-muted font-monospace';
            idLine.textContent = project.id;
            textWrap.appendChild(idLine);

            left.appendChild(textWrap);
            item.appendChild(left);

            if (isActive) {
                const check = document.createElement('i');
                check.className = 'mdi mdi-check text-cyber';
                item.appendChild(check);
            }

            item.addEventListener('click', (e) => {
                e.preventDefault();
                if (!isActive) {
                    this.activate(project.id);
                }
            });

            this.list.appendChild(item);
        });
    }

    async activate(projectId) {
        try {
            const response = await fetch('/api/project/active', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id: projectId })
            });
            const data = await response.json();

            if (!data.success) {
                window.toast?.error(data.error || 'Could not switch project');
                return;
            }

            // The File Browser URL carries the project id, so go to the active one
            // (otherwise the old id would re-apply the previous project on reload).
            if (window.location.pathname.startsWith('/file-browser')) {
                window.location.href = '/file-browser';
            } else {
                window.location.reload();
            }
        } catch (error) {
            window.toast?.error('Could not switch project');
        }
    }
}
