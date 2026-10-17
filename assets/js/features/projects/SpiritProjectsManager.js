/**
 * SpiritProjectsManager — the "Projects" tab on the Spirit detail page.
 *
 * Lets you choose which Projects (workspaces) a Spirit belongs to. Loaded lazily
 * when the tab is shown. The Spirit-side counterpart of the project's "Spirits" picker.
 *
 * See docs/features/CQ-PROJECT.md.
 */
export class SpiritProjectsManager {
    constructor() {
        this.panel = document.getElementById('panel-projects');
        this.list = document.getElementById('spirit-projects-list');

        if (!this.panel || !this.list) {
            return;
        }

        this.spiritId = this.panel.dataset.spiritId;
        this.init();
    }

    init() {
        // Load lazily when the Projects tab is shown (Bootstrap fires shown.bs.tab).
        document.addEventListener('shown.bs.tab', (e) => {
            if (e.target.getAttribute('data-bs-target') === '#panel-projects') {
                this.render();
            }
        });

        document.getElementById('spirit-projects-save')?.addEventListener('click', () => this.save());
    }

    async render() {
        if (!this.spiritId) {
            return;
        }

        this.list.innerHTML = '<div class="text-secondary small p-3">Loading…</div>';

        try {
            const [projectsRes, assignedRes] = await Promise.all([
                fetch('/api/project').then(r => r.json()),
                fetch(`/api/spirit/${encodeURIComponent(this.spiritId)}/projects`).then(r => r.json())
            ]);

            const projects = projectsRes.projects || [];
            const assigned = new Set(assignedRes.projectIds || []);

            if (!projects.length) {
                this.list.innerHTML = '<div class="text-secondary small p-3">No projects yet.</div>';
                return;
            }

            this.list.innerHTML = '';
            projects.forEach((project) => {
                const id = `spirit-project-${project.id}`;

                const row = document.createElement('div');
                row.className = 'list-group-item bg-transparent d-flex align-items-center gap-2';

                const cb = document.createElement('input');
                cb.type = 'checkbox';
                cb.className = 'form-check-input mt-0';
                cb.id = id;
                cb.value = project.id;
                cb.checked = assigned.has(project.id);

                const label = document.createElement('label');
                label.className = 'mb-0 cursor-pointer';
                label.htmlFor = id;

                const title = document.createElement('span');
                title.textContent = project.title;

                const slug = document.createElement('span');
                slug.className = 'text-muted font-monospace small ms-2';
                slug.textContent = project.id;

                label.appendChild(title);
                label.appendChild(slug);

                row.appendChild(cb);
                row.appendChild(label);
                this.list.appendChild(row);
            });
        } catch (e) {
            this.list.innerHTML = '<div class="text-danger small p-3">Something went wrong</div>';
        }
    }

    async save() {
        const ids = Array.from(
            document.querySelectorAll('#spirit-projects-list input[type="checkbox"]:checked')
        ).map(cb => cb.value);

        try {
            const response = await fetch(`/api/spirit/${encodeURIComponent(this.spiritId)}/projects`, {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ projectIds: ids })
            });
            const data = await response.json();

            if (!data.success) {
                window.toast?.error(data.error || 'Something went wrong');
                return;
            }

            window.toast?.success('Projects updated');
        } catch (e) {
            window.toast?.error('Something went wrong');
            return;
        }

        // Best-effort: refresh the navbar Spirit dropdown so it reflects the change
        // (Spirits are project-scoped by the active project). Kept out of the save
        // try/catch so a refresh failure never looks like a save failure.
        try {
            window.spiritDropdownManager?.loadSpirits?.();
        } catch (e) {
            console.error('Failed to refresh Spirit dropdown', e);
        }
    }
}
