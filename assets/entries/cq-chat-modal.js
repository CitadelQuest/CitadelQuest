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

    // ========================================
    // Global CQ Explorer feed-updates polling (separate from /api/updates, 60s interval)
    // Updates nav badge + dashboard badge + notifies ExplorerSidebar
    // ========================================
    async function checkFeedUpdates() {
        try {
            const resp = await fetch('/api/follow/feed-updates');
            if (!resp.ok) return;
            const data = await resp.json();
            if (!data.success) return;

            const items = data.items || [];
            const feedLastViewed = localStorage.getItem('cqFeedLastViewedAt');
            let newCount = 0;
            items.forEach(item => {
                if (item.has_new_content) {
                    newCount++;
                } else if (item.has_new && item.has_new_feed) {
                    // Feed-only update — only count if not yet viewed
                    const feedTs = item.last_feed_updated_at;
                    if (!feedLastViewed || (feedTs && feedTs > feedLastViewed)) newCount++;
                }
            });

            // Update nav badge
            const navBadge = document.getElementById('cqExplorerNewBadge');
            if (navBadge) {
                if (newCount > 0) {
                    navBadge.textContent = newCount;
                    navBadge.classList.remove('d-none');
                } else {
                    navBadge.textContent = '';
                    navBadge.classList.add('d-none');
                }
            }

            // Update dashboard badge if present
            const dashBadge = document.getElementById('feed-new-badge');
            if (dashBadge) {
                if (newCount > 0) {
                    dashBadge.textContent = newCount;
                    dashBadge.classList.remove('d-none');
                } else {
                    dashBadge.classList.add('d-none');
                }
            }

            // Dispatch event for ExplorerSidebar and other listeners
            window.dispatchEvent(new CustomEvent('cq-feed-updates', { detail: { items, newCount } }));
        } catch (e) {
            // Silently fail — feed check is non-critical
        }
    }

    // Run immediately + every 60s
    checkFeedUpdates();
    setInterval(checkFeedUpdates, 60000);
});
