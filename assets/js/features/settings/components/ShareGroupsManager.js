import * as bootstrap from 'bootstrap';
import MarkdownIt from 'markdown-it';
/**
 * ShareGroupsManager
 * 
 * Manages the Share Groups settings page: group CRUD, item management,
 * drag-and-drop reordering, and MDI icon picker.
 */
export class ShareGroupsManager {
    constructor() {
        this.container = document.getElementById('share-groups-settings');
        if (!this.container) return;

        this.apiUrl = this.container.dataset.apiUrl;
        this.sharesUrl = this.container.dataset.sharesUrl;
        this.t = JSON.parse(this.container.dataset.translations || '{}');

        this.groups = [];
        this.allShares = [];
        this.dragSrcEl = null;

        this.listEl = document.getElementById('share-groups-list');
        this.loadingEl = document.getElementById('share-groups-loading');
        this.emptyEl = document.getElementById('share-groups-empty');

        this.initModals();
        this.initEventListeners();
        this.loadGroups();
    }

    // ========================================
    // Initialization
    // ========================================

    initModals() {
        // Group edit modal
        this.groupModal = new bootstrap.Modal(document.getElementById('groupEditModal'));
        this.groupEditId = document.getElementById('group-edit-id');
        this.groupEditTitle = document.getElementById('group-edit-title');
        this.groupEditIcon = document.getElementById('group-edit-icon');
        this.groupIconPreview = document.getElementById('group-icon-preview-i');
        this.groupEditScope = document.getElementById('group-edit-scope');
        this.groupEditNav = document.getElementById('group-edit-nav');
        this.groupEditActive = document.getElementById('group-edit-active');
        this.groupEditIconColor = document.getElementById('group-edit-icon-color');
        this.groupEditIconColorText = document.getElementById('group-edit-icon-color-text');
        this.groupEditSlug = document.getElementById('group-edit-slug');
        this.slugPreviewVal = document.getElementById('slug-preview-val');
        this.saveGroupBtn = document.getElementById('btn-save-group');

        // Add share modal
        this.addShareModal = new bootstrap.Modal(document.getElementById('addShareModal'));
        this.addShareModalBody = document.getElementById('add-share-modal-body');

        // Icon picker modal
        this.iconPickerModal = new bootstrap.Modal(document.getElementById('iconPickerModal'));
        this.iconPickerSearch = document.getElementById('icon-picker-search');
        this.iconPickerGrid = document.getElementById('icon-picker-grid');
    }

    initEventListeners() {
        // Create group button
        document.getElementById('btn-create-group')?.addEventListener('click', () => this.openGroupModal());

        // Save group
        this.saveGroupBtn?.addEventListener('click', () => this.saveGroup());

        // Icon input live preview
        this.groupEditIcon?.addEventListener('input', () => {
            const val = this.groupEditIcon.value.trim();
            this.groupIconPreview.className = `mdi ${val} text-cyber`;
            this.groupIconPreview.style.fontSize = '22px';
        });

        // Icon picker button
        document.getElementById('btn-icon-picker')?.addEventListener('click', () => this.openIconPicker());
        document.getElementById('group-icon-preview')?.addEventListener('click', () => this.openIconPicker());

        // Icon picker search
        this.iconPickerSearch?.addEventListener('input', () => this.filterIcons());

        // Icon color picker sync
        this.groupEditIconColor?.addEventListener('input', () => {
            this.groupEditIconColorText.value = this.groupEditIconColor.value;
            this.updateIconPreviewColor();
        });
        this.groupEditIconColorText?.addEventListener('input', () => {
            const v = this.groupEditIconColorText.value.trim();
            if (/^#[0-9a-fA-F]{6}$/.test(v)) {
                this.groupEditIconColor.value = v;
                this.updateIconPreviewColor();
            }
        });
        document.getElementById('btn-reset-icon-color')?.addEventListener('click', () => {
            this.groupEditIconColor.value = '#95ec86';
            this.groupEditIconColorText.value = '#95ec86';
            this.updateIconPreviewColor();
        });

        // Slug live preview
        this.groupEditSlug?.addEventListener('input', () => {
            const v = this.groupEditSlug.value.trim().toLowerCase().replace(/[^a-z0-9\s-]/g, '').replace(/[\s]+/g, '-');
            this.slugPreviewVal.textContent = v || 'my-content';
        });

        // Enter key in group title saves
        this.groupEditTitle?.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') { e.preventDefault(); this.saveGroup(); }
        });
    }

    updateIconPreviewColor() {
        const color = this.groupEditIconColor?.value || '#95ec86';
        this.groupIconPreview.style.color = color;
        this.groupIconPreview.classList.remove('text-cyber');
    }

    // ========================================
    // Data Loading
    // ========================================

    async loadGroups() {
        this.showLoading(true);
        try {
            const resp = await fetch(this.apiUrl);
            const data = await resp.json();
            this.groups = data.success ? (data.groups || []) : [];
        } catch (e) {
            console.error('Failed to load share groups:', e);
            this.groups = [];
        }
        this.render();
    }

    async loadSharesForPicker(groupId) {
        try {
            const resp = await fetch(this.sharesUrl);
            const data = await resp.json();
            this.allShares = data.success ? (data.shares || []) : [];
        } catch (e) {
            console.error('Failed to load shares:', e);
            this.allShares = [];
        }
        this.renderSharePicker(groupId);
    }

    // ========================================
    // Rendering
    // ========================================

    showLoading(show) {
        this.loadingEl?.classList.toggle('d-none', !show);
        this.listEl?.classList.toggle('d-none', show);
        this.emptyEl?.classList.add('d-none');
    }

    render() {
        this.showLoading(false);

        if (this.groups.length === 0) {
            this.listEl.classList.add('d-none');
            this.emptyEl.classList.remove('d-none');
            return;
        }

        this.emptyEl.classList.add('d-none');
        this.listEl.classList.remove('d-none');

        let html = '';
        this.groups.forEach((group, idx) => {
            const items = group.items || [];
            const isInactive = !group.is_active;
            const opacityClass = isInactive ? ' opacity-50' : '';

            html += `
            <div class="card glass-panel mb-3 share-group-card${opacityClass}" data-group-id="${group.id}" draggable="true">
                <div class="card-header bg-transparent border-secondary border-opacity-25 p-3 d-flex justify-content-between align-items-center">
                    <div class="d-flex align-items-center gap-2">
                        <i class="mdi mdi-drag-vertical text-muted" style="cursor: grab;" title="Drag to reorder"></i>
                        <i class="mdi ${group.mdi_icon || 'mdi-folder'}" style="font-size: 20px; color: ${group.icon_color || '#95ec86'}; opacity: 0.75;"></i>
                        <span class="text-light fw-bold">${this.esc(group.title)}</span>
                        <span class="badge bg-dark border border-secondary border-opacity-25 small text-muted">
                            ${items.length} ${this.tl('items')}
                        </span>
                        ${group.show_in_nav ? '<i class="mdi mdi-navigation-variant-outline text-cyber opacity-50 ms-1" title="' + this.tl('show_in_nav') + '"></i>' : ''}
                        ${isInactive ? '<span class="badge bg-secondary small ms-1">inactive</span>' : ''}
                        <span class="badge bg-${group.scope === 0 ? 'success' : 'info'} bg-opacity-25 small">
                            ${group.scope === 0 ? this.tl('scope_public') : this.tl('scope_federation')}
                        </span>
                    </div>
                    <div class="d-flex align-items-center gap-1">
                        <button class="btn btn-sm btn-outline-cyber border-0" onclick="shareGroupsMgr.openAddShareModal('${group.id}')" title="${this.tl('add_share')}">
                            <i class="mdi mdi-plus"></i>
                        </button>
                        <button class="btn btn-sm btn-outline-secondary border-0" onclick="shareGroupsMgr.openGroupModal('${group.id}')" title="${this.tl('edit_group')}">
                            <i class="mdi mdi-pencil"></i>
                        </button>
                        <button class="btn btn-sm btn-outline-danger border-0" onclick="shareGroupsMgr.deleteGroup('${group.id}')" title="${this.tl('delete_group')}">
                            <i class="mdi mdi-delete"></i>
                        </button>
                    </div>
                </div>`;

            if (items.length > 0) {
                html += `<div class="card-body p-0"><div class="list-group list-group-flush bg-transparent" data-group-id="${group.id}">`;
                items.forEach(item => {
                    const isCqmpack = item.source_type === 'cqmpack';
                    const icon = isCqmpack ? 'mdi-graph' : 'mdi-file';
                    const iconColor = isCqmpack ? 'text-info' : 'text-warning';
                    const dsLabel = this.dsLabel(item.display_style);
                    const ddsLabel = this.ddsLabel(item.description_display_style);

                    html += `
                    <div class="list-group-item bg-transparent border-secondary border-opacity-10 px-3 py-2 d-flex justify-content-between align-items-center share-group-item" 
                         data-item-id="${item.id}" data-group-id="${group.id}" draggable="true">
                        <div class="d-flex align-items-center gap-2">
                            <i class="mdi mdi-drag-horizontal text-muted" style="cursor: grab; font-size: 14px;"></i>
                            <i class="mdi ${icon} ${iconColor}" style="font-size: 14px;"></i>
                            <span class="text-light small">${this.esc(item.share_title || item.title || '—')}</span>
                            ${!item.show_header ? '<i class="mdi mdi-eye-off-outline text-muted small ms-1" title="Header hidden"></i>' : ''}
                            ${dsLabel ? `<span class="badge bg-dark text-muted small border border-secondary border-opacity-25">${dsLabel}</span>` : ''}
                            ${ddsLabel ? `<span class="badge bg-dark text-muted small border border-secondary border-opacity-25">${ddsLabel}</span>` : ''}
                        </div>
                        <div class="d-flex align-items-center gap-1">
                            <button class="btn btn-sm btn-outline-secondary border-0 py-0" onclick="shareGroupsMgr.openItemConfig('${group.id}', '${item.id}')" title="Configure">
                                <i class="mdi mdi-cog small"></i>
                            </button>
                            <button class="btn btn-sm btn-outline-danger border-0 py-0" onclick="shareGroupsMgr.removeItem('${group.id}', '${item.id}')" title="${this.tl('remove_item')}">
                                <i class="mdi mdi-close small"></i>
                            </button>
                        </div>
                    </div>`;
                });
                html += `</div></div>`;
            } else {
                html += `<div class="card-body py-3 text-center text-muted small">${this.tl('empty_group')}</div>`;
            }

            html += `</div>`;
        });

        this.listEl.innerHTML = html;
        this.initDragAndDrop();
    }

    dsLabel(val) {
        if (val === null || val === undefined || val === '') return '';
        val = parseInt(val);
        if (val === 0) return this.tl('ds_hidden');
        if (val === 1) return this.tl('ds_preview');
        if (val === 2) return this.tl('ds_full');
        return '';
    }

    ddsLabel(val) {
        if (val === null || val === undefined || val === '') return '';
        val = parseInt(val);
        if (val === 0) return '↑ ' + this.tl('desc_above');
        if (val === 1) return '↓ ' + this.tl('desc_below');
        if (val === 2) return '← ' + this.tl('desc_left');
        if (val === 3) return '→ ' + this.tl('desc_right');
        return '';
    }

    // ========================================
    // Group CRUD
    // ========================================

    openGroupModal(groupId = null) {
        const modalTitle = document.getElementById('groupEditModalTitle');
        if (groupId) {
            const group = this.groups.find(g => g.id === groupId);
            if (!group) return;
            modalTitle.textContent = this.tl('edit_group');
            this.groupEditId.value = group.id;
            this.groupEditTitle.value = group.title;
            this.groupEditIcon.value = group.mdi_icon || 'mdi-folder';
            this.groupEditScope.value = group.scope;
            this.groupEditNav.checked = !!group.show_in_nav;
            this.groupEditActive.checked = !!group.is_active;
            const color = group.icon_color || '#95ec86';
            this.groupEditIconColor.value = color;
            this.groupEditIconColorText.value = color;
            this.groupEditSlug.value = group.url_slug || '';
            this.slugPreviewVal.textContent = group.url_slug || 'my-content';
        } else {
            modalTitle.textContent = this.tl('create_group');
            this.groupEditId.value = '';
            this.groupEditTitle.value = '';
            this.groupEditIcon.value = 'mdi-folder';
            this.groupEditScope.value = '0';
            this.groupEditNav.checked = true;
            this.groupEditActive.checked = true;
            this.groupEditIconColor.value = '#95ec86';
            this.groupEditIconColorText.value = '#95ec86';
            this.groupEditSlug.value = '';
            this.slugPreviewVal.textContent = 'my-content';
        }
        const iconClass = this.groupEditIcon.value;
        const iconColor = this.groupEditIconColor.value || '#95ec86';
        this.groupIconPreview.className = `mdi ${iconClass}`;
        this.groupIconPreview.style.fontSize = '22px';
        this.groupIconPreview.style.color = iconColor;
        this.groupModal.show();
        setTimeout(() => this.groupEditTitle.focus(), 300);
    }

    async saveGroup() {
        const id = this.groupEditId.value;
        const title = this.groupEditTitle.value.trim();
        if (!title) { this.groupEditTitle.focus(); return; }

        const spinner = this.saveGroupBtn.querySelector('.spinner-border');
        spinner?.classList.remove('d-none');
        this.saveGroupBtn.disabled = true;

        const payload = {
            title,
            mdi_icon: this.groupEditIcon.value.trim() || 'mdi-folder',
            scope: parseInt(this.groupEditScope.value),
            show_in_nav: this.groupEditNav.checked ? 1 : 0,
            is_active: this.groupEditActive.checked ? 1 : 0,
            icon_color: this.groupEditIconColorText.value.trim() || null,
            url_slug: this.groupEditSlug.value.trim() || null,
        };

        try {
            const url = id ? `${this.apiUrl}/${id}` : this.apiUrl;
            const method = id ? 'PUT' : 'POST';
            const resp = await fetch(url, {
                method,
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            const data = await resp.json();
            if (data.success) {
                window.toast?.success(this.tl('saved'));
                this.groupModal.hide();
                this.loadGroups();
            } else {
                window.toast?.error(data.message || this.tl('error'));
            }
        } catch (e) {
            console.error('Save group error:', e);
            window.toast?.error(this.tl('error'));
        } finally {
            spinner?.classList.add('d-none');
            this.saveGroupBtn.disabled = false;
        }
    }

    async deleteGroup(groupId) {
        if (!confirm(this.tl('delete_group_confirm'))) return;

        try {
            const resp = await fetch(`${this.apiUrl}/${groupId}`, { method: 'DELETE' });
            const data = await resp.json();
            if (data.success) {
                window.toast?.success(this.tl('deleted'));
                this.loadGroups();
            } else {
                window.toast?.error(data.message || this.tl('error'));
            }
        } catch (e) {
            console.error('Delete group error:', e);
            window.toast?.error(this.tl('error'));
        }
    }

    // ========================================
    // Add Share to Group
    // ========================================

    openAddShareModal(groupId) {
        this.currentGroupIdForAdd = groupId;
        this.addShareModalBody.innerHTML = `<div class="text-center py-3"><div class="spinner-border spinner-border-sm text-cyber" role="status"></div></div>`;
        this.addShareModal.show();
        this.loadSharesForPicker(groupId);
    }

    renderSharePicker(groupId) {
        const group = this.groups.find(g => g.id === groupId);
        const existingShareIds = (group?.items || []).map(i => i.share_id);

        // Filter out shares already in this group
        const available = this.allShares.filter(s => !existingShareIds.includes(s.id));

        if (available.length === 0) {
            this.addShareModalBody.innerHTML = `<p class="text-muted text-center py-3">${this.tl('no_shares')}</p>`;
            return;
        }

        let html = `<div class="list-group list-group-flush bg-transparent">`;
        available.forEach(share => {
            const isCqmpack = share.source_type === 'cqmpack';
            const icon = isCqmpack ? 'mdi-graph text-info' : 'mdi-file text-warning';
            html += `
            <button class="list-group-item list-group-item-action bg-transparent text-light border-secondary border-opacity-10 d-flex align-items-center gap-2"
                    onclick="shareGroupsMgr.addShareToGroup('${groupId}', '${share.id}')">
                <i class="mdi ${icon}"></i>
                <span>${this.esc(share.title || share.share_url)}</span>
                <span class="badge bg-secondary bg-opacity-25 small ms-auto">${isCqmpack ? 'Memory Pack' : 'File'}</span>
            </button>`;
        });
        html += `</div>`;
        this.addShareModalBody.innerHTML = html;
    }

    async addShareToGroup(groupId, shareId) {
        try {
            const resp = await fetch(`${this.apiUrl}/${groupId}/items`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ share_id: shareId })
            });
            const data = await resp.json();
            if (data.success) {
                window.toast?.success(this.tl('item_added'));
                this.addShareModal.hide();
                this.loadGroups();
            } else {
                window.toast?.error(data.message || this.tl('error'));
            }
        } catch (e) {
            console.error('Add share error:', e);
            window.toast?.error(this.tl('error'));
        }
    }

    // ========================================
    // Item Configuration (inline popover)
    // ========================================

    openItemConfig(groupId, itemId) {
        const group = this.groups.find(g => g.id === groupId);
        const item = (group?.items || []).find(i => i.id === itemId);
        if (!item) return;

        // Build inline config form
        const itemEl = this.listEl.querySelector(`[data-item-id="${itemId}"]`);
        if (!itemEl) return;

        // Toggle — if config is already open, close it
        const existing = itemEl.nextElementSibling;
        if (existing && existing.classList.contains('item-config-panel')) {
            existing.remove();
            return;
        }

        const ds = item.display_style;
        const dds = item.description_display_style;
        const sh = item.show_header;

        const panel = document.createElement('div');
        panel.className = 'item-config-panel bg-dark border-secondary border-opacity-10 px-4 py-3';
        panel.innerHTML = `
            <div class="row g-2 align-items-end">
                <div class="col-auto">
                    <label class="form-label text-muted small mb-1">${this.tl('display_style')}</label>
                    <select class="form-select form-select-sm bg-dark text-light border-secondary" id="item-cfg-ds-${itemId}" style="width: 120px;">
                        <option value="" ${ds === null || ds === undefined || ds === '' ? 'selected' : ''}>${this.tl('ds_inherit')}</option>
                        <option value="0" ${ds === 0 ? 'selected' : ''}>${this.tl('ds_hidden')}</option>
                        <option value="1" ${ds === 1 ? 'selected' : ''}>${this.tl('ds_preview')}</option>
                        <option value="2" ${ds === 2 ? 'selected' : ''}>${this.tl('ds_full')}</option>
                    </select>
                </div>
                <div class="col-auto">
                    <label class="form-label text-muted small mb-1">${this.tl('desc_ds')}</label>
                    <select class="form-select form-select-sm bg-dark text-light border-secondary" id="item-cfg-dds-${itemId}" style="width: 120px;">
                        <option value="" ${dds === null || dds === undefined || dds === '' ? 'selected' : ''}>${this.tl('ds_inherit')}</option>
                        <option value="0" ${dds === 0 ? 'selected' : ''}>${this.tl('desc_above')}</option>
                        <option value="1" ${dds === 1 ? 'selected' : ''}>${this.tl('desc_below')}</option>
                        <option value="2" ${dds === 2 ? 'selected' : ''}>${this.tl('desc_left')}</option>
                        <option value="3" ${dds === 3 ? 'selected' : ''}>${this.tl('desc_right')}</option>
                    </select>
                </div>
                <div class="col-auto d-flex align-items-center" style="padding-bottom: 4px;">
                    <div class="form-check form-switch mb-0">
                        <input class="form-check-input" type="checkbox" id="item-cfg-sh-${itemId}" ${sh !== 0 ? 'checked' : ''}>
                        <label class="form-check-label text-light small" for="item-cfg-sh-${itemId}">${this.tl('show_header')}</label>
                    </div>
                </div>
                <div class="col-auto ms-auto">
                    <button class="btn btn-sm btn-cyber" onclick="shareGroupsMgr.saveItemConfig('${groupId}', '${itemId}')">
                        <i class="mdi mdi-check me-1"></i>${this.tl('save')}
                    </button>
                </div>
            </div>
        `;
        itemEl.after(panel);
    }

    async saveItemConfig(groupId, itemId) {
        const dsEl = document.getElementById(`item-cfg-ds-${itemId}`);
        const ddsEl = document.getElementById(`item-cfg-dds-${itemId}`);
        const shEl = document.getElementById(`item-cfg-sh-${itemId}`);

        const payload = {
            display_style: dsEl.value === '' ? null : parseInt(dsEl.value),
            description_display_style: ddsEl.value === '' ? null : parseInt(ddsEl.value),
            show_header: shEl.checked ? 1 : 0,
        };

        try {
            const resp = await fetch(`${this.apiUrl}/${groupId}/items/${itemId}`, {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            const data = await resp.json();
            if (data.success) {
                window.toast?.success(this.tl('saved'));
                this.loadGroups();
            } else {
                window.toast?.error(data.message || this.tl('error'));
            }
        } catch (e) {
            console.error('Save item config error:', e);
            window.toast?.error(this.tl('error'));
        }
    }

    async removeItem(groupId, itemId) {
        try {
            const resp = await fetch(`${this.apiUrl}/${groupId}/items/${itemId}`, { method: 'DELETE' });
            const data = await resp.json();
            if (data.success) {
                window.toast?.success(this.tl('item_removed'));
                this.loadGroups();
            } else {
                window.toast?.error(data.message || this.tl('error'));
            }
        } catch (e) {
            console.error('Remove item error:', e);
            window.toast?.error(this.tl('error'));
        }
    }

    // ========================================
    // Drag and Drop — Group Reordering
    // ========================================

    initDragAndDrop() {
        // Group card drag
        this.listEl.querySelectorAll('.share-group-card').forEach(card => {
            card.addEventListener('dragstart', (e) => this.onGroupDragStart(e));
            card.addEventListener('dragover', (e) => this.onGroupDragOver(e));
            card.addEventListener('drop', (e) => this.onGroupDrop(e));
            card.addEventListener('dragend', (e) => this.onDragEnd(e));
        });

        // Item drag within groups
        this.listEl.querySelectorAll('.share-group-item').forEach(item => {
            item.addEventListener('dragstart', (e) => this.onItemDragStart(e));
            item.addEventListener('dragover', (e) => this.onItemDragOver(e));
            item.addEventListener('drop', (e) => this.onItemDrop(e));
            item.addEventListener('dragend', (e) => this.onDragEnd(e));
        });
    }

    onGroupDragStart(e) {
        // Only drag from the drag handle
        if (!e.target.classList.contains('share-group-card')) {
            const card = e.target.closest('.share-group-card');
            if (card) {
                this.dragSrcEl = card;
                e.dataTransfer.effectAllowed = 'move';
                e.dataTransfer.setData('text/plain', card.dataset.groupId);
                card.classList.add('opacity-50');
            }
            return;
        }
        this.dragSrcEl = e.target;
        e.dataTransfer.effectAllowed = 'move';
        e.dataTransfer.setData('text/plain', e.target.dataset.groupId);
        e.target.classList.add('opacity-50');
    }

    onGroupDragOver(e) {
        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';
    }

    async onGroupDrop(e) {
        e.preventDefault();
        const targetCard = e.target.closest('.share-group-card');
        if (!targetCard || !this.dragSrcEl || targetCard === this.dragSrcEl) return;

        // Reorder in DOM
        const cards = [...this.listEl.querySelectorAll('.share-group-card')];
        const srcIdx = cards.indexOf(this.dragSrcEl);
        const tgtIdx = cards.indexOf(targetCard);

        if (srcIdx < tgtIdx) {
            targetCard.after(this.dragSrcEl);
        } else {
            targetCard.before(this.dragSrcEl);
        }

        // Collect new order
        const orderedIds = [...this.listEl.querySelectorAll('.share-group-card')].map(c => c.dataset.groupId);
        await this.reorderGroups(orderedIds);
    }

    onItemDragStart(e) {
        e.stopPropagation();
        this.dragSrcEl = e.target.closest('.share-group-item');
        e.dataTransfer.effectAllowed = 'move';
        e.dataTransfer.setData('text/plain', this.dragSrcEl?.dataset.itemId || '');
        this.dragSrcEl?.classList.add('opacity-50');
    }

    onItemDragOver(e) {
        e.preventDefault();
        e.stopPropagation();
        e.dataTransfer.dropEffect = 'move';
    }

    async onItemDrop(e) {
        e.preventDefault();
        e.stopPropagation();
        const targetItem = e.target.closest('.share-group-item');
        if (!targetItem || !this.dragSrcEl || targetItem === this.dragSrcEl) return;

        // Only reorder within same group
        if (targetItem.dataset.groupId !== this.dragSrcEl.dataset.groupId) return;

        const groupId = targetItem.dataset.groupId;
        const container = targetItem.parentElement;
        const items = [...container.querySelectorAll('.share-group-item')];
        const srcIdx = items.indexOf(this.dragSrcEl);
        const tgtIdx = items.indexOf(targetItem);

        if (srcIdx < tgtIdx) {
            targetItem.after(this.dragSrcEl);
        } else {
            targetItem.before(this.dragSrcEl);
        }

        // Collect new order
        const orderedIds = [...container.querySelectorAll('.share-group-item')].map(i => i.dataset.itemId);
        await this.reorderItems(groupId, orderedIds);
    }

    onDragEnd(e) {
        e.target.classList.remove('opacity-50');
        this.dragSrcEl?.classList.remove('opacity-50');
        this.dragSrcEl = null;
    }

    async reorderGroups(orderedIds) {
        try {
            await fetch(`${this.apiUrl}/reorder`, {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ ordered_ids: orderedIds })
            });
            this.loadGroups();
        } catch (e) {
            console.error('Reorder groups error:', e);
        }
    }

    async reorderItems(groupId, orderedIds) {
        try {
            await fetch(`${this.apiUrl}/${groupId}/items/reorder`, {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ ordered_ids: orderedIds })
            });
            this.loadGroups();
        } catch (e) {
            console.error('Reorder items error:', e);
        }
    }

    // ========================================
    // MDI Icon Picker
    // ========================================

    openIconPicker() {
        this.groupModal.hide();
        this.renderIconGrid();
        this.iconPickerModal.show();
        setTimeout(() => this.iconPickerSearch.focus(), 300);
    }

    renderIconGrid(filter = '') {
        const icons = this.getCommonMdiIcons();
        const filtered = filter ? icons.filter(i => i.includes(filter.toLowerCase())) : icons;

        let html = '';
        filtered.forEach(icon => {
            html += `<div class="icon-picker-item d-flex align-items-center justify-content-center rounded border border-secondary border-opacity-25" 
                          style="width: 42px; height: 42px; cursor: pointer; background: rgba(255,255,255,0.03);"
                          onclick="shareGroupsMgr.selectIcon('${icon}')" title="${icon}">
                <i class="mdi ${icon} text-light" style="font-size: 20px;"></i>
            </div>`;
        });
        this.iconPickerGrid.innerHTML = html || '<p class="text-muted small">No icons found</p>';
    }

    filterIcons() {
        this.renderIconGrid(this.iconPickerSearch.value.trim());
    }

    selectIcon(icon) {
        this.groupEditIcon.value = icon;
        const color = this.groupEditIconColor?.value || '#95ec86';
        this.groupIconPreview.className = `mdi ${icon}`;
        this.groupIconPreview.style.fontSize = '22px';
        this.groupIconPreview.style.color = color;
        this.iconPickerModal.hide();
        setTimeout(() => this.groupModal.show(), 300);
    }

    getCommonMdiIcons() {
        return [
            'mdi-folder', 'mdi-folder-open', 'mdi-folder-star', 'mdi-folder-heart',
            'mdi-folder-music', 'mdi-folder-image', 'mdi-folder-video', 'mdi-folder-text',
            'mdi-star', 'mdi-star-outline', 'mdi-heart', 'mdi-heart-outline',
            'mdi-bookmark', 'mdi-bookmark-outline', 'mdi-pin', 'mdi-pin-outline',
            'mdi-music', 'mdi-music-note', 'mdi-headphones', 'mdi-microphone',
            'mdi-image', 'mdi-image-multiple', 'mdi-camera', 'mdi-palette',
            'mdi-video', 'mdi-movie', 'mdi-filmstrip', 'mdi-television',
            'mdi-file', 'mdi-file-document', 'mdi-file-pdf-box', 'mdi-file-code',
            'mdi-book', 'mdi-book-open-variant', 'mdi-library', 'mdi-notebook',
            'mdi-code-tags', 'mdi-xml', 'mdi-language-javascript', 'mdi-language-python',
            'mdi-earth', 'mdi-web', 'mdi-link', 'mdi-link-variant',
            'mdi-graph', 'mdi-graph-outline', 'mdi-chart-line', 'mdi-chart-bar',
            'mdi-brain', 'mdi-head-cog', 'mdi-robot', 'mdi-ghost',
            'mdi-lightbulb', 'mdi-lightbulb-outline', 'mdi-idea', 'mdi-thought-bubble',
            'mdi-trophy', 'mdi-medal', 'mdi-crown', 'mdi-diamond-stone',
            'mdi-school', 'mdi-graduation-cap', 'mdi-human-male-board', 'mdi-pencil',
            'mdi-briefcase', 'mdi-account-group', 'mdi-handshake', 'mdi-domain',
            'mdi-home', 'mdi-castle', 'mdi-shield', 'mdi-security',
            'mdi-cog', 'mdi-wrench', 'mdi-hammer', 'mdi-tools',
            'mdi-flask', 'mdi-microscope', 'mdi-atom', 'mdi-rocket',
            'mdi-gamepad-variant', 'mdi-controller', 'mdi-puzzle', 'mdi-dice-5',
            'mdi-map', 'mdi-compass', 'mdi-navigation', 'mdi-airplane',
            'mdi-food', 'mdi-coffee', 'mdi-beer', 'mdi-glass-wine',
            'mdi-tree', 'mdi-flower', 'mdi-leaf', 'mdi-pine-tree',
            'mdi-cat', 'mdi-dog', 'mdi-paw', 'mdi-fish',
            'mdi-weather-sunny', 'mdi-weather-night', 'mdi-moon-waning-crescent', 'mdi-fire',
            'mdi-emoticon', 'mdi-emoticon-cool', 'mdi-emoticon-happy', 'mdi-emoticon-excited',
            'mdi-tag', 'mdi-tag-multiple', 'mdi-label', 'mdi-label-outline',
            'mdi-share-variant', 'mdi-share', 'mdi-export', 'mdi-import',
            'mdi-cloud', 'mdi-cloud-upload', 'mdi-cloud-download', 'mdi-database',
            'mdi-lock', 'mdi-lock-open', 'mdi-key', 'mdi-eye',
            'mdi-bell', 'mdi-alarm', 'mdi-clock', 'mdi-calendar',
            'mdi-flag', 'mdi-flag-variant', 'mdi-bullhorn', 'mdi-message',
            'mdi-email', 'mdi-phone', 'mdi-cellphone', 'mdi-chat',
            'mdi-currency-usd', 'mdi-currency-eur', 'mdi-currency-btc', 'mdi-wallet',
            'mdi-shopping', 'mdi-cart', 'mdi-gift', 'mdi-basket',
            'mdi-run', 'mdi-walk', 'mdi-bike', 'mdi-swim',
            'mdi-weight-lifter', 'mdi-yoga', 'mdi-meditation', 'mdi-dumbbell',
        ];
    }

    // ========================================
    // Helpers
    // ========================================

    tl(key) {
        return this.t[key] || key;
    }

    esc(str) {
        const div = document.createElement('div');
        div.textContent = str || '';
        return div.innerHTML;
    }
}

// Expose globally for onclick handlers
window.shareGroupsMgr = null;
document.addEventListener('DOMContentLoaded', () => {
    const existing = document.getElementById('share-groups-settings');
    if (existing && !window.shareGroupsMgr) {
        window.shareGroupsMgr = new ShareGroupsManager();
    }
});
