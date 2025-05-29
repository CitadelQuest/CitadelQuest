/**
 * Admin Users Management entry point
 */

import '../styles/app.scss';
import { AdminUsersManager } from '../js/features/admin';

// Initialize theme and shared components
import '../js/shared/theme';
import '../js/shared/toast';

// Initialize the admin users manager when the DOM is loaded
document.addEventListener('DOMContentLoaded', () => {
    // Initialize the admin users manager
    new AdminUsersManager();
    
    // Hide page loading indicator
    const loadingIndicator = document.getElementById('page-loading-indicator');
    if (loadingIndicator) {
        loadingIndicator.classList.add('d-none');
    }
});
