import { CqChatModalManager } from '../js/features/cq-chat/CqChatModalManager.js';

// Initialize CQ Chat Modal Manager when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
    // Create global instance for access from other pages
    window.cqChatModalManager = new CqChatModalManager();
});
