/**
 * SystemBackupsManager.js
 * Manages the system backups functionality in admin panel
 */

import * as bootstrap from 'bootstrap';

export class SystemBackupsManager {
    constructor(config = {}) {
        this.translations = config.translations || {};
        this.deleteUrl = config.deleteUrl || '/administration/system-backups/delete/BACKUP_NAME';
        
        this.deleteModal = null;
        this.currentBackupName = null;
        this.currentRowId = null;
        
        this.init();
    }

    init() {
        const modalEl = document.getElementById('deleteBackupModal');
        if (!modalEl) return;
        
        this.deleteModal = new bootstrap.Modal(modalEl);
        this.initDeleteButtons();
        this.initConfirmDelete();
    }

    initDeleteButtons() {
        document.querySelectorAll('.delete-backup-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                this.currentBackupName = btn.dataset.backupName;
                this.currentRowId = btn.dataset.rowId;
                document.getElementById('deleteBackupName').textContent = this.currentBackupName;
                this.deleteModal.show();
            });
        });
    }

    initConfirmDelete() {
        const confirmBtn = document.getElementById('confirmDeleteBtn');
        if (!confirmBtn) return;

        confirmBtn.addEventListener('click', async () => {
            if (!this.currentBackupName) return;

            confirmBtn.disabled = true;
            confirmBtn.innerHTML = `<span class="spinner-border spinner-border-sm me-2"></span>${this.translations.deleting || 'Deleting'}...`;

            try {
                const url = this.deleteUrl.replace('BACKUP_NAME', this.currentBackupName);
                const response = await fetch(url, {
                    method: 'POST',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                });

                const data = await response.json();

                if (data.success) {
                    const row = document.getElementById(this.currentRowId);
                    if (row) {
                        row.remove();
                    }
                    
                    this.deleteModal.hide();
                    
                    if (window.toast) {
                        window.toast.success(data.message);
                    }

                    // Check if table is now empty
                    const tbody = document.querySelector('table tbody');
                    if (tbody && tbody.children.length === 0) {
                        location.reload();
                    }
                } else {
                    if (window.toast) {
                        window.toast.error(data.message);
                    }
                }
            } catch (error) {
                console.error('Delete failed:', error);
                if (window.toast) {
                    window.toast.error(this.translations.deleteFailed || 'Failed to delete system backup');
                }
            } finally {
                confirmBtn.disabled = false;
                confirmBtn.innerHTML = `<i class="mdi mdi-delete me-2"></i>${this.translations.delete || 'Delete'}`;
            }
        });
    }
}
