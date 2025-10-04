/*
 * CitadelQuest Main Application Entry Point
 */

// Import styles
import '../styles/app.scss';
import '@mdi/font/css/materialdesignicons.min.css';

// Import icons and images
import '../images/android-chrome-192x192.png';
import '../images/android-chrome-512x512.png';
import '../images/apple-touch-icon.png';
import '../images/favicon-16x16.png';
import '../images/favicon-32x32.png';
import '../images/favicon.ico';
import '../images/logo-sm.png';
import '../images/citadel_quest_bg.jpg';
import '../images/bg-dreamy-flowers-1.webp';
import '../images/bg-dreamy-flowers-2.webp';
import '../images/bg-glow-flowers-1.jpg';
import '../images/night-forest-sky.webp';

// Import Bootstrap's JavaScript
import 'bootstrap';

// Import language switcher
import { initLanguageSwitcher } from '../js/ui/language-switcher';

// Import notifications
import '../js/shared/notifications';

// Import toast
import { ToastService } from '../js/shared/toast';
window.toast = new ToastService();

// Import theme service
import themeService from '../js/shared/theme';

// Initialize Bootstrap components
document.addEventListener('DOMContentLoaded', () => {
    // Get username
    const userBlock = document.querySelector('.js-user');
    if (userBlock) {
        window.appUsername = userBlock.dataset.username;
    } else {
        window.appUsername = null;
    }

    // Initialize theme toggle
    const themeToggle = document.getElementById('theme-toggle');
    if (themeToggle) {
        themeToggle.addEventListener('click', () => {
            themeService.toggleTheme();
        });
    }
    // Initialize language switcher
    initLanguageSwitcher();
    // Enable tooltips everywhere
    const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });

    // Enable popovers everywhere
    const popoverTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="popover"]'));
    popoverTriggerList.map(function (popoverTriggerEl) {
        return new bootstrap.Popover(popoverTriggerEl);
    });

    // Page loading indicator - hide
    const pageLoadingIndicator = document.getElementById('page-loading-indicator');
    if (pageLoadingIndicator) {
        pageLoadingIndicator.classList.remove('d-block');
        pageLoadingIndicator.classList.add('d-none');
    }

});

// show loading indicator on refresh
window.addEventListener('beforeunload', () => {
    const pageLoadingIndicator = document.getElementById('page-loading-indicator');
    if (pageLoadingIndicator) {
        //pageLoadingIndicator.classList.remove('d-none');
        //pageLoadingIndicator.classList.add('d-block');
    }
});


