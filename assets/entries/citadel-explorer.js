import { CitadelExplorer } from '../js/features/cq-contact/CitadelExplorer.js';
import { ExplorerSidebar } from '../js/features/cq-contact/ExplorerSidebar.js';
import { CQFeedManager } from '../js/features/cq-feed/CQFeedManager.js';

// Initialize when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
    // Capture URL params BEFORE CitadelExplorer constructor strips them
    const urlParams = new URLSearchParams(window.location.search);
    const hasUrlParam = urlParams.has('url');
    const feedPostId = urlParams.get('feed-post');

    window.citadelExplorer = new CitadelExplorer();
    window.explorerSidebar = new ExplorerSidebar(window.explorerSidebarConfig || {});
    window.cqFeedManager = new CQFeedManager(window.cqFeedConfig || {});

    // Tab switching: CQ Explorer / CQ Feed
    const explorerTab = document.getElementById('cqExplorerTab');
    const feedTab = document.getElementById('cqFeedTab');
    const explorerPane = document.getElementById('cqExplorerPane');
    const feedPane = document.getElementById('cqFeedPane');

    const activateExplorer = () => {
        explorerTab.classList.add('active');
        feedTab.classList.remove('active');
        explorerPane.classList.remove('d-none');
        feedPane.classList.add('d-none');
        localStorage.setItem('cqExplorerActiveTab', 'explorer');
    };

    const activateFeed = async () => {
        feedTab.classList.add('active');
        explorerTab.classList.remove('active');
        feedPane.classList.remove('d-none');
        explorerPane.classList.add('d-none');
        localStorage.setItem('cqExplorerActiveTab', 'feed');
        // Lazy init on first activation, refresh on subsequent
        if (window.cqFeedManager.isInitialized) {
            await window.cqFeedManager.refresh();
        } else {
            await window.cqFeedManager.init();
        }
        // Mark feed as viewed — clears bell icon + border on next poll
        if (window.explorerSidebar) {
            window.explorerSidebar.markFeedViewed();
        }
    };

    if (explorerTab && feedTab && explorerPane && feedPane) {
        explorerTab.addEventListener('click', (e) => {
            e.preventDefault();
            activateExplorer();
        });

        feedTab.addEventListener('click', async (e) => {
            e.preventDefault();
            await activateFeed();
        });

        // If URL had ?feed-post= param, activate CQ Feed tab and scroll to the post
        if (feedPostId) {
            // Clean URL
            window.history.replaceState({}, '', window.location.pathname);
            // Store target post ID — CQFeedTimeline will handle scroll/highlight/open after each render
            window.cqFeedManager.pendingScrollToPostId = feedPostId;
            activateFeed();
        } else if (hasUrlParam) {
            // If URL had ?url= param, always show CQ Explorer tab
            activateExplorer();
        } else {
            // Restore last active tab from localStorage
            const savedTab = localStorage.getItem('cqExplorerActiveTab');
            if (savedTab === 'feed') {
                activateFeed();
            }
        }
    }
});
