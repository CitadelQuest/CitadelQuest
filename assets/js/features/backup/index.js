import { ToastService } from '../../shared/toast';

function getTranslations() {
    const container = document.querySelector('[data-translations]');
    const translationsAttr = container ? container.dataset.translations : null;
    const translations = translationsAttr ? JSON.parse(translationsAttr) : {};
    return translations;
}

function handleDeleteBackup(event) {
    const button = event.currentTarget;
    const filename = button.dataset.backupFile;
    
    const translations = getTranslations();
    
    if (!confirm(translations.confirm_delete || 'Are you sure you want to delete this backup?')) {
        return;
    }

    button.disabled = true;
    const originalHtml = button.innerHTML;
    button.innerHTML = '<i class="mdi mdi-loading mdi-spin"></i>';

    fetch(`/backup/delete/${filename}`, {
        method: 'POST',
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Remove the list item
            button.closest('.list-group-item').remove();
            
            // If no more backups, refresh the page to hide the card
            const listGroup = document.querySelector('.list-group');
            if (!listGroup || listGroup.children.length === 0) {
                window.location.reload();
            }
        } else {
            throw new Error(data.error || translations.failed_delete || 'Failed to delete backup');
        }
    })
    .catch(error => {
        const toast = new ToastService();
        toast.error(error.message);
        button.disabled = false;
        button.innerHTML = originalHtml;
    });
}

function handleRestoreBackup(event) {
    const button = event.currentTarget;
    const filename = button.dataset.backupFile;
    
    const translations = getTranslations();
    
    if (!confirm(translations.confirm_restore || 'Are you sure you want to restore this backup? This will replace your current data.')) {
        return;
    }

    button.disabled = true;
    const originalHtml = button.innerHTML;
    button.innerHTML = '<i class="mdi mdi-loading mdi-spin"></i>';

    fetch(`/backup/restore/${filename}`, {
        method: 'POST',
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Show success message
            const toast = new ToastService();
            toast.success(data.message || translations.backup_restored || 'Backup restored successfully!');
            
            // Reload after showing message
            setTimeout(() => window.location.reload(), 2500);
        } else {
            throw new Error(data.error || translations.failed_restore || 'Failed to restore backup');
        }
    })
    .catch(error => {
        const toast = new ToastService();
        toast.error(error.message);
        button.disabled = false;
        button.innerHTML = originalHtml;
    });
}

export function initBackup() {
    const form = document.getElementById('backupForm');
    if (!form) return;

    const btn = document.getElementById('createBackupBtn');
    if (!btn) return;

    const originalBtnText = btn.innerHTML;
    let isProcessing = false;

    form.addEventListener('submit', async function(e) {
        e.preventDefault();
        if (isProcessing) return;

        // Set button to loading state
        isProcessing = true;
        btn.disabled = true;
        const translations = getTranslations();
        btn.innerHTML = `<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> ${translations.creating || 'Creating backup...'}`;

        try {
            // Create backup
            const response = await fetch(form.action, {
                method: 'POST'
            });
            
            if (!response.ok) throw new Error(translations.failed_create || 'Backup failed');
            
            // Get the filename from Content-Disposition header
            const contentDisposition = response.headers.get('Content-Disposition');
            let filename = 'citadel_backup.citadel';
            if (contentDisposition) {
                const matches = /filename[^;=\n]*=((['"]).*(\2)|[^;\n]*)/.exec(contentDisposition);
                if (matches && matches[1]) {
                    filename = matches[1].replace(/['"]*/g, '');
                }
            }
            
            // Get the blob and create download URL
            const blob = await response.blob();
            const url = window.URL.createObjectURL(blob);
            
            // Create download link and trigger download
            const a = document.createElement('a');
            a.href = url;
            a.download = filename;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            window.URL.revokeObjectURL(url);
            
            // Show success toast
            const toast = new ToastService();
            toast.success(translations.backup_created || 'Backup created successfully!');
            
            // Reload page after showing message
            setTimeout(() => window.location.reload(), 2500);
        } catch (error) {
            console.error('Backup failed:', error);
            const toast = new ToastService();
            toast.error(translations.failed_create || 'Failed to create backup');
            btn.innerHTML = originalBtnText;
            btn.disabled = false;
            isProcessing = false;
        }
    });

    // Add click handlers for delete buttons
    document.querySelectorAll('.delete-backup').forEach(button => {
        button.addEventListener('click', handleDeleteBackup);
    });

    // Add click handlers for restore buttons
    document.querySelectorAll('.restore-backup').forEach(button => {
        button.addEventListener('click', handleRestoreBackup);
    });
}
