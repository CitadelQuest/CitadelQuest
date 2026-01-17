/**
 * Admin System Backups entry point
 */

import '../styles/app.scss';
import { SystemBackupsManager } from '../js/features/admin';

// Initialize theme and shared components
import '../js/shared/theme';
import '../js/shared/toast';

// Initialize the system backups manager when the DOM is loaded
document.addEventListener('DOMContentLoaded', () => {
    // Get configuration from data attributes
    const container = document.getElementById('systemBackupsContainer');
    const config = {
        translations: {
            deleting: container?.dataset.transDeleting || 'Deleting',
            delete: container?.dataset.transDelete || 'Delete',
            deleteFailed: container?.dataset.transDeleteFailed || 'Failed to delete system backup'
        },
        deleteUrl: container?.dataset.deleteUrl || '/administration/system-backups/delete/BACKUP_NAME'
    };

    new SystemBackupsManager(config);
    
    // Hide page loading indicator
    const loadingIndicator = document.getElementById('page-loading-indicator');
    if (loadingIndicator) {
        loadingIndicator.classList.add('d-none');
    }
});
