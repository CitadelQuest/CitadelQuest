import { CqChatModalManager } from '../js/features/cq-chat/CqChatModalManager.js';
import { updatesService } from '../js/services/UpdatesService.js';

// Initialize CQ Chat Modal Manager when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
    // Create global instance for access from other pages
    window.cqChatModalManager = new CqChatModalManager();
    
    // Start unified polling immediately (not just when modal opens)
    // This keeps badges and dropdown updated even when modal is closed
    // Pass function to get current chat ID for detailed updates
    updatesService.startPolling(5000, () => window.cqChatModalManager?.currentChatId);

    // Footer memory jobs indicator
    updatesService.addListener('footerMemoryJobs', (updates) => {
        const indicator = document.getElementById('memory-jobs-indicator');
        if (!indicator) return;

        const active = updates.memoryJobs?.active || [];
        const processing = active.filter(j => j.status === 'processing').length;

        if (active.length > 0) {
            document.getElementById('memory-jobs-processing').textContent = processing;
            indicator.classList.remove('d-none');
        } else {
            indicator.classList.add('d-none');
        }
    });
});
