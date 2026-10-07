// Using global window.toast service
import { BackupUploader } from './BackupUploader';

const POLL_INTERVAL = 2000;
const MAX_POLL_ERRORS = 5;

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
        window.toast.error(error.message);
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
            window.toast.success(data.message || translations.backup_restored || 'Backup restored successfully!');
            
            // Reload after showing message
            setTimeout(() => window.location.reload(), 2500);
        } else {
            throw new Error(data.error || translations.failed_restore || 'Failed to restore backup');
        }
    })
    .catch(error => {
        window.toast.error(error.message);
        button.disabled = false;
        button.innerHTML = originalHtml;
    });
}

/**
 * Poll a background backup job until it finishes.
 * Resolves on completion (the page then reloads to show the new backup) and
 * rejects on failure so the caller can reset the button and show the error.
 */
async function pollBackupJob(jobId, translations) {
    let consecutiveErrors = 0;

    while (true) {
        let status;
        try {
            const response = await fetch(`/backup/status/${jobId}`, {
                method: 'GET',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                },
                signal: AbortSignal.timeout(30000) // poll must be fast; abort stuck polls
            });
            status = await response.json();
            consecutiveErrors = 0;
        } catch (err) {
            // Network/poll hiccup — retry a few times before giving up.
            // The worker keeps running server-side regardless.
            consecutiveErrors++;
            if (consecutiveErrors >= MAX_POLL_ERRORS) {
                throw new Error(translations.lost_connection || 'Lost connection while waiting for the backup. It may still be running — refresh to check.');
            }
            await new Promise(resolve => setTimeout(resolve, POLL_INTERVAL));
            continue;
        }

        if (!status.success) {
            throw new Error(status.error || translations.failed_create || 'Backup failed');
        }

        if (status.done) {
            if (status.status === 'completed') {
                // Flag survives the reload so the success toast shows on the fresh page.
                sessionStorage.setItem('cq_backup_created', '1');
                window.location.reload();
                return;
            }
            throw new Error(status.error || translations.failed_create || 'Backup failed');
        }

        await new Promise(resolve => setTimeout(resolve, POLL_INTERVAL));
    }
}

export function initBackup() {
    const form = document.getElementById('backupForm');
    if (!form) return;

    const btn = document.getElementById('createBackupBtn');
    if (!btn) return;

    const translations = getTranslations();
    const originalBtnText = btn.innerHTML;
    let isProcessing = false;

    const setProcessing = (on) => {
        isProcessing = on;
        btn.disabled = on;
        btn.innerHTML = on
            ? `<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> ${translations.creating || 'Creating backup...'}`
            : originalBtnText;
    };

    // Show the success toast that was deferred across the post-completion reload.
    if (sessionStorage.getItem('cq_backup_created') === '1') {
        sessionStorage.removeItem('cq_backup_created');
        window.toast.success(translations.success || 'Backup created successfully');
    }

    const runJob = async (jobId) => {
        setProcessing(true);
        try {
            await pollBackupJob(jobId, translations);
        } catch (error) {
            console.error('Backup failed:', error);
            window.toast.error(error.message || translations.failed_create || 'Failed to create backup');
            setProcessing(false);
        }
    };

    form.addEventListener('submit', async function(e) {
        e.preventDefault();
        if (isProcessing) return;

        setProcessing(true);

        try {
            // Start the background job — returns immediately, no Cloudflare 524.
            const response = await fetch(form.action, {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });
            const data = await response.json();

            // Another backup is already running — attach to it instead of erroring.
            if (response.status === 409 && data.jobId) {
                await runJob(data.jobId);
                return;
            }

            if (!response.ok || !data.success || !data.jobId) {
                throw new Error(data.error || translations.failed_create || 'Backup failed');
            }

            await runJob(data.jobId);
        } catch (error) {
            console.error('Backup failed:', error);
            window.toast.error(error.message || translations.failed_create || 'Failed to create backup');
            setProcessing(false);
        }
    });

    // Resume polling a still-running job (e.g. after the page was reloaded mid-backup).
    const activeJobId = form.dataset.activeJob;
    if (activeJobId) {
        runJob(activeJobId);
    }

    // Add click handlers for delete buttons
    document.querySelectorAll('.delete-backup').forEach(button => {
        button.addEventListener('click', handleDeleteBackup);
    });

    // Add click handlers for restore buttons
    document.querySelectorAll('.restore-backup').forEach(button => {
        button.addEventListener('click', handleRestoreBackup);
    });
    
    // Initialize backup uploader
    const uploaderContainer = document.getElementById('backupUploaderContainer');
    if (uploaderContainer) {
        new BackupUploader({
            containerId: 'backupUploaderContainer',
            translations: translations,
            onUploadSuccess: (response) => {
                window.toast.success(translations.upload_success || 'Backup uploaded successfully!');
            }
        });
    }
}
