/**
 * Admin Dashboard entry point
 */

import '../styles/app.scss';
import { AdminDashboardManager } from '../js/features/admin';

// Initialize theme and shared components
import '../js/shared/theme';
import '../js/shared/toast';

// Initialize the admin dashboard when the DOM is loaded
document.addEventListener('DOMContentLoaded', () => {
    // Initialize the admin dashboard manager
    const adminDashboardManager = new AdminDashboardManager();
    adminDashboardManager.init();
    
    // Hide page loading indicator
    const loadingIndicator = document.getElementById('page-loading-indicator');
    if (loadingIndicator) {
        loadingIndicator.classList.add('d-none');
    }
});
