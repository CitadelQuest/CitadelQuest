/**
 * ProjectsManager — the /projects page.
 *
 * Handles create / edit / delete (cascade, typed confirmation) and setting the
 * active project. Reloads the page after a successful mutation.
 *
 * See docs/features/CQ-PROJECT.md.
 */
import * as bootstrap from 'bootstrap';

export class ProjectsManager {
    constructor() {
        this.page = document.getElementById('projects-page');
        if (!this.page) {
            return;
        }

        this.translations = JSON.parse(this.page.dataset.translations || '{}');
        this.formModalEl = document.getElementById('projectFormModal');
        this.deleteModalEl = document.getElementById('projectDeleteModal');
        this.spiritsModalEl = document.getElementById('projectSpiritsModal');
        this.formModal = this.formModalEl ? new bootstrap.Modal(this.formModalEl) : null;
        this.deleteModal = this.deleteModalEl ? new bootstrap.Modal(this.deleteModalEl) : null;
        this.spiritsModal = this.spiritsModalEl ? new bootstrap.Modal(this.spiritsModalEl) : null;

        this.editingId = null;
        this.deletingId = null;
        this.spiritsProjectId = null;

        this.init();
    }

    init() {
        document.getElementById('project-new-btn')?.addEventListener('click', () => this.openCreate());
        document.getElementById('project-form-save')?.addEventListener('click', () => this.saveForm());
        document.getElementById('project-spirits-save')?.addEventListener('click', () => this.saveSpirits());

        const confirmInput = document.getElementById('project-delete-confirm-input');
        confirmInput?.addEventListener('input', () => {
            const btn = document.getElementById('project-delete-confirm-btn');
            if (btn) {
                btn.disabled = confirmInput.value.trim() !== this.deletingId;
            }
        });
        document.getElementById('project-delete-confirm-btn')?.addEventListener('click', () => this.confirmDelete());

        document.querySelectorAll('.project-card').forEach((card) => {
            card.querySelector('[data-action="activate"]')
                ?.addEventListener('click', () => this.activate(card.dataset.projectId));
            card.querySelector('[data-action="edit"]')
                ?.addEventListener('click', () => this.openEdit(card));
            card.querySelector('[data-action="spirits"]')
                ?.addEventListener('click', () => this.openSpirits(card.dataset.projectId, card.dataset.projectTitle));
            card.querySelector('[data-action="delete"]')
                ?.addEventListener('click', () => this.openDelete(card.dataset.projectId));
        });

        // Deep link: /projects?spirits=<projectId> opens the Spirits picker.
        // Clear the param immediately so it doesn't re-open on Save/reload.
        const spiritsParam = new URLSearchParams(window.location.search).get('spirits');
        if (spiritsParam) {
            history.replaceState({}, '', window.location.pathname);
            const card = document.querySelector(`.project-card[data-project-id="${CSS.escape(spiritsParam)}"]`);
            if (card) {
                this.openSpirits(spiritsParam, card.dataset.projectTitle);
            }
        }
    }

    t(key, fallback) {
        return this.translations[key] || fallback;
    }

    openCreate() {
        this.editingId = null;
        document.getElementById('projectFormModalTitle').textContent =
            document.getElementById('project-new-btn').textContent.trim();
        document.getElementById('project-form-title').value = '';
        document.getElementById('project-form-description').value = '';
        this.formModal?.show();
    }

    openEdit(card) {
        this.editingId = card.dataset.projectId;
        document.getElementById('projectFormModalTitle').textContent = card.dataset.projectTitle;
        document.getElementById('project-form-title').value = card.dataset.projectTitle || '';
        document.getElementById('project-form-description').value = card.dataset.projectDescription || '';
        this.formModal?.show();
    }

    async saveForm() {
        const title = document.getElementById('project-form-title').value.trim();
        const description = document.getElementById('project-form-description').value.trim();

        if (!title) {
            window.toast?.error(this.t('title_required', 'Title'));
            return;
        }

        const isEdit = this.editingId !== null;
        const url = isEdit ? `/api/project/${encodeURIComponent(this.editingId)}` : '/api/project';

        try {
            const response = await fetch(url, {
                method: isEdit ? 'PUT' : 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ title, description })
            });
            const data = await response.json();

            if (!data.success) {
                window.toast?.error(data.error || this.t('error_generic', 'Something went wrong'));
                return;
            }

            window.toast?.success(isEdit
                ? this.t('updated_success', 'Project updated')
                : this.t('created_success', 'Project created'));
            this.formModal?.hide();
            window.location.reload();
        } catch (e) {
            window.toast?.error(this.t('error_generic', 'Something went wrong'));
        }
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
                window.toast?.error(data.error || this.t('switch_failed', 'Could not switch project'));
                return;
            }

            window.location.reload();
        } catch (e) {
            window.toast?.error(this.t('switch_failed', 'Could not switch project'));
        }
    }

    async openSpirits(projectId, projectTitle) {
        this.spiritsProjectId = projectId;
        document.getElementById('project-spirits-project-name').textContent = projectTitle || projectId;
        this.spiritsModal?.show();
        await this.renderSpiritsList();
    }

    async renderSpiritsList() {
        const list = document.getElementById('project-spirits-list');
        list.innerHTML = '<div class="text-center p-3"><div class="spinner-border spinner-border-sm text-cyber" role="status"></div></div>';

        try {
            const [allRes, assignedRes] = await Promise.all([
                fetch('/api/spirit/list?all=1').then(r => r.json()),
                fetch(`/api/project/${encodeURIComponent(this.spiritsProjectId)}/spirits`).then(r => r.json())
            ]);

            const spirits = allRes.spirits || [];
            const assigned = new Set(assignedRes.spiritIds || []);

            if (!spirits.length) {
                list.innerHTML = `<p class="text-muted small mb-0">${this.t('spirits_empty', 'No Spirits yet.')}</p>`;
                return;
            }

            list.innerHTML = '';
            spirits.forEach((spirit) => {
                const id = `project-spirit-${spirit.id}`;

                const row = document.createElement('div');
                row.className = 'form-check';

                const cb = document.createElement('input');
                cb.type = 'checkbox';
                cb.className = 'form-check-input';
                cb.id = id;
                cb.value = spirit.id;
                cb.checked = assigned.has(spirit.id);

                const label = document.createElement('label');
                label.className = 'form-check-label';
                label.htmlFor = id;
                label.textContent = spirit.name;

                row.appendChild(cb);
                row.appendChild(label);
                list.appendChild(row);
            });
        } catch (e) {
            list.innerHTML = `<p class="text-danger small mb-0">${this.t('error_generic', 'Something went wrong')}</p>`;
        }
    }

    async saveSpirits() {
        const ids = Array.from(
            document.querySelectorAll('#project-spirits-list input[type="checkbox"]:checked')
        ).map(cb => cb.value);

        try {
            const response = await fetch(`/api/project/${encodeURIComponent(this.spiritsProjectId)}/spirits`, {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ spiritIds: ids })
            });
            const data = await response.json();

            if (!data.success) {
                window.toast?.error(data.error || this.t('error_generic', 'Something went wrong'));
                return;
            }

            window.toast?.success(this.t('spirits_saved', 'Spirits updated'));
            this.spiritsModal?.hide();
            window.location.reload();
        } catch (e) {
            window.toast?.error(this.t('error_generic', 'Something went wrong'));
        }
    }

    openDelete(projectId) {
        this.deletingId = projectId;
        document.getElementById('project-delete-id-label').textContent = projectId;

        const input = document.getElementById('project-delete-confirm-input');
        input.value = '';
        document.getElementById('project-delete-confirm-btn').disabled = true;

        this.deleteModal?.show();
    }

    async confirmDelete() {
        if (!this.deletingId) {
            return;
        }

        try {
            const response = await fetch(`/api/project/${encodeURIComponent(this.deletingId)}`, {
                method: 'DELETE'
            });
            const data = await response.json();

            if (!data.success) {
                window.toast?.error(data.error || this.t('error_generic', 'Something went wrong'));
                return;
            }

            window.toast?.success(this.t('deleted_success', 'Project deleted'));
            this.deleteModal?.hide();
            window.location.reload();
        } catch (e) {
            window.toast?.error(this.t('error_generic', 'Something went wrong'));
        }
    }
}
